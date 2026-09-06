<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\LlarInstaller;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class InstallLlar extends Command
{
    protected $signature = 'clockwork:install-llar
        {--site= : Limit to a specific site ID or domain}
        {--all-missing : Process every WordPress site where llar_enabled is false}';

    protected $description = 'Install Limit Login Attempts Reloaded on WordPress sites and disable its lockout email feature. Sites that already have LLAR are left untouched.';

    public function handle(LlarInstaller $installer): int
    {
        if (! $this->option('site') && ! $this->option('all-missing')) {
            $this->error('Pass --site=<id|domain> or --all-missing.');

            return self::FAILURE;
        }

        $sites = $this->targetSites();

        if ($sites->isEmpty()) {
            $this->warn('No sites match the given filters.');

            return self::SUCCESS;
        }

        $installed = 0;
        $alreadyPresent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($sites as $site) {
            $this->line('');
            $this->line("→ {$site->domain} (server={$site->server?->name})");

            $result = $installer->process($site);

            switch ($result['result']) {
                case LlarInstaller::RESULT_INSTALLED:
                    $this->info("  ✓ {$result['message']}");
                    $installed++;
                    break;
                case LlarInstaller::RESULT_ALREADY_PRESENT:
                    $this->line("  ↺ {$result['message']}");
                    $alreadyPresent++;
                    break;
                case LlarInstaller::RESULT_SKIPPED_NOT_WP:
                    $this->line("  · {$result['message']}");
                    $skipped++;
                    break;
                default:
                    $this->error("  ✗ {$result['message']}");
                    $failed++;
            }

            if (! empty($result['output']) && $this->getOutput()->isVerbose()) {
                $this->line('  --- wp-cli output ---');
                foreach (explode("\n", $result['output']) as $line) {
                    $this->line('  '.$line);
                }
            }
        }

        $this->line('');
        $this->info("Done. installed={$installed}, already-present={$alreadyPresent}, skipped={$skipped}, failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, Site> */
    private function targetSites()
    {
        $q = Site::query()->with('server')->where('is_wordpress', true);

        if ($siteOpt = $this->option('site')) {
            $q->where(function ($q) use ($siteOpt) {
                $q->where('id', is_numeric($siteOpt) ? (int) $siteOpt : 0)
                    ->orWhere('domain', $siteOpt);
            });
        } elseif ($this->option('all-missing')) {
            $q->where('llar_enabled', false);
        }

        return $q->orderBy('domain')->get();
    }
}
