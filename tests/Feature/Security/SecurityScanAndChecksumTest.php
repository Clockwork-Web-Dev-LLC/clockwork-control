<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCoreChecksumAllowlist;
use App\Models\SiteSecurityScan;
use App\Services\Chat\ChatNotifier;
use App\Services\Security\CoreChecksumAllowlist;
use App\Services\Security\SecurityScanRecorder;
use App\Services\Security\SecurityScanResult;
use App\Services\Security\WpCoreChecksumVerifier;
use App\Services\Ssh\SshClient;
use Modules\Pressable\PressableCommandRunner;

/*
|--------------------------------------------------------------------------
| Malware/compromise detection safety net
|--------------------------------------------------------------------------
|
| These two classes are the chokepoint between "a scanner found something"
| and "an operator/client actually gets told". A silent bug here means a
| hacked site stays hacked with nobody notified. Transport selection
| (SSH vs Pressable) is already characterized in
| tests/Feature/SiteProviderGatingTest.php — these tests mock the transport
| just enough to drive WpCoreChecksumVerifier's parsing/filtering logic, and
| focus SecurityScanRecorder's coverage on the persist + alert-transition
| behavior.
|
| Verified against the real code (not assumed): SecurityScanRecorder alerts
| ONLY on a has_malware_hit boolean transition from false/absent -> true for
| that (site, scan_type) pair (see the $previouslyHadMalwareHit query and the
| `if ($r->hasMalwareHit && ! $previouslyHadMalwareHit)` gate). A site that
| stays malware-positive across repeated scans does NOT get re-alerted on
| every run.
*/

function fakeSshRawOutput(string $wpCliOutput, int $exit): string
{
    // Mirrors WpCoreChecksumVerifier::verify()'s sentinel-stripping: the real
    // inner command appends "SENTINEL:$?" as its own line after wp-cli's
    // output.
    return trim($wpCliOutput)."\n__CLOCKWORK_WP_EXIT__:{$exit}";
}

function spinupSiteForChecksumVerify(): Site
{
    $server = Server::factory()->create(['ssh_password' => 'secret']);

    return Site::factory()->spinupwp()->create([
        'server_id' => $server->id,
        'is_wordpress' => true,
        'site_user' => 'siteuser',
    ]);
}

