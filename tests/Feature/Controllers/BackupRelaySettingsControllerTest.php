<?php

namespace Tests\Feature\Controllers;

use App\Models\BackupRelayRun;
use App\Models\Site;
use App\Models\User;
use App\Services\Process\BackgroundArtisan;
use App\Services\Process\BackgroundArtisanResult;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RendersAuthenticatedPages;
use Tests\TestCase;

class BackupRelaySettingsControllerTest extends TestCase
{
    use RefreshDatabase;
    use RendersAuthenticatedPages;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockIssueCounterZero();
        $this->user = User::factory()->create();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.backup-relay.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_displays_sites_and_runs(): void
    {
        $siteA = Site::query()->create([
            'domain' => 'pressable-target.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '101',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        $siteB = Site::query()->create([
            'domain' => 'spinupwp-target.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'spinupwp_id' => 202,
            'backup_relay_enabled' => false,
            'is_wordpress' => true,
        ]);

        $run = BackupRelayRun::query()->create([
            'sites_total' => 2,
            'sites_archived' => 1,
            'sites_skipped' => 1,
            'sites_failed' => 0,
            'failures' => [],
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(50),
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.backup-relay.index'))
            ->assertOk()
            ->assertViewIs('settings.backup-relay')
            ->assertSee('Backup Relay')
            ->assertSee('pressable-target.example.com')
            ->assertSee('spinupwp-target.example.com')
            ->assertSee('External Agent')
            ->assertSee('1 a week (Weekly)');
    }

    public function test_policy_update_via_ajax(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->user)
            ->patchJson(route('settings.backup-relay.update'), [
                'policy_update' => 1,
                'frequency' => 'weekly',
                'retention_days' => 90,
                'mode' => 'external_agent',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'frequency' => 'weekly',
                'retention_days' => 90,
                'mode' => 'external_agent',
            ]);

        $settings = app(Settings::class);
        $this->assertSame('weekly', $settings->get('backup_relay.frequency'));
        $this->assertSame(90, $settings->get('backup_relay.retention_days'));
        $this->assertSame('external_agent', $settings->get('backup_relay.mode'));
    }

    public function test_single_site_toggle_via_ajax(): void
    {
        $site = Site::query()->create([
            'domain' => 'toggle.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '102',
            'backup_relay_enabled' => false,
            'is_wordpress' => true,
        ]);

        $this->actingAs($this->user)
            ->patchJson(route('settings.backup-relay.update'), [
                'site_id' => $site->id,
                'enabled' => true,
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'enabled' => true,
            ]);

        $this->assertTrue($site->fresh()->backup_relay_enabled);
    }

    public function test_bulk_update_toggles_selected_sites(): void
    {
        $site1 = Site::query()->create([
            'domain' => 'site1.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '1',
            'backup_relay_enabled' => false,
            'is_wordpress' => true,
        ]);

        $site2 = Site::query()->create([
            'domain' => 'site2.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '2',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        $this->actingAs($this->user)
            ->patch(route('settings.backup-relay.update'), [
                'sites' => [$site1->id],
            ])
            ->assertRedirect();

        $this->assertTrue($site1->fresh()->backup_relay_enabled);
        $this->assertFalse($site2->fresh()->backup_relay_enabled);
    }

    public function test_single_site_toggle_refuses_a_direct_upload_site_without_backup_create(): void
    {
        Storage::fake('s3');

        $site = $this->customSite('old-plugin.example.com', ['snapshot'], enabled: false);

        $this->actingAs($this->user)
            ->patchJson(route('settings.backup-relay.update'), [
                'site_id' => $site->id,
                'enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'enabled' => false,
                'message' => 'Backup relay was not enabled for old-plugin.example.com: Companion 1.30.3 lacks the backup-create capability; update Companion or switch to Renegade.',
            ]);

        $this->assertFalse($site->fresh()->backup_relay_enabled);
    }

    public function test_single_site_toggle_enables_a_direct_upload_site_with_backup_create(): void
    {
        Storage::fake('s3');

        $site = $this->customSite('new-plugin.example.com', ['snapshot', 'backup-create'], enabled: false);

        $this->actingAs($this->user)
            ->patchJson(route('settings.backup-relay.update'), [
                'site_id' => $site->id,
                'enabled' => true,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'enabled' => true]);

        $this->assertTrue($site->fresh()->backup_relay_enabled);
    }

    public function test_bulk_update_skips_sites_whose_plugin_lacks_backup_create(): void
    {
        Storage::fake('s3');

        $blocked = $this->customSite('blocked.example.com', ['snapshot'], enabled: false);
        $capable = $this->customSite('capable.example.com', ['backup-create'], enabled: false);
        $alreadyOn = $this->customSite('already-on.example.com', ['snapshot'], enabled: true);
        $hosted = Site::query()->create([
            'domain' => 'hosted.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '3',
            'backup_relay_enabled' => false,
            'is_wordpress' => true,
        ]);

        $this->actingAs($this->user)
            ->patch(route('settings.backup-relay.update'), [
                'sites' => [$blocked->id, $capable->id, $alreadyOn->id, $hosted->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Backup relay settings updated for 3 site(s).')
            ->assertSessionHas('warning', 'Not enabled: blocked.example.com (Companion 1.30.3 lacks the backup-create capability; update Companion or switch to Renegade.)');

        $this->assertFalse($blocked->fresh()->backup_relay_enabled);
        $this->assertTrue($capable->fresh()->backup_relay_enabled);
        // Already on: left on, so its failures stay visible.
        $this->assertTrue($alreadyOn->fresh()->backup_relay_enabled);
        $this->assertTrue($hosted->fresh()->backup_relay_enabled);
    }

    public function test_bulk_update_via_ajax_lists_blocked_sites(): void
    {
        Storage::fake('s3');

        $blocked = $this->customSite('blocked-ajax.example.com', [], enabled: false);
        $capable = $this->customSite('capable-ajax.example.com', ['backup-create'], enabled: false);

        $this->actingAs($this->user)
            ->patchJson(route('settings.backup-relay.update'), [
                'sites' => [$blocked->id, $capable->id],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('enabled_count', 1)
            ->assertJsonPath('blocked.0.site_id', $blocked->id)
            ->assertJsonPath('blocked.0.domain', 'blocked-ajax.example.com');

        $this->assertFalse($blocked->fresh()->backup_relay_enabled);
        $this->assertTrue($capable->fresh()->backup_relay_enabled);
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function customSite(string $domain, array $capabilities, bool $enabled): Site
    {
        return Site::factory()->custom()->withCompanionInstalled()->create([
            'domain' => $domain,
            'companion_variant' => 'companion',
            'companion_version' => '1.30.3',
            'companion_capabilities' => $capabilities,
            'backup_relay_enabled' => $enabled,
        ]);
    }

    public function test_run_now_executes_in_repo_mode(): void
    {
        app(Settings::class)->put('backup_relay.mode', 'in_repo');
        config(['clockwork.backup_relay.mode' => 'in_repo']);
        Storage::fake('s3-backup-relay');

        Site::query()->create([
            'domain' => 'archive-target.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '303',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(fn (string $key, array $cmds) => $key === 'backup_relay.run'
                    && $cmds === ['clockwork:backup-relay-run'])
                ->andReturn(BackgroundArtisanResult::ok());
        });

        $this->actingAs($this->user)
            ->post(route('settings.backup-relay.runNow'))
            ->assertRedirect()
            ->assertSessionHas('status', function ($status) {
                return str_contains($status, 'Backup relay started in the background');
            });

        $this->assertSame(0, BackupRelayRun::query()->count());
    }

    public function test_run_now_rejects_in_external_agent_mode(): void
    {
        app(Settings::class)->put('backup_relay.mode', 'external_agent');
        config(['clockwork.backup_relay.mode' => 'external_agent']);

        $this->actingAs($this->user)
            ->post(route('settings.backup-relay.runNow'))
            ->assertRedirect()
            ->assertSessionHas('warning');
    }

    public function test_run_now_warns_when_already_running(): void
    {
        app(Settings::class)->put('backup_relay.mode', 'in_repo');
        config(['clockwork.backup_relay.mode' => 'in_repo']);

        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->andReturn(BackgroundArtisanResult::busy());
        });

        $this->actingAs($this->user)
            ->post(route('settings.backup-relay.runNow'))
            ->assertRedirect()
            ->assertSessionHas('warning', 'A backup relay run is already in progress.');
    }
}
