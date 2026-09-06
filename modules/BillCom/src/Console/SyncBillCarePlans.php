<?php

namespace Modules\BillCom\Console;

use App\Models\ActionLog;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Console\Command;
use Modules\BillCom\BillComClient;
use Modules\BillCom\CarePlanSyncService;
use Throwable;

/**
 * Walks Bill.com Items + recent invoices to determine which customers are
 * actively on a care plan, then flips care_plan_enabled on linked sites.
 * Respects care_plan_override — sites with a manual override are never touched.
 *
 * Scheduled daily at 01:30 (after sync-bill-customers at 01:00 so the customer
 * cache + site links are fresh).
 */
class SyncBillCarePlans extends Command
{
    protected $signature = 'clockwork:sync-bill-care-plans
                            {--dry-run : Print what would change without writing}';

    protected $description = 'Sync care_plan_enabled flag based on Bill.com invoice line items referencing care-plan Items.';

    public function handle(BillComClient $client, ActionLogger $logger): int
    {
        // Note: the scheduler gates on `clockwork.bill_com.enabled` in
        // routes/console.php. Manual artisan invocations are always allowed
        // so testing + ad-hoc resyncs work regardless of the flag.
        $dryRun = (bool) $this->option('dry-run');
        $windowDays = (int) config('clockwork.bill_com.care_plan_window_days', 60);
        $itemRegex = (string) config('clockwork.bill_com.care_plan_item_regex', '/care plan/i');

        $this->line(($dryRun ? '[DRY RUN] ' : '')."Walking invoices from last {$windowDays} days, regex {$itemRegex}...");

        $start = microtime(true);
        try {
            $stats = (new CarePlanSyncService($client))->sync($windowDays, $itemRegex, $dryRun);
        } catch (Throwable $e) {
            $this->error("Sync failed: {$e->getMessage()}");
            $logger->record(
                actionType: ActionLog::TYPE_BILL_COM_SYNC,
                summary: 'Bill.com care plan sync FAILED.',
                target: 'care_plans',
                ok: false,
                error: $e->getMessage(),
                actor: 'scheduled',
            );

            return self::FAILURE;
        }
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $this->info(sprintf(
            '%sItems %d (%d care-plan) · %d active domains + %d active customers (annual fallback) · sites: +%d / -%d · %d skipped (manual override) · %d care-plan line items had no domain · %dms',
            $dryRun ? '[DRY RUN] ' : '',
            $stats['items_synced'],
            $stats['care_plan_items_count'],
            $stats['active_care_plan_domains'],
            $stats['active_care_plan_customers'],
            $stats['sites_set_true'],
            $stats['sites_set_false'],
            $stats['sites_skipped_due_to_override'],
            $stats['care_plan_lineitems_without_domain'],
            $elapsedMs
        ));

        if (! empty($stats['unmatched_care_plan_domains'])) {
            $this->line('Care-plan domains with no matching Site (probably not in Clockwork yet):');
            foreach ($stats['unmatched_care_plan_domains'] as $domain => $_) {
                $this->line("  {$domain}");
            }
        }

        $logger->record(
            actionType: ActionLog::TYPE_BILL_COM_SYNC,
            summary: sprintf(
                '%sBill.com care plan sync: %d sites set on, %d off, %d skipped (override).',
                $dryRun ? '[dry run] ' : '',
                $stats['sites_set_true'],
                $stats['sites_set_false'],
                $stats['sites_skipped_due_to_override']
            ),
            target: 'care_plans',
            details: $stats,
            elapsedMs: $elapsedMs,
            actor: 'scheduled',
        );

        return self::SUCCESS;
    }
}
