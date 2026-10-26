<?php

use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\SiteBackupCheck;
use App\Services\DigitalOcean\SpacesClient;
use Modules\BackupRelay\Services\BackupArchiveEnumerator;
use Modules\Pressable\PressableClient;

/*
| clockwork:record-site-backups — the evidence client reports are built from.
| Each source's client is mocked as a collaborator (its own HTTP shape is
| covered by its own tests); rows use the shapes those clients really return.
*/

function pressableBackupsSite(): Site
{
    return Site::factory()->create([
        'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
        'pressable_site_id' => '9001',
        'is_inactive' => false,
    ]);
}

it('records Pressable file backups and one database backup per day', function () {
    $site = pressableBackupsSite();
    $this->mock(PressableClient::class, function ($mock) {
        $mock->shouldReceive('siteFilesystemBackups')->with('9001')->andReturn([
            ['id' => 11, 'backup_timestamp' => '2026-10-14 00:00:00', 'title' => 'Wed, 14 Oct 2026 00:00:00 UTC - 272.92 MB'],
            ['id' => 10, 'backup_timestamp' => '2026-10-13 00:00:00', 'title' => 'Tue, 13 Oct 2026 00:00:00 UTC (on-demand)'],
        ]);
        $mock->shouldReceive('siteDatabaseBackups')->with('9001')->andReturn([
            ['id' => 23, 'backup_timestamp' => '2026-10-14 23:00:00', 'title' => 'Wed, 14 Oct 2026 23:00:00 UTC - 12.50 MB'],
            ['id' => 22, 'backup_timestamp' => '2026-10-14 01:00:00', 'title' => 'Wed, 14 Oct 2026 01:00:00 UTC - 12.40 MB'],
            ['id' => 21, 'backup_timestamp' => '2026-10-13 05:00:00', 'title' => 'Tue, 13 Oct 2026 05:00:00 UTC - 12.30 MB'],
        ]);
    });

    $this->artisan('clockwork:record-site-backups')->assertSuccessful();

    $files = SiteBackup::where('kind', SiteBackup::KIND_FILES)->orderByDesc('backup_at')->get();
    expect($files)->toHaveCount(2)
        ->and($files[0]->bytes)->toBe((int) round(272.92 * 1024 ** 2))
        ->and($files[0]->backup_at->toDateTimeString())->toBe('2026-10-14 00:00:00')
        ->and($files[1]->is_manual)->toBeTrue()
        ->and($files[1]->bytes)->toBeNull();

    $db = SiteBackup::where('kind', SiteBackup::KIND_DATABASE)->orderByDesc('backup_at')->pluck('external_ref')->all();
    expect($db)->toBe(['day:2026-10-14', 'day:2026-10-13'])
        ->and(SiteBackup::where('kind', SiteBackup::KIND_DATABASE)->orderByDesc('backup_at')->first()->bytes)->toBe((int) round(12.50 * 1024 ** 2));

    expect(SiteBackupCheck::where('site_id', $site->id)->sole())->ok->toBeTrue()->backups_seen->toBe(4);
});

it('is idempotent and keeps backups the source has since pruned', function () {
    pressableBackupsSite();
    $first = [['id' => 1, 'backup_timestamp' => '2026-10-01 00:00:00', 'title' => 'x - 1 MB'], ['id' => 2, 'backup_timestamp' => '2026-10-02 00:00:00', 'title' => 'x - 1 MB']];
    $later = [['id' => 2, 'backup_timestamp' => '2026-10-02 00:00:00', 'title' => 'x - 1 MB'], ['id' => 3, 'backup_timestamp' => '2026-10-03 00:00:00', 'title' => 'x - 1 MB']];
    $calls = 0;
    $this->mock(PressableClient::class, function ($mock) use ($first, $later, &$calls) {
        $mock->shouldReceive('siteFilesystemBackups')->andReturnUsing(function () use ($first, $later, &$calls) {
            return $calls++ === 0 ? $first : $later;
        });
        $mock->shouldReceive('siteDatabaseBackups')->andReturn([]);
    });

    $this->artisan('clockwork:record-site-backups')->assertSuccessful();
    $this->artisan('clockwork:record-site-backups')->assertSuccessful();

    expect(SiteBackup::orderBy('backup_at')->pluck('external_ref')->all())->toBe(['1', '2', '3']);
});

