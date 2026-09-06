<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCoreChecksumAllowlist;
use App\Models\SiteSecurityScan;
use App\Models\User;
use App\Services\Security\SecurityScanResult;
use App\Services\Security\SucuriSiteCheckClient;
use App\Services\Security\WpCoreChecksumVerifier;
use App\Services\Ssh\SshClient;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| SecurityScansController
|--------------------------------------------------------------------------
|
| runForSite() runs SucuriSiteCheckClient::scanSite() and/or
| WpCoreChecksumVerifier::verify() (both mocked — the former hits Sucuri's
| public API over HTTP, the latter SSHs into the box) and feeds the result
| through the REAL SecurityScanRecorder, which is a thin, dependency-light
| chokepoint (ActionLogger has no constructor deps; ChatNotifier is only
| invoked on a clean→malware-hit transition, which none of these tests
| trigger) — so it's exercised for real rather than mocked.
|
| viewFile()'s path-validation guard (CoreChecksumAllowlist::
| bucketForPathInLatestScan()) is tested explicitly per the task brief:
| a path not present in the latest scan's modified/missing/should_not_exist
| buckets must be rejected BEFORE any SSH call is made.
*/

beforeEach(function () {
    $this->mockIssueCounterZero();
});

function checksumScanForViewFile(Site $site, array $details): SiteSecurityScan
{
    return SiteSecurityScan::factory()->for($site)->coreChecksums()->create([
        'status' => SiteSecurityScan::STATUS_ISSUES_FOUND,
        'details' => $details,
    ]);
}

it('requires authentication', function () {
    $this->get(route('security.scans'))->assertRedirect(route('login'));
});

describe('index', function () {
    it('renders the fleet scan inventory with sites and their latest scans', function () {
        $server = Server::factory()->create(['is_ignored' => false]);
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'domain' => 'example-scan-site.test',
            'care_plan_enabled' => true,
        ]);
        SiteSecurityScan::factory()->for($site)->create([
            'scan_type' => SiteSecurityScan::TYPE_SITECHECK,
            'status' => SiteSecurityScan::STATUS_CLEAN,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('security.scans'));

        $response->assertOk();
        $response->assertSee('example-scan-site.test');
    });

    it('includes serverless sites like Pressable in the security scan inventory', function () {
        $site = Site::factory()->pressable()->create([
            'domain' => 'pressable-scan-site.test',
            'care_plan_enabled' => true,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('security.scans'));

        $response->assertOk();
        $response->assertSee('pressable-scan-site.test');
        $response->assertSee('Pressable');
    });
});

describe('runForSite', function () {
    it('runs both scanners, records two scan rows, and redirects with a success flash', function () {
        $site = Site::factory()->spinupwp()->create(['is_wordpress' => true]);

        $this->mock(SucuriSiteCheckClient::class)
            ->shouldReceive('scanSite')
            ->once()
            ->withArgs(fn (Site $s) => $s->is($site))
            ->andReturn(new SecurityScanResult(
                site: $site,
                scanType: SiteSecurityScan::TYPE_SITECHECK,
                status: SiteSecurityScan::STATUS_CLEAN,
                summary: 'Clean.',
            ));

        $this->mock(WpCoreChecksumVerifier::class)
            ->shouldReceive('verify')
            ->once()
            ->withArgs(fn (Site $s) => $s->is($site))
            ->andReturn(new SecurityScanResult(
                site: $site,
                scanType: SiteSecurityScan::TYPE_CORE_CHECKSUMS,
                status: SiteSecurityScan::STATUS_CLEAN,
                summary: 'All core files match WordPress.org checksums.',
            ));

        $response = $this->actingAs(User::factory()->create())
            ->post(route('security.scans.run', $site));

        $response->assertRedirect();
        $response->assertSessionHas('flash', "Ran Sucuri SiteCheck + core checksums on {$site->domain}.");

        expect(SiteSecurityScan::query()->where('site_id', $site->id)->count())->toBe(2);
        expect(SiteSecurityScan::query()->where('site_id', $site->id)->where('scan_type', SiteSecurityScan::TYPE_SITECHECK)->exists())->toBeTrue();
        expect(SiteSecurityScan::query()->where('site_id', $site->id)->where('scan_type', SiteSecurityScan::TYPE_CORE_CHECKSUMS)->exists())->toBeTrue();
    });

    it('skips the core-checksums scanner entirely for a non-WordPress site', function () {
        $site = Site::factory()->spinupwp()->create(['is_wordpress' => false]);

        $this->mock(SucuriSiteCheckClient::class)
            ->shouldReceive('scanSite')
            ->once()
            ->andReturn(new SecurityScanResult(
                site: $site,
                scanType: SiteSecurityScan::TYPE_SITECHECK,
                status: SiteSecurityScan::STATUS_CLEAN,
            ));

        $this->mock(WpCoreChecksumVerifier::class)->shouldNotReceive('verify');

        $response = $this->actingAs(User::factory()->create())
            ->post(route('security.scans.run', $site));

        $response->assertRedirect();
        $response->assertSessionHas('flash', "Ran Sucuri SiteCheck on {$site->domain}.");

        expect(SiteSecurityScan::query()->where('site_id', $site->id)->count())->toBe(1);
    });
});

