<?php

/**
 * SAFETY NOTE — read before touching this file.
 *
 * OperationsUpdatesController::refresh() calls the raw, unqualified exec()
 * to launch `nohup php artisan clockwork:poll-system-updates ... &` as a
 * detached background process. That command SSHes into every monitored
 * server for real. This suite must never let that reach the real global
 * exec().
 *
 * Technique (same one already proven in
 * tests/Feature/Console/EnsureQueueWorkerTest.php): PHP resolves an
 * unqualified function call inside a namespaced file by first looking for a
 * function of that name in the CURRENT namespace, falling back to the
 * global one only if none exists. OperationsUpdatesController.php calls
 * exec() unqualified inside `namespace App\Http\Controllers`, so declaring
 * our own App\Http\Controllers\exec() below shadows it for every call site
 * in that class.
 */

namespace App\Http\Controllers {
    function exec(string $command, &$output = null, &$result_code = null)
    {
        $GLOBALS['__ouc_exec_calls'][] = $command;
        $result_code = 0;

        return '';
    }
}

namespace {

    use App\Http\Controllers\OperationsUpdatesController;
    use App\Models\Server;
    use App\Models\ServerUpdateSnapshot;
    use App\Models\Tag;
    use App\Models\User;
    use App\Services\Process\DetachedShell;
    use Illuminate\Support\Facades\Cache;
    use Tests\Concerns\RendersAuthenticatedPages;

    uses(RendersAuthenticatedPages::class);

    function oucResetExecFixtures(): void
    {
        $GLOBALS['__ouc_exec_calls'] = [];
    }