it('records a failed read as "could not check", not as zero backups', function () {
    $site = pressableBackupsSite();
    $this->mock(PressableClient::class, function ($mock) {
        $mock->shouldReceive('siteFilesystemBackups')->andThrow(new RuntimeException('Pressable API returned 503'));
    });

    $this->artisan('clockwork:record-site-backups')
        ->expectsOutputToContain('Pressable API returned 503')
        ->assertFailed();

    expect(SiteBackupCheck::where('site_id', $site->id)->sole())
        ->ok->toBeFalse()
        ->error->toContain('503');
    expect(SiteBackup::count())->toBe(0);
});

it('records SpinupWP runs from the Spaces bucket', function () {
    $site = Site::factory()->create(['hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP, 'domain' => 'spun.example.com', 'is_inactive' => false]);
    $this->mock(SpacesClient::class, function ($mock) {
        $mock->shouldReceive('listSiteBackupObjectsOrFail')->andReturn(['objects']);
        $mock->shouldReceive('toHistoryRows')->with(['objects'])->andReturn([
            ['date' => '2026-10-14T03:00:12+00:00', 'type' => 'daily', 'database_bytes' => 1000, 'files_bytes' => 5000, 'notes' => null],
        ]);
    });

    $this->artisan('clockwork:record-site-backups')->assertSuccessful();

    expect(SiteBackup::where('site_id', $site->id)->sole())
        ->source->toBe(SiteBackup::SOURCE_SPINUPWP_SPACES)
        ->kind->toBe(SiteBackup::KIND_FULL)
        ->bytes->toBe(6000);
});

it('records our own S3 archives for sites we do not host', function () {
    $site = Site::factory()->create(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'backup_relay_enabled' => true, 'is_inactive' => false]);
    $this->mock(BackupArchiveEnumerator::class, function ($mock) {
        $mock->shouldReceive('listArchiveObjects')->andReturn([
            ['key' => 'archives/x/2026-10-14.zip', 'type' => 'full', 'bytes' => 2048, 'archived_at' => now()->setDate(2026, 10, 14)->startOfDay(), 'has_checksum' => true],
            ['key' => 'archives/x/2026-10-12_15-30-00.zip', 'type' => 'full', 'bytes' => 1024, 'archived_at' => now()->setDate(2026, 10, 12)->setTime(15, 30), 'has_checksum' => true],
        ]);
    });

    $this->artisan('clockwork:record-site-backups')->assertSuccessful();

    $rows = SiteBackup::where('site_id', $site->id)->orderByDesc('backup_at')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->is_manual)->toBeFalse()
        ->and($rows[1]->is_manual)->toBeTrue();
});

it('skips sites that have no backup source', function () {
    Site::factory()->create(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'backup_relay_enabled' => false, 'is_inactive' => false]);

    $this->artisan('clockwork:record-site-backups')
        ->expectsOutputToContain('Recorded backups for 0 sites')
        ->assertSuccessful();
});

it('keeps one database backup per day when a later run sees a newer one', function () {
    pressableBackupsSite();
    $earlyRun = [['id' => 31, 'backup_timestamp' => '2026-10-14 05:00:00', 'title' => 'x - 10 MB']];
    $nextRun = [
        ['id' => 40, 'backup_timestamp' => '2026-10-15 05:00:00', 'title' => 'x - 11 MB'],
        ['id' => 39, 'backup_timestamp' => '2026-10-14 23:00:00', 'title' => 'x - 12 MB'],
        ['id' => 31, 'backup_timestamp' => '2026-10-14 05:00:00', 'title' => 'x - 10 MB'],
    ];
    $calls = 0;
    $this->mock(PressableClient::class, function ($mock) use ($earlyRun, $nextRun, &$calls) {
        $mock->shouldReceive('siteFilesystemBackups')->andReturn([]);
        $mock->shouldReceive('siteDatabaseBackups')->andReturnUsing(function () use ($earlyRun, $nextRun, &$calls) {
            return $calls++ === 0 ? $earlyRun : $nextRun;
        });
    });

    $this->artisan('clockwork:record-site-backups')->assertSuccessful();
    $this->artisan('clockwork:record-site-backups')->assertSuccessful();

    $rows = SiteBackup::where('kind', SiteBackup::KIND_DATABASE)->orderBy('backup_at')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->backup_at->toDateTimeString())->toBe('2026-10-14 23:00:00')
        ->and($rows[0]->bytes)->toBe(12 * 1024 ** 2);
});

