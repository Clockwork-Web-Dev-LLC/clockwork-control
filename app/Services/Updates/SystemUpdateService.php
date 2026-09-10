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

    public const SETTING_LAST_APPLY_RESULT = 'updates.last_apply_result';

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
        $bundledVersion = ltrim((string) config('clockwork.companion.version', '1.34.0'), 'v');

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
     * updates composer dependencies if changed, executes migrations with
     * rollback protection, and warms caches.
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
            $err = 'Update aborted: You have uncommitted local file modifications in your repository. Please commit or stash your changes before applying an update.';
            Log::warning('system_update.aborted_dirty', ['error' => $err]);

            return [
                'success' => false,
                'steps' => [],
                'error' => $err,
            ];
        }

        $subprocessEnv = $this->subprocessEnv();
        $prePullCommit = null;

        // Enter maintenance mode during the update window to prevent race conditions on live requests
        $maintenanceActive = false;
        if ($gitInfo['is_git']) {
            try {
                Artisan::call('down', [
                    '--retry' => 15,
                    '--refresh' => 15,
                ]);
                $maintenanceActive = true;
                Log::info('system_update.maintenance_enabled');
            } catch (Throwable $e) {
                Log::warning('system_update.maintenance_down_failed', ['error' => $e->getMessage()]);
            }
        }

        try {
            // 2. Git Fetch & Pull (if git repo)
            if ($gitInfo['is_git']) {
                $branch = $gitInfo['branch'] ?: 'main';
                $prePullCommit = $this->getCurrentCommitHash();

                Log::info('system_update.started', [
                    'branch' => $branch,
                    'from_commit' => $prePullCommit,
                ]);

                $result = Process::path($basePath)->timeout(120)->env($subprocessEnv)->run(['git', 'pull', 'origin', $branch]);

                $success = $result->successful();
                $pullOutput = trim($result->output().' '.$result->errorOutput());
                $steps[] = [
                    'step' => 'Pulling latest updates from git (origin/'.$branch.')',
                    'success' => $success,
                    'output' => $pullOutput,
                ];

                if (! $success) {
                    Log::error('system_update.git_pull_failed', ['output' => $pullOutput]);
                    $finalResult = [
                        'success' => false,
                        'steps' => $steps,
                        'error' => 'Git pull failed: '.trim($result->errorOutput() ?: $result->output()),
                    ];
                    $this->persistApplyResult($finalResult, $prePullCommit);

                    return $finalResult;
                }

                Log::info('system_update.git_pull_succeeded', ['output' => $pullOutput]);
            }

            $postPullCommit = $this->getCurrentCommitHash();

            // 3. Composer dependencies
            // Only run composer install if composer.json or composer.lock changed in this update.
            $needsComposer = true;
            if ($gitInfo['is_git'] && $prePullCommit && $postPullCommit && $prePullCommit !== $postPullCommit) {
                $diffCheck = Process::path($basePath)->env($subprocessEnv)->run([
                    'git', 'diff', $prePullCommit, $postPullCommit, '--name-only', '--', 'composer.json', 'composer.lock',
                ]);
                if ($diffCheck->successful() && trim($diffCheck->output()) === '') {
                    $needsComposer = false;
                }
            }

            if (! $needsComposer) {
                $steps[] = [
                    'step' => 'Installing updated dependencies (composer install --no-dev)',
                    'success' => true,
                    'output' => 'No dependency changes in this update; skipping composer install.',
                ];
                Log::info('system_update.composer_skipped', ['reason' => 'composer.json and composer.lock unchanged']);
            } else {
                Log::info('system_update.composer_started');
                $composerResult = Process::path($basePath)->timeout(300)->env($subprocessEnv)->run(['composer', 'install', '--no-dev', '--optimize-autoloader']);
                $composerSuccess = $composerResult->successful();
                $composerOutput = trim($composerResult->output().' '.$composerResult->errorOutput());

                if (! $composerSuccess) {
                    Log::error('system_update.composer_failed', ['output' => $composerOutput]);

                    // Roll back working copy so disk isn't stranded on uninstalled dependencies
                    $rollbackNote = '';
                    if ($gitInfo['is_git'] && $prePullCommit) {
                        $rollback = Process::path($basePath)->env($subprocessEnv)->run(['git', 'reset', '--hard', $prePullCommit]);
                        if ($rollback->successful()) {
                            $rollbackNote = " Codebase was automatically rolled back to {$prePullCommit}.";
                            Log::warning('system_update.rolled_back_after_composer_failure', ['to_commit' => $prePullCommit]);
                            try {
                                Artisan::call('optimize:clear');
                            } catch (Throwable) {
                            }
                        } else {
                            $rollbackNote = ' Automatic rollback failed: '.$rollback->errorOutput();
                            Log::error('system_update.rollback_failed', ['error' => $rollback->errorOutput()]);
                        }
                    }

                    $steps[] = [
                        'step' => 'Installing updated dependencies (composer install --no-dev)',
                        'success' => false,
                        'output' => $composerOutput.$rollbackNote,
                    ];

                    $finalResult = [
                        'success' => false,
                        'steps' => $steps,
                        'error' => 'Composer install failed: '.trim($composerResult->errorOutput() ?: $composerResult->output()).$rollbackNote,
                    ];
                    $this->persistApplyResult($finalResult, $prePullCommit, $postPullCommit);

                    return $finalResult;
                }

                $steps[] = [
                    'step' => 'Installing updated dependencies (composer install --no-dev)',
                    'success' => true,
                    'output' => $composerOutput,
                ];
                Log::info('system_update.composer_succeeded');
            }

            // 4. Frontend assets & npm dependencies
            $hasNpm = false;
            try {
                $npmCheck = Process::path($basePath)->env($subprocessEnv)->run(['npm', '--version']);
                $hasNpm = $npmCheck->successful() && trim($npmCheck->output()) !== '';
            } catch (Throwable) {
                $hasNpm = false;
            }

            if ($hasNpm) {
                // Check if package dependencies changed or node_modules is missing
                $needsNpmInstall = ! is_dir($basePath.'/node_modules');
                if (! $needsNpmInstall && $gitInfo['is_git'] && $prePullCommit && $postPullCommit && $prePullCommit !== $postPullCommit) {
                    $pkgDiff = Process::path($basePath)->env($subprocessEnv)->run([
                        'git', 'diff', $prePullCommit, $postPullCommit, '--name-only', '--', 'package.json', 'package-lock.json',
                    ]);
                    if ($pkgDiff->successful() && trim($pkgDiff->output()) !== '') {
                        $needsNpmInstall = true;
                    }
                }

                if ($needsNpmInstall) {
                    Log::info('system_update.npm_install_started');
                    $npmInstallResult = Process::path($basePath)->timeout(300)->env($subprocessEnv)->run(['npm', 'install', '--no-audit', '--no-fund']);
                    $npmInstallSuccess = $npmInstallResult->successful();
                    $npmInstallOutput = trim($npmInstallResult->output().' '.$npmInstallResult->errorOutput());

                    if (! $npmInstallSuccess) {
                        Log::error('system_update.npm_install_failed', ['output' => $npmInstallOutput]);

                        $rollbackNote = '';
                        if ($gitInfo['is_git'] && $prePullCommit) {
                            $rollback = Process::path($basePath)->env($subprocessEnv)->run(['git', 'reset', '--hard', $prePullCommit]);
                            if ($rollback->successful()) {
                                $rollbackNote = " Codebase was automatically rolled back to {$prePullCommit}.";
                                Log::warning('system_update.rolled_back_after_npm_install_failure', ['to_commit' => $prePullCommit]);
                                try {
                                    Artisan::call('optimize:clear');
                                } catch (Throwable) {
                                }
                            } else {
                                $rollbackNote = ' Automatic rollback failed: '.$rollback->errorOutput();
                            }
                        }

                        $steps[] = [
                            'step' => 'Installing frontend dependencies (npm install)',
                            'success' => false,
                            'output' => $npmInstallOutput.$rollbackNote,
                        ];

                        $finalResult = [
                            'success' => false,
                            'steps' => $steps,
                            'error' => 'Frontend dependency install failed: '.trim($npmInstallResult->errorOutput() ?: $npmInstallResult->output()).$rollbackNote,
                        ];
                        $this->persistApplyResult($finalResult, $prePullCommit, $postPullCommit);

                        return $finalResult;
                    }

                    $steps[] = [
                        'step' => 'Installing frontend dependencies (npm install)',
                        'success' => true,
                        'output' => $npmInstallOutput ?: 'Frontend dependencies updated successfully.',
                    ];
                    Log::info('system_update.npm_install_succeeded');
                }

                // Check if frontend build is needed (manifest missing or frontend source files changed)
                $needsBuild = ! file_exists($basePath.'/public/build/manifest.json');
                if (! $needsBuild && $gitInfo['is_git'] && $prePullCommit && $postPullCommit && $prePullCommit !== $postPullCommit) {
                    $assetDiff = Process::path($basePath)->env($subprocessEnv)->run([
                        'git', 'diff', $prePullCommit, $postPullCommit, '--name-only', '--', 'resources/css', 'resources/js', 'vite.config.js', 'package.json', 'package-lock.json',
                    ]);
                    if ($assetDiff->successful() && trim($assetDiff->output()) !== '') {
                        $needsBuild = true;
                    }
                }

                if ($needsBuild) {
                    Log::info('system_update.npm_build_started');
                    $buildResult = Process::path($basePath)->timeout(300)->env($subprocessEnv)->run(['npm', 'run', 'build']);
                    $buildSuccess = $buildResult->successful();
                    $buildOutput = trim($buildResult->output().' '.$buildResult->errorOutput());

                    if (! $buildSuccess) {
                        Log::error('system_update.npm_build_failed', ['output' => $buildOutput]);

                        $rollbackNote = '';
                        if ($gitInfo['is_git'] && $prePullCommit) {
                            $rollback = Process::path($basePath)->env($subprocessEnv)->run(['git', 'reset', '--hard', $prePullCommit]);
                            if ($rollback->successful()) {
                                $rollbackNote = " Codebase was automatically rolled back to {$prePullCommit}.";
                                Log::warning('system_update.rolled_back_after_npm_build_failure', ['to_commit' => $prePullCommit]);
                                try {
                                    Artisan::call('optimize:clear');
                                } catch (Throwable) {
                                }
                            } else {
                                $rollbackNote = ' Automatic rollback failed: '.$rollback->errorOutput();
                            }
                        }

                        $steps[] = [
                            'step' => 'Building frontend production assets (npm run build)',
                            'success' => false,
                            'output' => $buildOutput.$rollbackNote,
                        ];

                        $finalResult = [
                            'success' => false,
                            'steps' => $steps,
                            'error' => 'Frontend asset build failed: '.trim($buildResult->errorOutput() ?: $buildResult->output()).$rollbackNote,
                        ];
                        $this->persistApplyResult($finalResult, $prePullCommit, $postPullCommit);

                        return $finalResult;
                    }

                    $steps[] = [
                        'step' => 'Building frontend production assets (npm run build)',
                        'success' => true,
                        'output' => $buildOutput ?: 'Frontend production assets compiled successfully.',
                    ];
                    Log::info('system_update.npm_build_succeeded');
                } else {
                    $steps[] = [
                        'step' => 'Building frontend production assets (npm run build)',
                        'success' => true,
                        'output' => 'No frontend asset changes in this update; compiled assets are up to date.',
                    ];
                    Log::info('system_update.npm_build_skipped', ['reason' => 'assets unchanged']);
                }
            } else {
                // npm not available
                $steps[] = [
                    'step' => 'Building frontend production assets (npm run build)',
                    'success' => true,
                    'output' => 'Notice: Node.js / npm not detected in environment; skipped asset build.',
                ];
                Log::info('system_update.npm_skipped_not_installed');
            }

            // 5. Database migrations
            try {
                Log::info('system_update.migrate_started');
                $exitCode = Artisan::call('migrate', ['--force' => true]);
                $migrateOutput = trim((string) Artisan::output());
                $migrateSuccess = ($exitCode === 0);

                $steps[] = [
                    'step' => 'Running database migrations (php artisan migrate --force)',
                    'success' => $migrateSuccess,
                    'output' => $migrateOutput ?: ($migrateSuccess ? 'Nothing to migrate.' : 'Migration command failed with non-zero exit code.'),
                ];

                if (! $migrateSuccess) {
                    Log::error('system_update.migrate_failed', [
                        'exit_code' => $exitCode,
                        'output' => $migrateOutput,
                    ]);

                    $rollbackNote = '';
                    if ($gitInfo['is_git'] && $prePullCommit) {
                        $rollback = Process::path($basePath)->env($subprocessEnv)->run(['git', 'reset', '--hard', $prePullCommit]);
                        if ($rollback->successful()) {
                            $rollbackNote = " Codebase was automatically rolled back to {$prePullCommit}.";
                            Log::warning('system_update.rolled_back_after_migrate_failure', ['to_commit' => $prePullCommit]);
                            try {
                                Artisan::call('optimize:clear');
                            } catch (Throwable) {
                            }
                        } else {
                            $rollbackNote = ' Automatic rollback failed: '.$rollback->errorOutput();
                        }
                    }

                    $finalResult = [
                        'success' => false,
                        'steps' => $steps,
                        'error' => 'Migration failed: '.($migrateOutput ?: 'Artisan migrate returned non-zero exit code.').$rollbackNote,
                    ];
                    $this->persistApplyResult($finalResult, $prePullCommit, $postPullCommit);

                    return $finalResult;
                }

                Log::info('system_update.migrate_succeeded', ['output' => $migrateOutput]);
            } catch (Throwable $e) {
                Log::error('system_update.migrate_exception', ['error' => $e->getMessage()]);

                $rollbackNote = '';
                if ($gitInfo['is_git'] && $prePullCommit) {
                    $rollback = Process::path($basePath)->env($subprocessEnv)->run(['git', 'reset', '--hard', $prePullCommit]);
                    if ($rollback->successful()) {
                        $rollbackNote = " Codebase was automatically rolled back to {$prePullCommit}.";
                        Log::warning('system_update.rolled_back_after_migrate_exception', ['to_commit' => $prePullCommit]);
                        try {
                            Artisan::call('optimize:clear');
                        } catch (Throwable) {
                        }
                    }
                }

                $steps[] = [
                    'step' => 'Running database migrations',
                    'success' => false,
                    'output' => $e->getMessage().$rollbackNote,
                ];

                $finalResult = [
                    'success' => false,
                    'steps' => $steps,
                    'error' => 'Migration failed: '.$e->getMessage().$rollbackNote,
                ];
                $this->persistApplyResult($finalResult, $prePullCommit, $postPullCommit);

                return $finalResult;
            }

            // 6. Cache clear & optimization
            try {
                Artisan::call('optimize:clear');
                $optimizeOutput = trim((string) Artisan::output());
                $steps[] = [
                    'step' => 'Rebuilding system caches (php artisan optimize:clear)',
                    'success' => true,
                    'output' => $optimizeOutput ?: 'Caches cleared successfully.',
                ];
                Log::info('system_update.optimize_cleared');
            } catch (Throwable $e) {
                $steps[] = [
                    'step' => 'Rebuilding system caches',
                    'success' => true, // Non-fatal
                    'output' => 'Notice: '.$e->getMessage(),
                ];
                Log::warning('system_update.optimize_clear_failed', ['error' => $e->getMessage()]);
            }

            // 7. Bust update cache so status reflects the update
            Cache::forget(self::CACHE_KEY_RELEASE);
            $this->settings->put(self::SETTING_LAST_CHECKED, now()->toIso8601String());

            $finalResult = [
                'success' => true,
                'steps' => $steps,
            ];
            $this->persistApplyResult($finalResult, $prePullCommit, $postPullCommit);
            Log::info('system_update.completed', ['steps_count' => count($steps)]);

            return $finalResult;
        } finally {
            if ($maintenanceActive) {
                try {
                    Artisan::call('up');
                    Log::info('system_update.maintenance_disabled');
                } catch (Throwable $e) {
                    Log::error('system_update.maintenance_up_failed', ['error' => $e->getMessage()]);
                }
            }
        }
    }

    /**
     * Current commit hash on disk.
     */
    public function getCurrentCommitHash(): ?string
    {
        $basePath = base_path();
        if (! is_dir($basePath.'/.git')) {
            return null;
        }

        try {
            $commit = trim(Process::path($basePath)->run(['git', 'rev-parse', 'HEAD'])->output());

            return $commit !== '' ? $commit : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Retrieve the persistent record of the last update attempt.
     *
     * @return ?array{success: bool, steps: list<array{step: string, success: bool, output: string}>, error?: string, applied_at: string, version: string, from_commit?: ?string, to_commit?: ?string}
     */
    public function getLastApplyResult(): ?array
    {
        $raw = $this->settings->get(self::SETTING_LAST_APPLY_RESULT);

        return is_array($raw) ? $raw : null;
    }

    private function persistApplyResult(array $result, ?string $fromCommit = null, ?string $toCommit = null): void
    {
        try {
            $payload = array_merge($result, [
                'applied_at' => now()->toIso8601String(),
                'version' => $this->getCurrentVersion(),
                'from_commit' => $fromCommit,
                'to_commit' => $toCommit,
            ]);
            $this->settings->put(self::SETTING_LAST_APPLY_RESULT, $payload);
        } catch (Throwable $e) {
            Log::warning('system_update.persist_result_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * HOME/COMPOSER_HOME and augmented PATH to explicitly merge into
     * subprocesses. Ensures that composer and git can write their caches
     * and are resolved across all runtime environments (CLI, Herd, Homebrew,
     * Valet, launchd).
     *
     * @return array<string, string>
     */
    public function subprocessEnv(): array
    {
        $home = getenv('HOME') ?: null;
        $composerHome = getenv('COMPOSER_HOME') ?: null;

        $fallbackHome = storage_path('app/subprocess-home');
        if (! is_dir($fallbackHome)) {
            @mkdir($fallbackHome, 0755, recursive: true);
        }

        $effectiveHome = $home ?: $fallbackHome;
        $effectiveComposerHome = $composerHome ?: $fallbackHome.'/composer';

        $currentPath = (string) (getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin');
        $extraPaths = [
            '/opt/homebrew/bin',
            '/opt/homebrew/sbin',
            '/usr/local/bin',
            '/usr/local/sbin',
            $effectiveHome.'/.config/herd/bin',
            $effectiveHome.'/Library/Application Support/Herd/bin',
            $effectiveHome.'/.composer/vendor/bin',
            $effectiveHome.'/.nvm/current/bin',
            $effectiveHome.'/.volta/bin',
            $effectiveHome.'/.asdf/shims',
            $effectiveHome.'/.bun/bin',
        ];

        $pathSegments = explode(':', $currentPath);
        foreach ($extraPaths as $extraPath) {
            if (! in_array($extraPath, $pathSegments, true)) {
                array_unshift($pathSegments, $extraPath);
            }
        }

        return [
            'HOME' => $effectiveHome,
            'COMPOSER_HOME' => $effectiveComposerHome,
            'PATH' => implode(':', $pathSegments),
        ];
    }
}