describe('viewFile', function () {
    it('SSHes to read a path that IS in the latest scan and renders the file viewer', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create(['server_id' => $server->id]);
        checksumScanForViewFile($site, [
            'wp_path' => '/var/www/example',
            'modified' => ['wp-includes/load.php'],
            'missing' => [],
            'should_not_exist' => [],
        ]);

        $this->mock(SshClient::class)
            ->shouldReceive('exec')
            ->once()
            ->withArgs(fn (Server $s) => $s->is($server))
            ->andReturn("-rw-r--r-- 1 www-data www-data 12345 wp-includes/load.php\n---STAT---\n2026-01-01\n---SIZE---\n12345\n---HEAD---\n<?php\n// tampered content here\n");

        $response = $this->actingAs(User::factory()->create())
            ->get(route('security.scans.file', ['site' => $site, 'path' => 'wp-includes/load.php']));

        $response->assertOk();
        $response->assertSee('wp-includes/load.php');
        $response->assertSee('tampered content here', false);
    });

    it('rejects a path that is NOT in the latest scan without ever calling SSH', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create(['server_id' => $server->id]);
        checksumScanForViewFile($site, [
            'wp_path' => '/var/www/example',
            'modified' => ['wp-includes/load.php'],
            'missing' => [],
            'should_not_exist' => [],
        ]);

        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('security.scans.file', ['site' => $site, 'path' => 'wp-config.php']));

        $response->assertRedirect(route('sites.show', [$site, 'security']));
        $response->assertSessionHas('flash', 'Path not in latest scan or not readable: wp-config.php');
    });

    it('rejects a path-traversal attempt without ever calling SSH', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create(['server_id' => $server->id]);
        checksumScanForViewFile($site, [
            'wp_path' => '/var/www/example',
            'modified' => ['wp-includes/load.php'],
            'missing' => [],
            'should_not_exist' => [],
        ]);

        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('security.scans.file', ['site' => $site, 'path' => '../../../etc/passwd']));

        $response->assertRedirect(route('sites.show', [$site, 'security']));
    });
});

describe('addToAllowlist', function () {
    it('adds a path that IS in the latest scan to the allowlist', function () {
        $site = Site::factory()->spinupwp()->create();
        checksumScanForViewFile($site, [
            'wp_path' => '/var/www/example',
            'modified' => [],
            'missing' => [],
            'should_not_exist' => ['wp-content/uploads/shell.php'],
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('security.scans.allowlist.add', $site), [
            'path' => 'wp-content/uploads/shell.php',
            'bucket' => SiteCoreChecksumAllowlist::BUCKET_UNEXPECTED,
            'reason' => 'known custom drop-in',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash', "Allowlisted wp-content/uploads/shell.php for {$site->domain}.");

        $entry = SiteCoreChecksumAllowlist::query()->where('site_id', $site->id)->firstOrFail();
        expect($entry->path)->toBe('wp-content/uploads/shell.php');
        expect($entry->bucket)->toBe(SiteCoreChecksumAllowlist::BUCKET_UNEXPECTED);
        expect($entry->reason)->toBe('known custom drop-in');
        expect($entry->added_by_user_id)->toBe($user->id);
    });

    it('refuses to allowlist a path that was NOT flagged in the latest scan', function () {
        $site = Site::factory()->spinupwp()->create();
        checksumScanForViewFile($site, [
            'wp_path' => '/var/www/example',
            'modified' => [],
            'missing' => [],
            'should_not_exist' => ['wp-content/uploads/shell.php'],
        ]);

        $response = $this->actingAs(User::factory()->create())->post(route('security.scans.allowlist.add', $site), [
            'path' => 'wp-content/uploads/not-flagged.php',
            'bucket' => SiteCoreChecksumAllowlist::BUCKET_UNEXPECTED,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash', 'Path not in latest scan: wp-content/uploads/not-flagged.php');

        expect(SiteCoreChecksumAllowlist::query()->where('site_id', $site->id)->count())->toBe(0);
    });

    it('fails validation when bucket is missing or not one of the known buckets', function () {
        $site = Site::factory()->spinupwp()->create();

        $this->actingAs(User::factory()->create())->post(route('security.scans.allowlist.add', $site), [
            'path' => 'wp-content/uploads/shell.php',
            'bucket' => 'not-a-real-bucket',
        ])->assertSessionHasErrors('bucket');

        expect(SiteCoreChecksumAllowlist::query()->count())->toBe(0);
    });
});

describe('removeFromAllowlist', function () {
    it('deletes an allowlist entry belonging to the site and redirects with a flash', function () {
        $site = Site::factory()->spinupwp()->create();
        $entry = SiteCoreChecksumAllowlist::factory()->for($site)->create([
            'path' => 'wp-content/mu-plugins/custom.php',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->delete(route('security.scans.allowlist.remove', [$site, $entry]));

        $response->assertRedirect();
        $response->assertSessionHas('flash', 'Removed wp-content/mu-plugins/custom.php from allowlist.');
        expect(SiteCoreChecksumAllowlist::query()->whereKey($entry->id)->exists())->toBeFalse();
    });

    it('404s when the allowlist entry belongs to a different site', function () {
        $site = Site::factory()->spinupwp()->create();
        $otherSite = Site::factory()->spinupwp()->create();
        $entry = SiteCoreChecksumAllowlist::factory()->for($otherSite)->create();

        $response = $this->actingAs(User::factory()->create())
            ->delete(route('security.scans.allowlist.remove', [$site, $entry]));

        $response->assertNotFound();
        expect(SiteCoreChecksumAllowlist::query()->whereKey($entry->id)->exists())->toBeTrue();
    });
});
