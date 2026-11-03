<?php

namespace Tests\Feature\Console;

use App\Models\ActionLog;
use App\Models\Site;
use Mockery;
use Modules\BillCom\BillComClient;
use Modules\BillCom\BillComCustomer;

/*
|--------------------------------------------------------------------------
| SyncBillCarePlans (artisan clockwork:sync-bill-care-plans)
|--------------------------------------------------------------------------
|
| Thin wrapper over CarePlanSyncService (covered in depth by
| tests/Feature/BillCom/CarePlanAndCustomerSyncTest.php). These tests pin
| the console report: a www.-stored site is matched (not listed as
| unmatched), a domain that only belongs to an archived site is labelled
| rather than reported as missing, and a truly unknown domain stays in the
| unmatched list. BillComClient is mocked — no real HTTP.
*/

function syncBillCarePlansClient(array $items, array $invoices): BillComClient
{
    $mock = Mockery::mock(BillComClient::class);
    $mock->shouldReceive('items')->andReturnUsing(function () use ($items) {
        yield from $items;
    });
    $mock->shouldReceive('invoicesSince')->andReturnUsing(function () use ($invoices) {
        yield from $invoices;
    });

    return $mock;
}

it('matches a www.-stored site and labels archived-only care-plan domains instead of calling them missing', function () {
    $customer = BillComCustomer::create(['id' => '0cuCMDCP1', 'name' => 'Example Client', 'archived' => false]);
    $site = Site::factory()->create([
        'domain' => 'www.example.com',
        'bill_com_customer_id' => $customer->id,
        'care_plan_enabled' => false,
        'care_plan_override' => null,
    ]);
    Site::factory()->archived()->create(['domain' => 'example.net']);

    $this->instance(BillComClient::class, syncBillCarePlansClient(
        items: [['id' => 'item-care-1', 'name' => 'WordPress Care Plan']],
        invoices: [[
            'customerId' => $customer->id,
            'invoiceNumber' => 'INV-6001',
            'invoiceDate' => '2026-08-01',
            'invoiceLineItems' => [
                ['itemId' => 'item-care-1', 'description' => 'http://www.example.com - WordPress Care Plan'],
                ['itemId' => 'item-care-1', 'description' => 'WordPress Care Plan for example.net and missing-example.org'],
            ],
        ]],
    ));

    $this->artisan('clockwork:sync-bill-care-plans')
        ->expectsOutputToContain('Care-plan domains with no matching Site')
        ->expectsOutputToContain('missing-example.org')
        ->expectsOutputToContain('example.net (archived)')
        ->doesntExpectOutputToContain('Domains shared by more than one active site')
        ->assertSuccessful();

    expect($site->refresh()->care_plan_enabled)->toBeTrue();

    $details = ActionLog::query()->where('action_type', ActionLog::TYPE_BILL_COM_SYNC)->sole()->details;
    expect($details['unmatched_care_plan_domains'])->toBe(['missing-example.org' => 1])
        ->and($details['archived_care_plan_domains'])->toBe(['example.net' => 1])
        ->and($details['sites_set_true'])->toBe(1);
});
