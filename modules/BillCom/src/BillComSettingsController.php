<?php

namespace Modules\BillCom;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

/**
 * /settings/bill-com — credentials status + last sync info + manual run buttons.
 *
 * Credentials live in .env (CLOCKWORK_BILL_COM_*); we never display them. The
 * page just shows whether they're configured + the last sync run summary
 * (read from the ActionLog table — same source the maintenance-history page
 * uses, so we don't double-track).
 *
 * The "Run sync now" buttons run synchronously and reload the page. For the
 * fleet size today (~150 sites, ~50 customers, ~hundreds of invoices/year)
 * the full sync completes in well under a minute.
 */
class BillComSettingsController extends Controller
{
    public function index(): View
    {
        $configured = $this->credentialsConfigured();
        $enabled = (bool) config('clockwork.bill_com.enabled', false);

        $lastRun = ActionLog::query()
            ->where('action_type', ActionLog::TYPE_BILL_COM_SYNC)
            ->orderByDesc('ran_at')
            ->limit(10)
            ->get();

        $linkedSiteCount = Site::query()
            ->whereNotNull('bill_com_customer_id')
            ->count();

        $totalSiteCount = Site::query()->count();

        $cachedCustomerCount = BillComCustomer::query()->count();
        $carePlanItems = BillComCarePlanItem::query()
            ->where('is_care_plan', true)
            ->orderBy('name')
            ->get();

        return view('settings.bill-com', [
            'configured' => $configured,
            'enabled' => $enabled,
            'lastRun' => $lastRun,
            'linkedSiteCount' => $linkedSiteCount,
            'totalSiteCount' => $totalSiteCount,
            'cachedCustomerCount' => $cachedCustomerCount,
            'carePlanItems' => $carePlanItems,
            'carePlanItemRegex' => (string) config('clockwork.bill_com.care_plan_item_regex'),
        ]);
    }

    public function runCustomerSync(BillComClient $client): RedirectResponse
    {
        if (! $this->credentialsConfigured()) {
            return back()->with('bill_com_error', 'Bill.com credentials are not configured. Set CLOCKWORK_BILL_COM_* in .env first.');
        }

        try {
            $stats = (new CustomerSyncService($client))->sync(
                (int) config('clockwork.bill_com.customer_link_window_days', 365)
            );
        } catch (Throwable $e) {
            return back()->with('bill_com_error', "Customer sync failed: {$e->getMessage()}");
        }

        return back()->with('bill_com_status', sprintf(
            'Customer sync complete: %d customers cached, %d sites linked, %d unmatched domains.',
            $stats['customers_synced'],
            $stats['sites_linked'],
            count($stats['unmatched_domains'])
        ));
    }

    public function runCarePlanSync(BillComClient $client): RedirectResponse
    {
        if (! $this->credentialsConfigured()) {
            return back()->with('bill_com_error', 'Bill.com credentials are not configured. Set CLOCKWORK_BILL_COM_* in .env first.');
        }

        try {
            $stats = (new CarePlanSyncService($client))->sync(
                (int) config('clockwork.bill_com.care_plan_window_days', 60),
                (string) config('clockwork.bill_com.care_plan_item_regex', '/care plan/i'),
            );
        } catch (Throwable $e) {
            return back()->with('bill_com_error', "Care plan sync failed: {$e->getMessage()}");
        }

        return back()->with('bill_com_status', sprintf(
            'Care plan sync complete: +%d sites set on, -%d off, %d skipped (manual override).',
            $stats['sites_set_true'],
            $stats['sites_set_false'],
            $stats['sites_skipped_due_to_override']
        ));
    }

    private function credentialsConfigured(): bool
    {
        foreach (['username', 'password', 'org_id', 'dev_key'] as $key) {
            if (empty(config("clockwork.bill_com.{$key}"))) {
                return false;
            }
        }

        return true;
    }
}
