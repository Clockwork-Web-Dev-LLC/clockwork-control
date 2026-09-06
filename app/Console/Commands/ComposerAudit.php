<?php

namespace App\Console\Commands;

use App\Services\Chat\ChatNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Wraps `composer audit --format=json` so we can surface known CVEs in our
 * dependencies via the same notification channel as everything else.
 *
 * Composer's audit command checks every installed package against the
 * FriendsOfPHP/security-advisories database — covers Laravel, phpseclib3,
 * Guzzle, Symfony, and every transitive dep. Catches "we're on a vulnerable
 * version" without requiring you to track changelogs manually.
 */
class ComposerAudit extends Command
{
    protected $signature = 'clockwork:composer-audit
        {--silent : Suppress Mattermost notifications even when advisories exist}';

    protected $description = 'Run `composer audit` and surface any advisories. Scheduled weekly.';

    public function handle(ChatNotifier $mattermost): int
    {
        $composer = $this->resolveComposer();
        if (! $composer) {
            $this->error('Could not find a composer binary on PATH.');
            Log::warning('composer_audit.missing_binary');

            return self::FAILURE;
        }

        $process = new Process([$composer, 'audit', '--format=json', '--no-interaction'], base_path());
        $process->setTimeout(120);
        $process->run();

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());

        // composer audit exits 0 when no advisories, non-zero otherwise.
        // Either way we get JSON on stdout. Parse it.
        $payload = json_decode($stdout, true);
        if (! is_array($payload)) {
            $this->error('Could not parse `composer audit` output.');
            $this->line($stdout ?: $stderr);
            Log::warning('composer_audit.parse_failed', ['stdout' => $stdout, 'stderr' => $stderr]);

            return self::FAILURE;
        }

        $advisories = $payload['advisories'] ?? [];
        $abandoned = $payload['abandoned'] ?? [];

        $advisoryCount = is_array($advisories) ? array_sum(array_map('count', $advisories)) : 0;
        $abandonedCount = is_array($abandoned) ? count($abandoned) : 0;

        $msg = "composer.audit advisories={$advisoryCount} abandoned={$abandonedCount}";
        Log::info($msg);
        $this->info($msg);

        if ($advisoryCount === 0 && $abandonedCount === 0) {
            $this->info('No advisories. Dependencies are clean.');

            return self::SUCCESS;
        }

        // Pretty-print advisories.
        if (is_array($advisories)) {
            foreach ($advisories as $package => $items) {
                if (! is_array($items)) {
                    continue;
                }
                foreach ($items as $advisory) {
                    $cve = $advisory['cve'] ?? $advisory['advisoryId'] ?? '(no id)';
                    $title = $advisory['title'] ?? '(no title)';
                    $version = $advisory['affectedVersions'] ?? '?';
                    $this->warn("{$package} {$version}: {$cve} — {$title}");
                }
            }
        }
        if (is_array($abandoned) && $abandoned !== []) {
            $this->line('');
            $this->warn('Abandoned packages: '.implode(', ', array_keys($abandoned)));
        }

        if (! $this->option('silent') && $advisoryCount > 0) {
            $packageList = is_array($advisories) ? implode(', ', array_keys($advisories)) : '?';
            $mattermost->send(sprintf(
                ':warning: **composer audit** found %d advisory %s in: %s',
                $advisoryCount,
                $advisoryCount === 1 ? 'item' : 'items',
                $packageList,
            ));
        }

        return self::FAILURE;
    }

    private function resolveComposer(): ?string
    {
        // Herd path takes precedence on this Mac.
        // Read HOME directly from the environment — this is a path resolver, not a Laravel
        // config concern, so config()-caching doesn't apply.
        $herd = (getenv('HOME') ?: '').'/Library/Application Support/Herd/bin/composer';
        if (is_executable($herd)) {
            return $herd;
        }
        $found = trim((string) shell_exec('command -v composer 2>/dev/null'));

        return $found !== '' ? $found : null;
    }
}
