<?php

namespace App\Services\Backups;

use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\SiteBackupCheck;
use App\Services\DigitalOcean\SpacesClient;
use Illuminate\Support\Carbon;
use Modules\BackupRelay\Services\BackupArchiveEnumerator;
use Modules\Core\Contracts\DirectS3BackupRelayAdapter;
use Modules\Pressable\PressableClient;
use Throwable;

/**
 * Records each site's backups from wherever they really live — Pressable's
 * backup API, SpinupWP's DigitalOcean Spaces bucket, or our own S3 archives
 * for sites we don't host — into site_backups, plus a site_backup_checks row
 * saying whether the read worked. Client reports are compiled from these
 * rows, never from a setting that says backups "should" be happening.
 */
class SiteBackupRecorder
{
    public function __construct(
        protected PressableClient $pressable,
        protected SpacesClient $spaces,
        protected BackupArchiveEnumerator $archives,
    ) {}

    /** The source this site's backups should be read from, or null if we have none. */
    public function sourceFor(Site $site): ?string
    {
        return match (true) {
            $site->hosting_provider === Site::HOSTING_PROVIDER_PRESSABLE && $site->pressable_site_id => SiteBackup::SOURCE_PRESSABLE,
            $site->hosting_provider === Site::HOSTING_PROVIDER_SPINUPWP => SiteBackup::SOURCE_SPINUPWP_SPACES,
            $site->backup_relay_enabled && $this->usesDirectArchives($site) => SiteBackup::SOURCE_S3_ARCHIVE,
            default => null,
        };
    }

    /**
     * @return array{source: string, ok: bool, seen: int, error: ?string}|null null when the site has no backup source
     */
    public function record(Site $site): ?array
    {
        $source = $this->sourceFor($site);
        if ($source === null) {
            return null;
        }

        try {
            $rows = match ($source) {
                SiteBackup::SOURCE_PRESSABLE => $this->fromPressable($site),
                SiteBackup::SOURCE_SPINUPWP_SPACES => $this->fromSpaces($site),
                SiteBackup::SOURCE_S3_ARCHIVE => $this->fromArchives($site),
            };

            $now = now();
            if ($source === SiteBackup::SOURCE_SPINUPWP_SPACES) {
                // A run seen mid-upload (database only) is the same run once
                // its files land, so its row is replaced rather than doubled.
                foreach ($rows as $row) {
                    SiteBackup::query()
                        ->where('site_id', $site->id)
                        ->where('source', $source)
                        ->where('external_ref', $row['external_ref'])
                        ->where('kind', '!=', $row['kind'])
                        ->delete();
                }
            }
            if ($rows !== []) {
                SiteBackup::query()->upsert(
                    array_map(fn (array $row) => $row + [
                        'site_id' => $site->id,
                        'source' => $source,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                    ], $rows),
                    ['site_id', 'source', 'kind', 'external_ref'],
                    ['backup_at', 'bytes', 'is_manual', 'last_seen_at'],
                );
            }
        } catch (Throwable $e) {
            $this->recordCheck($site, $source, false, 0, $e->getMessage());

            return ['source' => $source, 'ok' => false, 'seen' => 0, 'error' => $e->getMessage()];
        }

        $this->recordCheck($site, $source, true, count($rows), null);

        return ['source' => $source, 'ok' => true, 'seen' => count($rows), 'error' => null];
    }

