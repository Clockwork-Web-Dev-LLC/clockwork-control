<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\BackupRelay\Services\BackupArchiveEnumerator;
use Throwable;

#[Signature('clockwork:backup-restore {--site= : Site ID} {--key= : Archive S3 key} {--phase= : Phase (stage or apply)} {--actor= : User email or actor initiating the restore}')]
#[Description('Orchestrate staging or applying an off-site backup restore via Companion.')]
class BackupRestoreCommand extends Command
{
    public const POLL_INTERVAL_SECONDS = 5;

    public static int $pollIntervalSeconds = self::POLL_INTERVAL_SECONDS;

    public const MAX_POLL_SECONDS = 600;

    public static int $maxPollSeconds = self::MAX_POLL_SECONDS;

    /** Cache-state statuses that mean a restore is actively in flight. */
    public const IN_FLIGHT_STATUSES = ['downloading', 'verifying', 'extracting', 'scanning', 'applying_sql', 'applying_files', 'finalizing'];

    public function handle(ActionLogger $logger, BackupArchiveEnumerator $enumerator): int
    {
        $siteId = $this->option('site');
        $site = Site::find($siteId);

        if (! $site) {
            $this->error("Site not found: {$siteId}");

            return self::FAILURE;
        }

        if (! $site->isCustom()) {
            $this->error("Site {$site->domain} is not a custom/standalone site.");

            return self::FAILURE;
        }

        if (! $site->backup_relay_enabled) {
            $this->error("Backup relay is not enabled for {$site->domain}.");

            return self::FAILURE;
        }

        if (! in_array('backup-restore', $site->companion_capabilities ?? [], true)) {
            $this->error("Site {$site->domain} lacks backup-restore companion capability.");

            return self::FAILURE;
        }

        if (empty($site->companion_secret)) {
            $this->error("Site {$site->domain} has no companion_secret.");

            return self::FAILURE;
        }

        if (! $enumerator->supportsPresignedUrls()) {
            $this->error('Configured backup relay disk does not support presigned URLs.');

            return self::FAILURE;
        }

        $actor = (string) ($this->option('actor') ?: 'system');
        $phase = strtolower(trim((string) $this->option('phase')));
        $cacheKey = "backup_restore.site.{$site->id}.state";

        if ($phase === 'stage') {
            return $this->handleStage($site, $logger, $enumerator, $actor, $cacheKey);
        }

        if ($phase === 'apply') {
            return $this->handleApply($site, $logger, $actor, $cacheKey);
        }

        $this->error("Invalid phase: '{$phase}'. Must be 'stage' or 'apply'.");

        return self::FAILURE;
    }

    private function handleStage(Site $site, ActionLogger $logger, BackupArchiveEnumerator $enumerator, string $actor, string $cacheKey): int
    {
        $key = trim((string) $this->option('key'));
        if ($key === '') {
            $this->error('Missing required --key for stage phase.');

            return self::FAILURE;
        }

        if (! $enumerator->belongsToSite($site, $key)) {
            $this->error("Archive key is not under this site's prefix: {$key}");

            return self::FAILURE;
        }

        // Decision D1: hash resolution (sidecar -> newest archive last_sha256 -> refuse)
        $sha256 = $enumerator->resolveArchiveSha256($site, $key);
        if (empty($sha256)) {
            $errMsg = "No integrity hash on record for archive {$key}.";
            $this->error($errMsg);

            Cache::put($cacheKey, [
                'phase' => 'stage',
                'status' => 'failed',
                'archive_key' => $key,
                'filename' => basename($key),
                'error' => 'no_hash',
                'error_detail' => $errMsg,
            ], now()->addHours(2));

            $logger->record(
                actionType: ActionLog::TYPE_BACKUP_RESTORE_FAILED,
                summary: "Backup restore stage rejected for {$site->domain}: no integrity hash on record",
                site: $site,
                target: $key,
                details: ['error' => 'no_hash', 'archive_key' => $key],
                actor: $actor
            );

            return self::FAILURE;
        }

        $downloadUrl = $enumerator->getDownloadUrl($site, $key);
        if (empty($downloadUrl)) {
            $this->error("Could not generate download URL for {$key}.");

            return self::FAILURE;
        }

        $client = new ClockworkCompanionClient($site);

        Cache::put($cacheKey, [
            'phase' => 'stage',
            'status' => 'downloading',
            'archive_key' => $key,
            'filename' => basename($key),
            'expected_sha256' => $sha256,
        ], now()->addHours(2));

        try {
            $client->stageBackupRestore([
                'download_url' => $downloadUrl,
                'archive_key' => $key,
                'expected_sha256' => $sha256,
            ]);
        } catch (Throwable $e) {
            // Connection timeout at ~55-60s is expected behind gateways; continue to status polling.
            Log::info("BackupRestore: stageBackupRestore request returned/timed out for {$site->domain}: {$e->getMessage()}");
        }

        $finalState = $this->pollUntilComplete($client, $cacheKey, ['staged', 'failed']);

        if (($finalState['status'] ?? '') === 'staged') {
            $this->info("Backup restore staged successfully for {$site->domain}.");

            $logger->record(
                actionType: ActionLog::TYPE_BACKUP_RESTORE_STAGED,
                summary: "Staged backup restore for {$site->domain} ({$key})",
                site: $site,
                target: $key,
                details: [
                    'archive_key' => $key,
                    'sha256' => $sha256,
                    'staged_id' => $finalState['staged_id'] ?? null,
                    'has_sql' => $finalState['has_sql'] ?? false,
                    'has_files' => $finalState['has_files'] ?? false,
                    'table_prefix' => $finalState['table_prefix'] ?? null,
                ],
                actor: $actor
            );

            return self::SUCCESS;
        }

        $errorMsg = $finalState['error_detail'] ?? ($finalState['error'] ?? 'Stage failed');
        $this->error("Backup restore stage failed for {$site->domain}: {$errorMsg}");

        $logger->record(
            actionType: ActionLog::TYPE_BACKUP_RESTORE_FAILED,
            summary: "Backup restore stage failed for {$site->domain}: {$errorMsg}",
            site: $site,
            target: $key,
            details: $finalState,
            actor: $actor
        );

        return self::FAILURE;
    }

