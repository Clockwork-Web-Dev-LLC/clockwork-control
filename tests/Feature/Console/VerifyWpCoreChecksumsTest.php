<?php

use App\Models\Server;
use App\Models\Site;
use App\Services\Security\SecurityScanRecorder;
use App\Services\Security\SecurityScanResult;
use App\Services\Security\WpCoreChecksumVerifier;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| clockwork:verify-wp-core-checksums
|--------------------------------------------------------------------------
|
| WpCoreChecksumVerifier's own dispatch/parsing logic (SSH vs Pressable
| transport, KNOWN_SAFE_UNEXPECTED_PREFIXES filtering, etc.) is already
| covered in tests/Feature/Security/SecurityScanAndChecksumTest.php, and
| provider-eligibility gating in tests/Feature/SiteProviderGatingTest.php.
| This file is scoped to what those don't touch: the CLI entry point —
| site targeting (--site override vs the default care_plan_enabled gate),
| that it calls verifier->verify() + recorder->record() for each matched
| site, its clean/issues/failed tallying, and its exit-code wiring.
| Both collaborators are mocked so no real SSH/Pressable transport ever
| runs here.
*/

function cleanChecksumResult(Site $site): SecurityScanResult
{
    return new SecurityScanResult(
        site: $site,
        scanType: 'core_checksums',
        status: 'clean',
        summary: 'Clean.',
    );
}

function issuesChecksumResult(Site $site): SecurityScanResult
{
    return new SecurityScanResult(
        site: $site,
        scanType: 'core_checksums',
        status: 'issues_found',
        modifiedFilesCount: 1,
        summary: '1 modified file.',
    );
}

function failedChecksumResult(Site $site): SecurityScanResult
{
    return new SecurityScanResult(
        site: $site,
        scanType: 'core_checksums',
        status: 'failed',
        error: 'wp-cli exited 255',
    );
}

describe('clockwork:verify-wp-core-checksums — site targeting', function () {
    it('warns and exits successfully when no site matches (empty fleet)', function () {
        $this->mock(WpCoreChecksumVerifier::class)->shouldNotReceive('verify');
        $this->mock(SecurityScanRecorder::class)->shouldNotReceive('record');

        $this->artisan('clockwork:verify-wp-core-checksums')
            ->expectsOutputToContain('No sites match.')
            ->assertSuccessful();
    });

    it('defaults to only care_plan_enabled WordPress sites when --site is omitted', function () {
        $server = Server::factory()->create();
        $paid = Site::factory()->create([
            'server_id' => $server->id,
            'is_wordpress' => true,
            'care_plan_enabled' => true,
            'domain' => 'paid.example.com',
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'is_wordpress' => true,
            'care_plan_enabled' => false,
            'domain' => 'unpaid.example.com',
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'is_wordpress' => false,
            'care_plan_enabled' => true,
            'domain' => 'not-wp.example.com',
        ]);

        $this->mock(WpCoreChecksumVerifier::class, function ($mock) use ($paid) {
            $mock->shouldReceive('hasTransport')->andReturn(true);
            $mock->shouldReceive('verify')
                ->once()
                ->with(Mockery::on(fn (Site $s) => $s->is($paid)))
                ->andReturn(cleanChecksumResult($paid));
        });

        $this->mock(SecurityScanRecorder::class)->shouldReceive('record')->once();

        $this->artisan('clockwork:verify-wp-core-checksums')
            ->expectsOutputToContain('Done. clean=1, issues=0, failed=0, skipped=0')
            ->assertSuccessful();
    });

    it('--site overrides the care_plan_enabled gate, matching by domain even when unpaid', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'is_wordpress' => true,
            'care_plan_enabled' => false,
            'domain' => 'diagnose-me.example.com',
        ]);

        $this->mock(WpCoreChecksumVerifier::class, function ($mock) use ($site) {
            $mock->shouldReceive('hasTransport')->andReturn(true);
            $mock->shouldReceive('verify')
                ->once()
                ->with(Mockery::on(fn (Site $s) => $s->is($site)))
                ->andReturn(cleanChecksumResult($site));
        });

        $this->mock(SecurityScanRecorder::class)->shouldReceive('record')->once();

        $this->artisan('clockwork:verify-wp-core-checksums', ['--site' => 'diagnose-me.example.com'])
            ->assertSuccessful();
    });
});

