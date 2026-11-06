<?php

namespace Modules\BackupRelay\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps each site's last backup relay attempt on the site row
 * (backup_relay_last_attempt_at / _last_error / _failing_since), so a failing
 * site is visible in the UI without access to the application log. The
 * scheduled run only logs and chats its failures, and its stdout is not kept.
 */
class RelayAttemptRecorder
{
    /** Errors are operator-facing one-liners; cap a runaway response body. */
    public const MAX_ERROR_LENGTH = 2000;

    /**
     * Column updates for a successful archive: clears any failure streak.
     *
     * @return array<string, mixed>
     */
    public static function successAttributes(): array
    {
        return [
            'backup_relay_last_attempt_at' => now(),
            'backup_relay_last_error' => null,
            'backup_relay_failing_since' => null,
        ];
    }

    /**
     * Record a failed attempt. failing_since keeps the start of the current
     * streak. Never throws: losing this bookkeeping must not mask the
     * original failure.
     */
    public static function recordFailure(Site $site, string $error): void
    {
        try {
            $error = trim($error) !== '' ? trim($error) : 'Backup relay attempt failed.';

            $site->forceFill([
                'backup_relay_last_attempt_at' => now(),
                'backup_relay_last_error' => mb_substr($error, 0, self::MAX_ERROR_LENGTH),
                'backup_relay_failing_since' => $site->backup_relay_failing_since ?? now(),
            ])->save();
        } catch (Throwable $e) {
            Log::warning("BackupRelay: Could not record failed attempt for {$site->domain}: {$e->getMessage()}");
        }
    }
}
