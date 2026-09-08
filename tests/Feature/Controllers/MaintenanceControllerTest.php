<?php

/**
 * SAFETY NOTE — read before touching this file.
 *
 * MaintenanceController::downloadBackup() calls the raw, unqualified
 * passthru() to pipe a REAL `mysqldump ... | gzip` straight to the response
 * body. This suite must never let that reach the real global passthru()
 * (no mysqldump binary is guaranteed in CI, and even if there were, we don't
 * want a test suite shelling out to dump a real database).
 *
 * Technique (same one already proven in
 * tests/Feature/Console/EnsureQueueWorkerTest.php): PHP resolves an
 * unqualified function call inside a namespaced file by first looking for a
 * function of that name in the CURRENT namespace, falling back to the
 * global one only if none exists. MaintenanceController.php calls
 * passthru() unqualified inside `namespace App\Http\Controllers`, so
 * declaring our own App\Http\Controllers\passthru() below shadows it for
 * every call site in that class.
 *
 * index() separately runs a MySQL-only `information_schema.tables` query
 * via DB::connection()->selectOne(...) (same class of problem
 * RendersAuthenticatedPages solves for IssueCounter::total(), just not
 * wired into that trait) — sqlite (the test DB) has no information_schema,
 * so that call is mocked per-test with DB::partialMock() rather than left
 * to hit the real facade.
 */

namespace App\Http\Controllers {
    function passthru(string $command, &$result_code = null)
    {
        $GLOBALS['__maint_passthru_calls'][] = $command;
        echo $GLOBALS['__maint_passthru_output'] ?? '';
        $result_code = 0;
    }
}

namespace {

    use App\Models\User;
    use App\Support\Settings;
    use Illuminate\Support\Facades\DB;
    use Tests\Concerns\RendersAuthenticatedPages;

    uses(RendersAuthenticatedPages::class);

    function maintResetPassthruFixtures(): void
    {
        $GLOBALS['__maint_passthru_calls'] = [];
        $GLOBALS['__maint_passthru_output'] = '';
    }

    describe('MaintenanceController', function () {
        beforeEach(function () {
            $this->mockIssueCounterZero();
            maintResetPassthruFixtures();
        });

        it('redirects guests to login for both maintenance routes', function () {
            $this->get(route('settings.maintenance.index'))->assertRedirect(route('login'));
            $this->get(route('settings.maintenance.backup'))->assertRedirect(route('login'));
        });

        it('renders the maintenance index with DB info and an approximate size', function () {
            DB::partialMock()
                ->shouldReceive('connection')
                ->andReturn(tap(Mockery::mock(), function ($conn) {
                    $conn->shouldReceive('selectOne')->once()->andReturn((object) ['total' => 52428800]); // 50MB
                }));

            $response = $this->actingAs(User::factory()->create())
                ->get(route('settings.maintenance.index'));

            $response->assertOk()
                ->assertSee('System &amp; Workspace', false)
                ->assertSee('Database Maintenance')
                ->assertSee('Database backup')
                ->assertSee('50.0 MB')
                ->assertSee('Anonymous usage telemetry')
                ->assertSee('Managed Sites')
                ->assertSee('Connected Servers')
                ->assertSee('Active Modules')
                ->assertSee('Zero-Knowledge Guarantee');
        });

        it('displays disabled telemetry banner and payload inspector when telemetry is disabled', function () {
            app(Settings::class)->put('telemetry.enabled', false);

            DB::partialMock()
                ->shouldReceive('connection')
                ->andReturn(tap(Mockery::mock(), function ($conn) {
                    $conn->shouldReceive('selectOne')->once()->andReturn((object) ['total' => 1048576]);
                }));

            $response = $this->actingAs(User::factory()->create())
                ->get(route('settings.maintenance.index'));

            $response->assertOk()
                ->assertSee('Telemetry is currently off')
                ->assertSee('Re-enable Telemetry')
                ->assertSee('sites_count')
                ->assertSee('servers_count')
                ->assertSee('modules_enabled')
                ->assertSee('module_site_counts')
                ->assertSee('module_server_counts')
                ->assertDontSee('hosting_provider_mix');
        });

        it('streams a gzipped mysqldump download without ever invoking the real passthru/mysqldump', function () {
            $GLOBALS['__maint_passthru_output'] = "\x1f\x8b-fake-gzip-bytes";

            $response = $this->actingAs(User::factory()->create())
                ->get(route('settings.maintenance.backup'));

            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/gzip');
            expect($response->headers->get('Cache-Control'))->toContain('no-store');
            expect($response->headers->get('Content-Disposition'))
                ->toContain('attachment')
                ->toContain('.sql.gz');

            // Actually pull the streamed body through so we can prove the
            // controller's own callback ran (and ran through our override,
            // not a real mysqldump process).
            expect($response->streamedContent())->toBe("\x1f\x8b-fake-gzip-bytes");

            expect($GLOBALS['__maint_passthru_calls'])->toHaveCount(1);
            expect($GLOBALS['__maint_passthru_calls'][0])
                ->toContain('mysqldump')
                ->toContain('--single-transaction')
                ->toContain('gzip');
        });
    });

}
