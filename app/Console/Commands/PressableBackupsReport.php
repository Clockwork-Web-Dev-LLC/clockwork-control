<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\BackupRelay\Services\BackupArchiveEnumerator;
use Modules\Pressable\PressableClient;
use Throwable;

/**
 * Pulls per-site backup history from Pressable and POSTs it to each
 * Companion-equipped Pressable site's /backups-report endpoint — the
 * Pressable counterpart to clockwork:push-companion-backups.
 *
 * Uses the two dedicated per-type endpoints (siteFilesystemBackups() /
 * siteDatabaseBackups()), NOT the combined siteBackups() endpoint — the
 * combined one only returns a short recent-pairing window. The per-type
 * endpoints return real depth (confirmed live against an established site:
 * daily filesystem backups tapering to weekly, going back months) with a
 * real size embedded in each entry's title string.
 *
 * Filesystem and database backups run on different, independent cadences
 * (daily vs. hourly) — reported as two separate lists rather than forced
 * into paired rows, matching Pressable's own UI (separate Filesystem/
 * Database sections).
 */
class PressableBackupsReport extends Command
{
    protected $signature = 'clockwork:pressable-backups-report
        {--site= : Limit to a single site (id or domain)}';

    protected $description = 'Push Pressable backup history to each Companion-equipped Pressable site for client visibility.';

    public function handle(PressableClient $pressable): int
    {
        if (! $pressable->isConfigured()) {
            $this->error('Pressable API credentials not configured.');

            return self::FAILURE;
        }

        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->warn('No Companion-equipped Pressable sites matched.');

            return self::SUCCESS;
        }

        $offsiteArchive = $this->loadOffsiteArchiveManifest();

