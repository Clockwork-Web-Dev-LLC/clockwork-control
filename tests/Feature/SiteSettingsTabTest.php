<?php

use App\Models\Site;
use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

beforeEach(function () {
    $this->mockIssueCounterZero();
    $this->user = User::factory()->create();
});

it('requires authentication to view settings tab', function () {
    $site = Site::factory()->spinupwp()->create(['domain' => 'test-settings.example.com']);

    $this->get(route('sites.show', ['site' => $site, 'tab' => 'settings']))
        ->assertRedirect(route('login'));
});

it('renders the redesigned settings tab with modular 3-column cards', function () {
    $site = Site::factory()->spinupwp()->create([
        'domain' => 'my-modular-site.com',
        'is_wordpress' => true,
        'cert_source' => 'spinupwp_le',
        'cert_expires_at' => now()->addDays(45),
        'cloudflare_state' => 'proxied',
        'resolved_a_record' => '192.0.2.1',
        'resolved_ns_record' => 'ns1.cloudflare.com',
        'wordfence_enabled' => true,
        'llar_enabled' => true,
        'care_plan_enabled' => true,
        'uptime_monitoring_enabled' => true,
        'is_inactive' => false,
        'companion_installed' => true,
        'companion_version' => '1.4.2',
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('sites.show', ['site' => $site, 'tab' => 'settings']));

    $response->assertOk()
        ->assertViewHas('tab', 'settings')
        ->assertSee('grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 mb-8', false)
        // Cert details card
        ->assertSee('Cert details')
        ->assertSee('Valid SSL')
        ->assertSee('id="cert-edit-toggle"', false)
        // Cloudflare card
        ->assertSee('Cloudflare')
        ->assertSee('Proxied')
        ->assertSee('192.0.2.1')
        ->assertSee('ns1.cloudflare.com')
        // WordPress security card
        ->assertSee('WordPress security')
        ->assertSee('Hardened')
        ->assertSee('id="llar-state"', false)
        // Care plan card
        ->assertSee('Care plan')
        ->assertSee('On care plan')
        ->assertSee('Mark NOT on care plan')
        // Uptime monitoring card
        ->assertSee('Uptime monitoring')
        ->assertSee('Probing')
        // Site status card
        ->assertSee('Site status')
        ->assertSee('Active')
        // Companion card
        ->assertSee('Companion mu-plugin')
        ->assertSee('v1.4.2')
        // Contact form testing card (when care plan enabled)
        ->assertSee('Contact forms')
        // Danger zone
        ->assertSee('Remove from monitoring')
        ->assertSee('does not delete the WordPress site on the host')
        ->assertSee('id="archive-site-toggle"', false);
});

it('renders pressable tools card for Pressable sites', function () {
    $site = Site::factory()->pressable()->create([
        'domain' => 'pressable-site.com',
        'is_wordpress' => true,
    ]);

    $this->actingAs($this->user)
        ->get(route('sites.show', ['site' => $site, 'tab' => 'settings']))
        ->assertOk()
        ->assertSee('Pressable tools')
        ->assertSee('Flush object cache')
        ->assertSee('Load resource metrics');
});

it('does not put a backups card on the settings tab', function () {
    $spinup = Site::factory()->spinupwp()->create();
    $custom = Site::factory()->custom()->create([
        'backup_relay_enabled' => true,
        'backup_relay_frequency' => 'daily',
    ]);

    $this->actingAs($this->user)
        ->get(route('sites.show', ['site' => $spinup, 'tab' => 'settings']))
        ->assertOk()
        ->assertDontSee('id="backup-relay-card"', false);

    $this->actingAs($this->user)
        ->get(route('sites.show', ['site' => $custom, 'tab' => 'settings']))
        ->assertOk()
        ->assertDontSee('Save backup schedule')
        ->assertDontSee('id="backup-relay-card"', false);
});