it('labels a SpinupWP run by what is actually there, and replaces a run first seen mid-upload', function () {
    $site = Site::factory()->create(['hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP, 'domain' => 'half.example.com', 'is_inactive' => false]);
    $calls = 0;
    $this->mock(SpacesClient::class, function ($mock) use (&$calls) {
        $mock->shouldReceive('listSiteBackupObjectsOrFail')->andReturn(['objects']);
        $mock->shouldReceive('toHistoryRows')->andReturnUsing(function () use (&$calls) {
            return $calls++ === 0
                ? [['date' => '2026-10-14T03:00:12+00:00', 'type' => 'daily', 'database_bytes' => 1000, 'files_bytes' => null, 'notes' => null]]
                : [['date' => '2026-10-14T03:00:12+00:00', 'type' => 'daily', 'database_bytes' => 1000, 'files_bytes' => 5000, 'notes' => null]];
        });
    });

    $this->artisan('clockwork:record-site-backups')->assertSuccessful();
    expect(SiteBackup::where('site_id', $site->id)->sole()->kind)->toBe(SiteBackup::KIND_DATABASE);

    $this->artisan('clockwork:record-site-backups')->assertSuccessful();
    expect(SiteBackup::where('site_id', $site->id)->sole())
        ->kind->toBe(SiteBackup::KIND_FULL)
        ->bytes->toBe(6000);
});

it('does not count an empty S3 archive as a backup', function () {
    $site = Site::factory()->create(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'backup_relay_enabled' => true, 'is_inactive' => false]);
    $this->mock(BackupArchiveEnumerator::class, function ($mock) {
        $mock->shouldReceive('listArchiveObjects')->andReturn([
            ['key' => 'archives/x/2026-10-14.zip', 'type' => 'full', 'bytes' => 0, 'archived_at' => now()->setDate(2026, 10, 14)->startOfDay(), 'has_checksum' => false],
        ]);
    });

    $this->artisan('clockwork:record-site-backups')->assertSuccessful();

    expect(SiteBackup::where('site_id', $site->id)->count())->toBe(0);
});

it('keeps going when one site throws, and reports the run as failed', function () {
    $broken = Site::factory()->create(['hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE, 'pressable_site_id' => '1', 'domain' => 'a-broken.example.com', 'is_inactive' => false]);
    $fine = Site::factory()->create(['hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE, 'pressable_site_id' => '2', 'domain' => 'b-fine.example.com', 'is_inactive' => false]);
    $this->mock(PressableClient::class, function ($mock) {
        $mock->shouldReceive('siteFilesystemBackups')->with('1')->andThrow(new RuntimeException('boom'));
        $mock->shouldReceive('siteFilesystemBackups')->with('2')->andReturn([['id' => 5, 'backup_timestamp' => '2026-10-14 00:00:00', 'title' => 'x - 1 MB']]);
        $mock->shouldReceive('siteDatabaseBackups')->andReturn([]);
    });

    $this->artisan('clockwork:record-site-backups')->assertFailed();

    expect(SiteBackupCheck::where('site_id', $broken->id)->sole()->ok)->toBeFalse()
        ->and(SiteBackupCheck::where('site_id', $fine->id)->sole()->ok)->toBeTrue();
});

it('matches --site by id only when it is all digits', function () {
    // MySQL casts '1stchoice…' to 1 when compared with an integer id.
    $first = Site::factory()->create(['hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE, 'pressable_site_id' => '8', 'is_inactive' => false]);
    $byDomain = Site::factory()->create(['hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE, 'pressable_site_id' => '7', 'domain' => $first->id.'stchoice.example.com', 'is_inactive' => false]);
    $this->mock(PressableClient::class, function ($mock) {
        $mock->shouldReceive('siteFilesystemBackups')->with('7')->once()->andReturn([]);
        $mock->shouldReceive('siteDatabaseBackups')->with('7')->once()->andReturn([]);
    });

    $this->artisan('clockwork:record-site-backups', ['--site' => $byDomain->domain])->assertSuccessful();

    expect(SiteBackupCheck::pluck('site_id')->all())->toBe([$byDomain->id]);
});