describe('clockwork:verify-wp-core-checksums — tallying and exit code', function () {
    it('tallies clean/issues/failed correctly, records every result, and fails when any site failed', function () {
        $server = Server::factory()->create();
        $clean = Site::factory()->create(['server_id' => $server->id, 'is_wordpress' => true, 'care_plan_enabled' => true, 'domain' => 'clean.example.com']);
        $issues = Site::factory()->create(['server_id' => $server->id, 'is_wordpress' => true, 'care_plan_enabled' => true, 'domain' => 'issues.example.com']);
        $failed = Site::factory()->create(['server_id' => $server->id, 'is_wordpress' => true, 'care_plan_enabled' => true, 'domain' => 'failed.example.com']);

        $this->mock(WpCoreChecksumVerifier::class, function ($mock) use ($clean, $issues, $failed) {
            $mock->shouldReceive('hasTransport')->andReturn(true);
            $mock->shouldReceive('verify')->with(Mockery::on(fn (Site $s) => $s->is($clean)))->andReturn(cleanChecksumResult($clean));
            $mock->shouldReceive('verify')->with(Mockery::on(fn (Site $s) => $s->is($issues)))->andReturn(issuesChecksumResult($issues));
            $mock->shouldReceive('verify')->with(Mockery::on(fn (Site $s) => $s->is($failed)))->andReturn(failedChecksumResult($failed));
        });

        $this->mock(SecurityScanRecorder::class)->shouldReceive('record')->times(3);

        $this->artisan('clockwork:verify-wp-core-checksums')
            ->expectsOutputToContain('Done. clean=1, issues=1, failed=1, skipped=0')
            ->assertFailed();
    });

    it('stamps the checksums_last_run_at setting after a run', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'is_wordpress' => true, 'care_plan_enabled' => true]);

        $this->mock(WpCoreChecksumVerifier::class, function ($mock) use ($site) {
            $mock->shouldReceive('hasTransport')->andReturn(true);
            $mock->shouldReceive('verify')->once()->andReturn(cleanChecksumResult($site));
        });
        $this->mock(SecurityScanRecorder::class)->shouldReceive('record')->once();

        $this->artisan('clockwork:verify-wp-core-checksums')->assertSuccessful();

        expect(app(Settings::class)->get('security_scans.checksums_last_run_at'))->not->toBeNull();
    });
});

/**
 * A standalone site on a host with no SSH, no Pressable command API and no
 * host command runner: core checksums can never run on it.
 */
function standaloneChecksumSite(array $overrides = []): Site
{
    return Site::factory()->custom()->carePlan()->withCompanionInstalled()->create(array_merge([
        'is_wordpress' => true,
        'domain' => 'standalone.example.com',
    ], $overrides));
}

describe('clockwork:verify-wp-core-checksums — sites with no checksum transport', function () {
    // Such a site used to write a failed 'no_command_runner' row every night
    // and turn the whole run red.
    it('skips it, records nothing for it, and still succeeds', function () {
        Log::spy();
        $standalone = standaloneChecksumSite();
        $server = Server::factory()->create();
        $hosted = Site::factory()->create(['server_id' => $server->id, 'is_wordpress' => true, 'care_plan_enabled' => true, 'domain' => 'hosted.example.com']);

        // Partial mock: the real hasTransport() decides, verify() is stubbed.
        $this->partialMock(WpCoreChecksumVerifier::class, function ($mock) use ($hosted) {
            $mock->shouldReceive('verify')
                ->once()
                ->with(Mockery::on(fn (Site $s) => $s->is($hosted)))
                ->andReturn(cleanChecksumResult($hosted));
        });
        $this->mock(SecurityScanRecorder::class)
            ->shouldReceive('record')
            ->once()
            ->with(Mockery::on(fn (SecurityScanResult $r) => $r->site->is($hosted)));

        $this->artisan('clockwork:verify-wp-core-checksums')
            ->expectsOutputToContain('Done. clean=1, issues=0, failed=0, skipped=1')
            ->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->with('verify-wp-core-checksums: skipped sites with no checksum transport', Mockery::on(
                fn (array $context) => $context['count'] === 1 && $context['site_ids'] === [$standalone->id]
            ))
            ->once();
        expect(app(Settings::class)->get('security_scans.checksums_last_run_at'))->not->toBeNull();
    });

    it('says why when the site was asked for by --site', function () {
        standaloneChecksumSite(['care_plan_enabled' => false]);

        $this->partialMock(WpCoreChecksumVerifier::class)->shouldNotReceive('verify');
        $this->mock(SecurityScanRecorder::class)->shouldNotReceive('record');

        $this->artisan('clockwork:verify-wp-core-checksums', ['--site' => 'standalone.example.com'])
            ->expectsOutputToContain('standalone.example.com: skipped, no SSH, Pressable or host command runner to run wp-cli')
            ->expectsOutputToContain('Done. clean=0, issues=0, failed=0, skipped=1')
            ->assertSuccessful();
    });
});

describe('WpCoreChecksumVerifier::hasTransport', function () {
    it('is true for a site on a server we can SSH into', function () {
        $site = Site::factory()->spinupwp()->create();

        expect(app(WpCoreChecksumVerifier::class)->hasTransport($site))->toBeTrue();
    });

    it('is true for a Pressable site, through its command API', function () {
        $site = Site::factory()->pressable()->create();

        expect(app(WpCoreChecksumVerifier::class)->hasTransport($site))->toBeTrue();
    });

    it('is false for a standalone site on a host with no shell access', function () {
        $site = Site::factory()->custom()->create();

        expect(app(WpCoreChecksumVerifier::class)->hasTransport($site))->toBeFalse();
    });
});
