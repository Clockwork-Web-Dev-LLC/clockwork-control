<?php

namespace App\Services\Updates;

use App\Models\Site;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Service for managing Clockwork Control Core self-updates,
 * checking upstream GitHub releases, tracking the companion plugin
 * fleet deployment state, and safely running operator-triggered updates.
 */
class SystemUpdateService
{
    public const CACHE_KEY_RELEASE = 'clockwork.system_updates.latest_release';

    public const SETTING_LAST_CHECKED = 'updates.last_checked_at';

    public function __construct(
        private readonly Settings $settings,
    ) {}

    /**
     * Current installed Clockwork Control version.
     */
    public function getCurrentVersion(): string
    {
        $version = (string) config('clockwork.version', '1.1.0');

        return ltrim($version, 'v');
    }

    /**
     * Inspect local Git repository state if available.
     *
     * @return array{is_git: bool, branch: ?string, commit: ?string, is_clean: bool, commit_date: ?string}
     */
    public function getGitInfo(): array
    {
        $basePath = base_path();
        if (! is_dir($basePath.'/.git')) {
            return [
                'is_git' => false,
                'branch' => null,
                'commit' => null,
                'is_clean' => true,
                'commit_date' => null,
            ];
        }

        try {
            $branch = trim(Process::path($basePath)->run('git rev-parse --abbrev-ref HEAD')->output()) ?: 'main';
            $commit = trim(Process::path($basePath)->run('git rev-parse --short HEAD')->output()) ?: null;
            $isClean = trim(Process::path($basePath)->run('git status --porcelain')->output()) === '';
            $commitDate = trim(Process::path($basePath)->run('git log -1 --format=%cd --date=relative')->output()) ?: null;

            return [
                'is_git' => true,
                'branch' => $branch,
                'commit' => $commit,
                'is_clean' => $isClean,
                'commit_date' => $commitDate,
            ];
        } catch (Throwable $e) {
            Log::debug('Could not inspect git repository state: '.$e->getMessage());

            return [
                'is_git' => true,
                'branch' => null,
                'commit' => null,
                'is_clean' => true,
                'commit_date' => null,
            ];
        }
    }

    /**
     * Timestamp of the last time updates were checked.
     */
    public function getLastCheckedAt(): ?Carbon
    {
        $raw = $this->settings->get(self::SETTING_LAST_CHECKED);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Check for Clockwork Control updates against the upstream release channel.
     *
     * @param  bool  $force  If true, ignores cache and forces a fresh query.
     * @return array{
     *   current_version: string,
     *   latest_version: string,
     *   has_update: bool,
     *   release_name: ?string,
     *   release_notes: ?string,
     *   published_at: ?Carbon,
     *   checked_at: ?Carbon,
     *   html_url: ?string,
     *   channel: string,
     *   git: array,
     *   error: ?string,
     * }
     */
    public function checkForUpdates(bool $force = false): array
    {
        $currentVersion = $this->getCurrentVersion();
        $gitInfo = $this->getGitInfo();
        $channel = (string) config('clockwork.updates.channel', 'stable');
        $cacheTtl = (int) config('clockwork.updates.cache_ttl', 43200);

        if (! $force) {
            $cached = Cache::get(self::CACHE_KEY_RELEASE);
            if (is_array($cached)) {
                $lastChecked = $this->getLastCheckedAt();

                return [
                    'current_version' => $currentVersion,
                    'latest_version' => $cached['version'] ?? $currentVersion,
                    'has_update' => (bool) ($cached['has_update'] ?? false),
                    'release_name' => $cached['name'] ?? null,
                    'release_notes' => $cached['notes'] ?? null,
                    'published_at' => ! empty($cached['published_at']) ? Carbon::parse($cached['published_at']) : null,
                    'checked_at' => $lastChecked,
                    'html_url' => $cached['html_url'] ?? null,
                    'channel' => $channel,
                    'git' => $gitInfo,
                    'error' => null,
                ];
            }
        }

        $apiUrl = (string) config(
            'clockwork.updates.api_url',
            'https://api.github.com/repos/Clockwork-Web-Dev-LLC/clockwork-control/releases/latest'
        );

        $errorMsg = null;
        $latestVersion = $currentVersion;
        $hasUpdate = false;
        $releaseName = null;
        $releaseNotes = null;
        $publishedAt = null;
        $htmlUrl = null;

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'Clockwork-Control/'.$currentVersion,
                ])
                ->get($apiUrl);