        $stats = ['ok' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($sites as $site) {
            $caps = $site->companion_capabilities ?? [];
            if (! in_array('backups-report', $caps, true)) {
                $stats['skipped']++;
                $this->line("  [skip] {$site->domain} — Companion v1.3.0+ not installed");

                continue;
            }

            try {
                $fsRows = $pressable->siteFilesystemBackups($site->pressable_site_id);
                $dbRows = $pressable->siteDatabaseBackups($site->pressable_site_id);
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('companion.pressable_backups_report.fetch_failed', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  [fail] {$site->domain}: Pressable fetch failed — {$e->getMessage()}");

                continue;
            }

            $historyFiles = $this->toHistoryRows($fsRows);
            $historyDatabase = $this->toHistoryRows($dbRows);

            $newestFiles = $historyFiles[0]['date'] ?? null;
            $newestDatabase = $historyDatabase[0]['date'] ?? null;
            $lastBackupAt = $this->maxDate($newestFiles, $newestDatabase);

            $report = [
                'source' => 'pressable',
                'fetched_at' => now()->toIso8601String(),
                'config' => [
                    'files' => $historyFiles !== [],
                    'database' => $historyDatabase !== [],
                    'next_run_time' => null,
                    'storage_provider' => null,
                    'paths_to_exclude' => null,
                ],
                'schedules' => [],
                'history_files' => $historyFiles,
                'history_database' => $historyDatabase,
                'last_backup_at' => $lastBackupAt,
                'care_plan_enabled' => (bool) $site->care_plan_enabled,
                // Depth here genuinely varies by how long the site's existed
                // (daily tapering to weekly for filesystem, hourly for
                // database) — not a fixed promise we control, unlike
                // SpinupWP+Spaces. Companion renders "history_scope:
                // available" as "here's everything your host currently
                // exposes," not a day-count guarantee.
                'history_scope' => 'available',
                'offsite_archive' => $this->offsiteArchiveFor($site->domain, $offsiteArchive, $site),
            ];

            try {
                (new ClockworkCompanionClient($site))->pushBackupsReport($report);
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('companion.pressable_backups_report.push_failed', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  [fail] {$site->domain}: push failed — {$e->getMessage()}");

                continue;
            }

            $stats['ok']++;
            $this->line("  [ok]   {$site->domain} — files=".count($historyFiles).' database='.count($historyDatabase));
        }

        $msg = sprintf(
            'companion.pressable_backups_report.push complete: ok=%d failed=%d skipped=%d',
            $stats['ok'],
            $stats['failed'],
            $stats['skipped'],
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }

    /**
     * Pressable embeds a human-readable size in each entry's `title`
     * (e.g. "Sat, 29 Aug 2026 00:00:00 UTC - 272.92 MB") rather than
     * exposing a separate structured bytes field. `backup_timestamp` is a
     * plain "YYYY-MM-DD HH:MM:SS" string, directly strtotime-able. Sorted
     * newest-first defensively rather than trusting API order.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function toHistoryRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $ts = strtotime((string) ($row['backup_timestamp'] ?? ''));
            if ($ts === false) {
                continue;
            }

            $out[] = [
                'date' => gmdate(\DateTimeInterface::ATOM, $ts),
                // 'automatic', not 'scheduled' — the latter reads as future-
                // tense ("this WILL happen") next to a row that's already a
                // completed, historical backup. Confusing on a history table.
                'type' => str_contains((string) ($row['title'] ?? ''), '(on-demand)') ? 'on-demand' : 'automatic',
                'bytes' => $this->parseBytesFromTitle((string) ($row['title'] ?? '')),
                'notes' => null,
                '_sort' => $ts,
            ];
        }

        usort($out, fn ($a, $b) => $b['_sort'] <=> $a['_sort']);

        return array_map(function ($row) {
            unset($row['_sort']);

            return $row;
        }, $out);
    }

    private function parseBytesFromTitle(string $title): ?int
    {
        if (! preg_match('/([\d.]+)\s*(KB|MB|GB|TB)\b/i', $title, $m)) {
            return null;
        }

        $value = (float) $m[1];
        $multiplier = match (strtoupper($m[2])) {
            'KB' => 1024,
            'MB' => 1024 ** 2,
            'GB' => 1024 ** 3,
            'TB' => 1024 ** 4,
            default => 1,
        };

        return (int) round($value * $multiplier);
    }

    private function maxDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return strtotime($a) >= strtotime($b) ? $a : $b;
    }

    /**
     * Reads the standalone backup-relay droplet's download-links manifest
     * from S3 — the same S3-mediated handoff clockwork:pull-backup-relay-report
     * uses, no direct connection to that droplet either way (see
     * app/Console/Commands/PushBackupRelayTargets.php's docblock). Only
     * care-plan Pressable sites ever appear in this manifest at all — it's
     * generated from the same site list this app itself pushed, so a
     * site's mere presence here already proves it's enrolled; nothing else
     * needs to re-check care_plan_enabled.
     *
     * @return array{sites: array<string, array{fs_download_url: ?string, db_download_url: ?string}>, generated_at: ?string, expires_at: ?string}
     */
    private function loadOffsiteArchiveManifest(): array
    {
        $empty = ['sites' => [], 'generated_at' => null, 'expires_at' => null];

        $key = rtrim((string) config('clockwork.backup_relay.s3_prefix'), '/').'/download-links.json';
        $disk = Storage::disk('s3');

        if (! $disk->exists($key)) {
            return $empty;
        }

        try {
            $data = json_decode((string) $disk->get($key), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('companion.pressable_backups_report.offsite_manifest_unreadable', ['error' => $e->getMessage()]);

            return $empty;
        }

        return [
            'sites' => is_array($data['sites'] ?? null) ? $data['sites'] : [],
            'generated_at' => isset($data['generated_at']) ? (string) $data['generated_at'] : null,
            'expires_at' => isset($data['expires_at']) ? (string) $data['expires_at'] : null,
        ];
    }

    /**
     * @param  array{sites: array<string, array{fs_download_url: ?string, db_download_url: ?string}>, generated_at: ?string, expires_at: ?string}  $manifest
     * @return ?array{active: bool, last_archived_at: ?string, fs_download_url: ?string, db_download_url: ?string, download_expires_at: ?string}
     */
    private function offsiteArchiveFor(string $domain, array $manifest, ?Site $site = null): ?array
    {
        if (isset($manifest['sites'][$domain])) {
            $links = $manifest['sites'][$domain];

            return [
                'active' => true,
                'last_archived_at' => $manifest['generated_at'],
                'fs_download_url' => $links['fs_download_url'] ?? null,
                'db_download_url' => $links['db_download_url'] ?? null,
                'download_expires_at' => $manifest['expires_at'],
            ];
        }

        if ($site && $site->backup_relay_enabled) {
            try {
                $enumerator = app(BackupArchiveEnumerator::class);

                // This payload is pushed to the Companion plugin's client-facing
                // wp-admin backups page — a link only works there if it's a real
                // presigned S3 URL. Without one, getDownloadUrl() falls back to
                // an operator-authenticated Clockwork Control route, which would
                // just bounce a client to our login screen. Skip enrichment
                // entirely rather than hand a client a dead-end link.
                if (! $enumerator->supportsPresignedUrls()) {
                    return null;
                }

                $siteData = $enumerator->forSite($site);
                if (! empty($siteData['archives'])) {
                    $fsUrl = null;
                    $dbUrl = null;
                    foreach ($siteData['archives'] as $arch) {
                        if ($arch['type'] === 'fs' && ! $fsUrl) {
                            $fsUrl = $arch['download_url'];
                        } elseif ($arch['type'] === 'db' && ! $dbUrl) {
                            $dbUrl = $arch['download_url'];
                        } elseif (($arch['type'] === 'full' || $arch['type'] === 'archive') && ! $fsUrl) {
                            $fsUrl = $arch['download_url'];
                        }
                    }

                    return [
                        'active' => true,
                        'last_archived_at' => $siteData['last_archived_at'],
                        'fs_download_url' => $fsUrl,
                        'db_download_url' => $dbUrl,
                        'download_expires_at' => now()->addHours(BackupArchiveEnumerator::DOWNLOAD_URL_TTL_HOURS)->toIso8601String(),
                    ];
                }
            } catch (Throwable $e) {
                Log::debug("PressableBackupsReport: Failed to enumerate archives for {$domain}: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * @return Collection<int, Site>
     */
    private function resolveSites(): Collection
    {
        $q = Site::query()
            ->where('companion_installed', true)
            ->whereNotNull('companion_secret')
            ->where('hosting_provider', Site::HOSTING_PROVIDER_PRESSABLE)
            ->whereNotNull('pressable_site_id');

        if ($needle = $this->option('site')) {
            $q->where(function ($q) use ($needle) {
                $q->where('id', $needle)->orWhere('domain', $needle);
            });
        }

        return $q->orderBy('domain')->get();
    }
}
