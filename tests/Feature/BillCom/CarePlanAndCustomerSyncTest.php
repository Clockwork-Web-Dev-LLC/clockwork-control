<?php

namespace Tests\Feature\BillCom;

use App\Models\Site;
use Mockery;
use Modules\BillCom\BillComClient;
use Modules\BillCom\BillComCustomer;
use Modules\BillCom\CarePlanSyncService;
use Modules\BillCom\CustomerSyncService;

/**
 * Billing-correctness coverage for CustomerSyncService + CarePlanSyncService.
 *
 * BillComClient is a concrete, constructor-heavy class (real HTTP calls via
 * Http:: inside private methods) with no interface, so rather than fake HTTP
 * responses we Mockery::mock(BillComClient::class) directly and hand the mock
 * to the service constructor — the same "no factory needed, just build the
 * real row" spirit as tests/Feature/Models/SiteRelationshipsAndCastsTest.php
 * uses for BillComCustomer (no Database\Factories\Modules\* autoload path
 * exists, so BillComCustomer::create() is used directly here too).
 *
 * Generators: BillComClient::customers()/items()/invoicesSince() are typed
 * \Generator returns, so mocked responses are supplied via andReturnUsing()
 * closures containing yield — calling such a closure produces a Generator,
 * matching the real return type.
 */
function billComClientMock(array $customers = [], array $items = [], array $invoices = []): BillComClient
{
    $mock = Mockery::mock(BillComClient::class);
    $mock->shouldReceive('customers')->andReturnUsing(function () use ($customers) {
        yield from $customers;
    })->byDefault();
    $mock->shouldReceive('items')->andReturnUsing(function () use ($items) {
        yield from $items;
    })->byDefault();
    $mock->shouldReceive('invoicesSince')->andReturnUsing(function () use ($invoices) {
        yield from $invoices;
    })->byDefault();

    return $mock;
}

describe('CustomerSyncService::sync()', function () {
    it('links a site to the right BillComCustomer when its domain matches an invoice line item description', function () {
        $site = Site::factory()->create([
            'domain' => 'clientsite.com',
            'bill_com_customer_id' => null,
            'bill_com_customer_name' => null,
            'bill_com_linked_via_invoice' => null,
            'bill_com_linked_at' => null,
        ]);

        $client = billComClientMock(
            customers: [
                ['id' => '0cuABC123', 'name' => 'Acme Corp', 'companyName' => 'Acme Corporation', 'email' => 'billing@acme.example', 'archived' => false],
            ],
            invoices: [
                [
                    'customerId' => '0cuABC123',
                    'invoiceNumber' => 'INV-1001',
                    'invoiceDate' => '2026-08-01',
                    'invoiceLineItems' => [
                        ['itemId' => 'item-1', 'description' => 'Website hosting for clientsite.com'],
                    ],
                ],
            ],
        );

        $stats = (new CustomerSyncService($client))->sync(windowDays: 365);

        $site->refresh();
        expect($site->bill_com_customer_id)->toBe('0cuABC123')
            ->and($site->bill_com_customer_name)->toBe('Acme Corp')
            ->and($site->bill_com_linked_via_invoice)->toBe('INV-1001')
            ->and($site->bill_com_linked_at)->not->toBeNull()
            ->and($stats['sites_linked'])->toBe(1)
            ->and($stats['sites_relinked'])->toBe(0);

        expect(BillComCustomer::find('0cuABC123'))
            ->not->toBeNull()
            ->name->toBe('Acme Corp');
    });

    it('leaves a site with no matching invoice line item untouched (no false-positive linking)', function () {
        $site = Site::factory()->create([
            'domain' => 'unrelated-site.com',
            'bill_com_customer_id' => null,
            'bill_com_customer_name' => null,
            'bill_com_linked_via_invoice' => null,
            'bill_com_linked_at' => null,
        ]);

        $client = billComClientMock(
            customers: [
                ['id' => '0cuXYZ999', 'name' => 'Other Client', 'archived' => false],
            ],
            invoices: [
                [
                    'customerId' => '0cuXYZ999',
                    'invoiceNumber' => 'INV-2002',
                    'invoiceDate' => '2026-08-01',
                    'invoiceLineItems' => [
                        ['itemId' => 'item-1', 'description' => 'Website hosting for somewhere-else.com'],
                    ],
                ],
            ],
        );

        $stats = (new CustomerSyncService($client))->sync(windowDays: 365);

        $site->refresh();
        expect($site->bill_com_customer_id)->toBeNull()
            ->and($site->bill_com_customer_name)->toBeNull()
            ->and($site->bill_com_linked_via_invoice)->toBeNull()
            ->and($site->bill_com_linked_at)->toBeNull()
            ->and($stats['sites_linked'])->toBe(0)
            ->and($stats['unmatched_domains'])->toHaveKey('somewhere-else.com');
    });
});