            if ($response->successful()) {
                $data = $response->json();
                $rawTag = (string) ($data['tag_name'] ?? '');
                $latestVersion = ltrim($rawTag, 'v') ?: $currentVersion;
                $releaseName = (string) ($data['name'] ?? 'Version '.$latestVersion);
                $releaseNotes = (string) ($data['body'] ?? '');
                $htmlUrl = (string) ($data['html_url'] ?? '');

                if (! empty($data['published_at'])) {
                    $publishedAt = Carbon::parse($data['published_at']);
                }

                // If remote tag is newer than current installed version
                $hasUpdate = version_compare($latestVersion, $currentVersion, '>');

                // Store in cache
                Cache::put(self::CACHE_KEY_RELEASE, [
                    'version' => $latestVersion,
                    'has_update' => $hasUpdate,
                    'name' => $releaseName,
                    'notes' => $releaseNotes,
                    'published_at' => $publishedAt?->toIso8601String(),
                    'html_url' => $htmlUrl,
                ], $cacheTtl);
            } else {
                $errorMsg = 'Could not retrieve latest release from update server (HTTP '.$response->status().').';
            }
        } catch (Throwable $e) {
            Log::warning('Clockwork update check failed: '.$e->getMessage());
            $errorMsg = 'Could not reach update server. Please check your internet connection.';
        }

        $checkedAt = now();
        $this->settings->put(self::SETTING_LAST_CHECKED, $checkedAt->toIso8601String());

        return [
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'has_update' => $hasUpdate,
            'release_name' => $releaseName,
            'release_notes' => $releaseNotes,
            'published_at' => $publishedAt,
            'checked_at' => $checkedAt,
            'html_url' => $htmlUrl,
            'channel' => $channel,
            'git' => $gitInfo,
            'error' => $errorMsg,
        ];
    }

    /**
     * Overview of the Companion plugin fleet rollout across client WordPress sites.
     *
     * @return array{
     *   bundled_version: string,
     *   total_sites: int,
     *   installed_sites: int,
     *   up_to_date: int,
     *   needs_update: int,
     *   uninstalled: int,
     *   versions: array<string, int>,
     * }
     */
    public function getCompanionFleetStatus(): array
    {
        $bundledVersion = ltrim((string) config('clockwork.companion.version', '1.33.0'), 'v');

        $sites = Site::query()
            ->hostMonitored()
            ->get(['id', 'domain', 'companion_installed', 'companion_version']);

        $totalSites = $sites->count();
        $installedSites = $sites->where('companion_installed', true);
        $uninstalled = $totalSites - $installedSites->count();

        $upToDate = 0;
        $needsUpdate = 0;
        $versions = [];

        foreach ($installedSites as $site) {
            $siteVer = ltrim((string) $site->companion_version, 'v');
            if ($siteVer === '') {
                $siteVer = 'unknown';
            }

            $versions[$siteVer] = ($versions[$siteVer] ?? 0) + 1;

            if ($siteVer !== 'unknown' && version_compare($siteVer, $bundledVersion, '>=')) {
                $upToDate++;
            } else {
                $needsUpdate++;
            }
        }

        return [
            'bundled_version' => $bundledVersion,
            'total_sites' => $totalSites,
            'installed_sites' => $installedSites->count(),
            'up_to_date' => $upToDate,
            'needs_update' => $needsUpdate,
            'uninstalled' => $uninstalled,
            'versions' => $versions,
        ];
    }

    /**
     * Operator-triggered execution of the application update pipeline.
     *
     * Runs preflight checks, pulls latest code via git if in git repository,
     * updates composer dependencies, executes migrations, and warms caches.
     *
     * @return array{success: bool, steps: list<array{step: string, success: bool, output: string}>, error?: string}
     */
    public function applyUpdate(): array
    {
        $basePath = base_path();
        $gitInfo = $this->getGitInfo();
        $steps = [];

        // 1. Preflight safety check
        if ($gitInfo['is_git'] && ! $gitInfo['is_clean']) {
            return [
                'success' => false,
                'steps' => [],
                'error' => 'Update aborted: You have uncommitted local file modifications in your repository. Please commit or stash your changes before applying an update.',
            ];
        }

        // 2. Git Fetch & Pull (if git repo)
        if ($gitInfo['is_git']) {
            $branch = $gitInfo['branch'] ?: 'main';
            $result = Process::path($basePath)->timeout(120)->run(['git', 'pull', 'origin', $branch]);

            $success = $result->successful();
            $steps[] = [
                'step' => 'Pulling latest updates from git (origin/'.$branch.')',
                'success' => $success,
                'output' => trim($result->output().' '.$result->errorOutput()),
            ];

            if (! $success) {
                return [
                    'success' => false,
                    'steps' => $steps,
                    'error' => 'Git pull failed: '.trim($result->errorOutput() ?: $result->output()),
                ];
            }
        }

        // 3. Composer dependencies (new/updated packages the pulled release may need)
        $composerResult = Process::path($basePath)->timeout(300)->run(['composer', 'install', '--no-dev', '--optimize-autoloader']);
        $composerSuccess = $composerResult->successful();
        $steps[] = [
            'step' => 'Installing updated dependencies (composer install --no-dev)',
            'success' => $composerSuccess,
            'output' => trim($composerResult->output().' '.$composerResult->errorOutput()),
        ];

        if (! $composerSuccess) {
            return [
                'success' => false,
                'steps' => $steps,
                'error' => 'Composer install failed: '.trim($composerResult->errorOutput() ?: $composerResult->output()),
            ];
        }

        // 4. Database migrations
        try {
            Artisan::call('migrate', ['--force' => true]);
            $migrateOutput = Artisan::output();
            $steps[] = [
                'step' => 'Running database migrations (php artisan migrate --force)',
                'success' => true,
                'output' => trim($migrateOutput) ?: 'Nothing to migrate.',
            ];
        } catch (Throwable $e) {
            $steps[] = [
                'step' => 'Running database migrations',
                'success' => false,
                'output' => $e->getMessage(),
            ];

            return [
                'success' => false,
                'steps' => $steps,
                'error' => 'Migration failed: '.$e->getMessage(),
            ];
        }

        // 5. Cache clear & optimization
        try {
            Artisan::call('optimize:clear');
            $optimizeOutput = Artisan::output();
            $steps[] = [
                'step' => 'Rebuilding system caches (php artisan optimize:clear)',
                'success' => true,
                'output' => trim($optimizeOutput) ?: 'Caches cleared successfully.',
            ];
        } catch (Throwable $e) {
            $steps[] = [
                'step' => 'Rebuilding system caches',
                'success' => true, // Non-fatal
                'output' => 'Notice: '.$e->getMessage(),
            ];
        }

        // 6. Bust update cache so status reflects the update
        Cache::forget(self::CACHE_KEY_RELEASE);
        $this->settings->put(self::SETTING_LAST_CHECKED, now()->toIso8601String());

        return [
            'success' => true,
            'steps' => $steps,
        ];
    }
}
