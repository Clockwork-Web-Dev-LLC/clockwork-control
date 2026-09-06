<?php

namespace App\Console\Commands;

use App\Services\Companion\CompanionTarballBuilder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Local-only helper. Tars the Companion source from CLOCKWORK_COMPANION_LOCAL_PATH
 * (default ~/Projects/clockwork-companion), writes the .tgz + .sha256 next to
 * it, and prints the env-var values to flip into dist mode.
 *
 * Why this exists: production should not source plugin code from
 * the operator's own machine on every install. dist mode (CLOCKWORK_COMPANION_DIST_URL +
 * CLOCKWORK_COMPANION_DIST_SHA256) requires a hosted, hash-pinned artifact —
 * this command builds that artifact deterministically so the publish step is
 * "tar locally, upload to wherever, paste two env vars".
 *
 * Hosting is out of scope here — the operator picks DO Spaces, GitHub release,
 * their own web server, anything HTTPS-reachable. Trust boundary is the URL
 * + the sha256.
 */
class BuildCompanionTarball extends Command
{
    protected $signature = 'clockwork:build-companion-tarball
                            {--out= : Directory to write the .tgz + .sha256 (default: same dir as the source)}';

    protected $description = 'Build a publishable Companion tarball + sha256 for use with CLOCKWORK_COMPANION_DIST_URL.';

    public function handle(CompanionTarballBuilder $builder): int
    {
        $localPath = (string) config('clockwork.companion.local_path');
        if ($localPath === '' || ! is_dir($localPath)) {
            $this->error("CLOCKWORK_COMPANION_LOCAL_PATH not set or not a directory ({$localPath}).");

            return self::FAILURE;
        }

        $version = $this->readVersion($localPath);
        if ($version === null) {
            $this->error('Could not read CLOCKWORK_COMPANION_VERSION from clockwork-companion.php.');

            return self::FAILURE;
        }

        try {
            $bytes = $builder->buildLocalTarballBytes($localPath);
        } catch (Throwable $e) {
            $this->error('Tarball build failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $hash = hash('sha256', $bytes);
        $outDir = (string) ($this->option('out') ?: dirname($localPath));
        if (! is_dir($outDir)) {
            $this->error("Output directory does not exist: {$outDir}");

            return self::FAILURE;
        }

        $tarPath = rtrim($outDir, '/')."/clockwork-companion-{$version}.tgz";
        $hashPath = $tarPath.'.sha256';

        if (file_put_contents($tarPath, $bytes) === false) {
            $this->error("Could not write tarball to {$tarPath}.");

            return self::FAILURE;
        }
        if (file_put_contents($hashPath, $hash."\n") === false) {
            @unlink($tarPath);
            $this->error("Could not write sha256 to {$hashPath}.");

            return self::FAILURE;
        }

        $this->info("Built {$tarPath} (".number_format(strlen($bytes)).' bytes)');
        $this->info("sha256 → {$hash}");
        $this->line('');
        $this->line('Next steps:');
        $this->line("  1. Upload {$tarPath} to your hosted location (DO Spaces, GitHub release, etc.)");
        $this->line('  2. Set in your .env:');
        $this->line('       CLOCKWORK_COMPANION_DIST_URL=https://your-host.example/clockwork-companion-'.$version.'.tgz');
        $this->line("       CLOCKWORK_COMPANION_DIST_SHA256={$hash}");
        $this->line('  3. Run a test install: `php artisan clockwork:install-companion <site>`');
        $this->line('  4. Confirm sites.companion_version updates.');

        return self::SUCCESS;
    }

    private function readVersion(string $localPath): ?string
    {
        $loader = $localPath.'/clockwork-companion.php';
        if (! is_file($loader)) {
            return null;
        }
        $contents = (string) file_get_contents($loader);
        if (! preg_match("/define\\(\\s*'CLOCKWORK_COMPANION_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $contents, $m)) {
            return null;
        }

        return $m[1];
    }
}
