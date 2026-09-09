<?php

/**
 * SAFETY NOTE — read before touching this file.
 *
 * IssuesController::fetchAllDbCreds() calls the raw, unqualified exec() to
 * launch `nohup php artisan clockwork:extract-wp-configs ... &` as a
 * detached background process. That command SSHes into every eligible
 * server for real. This suite must never let that reach the real global
 * exec().
 *
 * The App\Http\Controllers\exec() shadow that makes that safe lives in
 * tests/Support/HttpControllersExecShadow.php — shared with
 * OperationsUpdatesControllerTest.php, since PHP fatal-errors on two files
 * each declaring the same namespaced function. See that file for the
 * technique. Calls land in $GLOBALS['__cw_http_controllers_exec_calls'].
 */

namespace {

    require_once __DIR__.'/../../Support/HttpControllersExecShadow.php';

    use App\Http\Controllers\IssuesController;
    use App\Models\Server;
    use App\Models\Site;
    use App\Models\User;
    use Illuminate\Support\Facades\Artisan;
    use Illuminate\Support\Facades\Cache;
    use Tests\Concerns\RendersAuthenticatedPages;

    uses(RendersAuthenticatedPages::class);

    function icResetExecFixtures(): void
    {
        $GLOBALS['__cw_http_controllers_exec_calls'] = [];
    }

