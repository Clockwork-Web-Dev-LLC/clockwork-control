<?php

use App\Models\ActionLog;
use App\Models\Server;
use App\Models\Site;
use App\Services\Companion\CompanionInstaller;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| CompanionCanaryDeploy — Phase 6 console coverage
|--------------------------------------------------------------------------
|
| App\Services\Companion\CompanionInstaller (the SSH installer) is the
| concrete class SpinupWpHostingProvider type-hints as `companionInstaller`
| (see modules/SpinupWp/src/SpinupWpHostingProvider.php's `use ... as
| SshCompanionInstaller` alias) — mocking it directly is the proven
| pattern from tests/Feature/Controllers/SitesControllerTest.php.
|
| ClockworkCompanionClient is `new`'d directly inside the command rather
| than container-injected, so its /detect and /malware-scan calls are
| faked via Http::fake() against the real signed-request URLs instead
| (same pattern as SitesControllerTest).
|
| Sites are left with companion_installed=false throughout — the mocked
| installer never flips that DB flag — which keeps ActionLogger::record's
| "mirror to Companion" push (pushToCompanion) from firing and needing its
| own Http::fake() entry; that push is out of scope for this command.
*/

function canarySite(array $overrides = []): Site
{
    $server = Server::factory()->create();

    return Site::factory()->spinupwp()->create(array_merge([
        'server_id' => $server->id,
        'companion_installed' => false,
        // companion_installed stays false throughout (the mocked installer
        // never flips it), so ActionLogger's Companion-mirror push never
        // fires despite a secret being present — but ClockworkCompanionClient
        // itself requires a non-null secret to sign /detect and /malware-scan.
        'companion_secret' => 'test-secret-'.uniqid(),
    ], $overrides));
}

describe('clockwork:companion-canary-deploy', function () {
    it('refuses to run when the canary set is empty', function () {
        $this->artisan('clockwork:companion-canary-deploy')->assertFailed();
    });

    it('installs then verifies every canary site, aggregating ok/fail counts across the whole set and gating the fleet-deploy flag on a clean sweep', function () {
        $ok = canarySite(['domain' => 'canary-ok.test', 'companion_capabilities' => ['malware-scan']]);
        $fail = canarySite(['domain' => 'canary-fail.test', 'companion_capabilities' => []]);

        app(Settings::class)->put('companion.canary_site_ids', [$ok->id, $fail->id]);

        // Installer is invoked once per site — different outcome per site so
        // the aggregation below can't be a pass-through of a single result.
        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldReceive('installOrUpdate')
                ->twice()
                ->andReturnUsing(fn (Site $site) => $site->domain === 'canary-ok.test'
                    ? ['result' => CompanionInstaller::RESULT_INSTALLED, 'message' => 'Installed Companion.', 'version' => '1.30.4']
                    : ['result' => CompanionInstaller::RESULT_FAILED, 'message' => 'SSH connect failed.']);
        });

        Http::fake([
            // $ok already carries the capability, so its only network call
            // in the verify pass is the malware scan.
            "https://{$ok->domain}/wp-json/clockwork/v1/malware-scan" => Http::response(
                ['ok' => true, 'scanned_files_count' => 100, 'findings' => []],
                200,
                ['Content-Type' => 'application/json'],
            ),
            // $fail lacks the capability, triggers a live /detect nudge —
            // which still doesn't report it, so verify never reaches malware-scan.
            "https://{$fail->domain}/wp-json/clockwork/v1/detect" => Http::response(
                ['installed' => true, 'version' => '1.30.4', 'capabilities' => []],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:companion-canary-deploy')->assertFailed();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'malware-scan') && str_contains($request->url(), $ok->domain));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'detect') && str_contains($request->url(), $fail->domain));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'malware-scan') && str_contains($request->url(), $fail->domain));

        // recordCompanionInstall wrote an action_log row for each site.
        expect(ActionLog::query()->where('site_id', $ok->id)->where('action_type', ActionLog::TYPE_COMPANION_INSTALL)->where('ok', true)->exists())->toBeTrue();
        expect(ActionLog::query()->where('site_id', $fail->id)->where('action_type', ActionLog::TYPE_COMPANION_INSTALL)->where('ok', false)->exists())->toBeTrue();

        // One ok + one fail in the verify pass means the fleet gate must NOT open.
        $settings = app(Settings::class);
        expect($settings->get('companion.canary_verified_at'))->toBeNull();
    });

    it('opens the fleet-deploy gate (sets canary_verified_at + canary_verified_capability) when every canary site verifies cleanly', function () {
        $a = canarySite(['domain' => 'canary-clean-a.test', 'companion_capabilities' => ['malware-scan']]);
        $b = canarySite(['domain' => 'canary-clean-b.test', 'companion_capabilities' => ['malware-scan']]);

        app(Settings::class)->put('companion.canary_site_ids', [$a->id, $b->id]);

        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldReceive('installOrUpdate')
                ->twice()
                ->andReturn(['result' => CompanionInstaller::RESULT_ALREADY_CURRENT, 'message' => 'Already current.', 'version' => '1.30.4']);
        });

        Http::fake([
            "https://{$a->domain}/wp-json/clockwork/v1/malware-scan" => Http::response(['ok' => true, 'scanned_files_count' => 10, 'findings' => []], 200, ['Content-Type' => 'application/json']),
            "https://{$b->domain}/wp-json/clockwork/v1/malware-scan" => Http::response(['ok' => true, 'scanned_files_count' => 10, 'findings' => []], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:companion-canary-deploy')->assertSuccessful();

        $settings = app(Settings::class);
        expect($settings->get('companion.canary_verified_at'))->not->toBeNull();
        expect($settings->get('companion.canary_verified_capability'))->toBe('malware-scan');
    });

    it('--skip-install runs only the verify pass and never touches the installer', function () {
        $site = canarySite(['domain' => 'canary-skip.test', 'companion_capabilities' => ['malware-scan']]);
        app(Settings::class)->put('companion.canary_site_ids', [$site->id]);

        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldNotReceive('installOrUpdate');
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/malware-scan" => Http::response(['ok' => true, 'scanned_files_count' => 5, 'findings' => []], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:companion-canary-deploy', ['--skip-install' => true])->assertSuccessful();

        expect(ActionLog::query()->where('site_id', $site->id)->exists())->toBeFalse();
    });
});
