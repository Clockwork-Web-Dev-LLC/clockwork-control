<?php

use App\Models\ActionLog;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('MaintenanceHistoryController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('redirects guests to login', function () {
        $this->get(route('maintenance-history.index'))->assertRedirect(route('login'));
    });

    it('renders the current month with a per-site rollup split by care plan', function () {
        $carePlanSite = Site::factory()->carePlan()->create(['domain' => 'covered-example.com']);
        $billableSite = Site::factory()->create(['domain' => 'billable-example.com', 'care_plan_enabled' => false]);

        $now = CarbonImmutable::now();

        ActionLog::factory()->create([
            'site_id' => $carePlanSite->id,
            'action_type' => ActionLog::TYPE_PLUGIN_UPDATE,
            'ran_at' => $now,
            'ok' => true,
        ]);
        ActionLog::factory()->create([
            'site_id' => $billableSite->id,
            'action_type' => ActionLog::TYPE_THEME_UPDATE,
            'ran_at' => $now,
            'ok' => false,
        ]);
        // Outside the current month — must not be counted.
        ActionLog::factory()->create([
            'site_id' => $carePlanSite->id,
            'action_type' => ActionLog::TYPE_PLUGIN_UPDATE,
            'ran_at' => $now->subMonths(2),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('maintenance-history.index'));

        $response->assertOk()
            ->assertSee('covered-example.com')
            ->assertSee('billable-example.com')
            ->assertSee($now->format('F Y'));
        // Operations workspace: dedicated operations tabs must render.
        $response->assertSee('Capacity')->assertSee('Maintenance History')->assertDontSee('Fleet & Branding');

        // Grand totals: 1 covered action, 1 billable action — the two rows
        // added above, the out-of-month row excluded.
        $response->assertSeeInOrder(['Total actions', '2']);
    });

    it('falls back to the current month instead of erroring on a malformed month query param', function () {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('maintenance-history.index', ['month' => 'not-a-month']));

        $response->assertOk()
            ->assertSee(CarbonImmutable::now()->format('F Y'));
    });
});