    private function handleApply(Site $site, ActionLogger $logger, string $actor, string $cacheKey): int
    {
        $cachedState = Cache::get($cacheKey);
        $stagedId = $cachedState['staged_id'] ?? null;

        if (empty($stagedId) || ($cachedState['status'] ?? '') !== 'staged') {
            $this->error("No staged restore ready to apply for {$site->domain}.");

            return self::FAILURE;
        }

        $archiveKey = (string) ($cachedState['archive_key'] ?? '');
        $requestedKey = trim((string) $this->option('key'));

        // The staged state is the source of truth for WHAT gets restored; the
        // apply request must name the same archive so a stale staged state can
        // never silently restore something the operator did not just confirm.
        if ($requestedKey === '' || ! hash_equals($archiveKey, $requestedKey)) {
            $errMsg = "Archive key mismatch: staged restore is for '{$archiveKey}' but apply requested '{$requestedKey}'. Discard and re-stage the restore.";
            $this->error($errMsg);

            Cache::put($cacheKey, array_merge($cachedState, [
                'status' => 'failed',
                'error' => 'archive_key_mismatch',
                'error_detail' => $errMsg,
            ]), now()->addHours(2));

            $logger->record(
                actionType: ActionLog::TYPE_BACKUP_RESTORE_FAILED,
                summary: "Backup restore apply rejected for {$site->domain}: archive key mismatch",
                site: $site,
                target: $archiveKey,
                details: ['error' => 'archive_key_mismatch', 'archive_key' => $archiveKey, 'requested_key' => $requestedKey],
                actor: $actor
            );

            return self::FAILURE;
        }

        $client = new ClockworkCompanionClient($site);

        Cache::put($cacheKey, array_merge($cachedState, [
            'phase' => 'apply',
            'status' => 'applying_sql',
        ]), now()->addHours(2));

        try {
            $client->applyBackupRestore($stagedId, $archiveKey);
        } catch (Throwable $e) {
            Log::info("BackupRestore: applyBackupRestore request returned/timed out for {$site->domain}: {$e->getMessage()}");
        }

        $finalState = $this->pollUntilComplete($client, $cacheKey, ['applied', 'failed']);

        if (($finalState['status'] ?? '') === 'applied') {
            $this->info("Backup restore applied successfully for {$site->domain}.");

            $logger->record(
                actionType: ActionLog::TYPE_BACKUP_RESTORE_APPLIED,
                summary: "Applied backup restore for {$site->domain} ({$archiveKey})",
                site: $site,
                target: $archiveKey,
                details: [
                    'archive_key' => $archiveKey,
                    'staged_id' => $stagedId,
                ],
                actor: $actor
            );

            return self::SUCCESS;
        }

        // Fail closed: the apply POST was dispatched, so Companion may have
        // engaged maintenance mode before things went sideways (sql_failed,
        // prefix_mismatch, files_failed, poll timeout, unreachable Companion).
        // Unless the last observed Companion status affirmatively reports
        // maintenance disabled, assume the site was left in maintenance mode.
        if (($finalState['maintenance'] ?? null) !== false) {
            $finalState['maintenance_left_on'] = true;
            Cache::put($cacheKey, $finalState, now()->addHours(2));
        }

        $errorMsg = $finalState['error_detail'] ?? ($finalState['error'] ?? 'Apply failed');
        $this->error("Backup restore apply failed for {$site->domain}: {$errorMsg}");

        $logger->record(
            actionType: ActionLog::TYPE_BACKUP_RESTORE_FAILED,
            summary: "Backup restore apply failed for {$site->domain}: {$errorMsg}",
            site: $site,
            target: $archiveKey,
            details: $finalState,
            actor: $actor
        );

        return self::FAILURE;
    }

    /**
     * @param  array<int, string>  $terminalStatuses
     * @return array<string, mixed>
     */
    private function pollUntilComplete(ClockworkCompanionClient $client, string $cacheKey, array $terminalStatuses): array
    {
        $startTime = time();
        $latest = (array) Cache::get($cacheKey, []);

        while (time() - $startTime < static::$maxPollSeconds) {
            try {
                $statusRes = $client->backupRestoreStatus();
                if ($statusRes !== []) {
                    $latest = array_merge($latest, $statusRes);
                    Cache::put($cacheKey, $latest, now()->addHours(2));

                    if (in_array($latest['status'] ?? '', $terminalStatuses, true)) {
                        return $latest;
                    }
                }
            } catch (Throwable $e) {
                Log::debug("BackupRestore: poll status error: {$e->getMessage()}");
            }

            if (static::$pollIntervalSeconds > 0) {
                sleep(static::$pollIntervalSeconds);
            }
        }

        $latest['status'] = 'failed';
        $latest['error'] = 'timeout';
        $latest['error_detail'] = 'Operation timed out while waiting for Companion.';
        Cache::put($cacheKey, $latest, now()->addHours(2));

        return $latest;
    }
}