describe('CarePlanSyncService::sync()', function () {
    it('flips care_plan_enabled to true for a site whose invoice line item names a care-plan item and its domain', function () {
        $customer = BillComCustomer::create([
            'id' => '0cuCAREPLAN1',
            'name' => 'Care Plan Client',
            'archived' => false,
        ]);

        $site = Site::factory()->create([
            'domain' => 'careplansite.com',
            'bill_com_customer_id' => $customer->id,
            'care_plan_enabled' => false,
            'care_plan_override' => null,
        ]);

        $client = billComClientMock(
            items: [
                ['id' => 'item-care-1', 'name' => 'Care Plan - Monthly'],
            ],
            invoices: [
                [
                    'customerId' => $customer->id,
                    'invoiceNumber' => 'INV-3003',
                    'invoiceDate' => '2026-08-15',
                    'invoiceLineItems' => [
                        ['itemId' => 'item-care-1', 'description' => 'Care plan services for careplansite.com'],
                    ],
                ],
            ],
        );

        $stats = (new CarePlanSyncService($client))->sync(windowDays: 60, itemRegex: '/care plan/i');

        $site->refresh();
        expect($site->care_plan_enabled)->toBeTrue()
            ->and($stats['sites_set_true'])->toBe(1)
            ->and($stats['sites_set_false'])->toBe(0)
            ->and($stats['care_plan_items_count'])->toBe(1)
            ->and($stats['active_care_plan_domains'])->toBe(1);
    });

    it('NEVER touches care_plan_enabled for a site with a non-null care_plan_override, regardless of invoice data', function () {
        $customer = BillComCustomer::create(['id' => '0cuOVERRIDE1', 'name' => 'Override Client', 'archived' => false]);

        // Override says "on" (true), but invoice data this run would compute
        // shouldBeOnPlan=false (no matching domain/customer) — if the sync
        // incorrectly respected the invoice data it would flip this to false.
        $overriddenOn = Site::factory()->create([
            'domain' => 'override-on.com',
            'bill_com_customer_id' => $customer->id,
            'care_plan_enabled' => true,
            'care_plan_override' => true,
        ]);

        // Override says "off" (false), but invoice data this run WOULD match
        // (domain appears in a care-plan line item) — if the sync incorrectly
        // respected the invoice data it would flip this to true.
        $overriddenOff = Site::factory()->create([
            'domain' => 'override-off.com',
            'bill_com_customer_id' => $customer->id,
            'care_plan_enabled' => false,
            'care_plan_override' => false,
        ]);

        $client = billComClientMock(
            items: [
                ['id' => 'item-care-1', 'name' => 'Care Plan - Annual'],
            ],
            invoices: [
                [
                    'customerId' => $customer->id,
                    'invoiceNumber' => 'INV-4004',
                    'invoiceDate' => '2026-08-15',
                    'invoiceLineItems' => [
                        // Only override-off.com's domain is in the active set;
                        // override-on.com's domain never appears anywhere.
                        ['itemId' => 'item-care-1', 'description' => 'Care plan for override-off.com'],
                    ],
                ],
            ],
        );

        $stats = (new CarePlanSyncService($client))->sync(windowDays: 60, itemRegex: '/care plan/i');

        $overriddenOn->refresh();
        $overriddenOff->refresh();

        expect($overriddenOn->care_plan_enabled)->toBeTrue() // unchanged, NOT flipped to false
            ->and($overriddenOff->care_plan_enabled)->toBeFalse() // unchanged, NOT flipped to true
            ->and($stats['sites_skipped_due_to_override'])->toBe(2)
            ->and($stats['sites_set_true'])->toBe(0)
            ->and($stats['sites_set_false'])->toBe(0);
    });

    it('flips care_plan_enabled back to false when a previously-enabled site no longer has qualifying invoice data', function () {
        $customer = BillComCustomer::create(['id' => '0cuLAPSED1', 'name' => 'Lapsed Client', 'archived' => false]);

        $site = Site::factory()->create([
            'domain' => 'lapsed-careplan.com',
            'bill_com_customer_id' => $customer->id,
            'care_plan_enabled' => true, // was on
            'care_plan_override' => null,
        ]);

        // Care-plan Item exists, but no invoice this window references it at
        // all for this site/customer — so neither the domain set nor the
        // customer fallback set contains anything for this site.
        $client = billComClientMock(
            items: [
                ['id' => 'item-care-1', 'name' => 'Care Plan - Monthly'],
            ],
            invoices: [],
        );

        $stats = (new CarePlanSyncService($client))->sync(windowDays: 60, itemRegex: '/care plan/i');

        $site->refresh();
        expect($site->care_plan_enabled)->toBeFalse() // confirmed: NOT additive-only, sync actively demotes
            ->and($stats['sites_set_false'])->toBe(1)
            ->and($stats['sites_set_true'])->toBe(0)
            ->and($stats['sites_skipped_due_to_override'])->toBe(0);
    });
});
