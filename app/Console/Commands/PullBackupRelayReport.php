<?php

namespace App\Console\Commands;

use App\Models\BackupRelayRun;
use App\Services\Chat\ChatNotifier;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Reads back the standalone backup-relay droplet's last run summary from S3
 * (the droplet writes it there directly — no connection back to this app,
 * see clockwork:push-backup-relay-targets for why) and, if it's newer than
 * the last one this app already recorded, stores a BackupRelayRun row and
 * updates the same Settings-backed last-run-at pattern every other
 * scheduled job uses, so this job's health shows up the same way.
 *
 * Scheduled after the relay's own cron time so there's something new to
 * read by the time this runs — but it runs DAILY even though the relay
 * itself only runs Sun+Wed, because the staleness check below needs a
 * daily heartbeat to notice when the relay has gone quiet.
 *
 * Staleness detection: the relay has no connection back to this app in
 * either direction, so this command can only infer "is it still alive" from
 * how long it's been since the last BackupRelayRun row landed — there's no
 * process to ping and no log to tail. STALE_DAYS gives one full missed cycle
 * (Wed→Sun is the longest gap) plus a two-day buffer before alerting, same
 * "state + timestamp, alert only on transition" shape as
 * DetectStuckCompanionState, tracked via Settings since this is a
 * fleet-level concern with no site to hang a column off of.
 */
class PullBackupRelayReport extends Command
{
    public const STALE_DAYS = 6;

    protected $signature = 'clockwork:pull-backup-relay-report';

    protected $description = 'Read the backup-relay droplet\'s last run report from S3 and record it if new.';

    public function handle(Settings $settings, ChatNotifier $chatNotifier): int
    {
        $exitCode = $this->pullReport($settings, $chatNotifier);
        $this->checkStaleness($settings, $chatNotifier);

        return $exitCode;
    }

    private function pullReport(Settings $settings, ChatNotifier $chatNotifier): int
    {
        $disk = Storage::disk('s3');
        $key = rtrim((string) config('clockwork.backup_relay.s3_prefix'), '/').'/last-report.json';

        if (! $disk->exists($key)) {
            $this->warn("No report found at s3://{$key} yet — the relay may not have run.");

            return self::SUCCESS;
        }

        try {
            $data = json_decode((string) $disk->get($key), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->error("Failed to read/parse report at s3://{$key}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $validator = Validator::make((array) $data, [
            'schema_version' => ['nullable', 'integer'],
            'sites_total' => ['required', 'integer', 'min:0'],
            'sites_archived' => ['required', 'integer', 'min:0'],
            'sites_skipped' => ['required', 'integer', 'min:0'],
            'sites_failed' => ['required', 'integer', 'min:0'],
            'failures' => ['nullable', 'array'],
            'failures.*.domain' => ['required_with:failures', 'string'],
            'failures.*.error' => ['required_with:failures', 'string'],
            'started_at' => ['required', 'date'],
            'finished_at' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            $this->error('Report at s3://'.$key.' failed validation: '.$validator->errors()->first());

            return self::FAILURE;
        }
        $validated = $validator->validated();

        $schemaVersion = (int) ($validated['schema_version'] ?? 1);
        if ($schemaVersion === 1) {
            $deprecationAlerted = (bool) $settings->get('backup_relay.v1_deprecated_alert_sent', false);
            if (! $deprecationAlerted) {
                $chatNotifier->send('Deprecation notice: External backup relay agent is reporting schema v1. Please upgrade the relay agent to schema v2.');
                $settings->put('backup_relay.v1_deprecated_alert_sent', true);
            }
        }

        // The relay overwrites the same object every run — only record it
        // once per distinct run, keyed on finished_at, so a Laravel-side job
        // that happens to run twice before the next relay run doesn't
        // create duplicate rows.
        $lastProcessed = (string) $settings->get('backup_relay.last_processed_finished_at', '');
        $finishedAt = Carbon::parse($validated['finished_at']);
        if ($lastProcessed !== '' && ! $finishedAt->gt(Carbon::parse($lastProcessed))) {
            $this->info('Report already processed (finished_at '.$finishedAt->toIso8601String().') — nothing new.');

            return self::SUCCESS;
        }

        $run = BackupRelayRun::query()->create([
            'sites_total' => $validated['sites_total'],
            'sites_archived' => $validated['sites_archived'],
            'sites_skipped' => $validated['sites_skipped'],
            'sites_failed' => $validated['sites_failed'],
            'failures' => $validated['failures'] ?? [],
            'started_at' => $validated['started_at'],
            'finished_at' => $validated['finished_at'],
        ]);

        $settings->put('backup_relay.last_run_at', now()->toIso8601String());
        $settings->put('backup_relay.last_processed_finished_at', $finishedAt->toIso8601String());
        $settings->put('backup_relay.last_run_stats', json_encode([
            'sites_total' => $run->sites_total,
            'sites_archived' => $run->sites_archived,
            'sites_skipped' => $run->sites_skipped,
            'sites_failed' => $run->sites_failed,
            'failures' => $run->failures,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ]));

        $this->info("Recorded relay run: archived={$run->sites_archived} skipped={$run->sites_skipped} failed={$run->sites_failed}.");

        return self::SUCCESS;
    }

    /**
     * Runs regardless of whether pullReport() found anything new — a
     * validation failure or a missing S3 object is itself evidence the
     * relay might be in trouble, so staleness still needs checking on
     * those paths too, not just the happy path.
     */
    private function checkStaleness(Settings $settings, ChatNotifier $chatNotifier): void
    {
        $lastRun = BackupRelayRun::query()->latest('finished_at')->first();

        // Never ran at all — a fresh, not-yet-configured environment, not a
        // stuck one. Nothing to alert on.
        if ($lastRun === null) {
            return;
        }

        $lastRunAt = $lastRun->finished_at;
        $daysSince = (int) $lastRunAt->diffInDays(Carbon::now());

        $frequency = (string) $settings->get('backup_relay.frequency', config('clockwork.backup_relay.frequency', 'weekly'));
        $staleThreshold = match ($frequency) {
            'weekly' => 10,
            'twice_weekly' => 6,
            'daily' => 3,
            default => self::STALE_DAYS,
        };
        $isStale = $daysSince >= $staleThreshold;

        $alertSince = (string) $settings->get('backup_relay.stale_alert_since', '');
        $wasStale = $alertSince !== '';

        if ($isStale && ! $wasStale) {
            $this->warn("Relay stale: last run {$daysSince} day(s) ago (finished {$lastRunAt->toIso8601String()}).");
            $settings->put('backup_relay.stale_alert_since', Carbon::now()->toIso8601String());
            $chatNotifier->backupRelayStale($daysSince, $lastRunAt);
        } elseif (! $isStale && $wasStale) {
            $this->info("Relay recovered: last run {$daysSince} day(s) ago.");
            $settings->put('backup_relay.stale_alert_since', null);
            $chatNotifier->backupRelayRecovered();
        }
    }
}
