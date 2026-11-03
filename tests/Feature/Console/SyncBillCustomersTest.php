<?php

namespace Tests\Feature\Console;

use App\Models\ActionLog;
use App\Models\Site;
use Mockery;
use Modules\BillCom\BillComClient;

/*
|--------------------------------------------------------------------------
| SyncBillCustomers (artisan clockwork:sync-bill-customers)
|--------------------------------------------------------------------------
|
| Thin wrapper over CustomerSyncService (covered in depth by
| tests/Feature/BillCom/CarePlanAndCustomerSyncTest.php). These tests pin
| the console report: truly unmatched domains, domains that only belong to
| an archived site (labelled, not reported as missing) and normalized-domain
| collisions. BillComClient is mocked — no real HTTP.
*/

function syncBillCustomersClient(array $customers, array $invoices): BillComClient
{
    $mock = Mockery::mock(BillComClient::class);
    $mock->shouldReceive('customers')->andReturnUsing(function () use ($customers) {
        yield from $customers;
    });
    $mock->shouldReceive('invoicesSince')->andReturnUsing(function () use ($invoices) {
        yield from $invoices;
    });

    return $mock;
}

it('reports unmatched, archived-only and colliding domains separately', function () {
    Site::factory()->archived()->create(['domain' => 'www.example.net']);
    Site::factory()->create(['domain' => 'example.com', 'bill_com_customer_id' => null]);
    Site::factory()->create(['domain' => 'www.example.com', 'bill_com_customer_id' => null]);

    $this->instance(BillComClient::class, syncBillCustomersClient(
        customers: [['id' => '0cuCMD1', 'name' => 'Example Client', 'archived' => false]],
        invoices: [[
            'customerId' => '0cuCMD1',
            'invoiceNumber' => 'INV-5001',
            'invoiceDate' => '2026-08-01',
            'invoiceLineItems' => [
                ['itemId' => 'item-1', 'description' => 'Hosting for example.com, example.net and missing-example.org'],
            ],
        ]],
    ));

    $this->artisan('clockwork:sync-bill-customers', ['--dry-run' => true])
        ->expectsOutputToContain('1 unmatched domains')
        ->expectsOutputToContain('missing-example.org (1 hit)')
        ->expectsOutputToContain('example.net (archived)')
        ->expectsOutputToContain('example.com (2 sites)')
        ->assertSuccessful();

    $details = ActionLog::query()->where('action_type', ActionLog::TYPE_BILL_COM_SYNC)->sole()->details;
    expect($details['unmatched_domains'])->toBe(['missing-example.org' => 1])
        ->and($details['archived_site_domains'])->toBe(['example.net' => 1])
        ->and($details['domain_collisions'])->toBe(['example.com' => 2]);
});
