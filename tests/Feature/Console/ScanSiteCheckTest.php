<?php

namespace Tests\Feature\Console;

use App\Models\AppSetting;
use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Services\Security\SecurityScanResult;
use App\Services\Security\SucuriSiteCheckClient;

/*
|--------------------------------------------------------------------------
| Phase 6 console coverage for clockwork:scan-sitecheck
|--------------------------------------------------------------------------
|
| SucuriSiteCheckClient is mocked wholesale (same convention already used
| in SecurityScansControllerTest for this exact client) — its own request
| parsing is covered elsewhere; what's under test here is the command's
| targeting (care_plan gate, --site override, unavailable gate/toggle),
| the 3-strikes sucuri_unavailable_at bookkeeping, and the exit-code rule.
*/

function scResult(Site $site, string $status, ?string $error = null): SecurityScanResult
{
    return new SecurityScanResult(
        site: $site,
        scanType: SiteSecurityScan::TYPE_SITECHECK,
        status: $status,
        summary: $status === SiteSecurityScan::STATUS_CLEAN ? 'Sucuri SiteCheck — clean.' : 'Sucuri SiteCheck — issue.',
        error: $error,
    );
}

describe('clockwork:scan-sitecheck', function () {
    it('scans every care-plan site by default and skips non-care-plan sites', function () {
        $carePlan = Site::factory()->carePlan()->create(['domain' => 'care-plan.example.test']);
        Site::factory()->create(['domain' => 'no-care-plan.example.test', 'care_plan_enabled' => false]);

        $this->mock(SucuriSiteCheckClient::class, function ($mock) use ($carePlan) {
            $mock->shouldReceive('scanSite')->once()
                ->with(\Mockery::on(fn (Site $s) => $s->is($carePlan)))
                ->andReturn(scResult($carePlan, SiteSecurityScan::STATUS_CLEAN));
        });

        $this->artisan('clockwork:scan-sitecheck')->assertSuccessful();

        expect(SiteSecurityScan::query()->count())->toBe(1)
            ->and(SiteSecurityScan::query()->first()->site_id)->toBe($carePlan->id)
            ->and(AppSetting::query()->where('key', 'security_scans.sitecheck_last_run_at')->value('value'))->not->toBeNull();
    });

    it('--site bypasses the care-plan gate entirely', function () {
        $noCarePlan = Site::factory()->create(['domain' => 'bypass.example.test', 'care_plan_enabled' => false]);

        $this->mock(SucuriSiteCheckClient::class, function ($mock) use ($noCarePlan) {
            $mock->shouldReceive('scanSite')->once()->andReturn(scResult($noCarePlan, SiteSecurityScan::STATUS_CLEAN));
        });

        $this->artisan('clockwork:scan-sitecheck', ['--site' => (string) $noCarePlan->id])->assertSuccessful();

        expect(SiteSecurityScan::query()->count())->toBe(1);
    });

    it('excludes sites already marked sucuri-unavailable from the default run', function () {
        Site::factory()->carePlan()->create([
            'domain' => 'unavailable.example.test',
            'sucuri_unavailable_at' => now()->subDay(),
        ]);
        $ok = Site::factory()->carePlan()->create(['domain' => 'available.example.test']);

        $this->mock(SucuriSiteCheckClient::class, function ($mock) use ($ok) {
            $mock->shouldReceive('scanSite')->once()->andReturn(scResult($ok, SiteSecurityScan::STATUS_CLEAN));
        });

        $this->artisan('clockwork:scan-sitecheck')->assertSuccessful();

        expect(SiteSecurityScan::query()->count())->toBe(1);
    });

    it('--include-unavailable re-includes previously marked-unavailable sites', function () {
        $unavailable = Site::factory()->carePlan()->create([
            'domain' => 'retry.example.test',
            'sucuri_unavailable_at' => now()->subDay(),
            'sucuri_unavailable_reason' => 'old failure',
        ]);

        $this->mock(SucuriSiteCheckClient::class, function ($mock) use ($unavailable) {
            $mock->shouldReceive('scanSite')->once()->andReturn(scResult($unavailable, SiteSecurityScan::STATUS_CLEAN));
        });

        $this->artisan('clockwork:scan-sitecheck', ['--include-unavailable' => true])->assertSuccessful();

        expect(SiteSecurityScan::query()->count())->toBe(1);

        // A clean result also auto-clears the flag.
        expect($unavailable->refresh()->sucuri_unavailable_at)->toBeNull();
    });

    it('marks a site sucuri-unavailable after the 3rd consecutive SiteCheck failure', function () {
        $site = Site::factory()->carePlan()->create(['domain' => 'flaky.example.test']);

        // Two prior failures already on record.
        SiteSecurityScan::factory()->count(2)->create([
            'site_id' => $site->id,
            'scan_type' => SiteSecurityScan::TYPE_SITECHECK,
            'status' => SiteSecurityScan::STATUS_FAILED,
        ]);

        $this->mock(SucuriSiteCheckClient::class, function ($mock) use ($site) {
            $mock->shouldReceive('scanSite')->once()
                ->andReturn(scResult($site, SiteSecurityScan::STATUS_FAILED, 'timeout'));
        });

        $this->artisan('clockwork:scan-sitecheck')->assertFailed();

        $site->refresh();
        expect($site->sucuri_unavailable_at)->not->toBeNull()
            ->and($site->sucuri_unavailable_reason)->toBe('timeout');
    });

    it('does NOT mark unavailable on only the 1st or 2nd failure', function () {
        $site = Site::factory()->carePlan()->create(['domain' => 'onefail.example.test']);

        $this->mock(SucuriSiteCheckClient::class, function ($mock) use ($site) {
            $mock->shouldReceive('scanSite')->once()->andReturn(scResult($site, SiteSecurityScan::STATUS_FAILED, 'timeout'));
        });

        $this->artisan('clockwork:scan-sitecheck')->assertFailed();

        expect($site->refresh()->sucuri_unavailable_at)->toBeNull();
    });

    it('returns FAILURE whenever at least one site failed (stricter than check-blacklists)', function () {
        $clean = Site::factory()->carePlan()->create(['domain' => 'clean2.example.test']);
        $failed = Site::factory()->carePlan()->create(['domain' => 'failed2.example.test']);

        $this->mock(SucuriSiteCheckClient::class, function ($mock) use ($clean, $failed) {
            $mock->shouldReceive('scanSite')->once()->with(\Mockery::on(fn (Site $s) => $s->is($clean)))
                ->andReturn(scResult($clean, SiteSecurityScan::STATUS_CLEAN));
            $mock->shouldReceive('scanSite')->once()->with(\Mockery::on(fn (Site $s) => $s->is($failed)))
                ->andReturn(scResult($failed, SiteSecurityScan::STATUS_FAILED, 'boom'));
        });

        $this->artisan('clockwork:scan-sitecheck')->assertFailed();
    });

    it('exits SUCCESS with a warning and no writes when nothing matches', function () {
        $this->mock(SucuriSiteCheckClient::class, function ($mock) {
            $mock->shouldNotReceive('scanSite');
        });

        $this->artisan('clockwork:scan-sitecheck')
            ->expectsOutputToContain('No sites match.')
            ->assertSuccessful();

        expect(SiteSecurityScan::query()->count())->toBe(0);
    });
});
