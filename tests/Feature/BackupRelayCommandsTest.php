<?php

namespace Tests\Feature;

use App\Models\BackupRelayRun;
use App\Models\Site;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the S3-mediated handoff between this app and the standalone
 * backup-relay droplet — no direct HTTP connection either direction, see
 * PushBackupRelayTargets/PullBackupRelayReport docblocks for why.
 */
class BackupRelayCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function s3Prefix(): string
    {
        return rtrim((string) config('clockwork.backup_relay.s3_prefix'), '/');
    }

    public function test_push_targets_writes_backup_relay_enabled_sites_across_providers(): void
    {
        Storage::fake('s3');

        Site::query()->create([
            'domain' => 'pressable-enabled.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '111',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);
        Site::query()->create([
            'domain' => 'pressable-disabled.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '222',
            'backup_relay_enabled' => false,
            'is_wordpress' => true,
        ]);
        Site::query()->create([
            'domain' => 'spinupwp-enabled.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'spinupwp_id' => 333,
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        $this->artisan('clockwork:push-backup-relay-targets')->assertSuccessful();

        $key = $this->s3Prefix().'/targets.json';
        Storage::disk('s3')->assertExists($key);

        $payload = json_decode(Storage::disk('s3')->get($key), true);
        $this->assertSame(2, $payload['schema_version']);
        $this->assertSame('weekly', $payload['frequency']);
        $this->assertSame(90, $payload['retention_days']);
        $this->assertCount(2, $payload['sites']);
        $this->assertSame('pressable-enabled.example.com', $payload['sites'][0]['domain']);
        $this->assertSame('pressable', $payload['sites'][0]['provider']);
        $this->assertSame('111', $payload['sites'][0]['pressable_site_id']);
        $this->assertSame('spinupwp-enabled.example.com', $payload['sites'][1]['domain']);
        $this->assertSame('spinupwp', $payload['sites'][1]['provider']);
        $this->assertEquals(333, $payload['sites'][1]['external_ref']['spinupwp_id']);
    }

    public function test_pull_report_does_nothing_when_no_object_exists(): void
    {
        Storage::fake('s3');

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();

        $this->assertSame(0, BackupRelayRun::query()->count());
    }

    public function test_pull_report_records_a_new_run_and_updates_settings(): void
    {
        Storage::fake('s3');

        $report = [
            'sites_total' => 10,
            'sites_archived' => 8,
            'sites_skipped' => 1,
            'sites_failed' => 1,
            'failures' => [['domain' => 'broken.example.com', 'error' => 'timeout']],
            'started_at' => '2026-08-30T05:00:00+00:00',
            'finished_at' => '2026-08-30T05:14:00+00:00',
        ];
        Storage::disk('s3')->put($this->s3Prefix().'/last-report.json', json_encode($report));

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();

        $this->assertSame(1, BackupRelayRun::query()->count());
        $run = BackupRelayRun::query()->first();
        $this->assertSame(8, $run->sites_archived);
        $this->assertSame(1, $run->sites_failed);
        $this->assertSame('broken.example.com', $run->failures[0]['domain']);

        $settings = app(Settings::class);
        $this->assertNotNull($settings->get('backup_relay.last_run_at'));
        $this->assertSame(
            '2026-08-30T05:14:00+00:00',
            $settings->get('backup_relay.last_processed_finished_at'),
        );
    }

    public function test_pull_report_does_not_duplicate_the_same_run(): void
    {
        Storage::fake('s3');

        $report = [
            'sites_total' => 5, 'sites_archived' => 5, 'sites_skipped' => 0, 'sites_failed' => 0,
            'failures' => [], 'started_at' => '2026-08-30T05:00:00+00:00', 'finished_at' => '2026-08-30T05:10:00+00:00',
        ];
        Storage::disk('s3')->put($this->s3Prefix().'/last-report.json', json_encode($report));

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();
        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();

        $this->assertSame(1, BackupRelayRun::query()->count());
    }

    public function test_stale_relay_alerts_once_and_recovers_once(): void
    {
        // Mattermost is disabled fleet-wide in .env.testing — enable it
        // explicitly for this test since it asserts a webhook actually
        // fires. Http::fake() still blocks the request from ever leaving
        // the process either way.
        config(['clockwork.mattermost.enabled' => true, 'clockwork.mattermost.webhook_url' => 'https://mattermost.example.com/hooks/test']);
        Http::fake();
        Storage::fake('s3');
        $settings = app(Settings::class);

        BackupRelayRun::query()->create([
            'sites_total' => 5, 'sites_archived' => 5, 'sites_skipped' => 0, 'sites_failed' => 0,
            'failures' => [], 'started_at' => now()->subDays(12), 'finished_at' => now()->subDays(12),
        ]);

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();

        $this->assertNotSame('', (string) $settings->get('backup_relay.stale_alert_since', ''));
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'Backup relay silent'));

        // Re-running while still stale must not re-alert (state already set).
        Http::fake();
        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();
        Http::assertNothingSent();

        // A fresh run lands — staleness clears and the recovery alert fires once.
        Http::fake();
        $report = [
            'sites_total' => 5, 'sites_archived' => 5, 'sites_skipped' => 0, 'sites_failed' => 0,
            'failures' => [], 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        ];
        Storage::disk('s3')->put($this->s3Prefix().'/last-report.json', json_encode($report));

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();

        $this->assertSame('', (string) $settings->get('backup_relay.stale_alert_since', ''));
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'Backup relay recovered'));
    }

    public function test_fresh_relay_never_alerts(): void
    {
        Http::fake();
        Storage::fake('s3');

        BackupRelayRun::query()->create([
            'sites_total' => 5, 'sites_archived' => 5, 'sites_skipped' => 0, 'sites_failed' => 0,
            'failures' => [], 'started_at' => now()->subDays(1), 'finished_at' => now()->subDays(1),
        ]);

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('', (string) app(Settings::class)->get('backup_relay.stale_alert_since', ''));
    }

    public function test_pull_report_accepts_v1_and_triggers_deprecation_notice(): void
    {
        config(['clockwork.mattermost.enabled' => true, 'clockwork.mattermost.webhook_url' => 'https://mattermost.example.com/hooks/test']);
        Http::fake();
        Storage::fake('s3');

        $report = [
            'schema_version' => 1,
            'sites_total' => 3, 'sites_archived' => 3, 'sites_skipped' => 0, 'sites_failed' => 0,
            'failures' => [], 'started_at' => '2026-09-01T05:00:00+00:00', 'finished_at' => '2026-09-01T05:10:00+00:00',
        ];
        Storage::disk('s3')->put($this->s3Prefix().'/last-report.json', json_encode($report));

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();

        $this->assertSame(1, BackupRelayRun::query()->count());
        $this->assertTrue((bool) app(Settings::class)->get('backup_relay.v1_deprecated_alert_sent'));
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'schema v1'));
    }

    public function test_pull_report_accepts_v2_without_deprecation_notice(): void
    {
        config(['clockwork.mattermost.enabled' => true, 'clockwork.mattermost.webhook_url' => 'https://mattermost.example.com/hooks/test']);
        Http::fake();
        Storage::fake('s3');

        $report = [
            'schema_version' => 2,
            'sites_total' => 3, 'sites_archived' => 3, 'sites_skipped' => 0, 'sites_failed' => 0,
            'failures' => [], 'started_at' => now()->subHours(2)->toIso8601String(), 'finished_at' => now()->subHours(1)->toIso8601String(),
        ];
        Storage::disk('s3')->put($this->s3Prefix().'/last-report.json', json_encode($report));

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();

        $this->assertSame(1, BackupRelayRun::query()->count());
        $this->assertFalse((bool) app(Settings::class)->get('backup_relay.v1_deprecated_alert_sent', false));
        Http::assertNothingSent();
    }
}
