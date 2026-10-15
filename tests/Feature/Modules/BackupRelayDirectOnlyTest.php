<?php

use App\Models\BackupRelayRun;
use App\Models\Site;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\BackupRelay\BackupRelayServiceProvider;

/*
| In external_agent mode the droplet only pulls from hosts that keep their own
| backups. Sites we don't host back up by Companion uploading straight to S3,
| which only Control can trigger — these tests pin that Control keeps doing it.
*/

function relaySite(string $provider, array $attributes = []): Site
{
    return Site::query()->create(array_merge([
        'domain' => $provider.'-'.uniqid().'.example.com',
        'hosting_provider' => $provider,
        'backup_relay_enabled' => true,
        'is_wordpress' => true,
    ], $attributes));
}

it('still schedules direct (Companion) backups in external_agent mode', function () {
    config(['clockwork.backup_relay.mode' => 'external_agent']);
    $schedule = new Schedule;

    (new BackupRelayServiceProvider(app()))->scheduledTasks($schedule);

    $commands = collect($schedule->events())->map(fn (Event $e) => $e->command ?? '');
    expect($commands->contains(fn ($c) => str_contains($c, 'clockwork:backup-relay-run --direct-only')))->toBeTrue()
        ->and($commands->contains(fn ($c) => str_contains($c, 'clockwork:push-backup-relay-targets')))->toBeTrue();
});

it('does not hand direct-to-S3 sites to the external agent', function () {
    Storage::fake('s3');
    relaySite(Site::HOSTING_PROVIDER_PRESSABLE, ['domain' => 'hosted.example.com', 'pressable_site_id' => '111']);
    relaySite(Site::HOSTING_PROVIDER_CUSTOM, ['domain' => 'unhosted.example.com']);

    $this->artisan('clockwork:push-backup-relay-targets')->assertSuccessful();

    $payload = json_decode(Storage::disk('s3')->get(rtrim((string) config('clockwork.backup_relay.s3_prefix'), '/').'/targets.json'), true);
    expect(collect($payload['sites'])->pluck('domain')->all())->toBe(['hosted.example.com']);
});

it('--direct-only skips host-backed sites', function () {
    relaySite(Site::HOSTING_PROVIDER_PRESSABLE, ['pressable_site_id' => '111']);

    $this->artisan('clockwork:backup-relay-run --direct-only')
        ->expectsOutputToContain('No eligible backup-relay-enabled sites matched.')
        ->assertSuccessful();
});

it('--direct-only processes unhosted sites without touching the agent run history', function () {
    Http::fake();
    Storage::fake('s3');
    $site = relaySite(Site::HOSTING_PROVIDER_CUSTOM, ['domain' => 'unhosted.example.com']);

    $this->artisan('clockwork:backup-relay-run --direct-only')
        ->expectsOutputToContain('unhosted.example.com')
        ->expectsOutputToContain('Direct backups complete');

    expect(BackupRelayRun::count())->toBe(0);
});

it('the scheduled pass skips a site that is mid-restore or already running Backup Now', function () {
    Http::fake();
    Storage::fake('s3');
    $restoring = relaySite(Site::HOSTING_PROVIDER_CUSTOM, ['domain' => 'a-restoring.example.com']);
    $busy = relaySite(Site::HOSTING_PROVIDER_CUSTOM, ['domain' => 'b-busy.example.com']);
    Cache::put("backup_restore.site.{$restoring->id}.state", ['status' => 'applying_sql'], 600);
    Cache::put("backup_relay.run.site.{$busy->id}", now()->toIso8601String(), 600);

    $this->artisan('clockwork:backup-relay-run --direct-only')
        ->expectsOutputToContain('a-restoring.example.com — a restore is in progress')
        ->expectsOutputToContain('b-busy.example.com — a backup is already running')
        ->assertSuccessful();

    // The sweep releases only locks it took itself.
    expect(Cache::has("backup_relay.run.site.{$busy->id}"))->toBeTrue();
});