describe('WpCoreChecksumVerifier checksum-comparison logic', function () {
    it('filters a should-not-exist file matching a KNOWN_SAFE_UNEXPECTED_PREFIXES entry with no allowlist row needed', function () {
        $site = spinupSiteForChecksumVerify();

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn(
            fakeSshRawOutput('Warning: File should not exist: wp-includes/php-ai-client/client.php', 1)
        );
        $this->mock(PressableCommandRunner::class)->shouldNotReceive('run');

        $result = app(WpCoreChecksumVerifier::class)->verify($site->fresh());

        expect($result->status)->toBe(SiteSecurityScan::STATUS_CLEAN);
        expect($result->modifiedFilesCount)->toBe(0);
        expect($result->details['ignored_unexpected'] ?? [])->toContain('wp-includes/php-ai-client/client.php');
    });

    it('filters WP 7.x wp-includes .htaccess hardening stubs matching KNOWN_SAFE_UNEXPECTED_PATTERN', function () {
        $site = spinupSiteForChecksumVerify();

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn(
            fakeSshRawOutput(
                "Warning: File should not exist: wp-includes/.htaccess\n".
                'Warning: File should not exist: wp-includes/js/tinymce/.htaccess',
                1
            )
        );
        $this->mock(PressableCommandRunner::class)->shouldNotReceive('run');

        $result = app(WpCoreChecksumVerifier::class)->verify($site->fresh());

        expect($result->status)->toBe(SiteSecurityScan::STATUS_CLEAN);
        expect($result->modifiedFilesCount)->toBe(0);
        expect($result->details['ignored_unexpected'] ?? [])
            ->toContain('wp-includes/.htaccess')
            ->toContain('wp-includes/js/tinymce/.htaccess');
    });

    it('does NOT filter a should-not-exist file that merely resembles but does not match a known-safe prefix', function () {
        $site = spinupSiteForChecksumVerify();

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn(
            fakeSshRawOutput('Warning: File should not exist: wp-content/uploads/wp-shell.php', 1)
        );
        $this->mock(PressableCommandRunner::class)->shouldNotReceive('run');

        $result = app(WpCoreChecksumVerifier::class)->verify($site->fresh());

        expect($result->status)->toBe(SiteSecurityScan::STATUS_ISSUES_FOUND);
        expect($result->modifiedFilesCount)->toBe(1);
        expect($result->details['should_not_exist'])->toBe(['wp-content/uploads/wp-shell.php']);
    });

    it('filters an EXPECTED_ABSENT disclosure file from the missing list', function () {
        $site = spinupSiteForChecksumVerify();

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn(
            fakeSshRawOutput('Warning: File is missing: license.txt', 1)
        );
        $this->mock(PressableCommandRunner::class)->shouldNotReceive('run');

        $result = app(WpCoreChecksumVerifier::class)->verify($site->fresh());

        expect($result->status)->toBe(SiteSecurityScan::STATUS_CLEAN);
        expect($result->modifiedFilesCount)->toBe(0);
    });

    it('flags a genuinely missing core file that is not in EXPECTED_ABSENT', function () {
        $site = spinupSiteForChecksumVerify();

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn(
            fakeSshRawOutput('Warning: File is missing: wp-admin/includes/file.php', 1)
        );
        $this->mock(PressableCommandRunner::class)->shouldNotReceive('run');

        $result = app(WpCoreChecksumVerifier::class)->verify($site->fresh());

        expect($result->status)->toBe(SiteSecurityScan::STATUS_ISSUES_FOUND);
        expect($result->details['missing'])->toBe(['wp-admin/includes/file.php']);
    });

    it('always flags a modified core file — no allowlist/known-safe filtering applies to the modified bucket', function () {
        $site = spinupSiteForChecksumVerify();

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn(
            fakeSshRawOutput("Warning: File doesn't verify against checksum: wp-includes/load.php", 1)
        );
        $this->mock(PressableCommandRunner::class)->shouldNotReceive('run');

        $result = app(WpCoreChecksumVerifier::class)->verify($site->fresh());

        expect($result->status)->toBe(SiteSecurityScan::STATUS_ISSUES_FOUND);
        expect($result->details['modified'])->toBe(['wp-includes/load.php']);
    });

    it('reports clean when wp-cli exits 0 with no warnings at all', function () {
        $site = spinupSiteForChecksumVerify();

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn(
            fakeSshRawOutput('', 0)
        );
        $this->mock(PressableCommandRunner::class)->shouldNotReceive('run');

        $result = app(WpCoreChecksumVerifier::class)->verify($site->fresh());

        expect($result->status)->toBe(SiteSecurityScan::STATUS_CLEAN);
    });
});

describe('CoreChecksumAllowlist bucket suppression', function () {
    it('suppresses a modified finding covered by a matching-bucket allowlist row, but keeps an unallowlisted one', function () {
        $site = Site::factory()->create();

        SiteCoreChecksumAllowlist::factory()->create([
            'site_id' => $site->id,
            'path' => 'wp-includes/load.php',
            'bucket' => SiteCoreChecksumAllowlist::BUCKET_MODIFIED,
        ]);

        $scan = SiteSecurityScan::factory()->coreChecksums()->create([
            'site_id' => $site->id,
            'status' => SiteSecurityScan::STATUS_ISSUES_FOUND,
            'modified_files_count' => 2,
            'details' => [
                'modified' => ['wp-includes/load.php', 'wp-includes/functions.php'],
                'missing' => [],
                'should_not_exist' => [],
            ],
        ]);

        $filtered = app(CoreChecksumAllowlist::class)->filter($site, $scan);

        expect($filtered['modified'])->toBe(['wp-includes/functions.php']);
        expect($filtered['allowlisted']['modified'])->toBe(['wp-includes/load.php']);
        expect($filtered['has_unallowlisted'])->toBeTrue();
        expect($filtered['total_unallowlisted'])->toBe(1);
    });

    it('suppresses any bucket for a path allowlisted under the "*" wildcard bucket', function () {
        $site = Site::factory()->create();

        SiteCoreChecksumAllowlist::factory()->create([
            'site_id' => $site->id,
            'path' => 'wp-content/mu-plugins/custom.php',
            'bucket' => SiteCoreChecksumAllowlist::BUCKET_ANY,
        ]);

        $scan = SiteSecurityScan::factory()->coreChecksums()->create([
            'site_id' => $site->id,
            'status' => SiteSecurityScan::STATUS_ISSUES_FOUND,
            'modified_files_count' => 1,
            'details' => [
                'modified' => [],
                'missing' => [],
                'should_not_exist' => ['wp-content/mu-plugins/custom.php'],
            ],
        ]);

        $filtered = app(CoreChecksumAllowlist::class)->filter($site, $scan);

        expect($filtered['should_not_exist'])->toBe([]);
        expect($filtered['allowlisted']['should_not_exist'])->toBe(['wp-content/mu-plugins/custom.php']);
        expect($filtered['has_unallowlisted'])->toBeFalse();
    });

    it('does NOT suppress a finding when the allowlist row is for the wrong bucket on the same path', function () {
        $site = Site::factory()->create();

        // Allowlisted only as "missing", but the scan flags this same path as
        // "modified" — a bucket-specific allowlist row must not leak across
        // buckets.
        SiteCoreChecksumAllowlist::factory()->create([
            'site_id' => $site->id,
            'path' => 'wp-includes/load.php',
            'bucket' => SiteCoreChecksumAllowlist::BUCKET_MISSING,
        ]);

        $scan = SiteSecurityScan::factory()->coreChecksums()->create([
            'site_id' => $site->id,
            'status' => SiteSecurityScan::STATUS_ISSUES_FOUND,
            'modified_files_count' => 1,
            'details' => [
                'modified' => ['wp-includes/load.php'],
                'missing' => [],
                'should_not_exist' => [],
            ],
        ]);

        $filtered = app(CoreChecksumAllowlist::class)->filter($site, $scan);

        expect($filtered['modified'])->toBe(['wp-includes/load.php']);
        expect($filtered['has_unallowlisted'])->toBeTrue();
    });
});

