<?php

namespace Modules\BillCom;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Console\Scheduling\Schedule;
use Modules\BillCom\Console\BillComTest;
use Modules\BillCom\Console\SyncBillCarePlans;
use Modules\BillCom\Console\SyncBillCustomers;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;

/**
 * Ties care_plan_enabled to Bill.com's invoicing data — see
 * resources/docs/integrations/bill-com.md for the full sync design.
 */
class BillComServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(BillComClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new BillComClient(
                username: $r->get('bill_com.username'),
                password: $r->get('bill_com.password'),
                orgId: $r->get('bill_com.org_id'),
                devKey: $r->get('bill_com.dev_key'),
                baseUrl: $r->get('bill_com.base_url', 'https://api.bill.com/api/v2'),
                timeout: (int) $r->get('bill_com.timeout', 30),
            );
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->commands([
            BillComTest::class,
            SyncBillCustomers::class,
            SyncBillCarePlans::class,
        ]);
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'bill_com',
            name: 'Bill.com',
            description: 'Links sites to Bill.com customers via invoice line items and flips care_plan_enabled based on active care-plan invoicing. Agency-specific — see CONTRIBUTING.md.',
            credentialFields: [
                'username' => ['label' => 'Username', 'secret' => false],
                'password' => ['label' => 'Password', 'secret' => true],
                'org_id' => ['label' => 'Organization ID', 'secret' => false],
                'dev_key' => ['label' => 'Developer Key', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified and in active daily use for agency billing sync.',
        );
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(BillComCheck::class);
    }

    public function navItems(): array
    {
        $r = $this->app->make(CredentialResolver::class);

        if (! (bool) $r->get('bill_com.enabled', false)) {
            return [];
        }

        return [
            new NavItem(
                label: 'Bill.com sync',
                icon: 'fa-solid fa-file-invoice-dollar',
                route: 'settings.bill-com.index',
            ),
        ];
    }

    public function scheduledTasks(Schedule $schedule): void
    {
        // Moved from routes/console.php (Phase 8 followup) — exact same
        // cron/modifier chains + config gate. Daily at 01:00 + 01:30, clear
        // of the security scan window (02:00/02:30) and the morning ingest
        // window (03:00+). Read-only sync; flips care_plan_enabled based on
        // actual invoicing reality. Disabled until CLOCKWORK_BILL_COM_ENABLED=true.
        $schedule->command('clockwork:sync-bill-customers')
            ->dailyAt('01:00')
            ->when(fn () => (bool) config('clockwork.bill_com.enabled'))
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground();

        $schedule->command('clockwork:sync-bill-care-plans')
            ->dailyAt('01:30')
            ->when(fn () => (bool) config('clockwork.bill_com.enabled'))
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground();
    }
}