    /**
     * Files are daily and kept as-is. Database backups are hourly, so only the
     * newest of each day is kept — a report lists days, not hours. That row is
     * keyed by its date, not Pressable's id: a run early in the day sees only
     * the first few hours, and the next run's newer pick replaces it.
     *
     * @return list<array{kind: string, external_ref: string, backup_at: Carbon, bytes: ?int, is_manual: bool}>
     */
    protected function fromPressable(Site $site): array
    {
        $rows = [];
        foreach ([
            SiteBackup::KIND_FILES => $this->pressable->siteFilesystemBackups($site->pressable_site_id),
            SiteBackup::KIND_DATABASE => $this->pressable->siteDatabaseBackups($site->pressable_site_id),
        ] as $kind => $backups) {
            $byDay = [];
            foreach ($backups as $backup) {
                $timestamp = (string) ($backup['backup_timestamp'] ?? '');
                if ($timestamp === '') {
                    continue;
                }
                // Pressable's timestamps carry no zone but are UTC (the title says so).
                $at = Carbon::parse($timestamp, 'UTC');
                $title = (string) ($backup['title'] ?? '');
                $row = [
                    'kind' => $kind,
                    'external_ref' => $kind === SiteBackup::KIND_DATABASE
                        ? 'day:'.$at->toDateString()
                        : (string) ($backup['id'] ?? $timestamp),
                    'backup_at' => $at,
                    'bytes' => self::bytesFromTitle($title),
                    'is_manual' => str_contains($title, '(on-demand)'),
                ];

                if ($kind === SiteBackup::KIND_FILES) {
                    $rows[] = $row;
                } elseif (! isset($byDay[$at->toDateString()]) || $byDay[$at->toDateString()]['backup_at']->lessThan($at)) {
                    $byDay[$at->toDateString()] = $row;
                }
            }
            array_push($rows, ...array_values($byDay));
        }

        return $rows;
    }

    /** @return list<array{kind: string, external_ref: string, backup_at: Carbon, bytes: ?int, is_manual: bool}> */
    protected function fromSpaces(Site $site): array
    {
        $rows = [];
        foreach ($this->spaces->toHistoryRows($this->spaces->listSiteBackupObjectsOrFail($site)) as $run) {
            // A zero-byte object is a failed upload, so that half is missing.
            $database = ($run['database_bytes'] ?? 0) > 0 ? (int) $run['database_bytes'] : null;
            $files = ($run['files_bytes'] ?? 0) > 0 ? (int) $run['files_bytes'] : null;
            if ($database === null && $files === null) {
                continue;
            }
            $rows[] = [
                // Say only what's actually there: a run can have just one half.
                'kind' => match (true) {
                    $database !== null && $files !== null => SiteBackup::KIND_FULL,
                    $database !== null => SiteBackup::KIND_DATABASE,
                    default => SiteBackup::KIND_FILES,
                },
                'external_ref' => (string) $run['date'],
                'backup_at' => Carbon::parse($run['date'])->utc(),
                'bytes' => (int) $database + (int) $files,
                'is_manual' => false,
            ];
        }

        return $rows;
    }

    /** @return list<array{kind: string, external_ref: string, backup_at: Carbon, bytes: ?int, is_manual: bool}> */
    protected function fromArchives(Site $site): array
    {
        $rows = [];
        foreach ($this->archives->listArchiveObjects($site) as $archive) {
            // An empty object is a failed upload, not a backup.
            if ($archive['archived_at'] === null || (int) $archive['bytes'] <= 0) {
                continue;
            }
            $rows[] = [
                'kind' => $archive['type'],
                'external_ref' => $archive['key'],
                'backup_at' => Carbon::instance($archive['archived_at']),
                'bytes' => (int) $archive['bytes'],
                // Backup Now writes a timestamped key; the scheduled run writes a date-only one.
                'is_manual' => (bool) preg_match('/\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}/', basename($archive['key'])),
            ];
        }

        return $rows;
    }

    protected function usesDirectArchives(Site $site): bool
    {
        try {
            return $site->host()->backupRelayAdapter() instanceof DirectS3BackupRelayAdapter;
        } catch (Throwable) {
            return false;
        }
    }

    protected function recordCheck(Site $site, string $source, bool $ok, int $seen, ?string $error): void
    {
        SiteBackupCheck::query()->updateOrCreate(
            ['site_id' => $site->id, 'source' => $source],
            ['checked_at' => now(), 'ok' => $ok, 'backups_seen' => $seen, 'error' => $error === null ? null : mb_substr($error, 0, 1000)],
        );
    }

    /** Pressable embeds the size in the title ("… UTC - 272.92 MB"); there's no structured field. */
    public static function bytesFromTitle(string $title): ?int
    {
        if (! preg_match('/([\d.]+)\s*(KB|MB|GB|TB)\b/i', $title, $m)) {
            return null;
        }

        return (int) round((float) $m[1] * match (strtoupper($m[2])) {
            'KB' => 1024,
            'MB' => 1024 ** 2,
            'GB' => 1024 ** 3,
            'TB' => 1024 ** 4,
            default => 1,
        });
    }
}
