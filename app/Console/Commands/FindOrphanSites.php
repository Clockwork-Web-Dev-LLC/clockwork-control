<?php

namespace App\Console\Commands;

use App\Services\Sites\OrphanSiteFinder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clockwork:find-orphan-sites {--archive-consolidated : Auto-archive orphans whose domain is now an additional_domain on another live site}')]
#[Description('Finds local Site rows whose SpinupWP linkage has been lost; classifies each as consolidated under another live site or unknown.')]
class FindOrphanSites extends Command
{
    public function handle(OrphanSiteFinder $finder): int
    {
        $results = $finder->find();
        if ($results->isEmpty()) {
            $this->info('No orphan sites found. Fleet is clean.');

            return self::SUCCESS;
        }

        $persisted = $finder->persist($results);

        $consolidated = $results->where('classification', 'consolidated');
        $unknown = $results->where('classification', 'unknown');

        $this->newLine();
        $this->info('Consolidated under another live site (safe to archive):');
        if ($consolidated->isEmpty()) {
            $this->line('  (none)');
        } else {
            foreach ($consolidated as $r) {
                $this->line(sprintf(
                    '  - %s (#%d) → consolidated under %s (#%d)',
                    $r['site']->domain,
                    $r['site']->id,
                    $r['parent']->domain,
                    $r['parent']->id,
                ));
            }
        }

        $this->newLine();
        $this->info('Unknown — needs manual review (no SpinupWP site claims this domain):');
        if ($unknown->isEmpty()) {
            $this->line('  (none)');
        } else {
            foreach ($unknown as $r) {
                $this->line(sprintf('  - %s (#%d)', $r['site']->domain, $r['site']->id));
            }
        }

        $this->newLine();
        if ($this->option('archive-consolidated') && $consolidated->isNotEmpty()) {
            $archived = $finder->autoArchiveConsolidated($results);
            $this->info("Archived {$archived} consolidated orphan(s).");
        }

        $this->info(sprintf(
            'Summary: total=%d consolidated=%d unknown=%d (consolidation links persisted: %d)',
            $results->count(),
            $consolidated->count(),
            $unknown->count(),
            $persisted,
        ));

        return self::SUCCESS;
    }
}
