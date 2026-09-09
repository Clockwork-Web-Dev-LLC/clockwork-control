<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleRegistry;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\ModuleStateResolver;
use Modules\Core\NavItem;
use Modules\Slack\SlackServiceProvider;
use Tests\Concerns\RendersAuthenticatedPages;
use Tests\TestCase;

/**
 * Phase 7: proves Modules\Core\ModuleRegistry::navItems() actually reaches
 * the gear menu. Modules\BillCom\BillComServiceProvider is a real consumer
 * of this today (its "Bill.com sync" link), but this test still registers
 * its own throwaway fake module rather than asserting against BillCom's real
 * one, so it keeps testing the wiring mechanism in isolation.
 */
class ModuleNavItemsTest extends TestCase
{
    use RefreshDatabase;
    use RendersAuthenticatedPages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockIssueCounterZero();
    }

    public function test_a_module_contributed_nav_item_renders_in_the_gear_menu(): void
    {
        $fakeProvider = new class($this->app) extends ModuleServiceProvider
        {
            public function manifest(): ModuleManifest
            {
                return new ModuleManifest(id: 'fake-test-module', name: 'Fake Test Module', description: 'Phase 7 test double.');
            }

            public function navItems(): array
            {
                return [
                    new NavItem(label: 'Fake Module Page', icon: 'fa-solid fa-flask', route: 'settings.diagnostics.index'),
                ];
            }
        };

        app(ModuleRegistry::class)->register($fakeProvider);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.diagnostics.index'));

        $response->assertOk();
        $response->assertSee('Fake Module Page');
        $response->assertSee(route('settings.diagnostics.index'), false);
    }

    public function test_gear_menu_renders_unchanged_when_no_module_contributes_a_nav_item(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.diagnostics.index'));

        $response->assertOk();
        $response->assertDontSee('Fake Module Page');
    }

    public function test_bill_com_nav_item_is_dynamically_gated_by_enabled_status(): void
    {
        $user = User::factory()->create();

        config(['clockwork.bill_com.enabled' => false]);
        $response = $this->actingAs($user)->get(route('capacity.index'));
        $response->assertDontSee('Bill.com sync');

        config(['clockwork.bill_com.enabled' => true]);
        $response = $this->actingAs($user)->get(route('capacity.index'));
        $response->assertSee('Bill.com sync');
    }

    public function test_twilio_sms_nav_item_is_dynamically_gated_and_rendered_once(): void
    {
        $user = User::factory()->create();

        config(['clockwork.twilio.enabled' => false]);
        $response = $this->actingAs($user)->get(route('capacity.index'));
        $response->assertDontSee('SMS notifications');

        config(['clockwork.twilio.enabled' => true]);
        $response = $this->actingAs($user)->get(route('capacity.index'));
        $response->assertSee('SMS notifications');
        // Ensure it appears only once, not duplicated
        $this->assertSame(1, substr_count($response->getContent(), 'SMS notifications'));
    }

    public function test_slack_nav_item_is_dynamically_gated_by_enabled_status(): void
    {
        $user = User::factory()->create();

        $slackProvider = new SlackServiceProvider($this->app);
        app(ModuleRegistry::class)->register($slackProvider);

        config(['clockwork.slack.enabled' => false]);
        $response = $this->actingAs($user)->get(route('capacity.index'));
        $response->assertDontSee('Slack notifications');

        config(['clockwork.slack.enabled' => true]);
        $response = $this->actingAs($user)->get(route('capacity.index'));
        $response->assertSee('Slack notifications');
    }

    public function test_client_reports_is_gated_from_nav_and_gear_menu_when_disabled(): void
    {
        $user = User::factory()->create();

        InstalledModule::updateOrCreate(
            ['module_id' => 'client_reports'],
            ['name' => 'Client Reports', 'enabled' => false, 'source' => 'bundled', 'status' => 'active']
        );
        app(ModuleStateResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('capacity.index'));
        $response->assertDontSee('Client reports');

        InstalledModule::updateOrCreate(
            ['module_id' => 'client_reports'],
            ['name' => 'Client Reports', 'enabled' => true, 'source' => 'bundled', 'status' => 'active']
        );
        app(ModuleStateResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('capacity.index'));
        $response->assertSee('Client reports');
    }

    public function test_client_management_is_gated_from_gear_menu_when_disabled(): void
    {
        $user = User::factory()->create();

        InstalledModule::updateOrCreate(
            ['module_id' => 'client-management'],
            ['name' => 'Client Management', 'enabled' => false, 'source' => 'bundled', 'status' => 'active']
        );
        app(ModuleStateResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('capacity.index'));
        $response->assertDontSee('Clients');

        InstalledModule::updateOrCreate(
            ['module_id' => 'client-management'],
            ['name' => 'Client Management', 'enabled' => true, 'source' => 'bundled', 'status' => 'active']
        );
        app(ModuleStateResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('capacity.index'));
        $response->assertSee('Clients');
    }
}