    describe('IssuesController', function () {
        beforeEach(function () {
            $this->mockIssueCounterZero();
            icResetExecFixtures();
        });

        it('redirects unauthenticated requests to login', function () {
            $this->get(route('issues.index'))->assertRedirect(route('login'));
        });

        it('renders the issues page', function () {
            $response = $this->actingAs(User::factory()->create())
                ->get(route('issues.index'));

            $response->assertOk()->assertSee('Issues');
        });

        it('poll-servers dispatches clockwork:poll-servers and reports unhealthy count', function () {
            Artisan::shouldReceive('call')
                ->once()
                ->with('clockwork:poll-servers')
                ->andReturn(0);

            Server::factory()->create(['status' => Server::STATUS_RED]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('issues.poll-servers'));

            $response->assertOk()->assertJson(['ok' => true, 'unhealthy' => 1]);
        });

        it('poll-servers returns a 500 JSON error when the artisan command throws', function () {
            Artisan::shouldReceive('call')
                ->once()
                ->with('clockwork:poll-servers')
                ->andThrow(new RuntimeException('ssh fleet unreachable'));

            $response = $this->actingAs(User::factory()->create())
                ->post(route('issues.poll-servers'));

            $response->assertStatus(500)->assertJson(['ok' => false, 'error' => 'ssh fleet unreachable']);
        });

        it('starts a background fetch on fetch-all-db-creds, stamps the in-progress cache marker, and never touches the real exec()', function () {
            $server = Server::factory()->create(['last_ssh_ok_at' => now()]);
            Site::factory()->spinupwp()->create([
                'server_id' => $server->id,
                'is_wordpress' => true,
                'db_password' => null,
            ]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('issues.fetch-all-db-creds'));

            $response->assertRedirect()
                ->assertSessionHas('status', 'Fetching DB credentials in the background — this page will show progress as sites complete.');

            expect(Cache::has(IssuesController::DB_CREDS_MARKER_KEY))->toBeTrue();
            expect($GLOBALS['__cw_http_controllers_exec_calls'])->toHaveCount(1);
            expect($GLOBALS['__cw_http_controllers_exec_calls'][0])
                ->toContain('clockwork:extract-wp-configs')
                ->toContain('< /dev/null')
                ->toContain('> /dev/null 2>&1');
        });

        it('fetch-all-db-creds reports no candidates when nothing is missing credentials', function () {
            $response = $this->actingAs(User::factory()->create())
                ->post(route('issues.fetch-all-db-creds'));

            $response->assertRedirect()
                ->assertSessionHas('status', 'No sites with missing DB credentials found.');

            expect(Cache::has(IssuesController::DB_CREDS_MARKER_KEY))->toBeFalse();
            expect($GLOBALS['__cw_http_controllers_exec_calls'])->toBeEmpty();
        });

        it('declines to start a second background fetch while one is already in flight', function () {
            $server = Server::factory()->create(['last_ssh_ok_at' => now()]);
            Site::factory()->spinupwp()->create([
                'server_id' => $server->id,
                'is_wordpress' => true,
                'db_password' => null,
            ]);

            Cache::put(IssuesController::DB_CREDS_MARKER_KEY, now()->toIso8601String(), 600);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('issues.fetch-all-db-creds'));

            $response->assertSessionHas('status', function ($status) {
                return str_contains($status, 'already running');
            });
            expect($GLOBALS['__cw_http_controllers_exec_calls'])->toBeEmpty();
        });

        it('shows a background-fetch-in-progress banner on the issues page while the marker is set and sites are still missing', function () {
            $server = Server::factory()->create(['last_ssh_ok_at' => now()]);
            Site::factory()->spinupwp()->create([
                'server_id' => $server->id,
                'is_wordpress' => true,
                'db_password' => null,
            ]);

            Cache::put(IssuesController::DB_CREDS_MARKER_KEY, now()->toIso8601String(), 600);

            $response = $this->actingAs(User::factory()->create())
                ->get(route('issues.index'));

            $response->assertOk()
                ->assertSee('Background fetch in progress')
                ->assertSee('Fetching…');
        });

        it('clears a stale in-progress marker once every site has its DB credentials', function () {
            Cache::put(IssuesController::DB_CREDS_MARKER_KEY, now()->toIso8601String(), 600);

            $response = $this->actingAs(User::factory()->create())
                ->get(route('issues.index'));

            $response->assertOk()->assertDontSee('Background fetch in progress');
            expect(Cache::has(IssuesController::DB_CREDS_MARKER_KEY))->toBeFalse();
        });

        it('destroys an orphaned site and flashes a status message', function () {
            $server = Server::factory()->create();
            $site = Site::factory()->spinupwp()->create([
                'server_id' => $server->id,
                'spinupwp_id' => null,
                'archived_at' => null,
                'domain' => 'orphan.example.com',
            ]);

            $response = $this->actingAs(User::factory()->create())
                ->delete(route('issues.orphans.destroy', ['siteId' => $site->id]));

            $response->assertRedirect()
                ->assertSessionHas('status', 'orphan.example.com removed from monitoring.');

            expect($site->fresh()->archived_at)->not->toBeNull();
        });

        it('rejects destroying a site that is not orphaned', function () {
            $server = Server::factory()->create();
            $site = Site::factory()->spinupwp()->create([
                'server_id' => $server->id,
                // spinupwp_id is non-null via the spinupwp() state -> not orphaned.
            ]);

            $response = $this->actingAs(User::factory()->create())
                ->delete(route('issues.orphans.destroy', ['siteId' => $site->id]));

            $response->assertStatus(422);
            expect($site->fresh()->archived_at)->toBeNull();
        });

        it('rejects destroying a non-SpinupWP site with a null spinupwp_id — it is not a SpinupWP orphan', function () {
            $site = Site::factory()->gridpane()->create(['archived_at' => null]);

            $response = $this->actingAs(User::factory()->create())
                ->delete(route('issues.orphans.destroy', ['siteId' => $site->id]));

            $response->assertStatus(422);
            expect($site->fresh()->archived_at)->toBeNull();
        });

        it('does not list a GridPane site with a null spinupwp_id as an orphaned site', function () {
            Site::factory()->spinupwp()->create([
                'spinupwp_id' => null,
                'archived_at' => null,
                'domain' => 'real-orphan.example.com',
            ]);
            Site::factory()->gridpane()->create([
                'archived_at' => null,
                'domain' => 'gridpane-site.example.com',
            ]);

            $response = $this->actingAs(User::factory()->create())
                ->get(route('issues.index'));

            $response->assertOk()
                ->assertSee('real-orphan.example.com')
                ->assertDontSee('gridpane-site.example.com');
        });
    });
}