describe('SecurityScanRecorder', function () {
    it('persists a malware-hit result and fires the malware alert on first detection', function () {
        $site = Site::factory()->create();
        $this->mock(ChatNotifier::class)
            ->shouldReceive('malwareFindingDetected')
            ->once()
            ->withArgs(fn (Site $s, SiteSecurityScan $scan) => $s->is($site) && $scan->has_malware_hit === true)
            ->andReturn(true);

        $result = new SecurityScanResult(
            site: $site,
            scanType: SiteSecurityScan::TYPE_SITECHECK,
            status: SiteSecurityScan::STATUS_ISSUES_FOUND,
            hasMalwareHit: true,
            summary: 'Malicious code detected',
        );

        $row = app(SecurityScanRecorder::class)->record($result);

        expect($row)->toBeInstanceOf(SiteSecurityScan::class);
        expect($row->has_malware_hit)->toBeTrue();
        expect($row->status)->toBe(SiteSecurityScan::STATUS_ISSUES_FOUND);
        $this->assertDatabaseHas('site_security_scans', [
            'id' => $row->id,
            'site_id' => $site->id,
            'has_malware_hit' => true,
            'status' => SiteSecurityScan::STATUS_ISSUES_FOUND,
        ]);
    });

    it('persists a clean result with has_malware_hit=false and never calls the malware notifier', function () {
        $site = Site::factory()->create();
        $this->mock(ChatNotifier::class)->shouldNotReceive('malwareFindingDetected');

        $result = new SecurityScanResult(
            site: $site,
            scanType: SiteSecurityScan::TYPE_SITECHECK,
            status: SiteSecurityScan::STATUS_CLEAN,
            hasMalwareHit: false,
            summary: 'No malware detected',
        );

        $row = app(SecurityScanRecorder::class)->record($result);

        expect($row->has_malware_hit)->toBeFalse();
        expect($row->status)->toBe(SiteSecurityScan::STATUS_CLEAN);
    });

    it('alerts on a clean-to-found transition (no prior scan of this type)', function () {
        $site = Site::factory()->create();
        $this->mock(ChatNotifier::class)->shouldReceive('malwareFindingDetected')->once()->andReturn(true);

        $result = new SecurityScanResult(
            site: $site,
            scanType: SiteSecurityScan::TYPE_SITECHECK,
            status: SiteSecurityScan::STATUS_ISSUES_FOUND,
            hasMalwareHit: true,
        );

        app(SecurityScanRecorder::class)->record($result);
    });

    it('does NOT re-alert on a second consecutive found scan — only the transition fires the notifier', function () {
        $site = Site::factory()->create();

        // Prior scan of the same type already flagged malware.
        SiteSecurityScan::factory()->malwareFound()->create([
            'site_id' => $site->id,
            'scan_type' => SiteSecurityScan::TYPE_SITECHECK,
            'scanned_at' => now()->subHour(),
        ]);

        $this->mock(ChatNotifier::class)->shouldNotReceive('malwareFindingDetected');

        $result = new SecurityScanResult(
            site: $site,
            scanType: SiteSecurityScan::TYPE_SITECHECK,
            status: SiteSecurityScan::STATUS_ISSUES_FOUND,
            hasMalwareHit: true,
            summary: 'Still infected',
        );

        $row = app(SecurityScanRecorder::class)->record($result);

        // The new row is still recorded as a hit — only the notification is
        // suppressed, not the persisted history.
        expect($row->has_malware_hit)->toBeTrue();
        $this->assertDatabaseCount('site_security_scans', 2);
    });

    it('alerts again if a site goes clean and then gets re-infected (found -> clean -> found)', function () {
        $site = Site::factory()->create();

        SiteSecurityScan::factory()->malwareFound()->create([
            'site_id' => $site->id,
            'scan_type' => SiteSecurityScan::TYPE_SITECHECK,
            'scanned_at' => now()->subHours(2),
        ]);
        SiteSecurityScan::factory()->create([
            'site_id' => $site->id,
            'scan_type' => SiteSecurityScan::TYPE_SITECHECK,
            'status' => SiteSecurityScan::STATUS_CLEAN,
            'has_malware_hit' => false,
            'scanned_at' => now()->subHour(),
        ]);

        $this->mock(ChatNotifier::class)->shouldReceive('malwareFindingDetected')->once()->andReturn(true);

        $result = new SecurityScanResult(
            site: $site,
            scanType: SiteSecurityScan::TYPE_SITECHECK,
            status: SiteSecurityScan::STATUS_ISSUES_FOUND,
            hasMalwareHit: true,
        );

        app(SecurityScanRecorder::class)->record($result);
    });

    it('swallows a notifier exception so the scan row is still persisted', function () {
        $site = Site::factory()->create();
        $this->mock(ChatNotifier::class)
            ->shouldReceive('malwareFindingDetected')
            ->once()
            ->andThrow(new RuntimeException('webhook down'));

        $result = new SecurityScanResult(
            site: $site,
            scanType: SiteSecurityScan::TYPE_SITECHECK,
            status: SiteSecurityScan::STATUS_ISSUES_FOUND,
            hasMalwareHit: true,
        );

        $row = app(SecurityScanRecorder::class)->record($result);

        expect($row)->not->toBeNull();
        $this->assertDatabaseHas('site_security_scans', ['id' => $row->id, 'has_malware_hit' => true]);
    });

    it('downgrades a core_checksums issues_found result to clean when every finding is allowlisted', function () {
        $site = Site::factory()->create();
        $this->mock(ChatNotifier::class)->shouldNotReceive('malwareFindingDetected');

        SiteCoreChecksumAllowlist::factory()->create([
            'site_id' => $site->id,
            'path' => 'wp-content/mu-plugins/custom.php',
            'bucket' => SiteCoreChecksumAllowlist::BUCKET_UNEXPECTED,
        ]);

        $result = new SecurityScanResult(
            site: $site,
            scanType: SiteSecurityScan::TYPE_CORE_CHECKSUMS,
            status: SiteSecurityScan::STATUS_ISSUES_FOUND,
            modifiedFilesCount: 1,
            summary: 'Core file integrity issue — 1 unexpected.',
            details: [
                'modified' => [],
                'missing' => [],
                'should_not_exist' => ['wp-content/mu-plugins/custom.php'],
            ],
        );

        $row = app(SecurityScanRecorder::class)->record($result);

        expect($row->status)->toBe(SiteSecurityScan::STATUS_CLEAN);
        expect($row->summary)->toContain('every finding allowlisted');
    });

    it('keeps a core_checksums result as issues_found when at least one finding is NOT allowlisted', function () {
        $site = Site::factory()->create();

        SiteCoreChecksumAllowlist::factory()->create([
            'site_id' => $site->id,
            'path' => 'wp-content/mu-plugins/custom.php',
            'bucket' => SiteCoreChecksumAllowlist::BUCKET_UNEXPECTED,
        ]);

        $result = new SecurityScanResult(
            site: $site,
            scanType: SiteSecurityScan::TYPE_CORE_CHECKSUMS,
            status: SiteSecurityScan::STATUS_ISSUES_FOUND,
            modifiedFilesCount: 2,
            summary: 'Core file integrity issue — 2 unexpected.',
            details: [
                'modified' => [],
                'missing' => [],
                'should_not_exist' => ['wp-content/mu-plugins/custom.php', 'wp-content/mu-plugins/backdoor.php'],
            ],
        );

        $row = app(SecurityScanRecorder::class)->record($result);

        expect($row->status)->toBe(SiteSecurityScan::STATUS_ISSUES_FOUND);
        expect($row->summary)->toBe('Core file integrity issue — 2 unexpected.');
    });
});
