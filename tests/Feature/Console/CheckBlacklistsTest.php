<?php

namespace Tests\Feature\Console;

use App\Models\AppSetting;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Services\Security\BlacklistChecker;
use App\Services\Security\SecurityScanResult;

/*
|--------------------------------------------------------------------------
| Phase 6 console coverage for clockwork:check-blacklists
|--------------------------------------------------------------------------
|
| BlacklistChecker itself makes a real DNS lookup (gethostbyname against
| dbl.spamhaus.org) that Http::fake() cannot intercept, plus two optional
| HTTP calls gated behind unset API keys. Rather than let any of that
| reach the network, every test here mocks BlacklistChecker::check()
| wholesale and asserts on the command's own orchestration: site
| targeting/filtering, SecurityScanRecorder's real DB writes, the
| sucuri/settings bookkeeping, and the exit-code formula.
*/

function cbResult(Site $site, string $status, bool $blacklistHit = false): SecurityScanResult
{
    return new SecurityScanResult(
        site: $site,
        scanType: SiteSecurityScan::TYPE_BLACKLIST,
        status: $status,
        blacklistHit: $blacklistHit,
        summary: $status === SiteSecurityScan::STATUS_CLEAN ? 'Clean against spamhaus_dbl.' : 'Blacklisted by spamhaus_dbl.',
        error: $status === SiteSecurityScan::STATUS_FAILED ? 'every source errored' : null,
    );
}

describe('clockwork:check-blacklists', function () {
    it('runs cleanly end-to-end: checks every monitored site and records a scan row per site', function () {
        $siteA = Site::factory()->create(['domain' => 'clean-site.example.test']);
        $siteB = Site::factory()->create(['domain' => 'flagged-site.example.test']);

        $this->mock(BlacklistChecker::class, function ($mock) use ($siteA, $siteB) {
            $mock->shouldReceive('check')
                ->once()
                ->with(\Mockery::on(fn (Site $s) => $s->is($siteA)))
                ->andReturn(cbResult($siteA, SiteSecurityScan::STATUS_CLEAN));
            $mock->shouldReceive('check')
                ->once()
                ->with(\Mockery::on(fn (Site $s) => $s->is($siteB)))
                ->andReturn(cbResult($siteB, SiteSecurityScan::STATUS_ISSUES_FOUND, blacklistHit: true));
        });

        $this->artisan('clockwork:check-blacklists')->assertSuccessful();

        expect(SiteSecurityScan::query()->where('site_id', $siteA->id)->where('scan_type', SiteSecurityScan::TYPE_BLACKLIST)->first()->status)
            ->toBe(SiteSecurityScan::STATUS_CLEAN)
            ->and(SiteSecurityScan::query()->where('site_id', $siteB->id)->where('scan_type', SiteSecurityScan::TYPE_BLACKLIST)->first())
            ->toMatchArray(['status' => SiteSecurityScan::STATUS_ISSUES_FOUND, 'blacklist_hit' => true]);

        expect(AppSetting::query()->where('key', 'security_scans.blacklist_last_run_at')->value('value'))->not->toBeNull();
    });

    it('--site limits the run to a single site by numeric ID', function () {
        $target = Site::factory()->create(['domain' => 'target.example.test']);
        Site::factory()->create(['domain' => 'other.example.test']);

        $this->mock(BlacklistChecker::class, function ($mock) use ($target) {
            $mock->shouldReceive('check')->once()->andReturn(cbResult($target, SiteSecurityScan::STATUS_CLEAN));
        });

        $this->artisan('clockwork:check-blacklists', ['--site' => (string) $target->id])->assertSuccessful();

        expect(SiteSecurityScan::query()->count())->toBe(1)
            ->and(SiteSecurityScan::query()->first()->site_id)->toBe($target->id);
    });

    it('--site limits the run to a single site by domain', function () {
        $target = Site::factory()->create(['domain' => 'by-domain.example.test']);
        Site::factory()->create(['domain' => 'other2.example.test']);

        $this->mock(BlacklistChecker::class, function ($mock) use ($target) {
            $mock->shouldReceive('check')->once()->andReturn(cbResult($target, SiteSecurityScan::STATUS_CLEAN));
        });

        $this->artisan('clockwork:check-blacklists', ['--site' => 'by-domain.example.test'])->assertSuccessful();

        expect(SiteSecurityScan::query()->count())->toBe(1);
    });

    it('excludes sites on ignored/staging servers from the default run', function () {
        $ignoredServer = Server::factory()->ignored()->create();
        $monitored = Site::factory()->create(['domain' => 'monitored.example.test']);
        Site::factory()->create(['domain' => 'ignored-host.example.test', 'server_id' => $ignoredServer->id]);

        $this->mock(BlacklistChecker::class, function ($mock) use ($monitored) {
            $mock->shouldReceive('check')->once()->andReturn(cbResult($monitored, SiteSecurityScan::STATUS_CLEAN));
        });

        $this->artisan('clockwork:check-blacklists')->assertSuccessful();

        expect(SiteSecurityScan::query()->count())->toBe(1);
    });

    it('exits SUCCESS with no DB writes and a warning when nothing matches', function () {
        $this->mock(BlacklistChecker::class, function ($mock) {
            $mock->shouldNotReceive('check');
        });

        $this->artisan('clockwork:check-blacklists', ['--site' => '999999'])
            ->expectsOutputToContain('No sites match.')
            ->assertSuccessful();

        expect(SiteSecurityScan::query()->count())->toBe(0);
    });

    it('returns FAILURE only when every site failed (no clean, no issues)', function () {
        $siteA = Site::factory()->create(['domain' => 'failA.example.test']);
        $siteB = Site::factory()->create(['domain' => 'failB.example.test']);

        $this->mock(BlacklistChecker::class, function ($mock) use ($siteA, $siteB) {
            $mock->shouldReceive('check')->once()->with(\Mockery::on(fn (Site $s) => $s->is($siteA)))
                ->andReturn(cbResult($siteA, SiteSecurityScan::STATUS_FAILED));
            $mock->shouldReceive('check')->once()->with(\Mockery::on(fn (Site $s) => $s->is($siteB)))
                ->andReturn(cbResult($siteB, SiteSecurityScan::STATUS_FAILED));
        });

        $this->artisan('clockwork:check-blacklists')->assertFailed();

        expect(SiteSecurityScan::query()->where('status', SiteSecurityScan::STATUS_FAILED)->count())->toBe(2);
    });

    it('stays SUCCESS when some sites fail but at least one is clean/has issues', function () {
        $clean = Site::factory()->create(['domain' => 'ok.example.test']);
        $failed = Site::factory()->create(['domain' => 'bad.example.test']);

        $this->mock(BlacklistChecker::class, function ($mock) use ($clean, $failed) {
            $mock->shouldReceive('check')->once()->with(\Mockery::on(fn (Site $s) => $s->is($clean)))
                ->andReturn(cbResult($clean, SiteSecurityScan::STATUS_CLEAN));
            $mock->shouldReceive('check')->once()->with(\Mockery::on(fn (Site $s) => $s->is($failed)))
                ->andReturn(cbResult($failed, SiteSecurityScan::STATUS_FAILED));
        });

        $this->artisan('clockwork:check-blacklists')->assertSuccessful();
    });
});
