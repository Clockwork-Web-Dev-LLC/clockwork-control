<?php

namespace Modules\BackupRelay;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Diagnostic probe for Backup Relay S3 connectivity.
 */
class BackupRelayCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'backup-relay';
    }

    public function name(): string
    {
        return 'Backup Relay (S3 Glacier)';
    }

    public function description(): string
    {
        return 'Verify S3 bucket connectivity for offsite backup archive storage.';
    }

    public function run(): CheckResult
    {
        $diskName = (string) config('clockwork.backup_relay.disk', 's3-backup-relay');
        $bucket = (string) config("filesystems.disks.{$diskName}.bucket");

        if ($bucket === '') {
            return CheckResult::skipped('No S3_BACKUP_RELAY_BUCKET configured');
        }

        $start = microtime(true);
        try {
            $disk = Storage::disk($diskName);
            $s3Prefix = (string) config('clockwork.backup_relay.s3_prefix', '_control/backup-relay');
            $files = $disk->files($s3Prefix);
            $ms = (int) ((microtime(true) - $start) * 1000);

            $count = count($files);

            return CheckResult::ok("Bucket \"{$bucket}\" reachable · {$count} manifest(s) found", null, $ms);
        } catch (Throwable $e) {
            $ms = (int) ((microtime(true) - $start) * 1000);

            return CheckResult::fail('S3 connection failed', $e->getMessage(), $ms);
        }
    }
}
