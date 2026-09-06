<?php

use App\Models\ActionLog;
use App\Models\Site;
use App\Models\User;
use Modules\BillCom\BillComCarePlanItem;
use Modules\BillCom\BillComCustomer;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

beforeEach(function () {
    $this->mockIssueCounterZero();
});

describe('auth gate', function () {
    it('redirects unauthenticated users away from the bill-com settings screen', function () {
        $this->get(route('settings.bill-com.index'))
            ->assertRedirect(route('login'));
    });
});

describe('Bill.com settings screen (GET /settings/bill-com)', function () {
    it('renders the bill-com settings screen with sync status and site metrics', function () {
        $user = User::factory()->create();

        BillComCustomer::create([
            'id' => '0cuTEST1',
            'name' => 'Acme Corporation',
            'archived' => false,
        ]);

        BillComCarePlanItem::create([
            'id' => '0iiPLAN1',
            'name' => 'Monthly Care Plan Standard',
            'price' => 199.00,
            'is_care_plan' => true,
        ]);

        Site::factory()->create([
            'bill_com_customer_id' => '0cuTEST1',
        ]);

        ActionLog::create([
            'action_type' => ActionLog::TYPE_BILL_COM_SYNC,
            'status' => 'success',
            'summary' => 'Synced 1 customers and 1 sites',
            'ran_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('settings.bill-com.index'));

        $response->assertOk()
            ->assertSee('Bill.com sync')
            ->assertSee('Bill.com customers cached locally')
            ->assertSee('Monthly Care Plan Standard')
            ->assertSee('Synced 1 customers and 1 sites');
    });

    it('prevents customer sync if credentials are not configured', function () {
        $user = User::factory()->create();
        config(['clockwork.bill_com.org_id' => '']);

        $response = $this->actingAs($user)
            ->post(route('settings.bill-com.run-customer-sync'));

        $response->assertRedirect()
            ->assertSessionHas('bill_com_error', 'Bill.com credentials are not configured. Set CLOCKWORK_BILL_COM_* in .env first.');
    });

    it('prevents care plan sync if credentials are not configured', function () {
        $user = User::factory()->create();
        config(['clockwork.bill_com.org_id' => '']);

        $response = $this->actingAs($user)
            ->post(route('settings.bill-com.run-care-plan-sync'));

        $response->assertRedirect()
            ->assertSessionHas('bill_com_error', 'Bill.com credentials are not configured. Set CLOCKWORK_BILL_COM_* in .env first.');
    });
});