    describe('OperationsUpdatesController', function () {
        beforeEach(function () {
            $this->mockIssueCounterZero();
            oucResetExecFixtures();
        });

        it('redirects guests to login on every route', function () {
            $this->get(route('operations.server-updates.index'))->assertRedirect(route('login'));
            $this->post(route('operations.server-updates.queueBulk'), [])->assertRedirect(route('login'));
            $this->post(route('operations.server-updates.refresh'), [])->assertRedirect(route('login'));
        });

        it('renders the fleet dashboard with roll-up tiles reflecting server snapshots', function () {
            $server = Server::factory()->create(['name' => 'db-primary.example.com']);
            ServerUpdateSnapshot::factory()->withPendingUpdates(7, 3)->create(['server_id' => $server->id]);
            Server::factory()->ignored()->create(); // must not appear in the fleet tally

            $response = $this->actingAs(User::factory()->create())
                ->get(route('operations.server-updates.index'));

            $response->assertOk()
                ->assertSee('db-primary.example.com')
                ->assertSee('Fleet server updates');
            // Regression: this route is part of the "Operations & Tools"
            // settings tier — the persistent two-tier settings nav must render.
            $response->assertSee('Operations & Tools')->assertSee('Fleet & Branding');
        });

        it('queues updates for eligible servers and reports skip reasons in the flash message', function () {
            $eligible = Server::factory()->create([
                'name' => 'web1.example.com',
                'clockwork_jail_provisioned_at' => now(),
                'update_status' => null,
            ]);
            ServerUpdateSnapshot::factory()->withPendingUpdates(5, 1)->create(['server_id' => $eligible->id]);

            $ignored = Server::factory()->ignored()->create(['name' => 'staging1.example.com']);

            $alreadyRunning = Server::factory()->create([
                'name' => 'web2.example.com',
                'clockwork_jail_provisioned_at' => now(),
                'update_status' => Server::UPDATE_STATUS_RUNNING,
            ]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('operations.server-updates.queueBulk'), [
                    'server_ids' => [$eligible->id, $ignored->id, $alreadyRunning->id],
                ]);

            $response->assertRedirect(route('operations.server-updates.index'));
            $response->assertStatus(303);
            $response->assertSessionHas('status', function ($status) {
                return str_contains($status, 'Queued 1 server')
                    && str_contains($status, '1 ignored')
                    && str_contains($status, '1 already running/queued');
            });

            expect($eligible->fresh()->update_status)->toBe(Server::UPDATE_STATUS_QUEUED);
            expect($ignored->fresh()->update_status)->not->toBe(Server::UPDATE_STATUS_QUEUED);
            expect($alreadyRunning->fresh()->update_status)->toBe(Server::UPDATE_STATUS_RUNNING);
        });

        it('skips a staging-tagged server rather than queuing it, since the drainer never processes staging servers', function () {
            $staging = Server::factory()->create([
                'name' => 'web-test4.example.com',
                'clockwork_jail_provisioned_at' => now(),
                'update_status' => null,
            ]);
            $staging->tags()->attach(Tag::factory()->create(['slug' => 'staging']));
            ServerUpdateSnapshot::factory()->withPendingUpdates(3, 0)->create(['server_id' => $staging->id]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('operations.server-updates.queueBulk'), [
                    'server_ids' => [$staging->id],
                ]);

            $response->assertSessionHas('status', function ($status) {
                return str_contains($status, 'Queued 0 server')
                    && str_contains($status, 'staging (never drained by the processor)');
            });
            expect($staging->fresh()->update_status)->toBeNull();
        });

        it('skips a server with no SSH credentials rather than queuing it', function () {
            $noSsh = Server::factory()->create([
                'name' => 'nossh.example.com',
                'clockwork_jail_provisioned_at' => null,
                'ssh_password' => null,
                'update_status' => null,
            ]);
            ServerUpdateSnapshot::factory()->withPendingUpdates(2, 0)->create(['server_id' => $noSsh->id]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('operations.server-updates.queueBulk'), [
                    'server_ids' => [$noSsh->id],
                ]);

            $response->assertSessionHas('status', function ($status) {
                return str_contains($status, 'Queued 0 server')
                    && str_contains($status, 'missing SSH credentials');
            });
            expect($noSsh->fresh()->update_status)->toBeNull();
        });

        it('starts a background poll on refresh, stamps the in-progress cache marker, and never touches the real exec()', function () {
            $this->mock(DetachedShell::class, function ($mock) {
                $mock->shouldReceive('run')
                    ->once()
                    ->withArgs(fn (string $cmd) => str_contains($cmd, 'clockwork:poll-system-updates --all')
                        && str_contains($cmd, 'nohup'));
            });

            $response = $this->actingAs(User::factory()->create())
                ->post(route('operations.server-updates.refresh'), []);

            $response->assertRedirect(route('operations.server-updates.index'));
            $response->assertSessionHas('status', 'Fleet poll started in the background — the page will refresh as servers complete.');

            expect(Cache::has(OperationsUpdatesController::POLL_MARKER_KEY))->toBeTrue();
        });

        it('declines to start a second background poll while one is already in flight', function () {
            Cache::put(OperationsUpdatesController::POLL_MARKER_KEY, now()->toIso8601String(), 600);

            $this->mock(DetachedShell::class, function ($mock) {
                $mock->shouldNotReceive('run');
            });

            $response = $this->actingAs(User::factory()->create())
                ->post(route('operations.server-updates.refresh'), []);

            $response->assertSessionHas('status', function ($status) {
                return str_contains($status, 'already running');
            });
        });

        it('redirects the GET refresh URL straight back to the index without erroring', function () {
            $response = $this->actingAs(User::factory()->create())
                ->get('/operations/server-updates/refresh');

            $response->assertRedirect(route('operations.server-updates.index'));
        });

        it('redirects old /operations/system-updates URL with a 301 to /operations/server-updates', function () {
            $response = $this->actingAs(User::factory()->create())
                ->get('/operations/system-updates');

            $response->assertRedirect(route('operations.server-updates.index'));
            $response->assertStatus(301);
        });

        it('queues an immediate reboot by setting scheduled_reboot_at to now when reboot_at is blank', function () {
            $server = Server::factory()->create([
                'name' => 'web-reboot.example.com',
                'clockwork_jail_provisioned_at' => now(),
                'update_status' => null,
            ]);
            ServerUpdateSnapshot::factory()->withPendingUpdates(3, 1)->create(['server_id' => $server->id]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('operations.server-updates.queueBulk'), [
                    'server_ids' => [$server->id],
                    'reboot_immediate' => 1,
                ]);

            $response->assertRedirect(route('operations.server-updates.index'));
            $response->assertSessionHas('status', function ($status) {
                return str_contains($status, 'Queued 1 server')
                    && str_contains($status, 'Reboots scheduled immediately');
            });

            $fresh = $server->fresh();
            expect($fresh->update_status)->toBe(Server::UPDATE_STATUS_QUEUED);
            expect($fresh->scheduled_reboot_at)->not->toBeNull();
            expect($fresh->scheduled_reboot_at->isPast())->toBeTrue();
        });

        it('allows queueing a server even if snapshot reports 0 updates when operator explicitly selects it', function () {
            $server = Server::factory()->create([
                'name' => 'uptodate.example.com',
                'clockwork_jail_provisioned_at' => now(),
                'update_status' => null,
            ]);
            ServerUpdateSnapshot::factory()->create([
                'server_id' => $server->id,
                'total_updates' => 0,
                'security_updates' => 0,
                'reboot_required' => false,
                'poll_status' => ServerUpdateSnapshot::STATUS_OK,
            ]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('operations.server-updates.queueBulk'), [
                    'server_ids' => [$server->id],
                ]);

            $response->assertSessionHas('status', function ($status) {
                return str_contains($status, 'Queued 1 server');
            });

            expect($server->fresh()->update_status)->toBe(Server::UPDATE_STATUS_QUEUED);
        });

        it('renders the immediate install and reboot button and confirmation prompt in the view', function () {
            $server = Server::factory()->create([
                'name' => 'web-view.example.com',
                'clockwork_jail_provisioned_at' => now(),
            ]);
            ServerUpdateSnapshot::factory()->withPendingUpdates(2, 0)->create(['server_id' => $server->id]);

            $response = $this->actingAs(User::factory()->create())
                ->get(route('operations.server-updates.index'));

            $response->assertOk()
                ->assertSee('Install updates & reboot immediately', false)
                ->assertSee('are you sure you want to run updates and reboot on all selected servers?', false)
                ->assertSee('Update & reboot', false);
        });

        it('removes the Update & reboot button for servers that are up to date', function () {
            $upToDateServer = Server::factory()->create([
                'name' => 'uptodate-box.example.com',
                'clockwork_jail_provisioned_at' => now(),
            ]);
            ServerUpdateSnapshot::factory()->create([
                'server_id' => $upToDateServer->id,
                'total_updates' => 0,
                'security_updates' => 0,
                'reboot_required' => false,
                'poll_status' => ServerUpdateSnapshot::STATUS_OK,
            ]);

            $response = $this->actingAs(User::factory()->create())
                ->get(route('operations.server-updates.index'));

            $response->assertOk();
            $content = $response->getContent();

            // Row for up-to-date server must have "up to date" badge, but NOT the single-update button
            expect($content)->toContain('uptodate-box.example.com');
            expect($content)->toContain('up to date');
            expect($content)->not->toContain("form=\"single-update-{$upToDateServer->id}\"");
            expect($content)->toContain('title="Server is up to date"');
        });
    });

}
