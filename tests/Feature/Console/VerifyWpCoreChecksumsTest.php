<?php

use App\Models\Server;
use App\Models\Site;
use App\Services\Security\SecurityScanRecorder;
use App\Services\Security\SecurityScanResult;
use App\Services\Security\WpCoreChecksumVerifier;
use App\Support\Settings;

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

        $this->mock(WpCoreChecksumVerifier::class)
            ->shouldReceive('verify')
            ->once()
            ->with(Mockery::on(fn (Site $s) => $s->is($paid)))
            ->andReturn(cleanChecksumResult($paid));

        $this->mock(SecurityScanRecorder::class)->shouldReceive('record')->once();

        $this->artisan('clockwork:verify-wp-core-checksums')
            ->expectsOutputToContain('Done. clean=1, issues=0, failed=0')
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

        $this->mock(WpCoreChecksumVerifier::class)
            ->shouldReceive('verify')
            ->once()
            ->with(Mockery::on(fn (Site $s) => $s->is($site)))
            ->andReturn(cleanChecksumResult($site));

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
            $mock->shouldReceive('verify')->with(Mockery::on(fn (Site $s) => $s->is($clean)))->andReturn(cleanChecksumResult($clean));
            $mock->shouldReceive('verify')->with(Mockery::on(fn (Site $s) => $s->is($issues)))->andReturn(issuesChecksumResult($issues));
            $mock->shouldReceive('verify')->with(Mockery::on(fn (Site $s) => $s->is($failed)))->andReturn(failedChecksumResult($failed));
        });

        $this->mock(SecurityScanRecorder::class)->shouldReceive('record')->times(3);

        $this->artisan('clockwork:verify-wp-core-checksums')
            ->expectsOutputToContain('Done. clean=1, issues=1, failed=1')
            ->assertFailed();
    });

    it('stamps the checksums_last_run_at setting after a run', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'is_wordpress' => true, 'care_plan_enabled' => true]);

        $this->mock(WpCoreChecksumVerifier::class)->shouldReceive('verify')->once()->andReturn(cleanChecksumResult($site));
        $this->mock(SecurityScanRecorder::class)->shouldReceive('record')->once();

        $this->artisan('clockwork:verify-wp-core-checksums')->assertSuccessful();

        expect(app(Settings::class)->get('security_scans.checksums_last_run_at'))->not->toBeNull();
    });
});
