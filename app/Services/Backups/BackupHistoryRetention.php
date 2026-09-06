<?php

namespace App\Services\Backups;

/**
 * Shared retention window policy for backup history pushed to Companion:
 * 90 days on a care plan, 30 days otherwise. Used by both the SpinupWP and
 * Pressable backup-report commands so the promise stays consistent (and
 * changes to it only need to happen in one place) regardless of host.
 */
class BackupHistoryRetention
{
    /**
     * Drop history rows older than $days. Permissive parser — rows without a
     * recognisable date are kept so a malformed entry doesn't silently vanish
     * (the page surfaces it as "—" and the agency notices).
     *
     * @param  array<int, array<string, mixed>>  $history
     * @return array<int, array<string, mixed>>
     */
    public static function filter(array $history, int $days): array
    {
        $cutoff = now()->subDays($days);
        $kept = [];
        foreach ($history as $row) {
            $date = $row['date'] ?? null;
            if (! is_string($date) || $date === '') {
                $kept[] = $row;

                continue;
            }
            $ts = strtotime($date);
            if ($ts === false) {
                $kept[] = $row;

                continue;
            }
            if ($ts >= $cutoff->getTimestamp()) {
                $kept[] = $row;
            }
        }

        return $kept;
    }
}
