<?php

namespace Modules\BillCom\Console;

use App\Models\ActionLog;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Console\Command;
use Modules\BillCom\BillComClient;
use Modules\BillCom\CustomerSyncService;
use Throwable;

/**
 * Pulls Bill.com customers + recent invoices, links sites to customers based
 * on domains found in invoice line item descriptions.
 *
 * Scheduled daily at 01:00 (clear of the 02:00/02:30 Security scan window).
 */
class SyncBillCustomers extends Command
{
    protected $signature = 'clockwork:sync-bill-customers
                            {--dry-run : Print what would change without writing}';

    protected $description = 'Sync Bill.com customers + link sites to customers via invoice line item descriptions.';

    public function handle(BillComClient $client, ActionLogger $logger): int
    {
        // Note: the scheduler gates on `clockwork.bill_com.enabled` in
        // routes/console.php. Manual artisan invocations are always allowed
        // so testing + ad-hoc resyncs work regardless of the flag.
        $dryRun = (bool) $this->option('dry-run');
        $windowDays = (int) config('clockwork.bill_com.customer_link_window_days', 365);

        $this->line(($dryRun ? '[DRY RUN] ' : '')."Pulling customers + invoices from last {$windowDays} days...");

        $start = microtime(true);
        try {
            $stats = (new CustomerSyncService($client))->sync($windowDays, $dryRun);
        } catch (Throwable $e) {
            $this->error("Sync failed: {$e->getMessage()}");
            $logger->record(
                actionType: ActionLog::TYPE_BILL_COM_SYNC,
                summary: 'Bill.com customer sync FAILED.',
                target: 'customers',
                ok: false,
                error: $e->getMessage(),
                actor: 'scheduled',
            );

            return self::FAILURE;
        }
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $this->info(sprintf(
            '%sSynced %d customers · linked %d sites · re-linked %d · %d unmatched domains · %dms',
            $dryRun ? '[DRY RUN] ' : '',
            $stats['customers_synced'],
            $stats['sites_linked'],
            $stats['sites_relinked'],
            count($stats['unmatched_domains']),
            $elapsedMs
        ));

        if (! empty($stats['unmatched_domains'])) {
            $this->line('Unmatched domains (extracted from invoices but no Site row exists):');
            foreach ($stats['unmatched_domains'] as $domain => $hits) {
                $this->line("  {$domain} ({$hits} hit".($hits === 1 ? '' : 's').')');
            }
        }

        $logger->record(
            actionType: ActionLog::TYPE_BILL_COM_SYNC,
            summary: sprintf(
                '%sBill.com customer sync: %d customers, %d sites linked.',
                $dryRun ? '[dry run] ' : '',
                $stats['customers_synced'],
                $stats['sites_linked']
            ),
            target: 'customers',
            details: $stats,
            elapsedMs: $elapsedMs,
            actor: 'scheduled',
        );

        return self::SUCCESS;
    }
}
