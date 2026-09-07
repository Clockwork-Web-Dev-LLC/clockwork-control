<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\CredentialResolver;
use App\Support\EnvCredentialManager;
use Illuminate\Support\Facades\Http;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleCatalog;
use Tests\Concerns\RendersAuthenticatedPages;
use Tests\Fixtures\SpinupWpFixtures;

uses(RendersAuthenticatedPages::class);

// Every EnvCredentialManager instance resolved during these requests is
// bound to a throwaway temp file (below), never the real .env — that file
// holds this app's actual live secrets, and a suite that wrote to it
// directly would be one crash/timeout/parallel-run away from clobbering
// them for real.
$tempEnvPath = null;
$configBackup = null;
$servicesBackup = null;
$envArrayBackup = null;
$serverBackup = null;

beforeEach(function () use (&$tempEnvPath, &$configBackup, &$servicesBackup, &$envArrayBackup, &$serverBackup) {
    $this->mockIssueCounterZero();

    $tempEnvPath = tempnam(sys_get_temp_dir(), 'clockwork_test_env_');
    file_put_contents($tempEnvPath, "APP_NAME=Testing\n");
    $this->app->instance(EnvCredentialManager::class, new EnvCredentialManager($tempEnvPath));

    $configBackup = config('clockwork');
    $servicesBackup = config('services');
    $envArrayBackup = $_ENV;
    $serverBackup = $_SERVER;
});

afterEach(function () use (&$tempEnvPath, &$configBackup, &$servicesBackup, &$envArrayBackup, &$serverBackup) {
    if ($tempEnvPath !== null && file_exists($tempEnvPath)) {
        unlink($tempEnvPath);
    }
    config(['clockwork' => $configBackup]);
    config(['services' => $servicesBackup]);
    $_ENV = $envArrayBackup;
    $_SERVER = $serverBackup;
    foreach ([
        'CLOCKWORK_DIGITALOCEAN_TOKEN',
        'CLOCKWORK_HETZNER_TOKEN',
        'CLOCKWORK_LINODE_TOKEN',
        'CLOCKWORK_VULTR_API_KEY',
        'CLOCKWORK_AZURE_TENANT_ID',
        'CLOCKWORK_AZURE_CLIENT_ID',
        'CLOCKWORK_AZURE_CLIENT_SECRET',
        'CLOCKWORK_AZURE_SUBSCRIPTION_ID',
        'CLOCKWORK_SPINUPWP_TOKEN',
        'TWILIO_ACCOUNT_SID',
        'TWILIO_AUTH_TOKEN',
        'TWILIO_FROM_NUMBER',
        'CLOCKWORK_SLACK_WEBHOOK_URL',
        'CLOCKWORK_MATTERMOST_WEBHOOK_URL',
    ] as $key) {
        if (isset($envArrayBackup[$key])) {
            putenv("{$key}={$envArrayBackup[$key]}");
        } else {
            putenv("{$key}");
        }
    }
});

describe('auth gate', function () {
    it('redirects unauthenticated users to login', function () {
        $this->get(route('setup.step1'))
            ->assertRedirect(route('login'));

        $this->get(route('setup.step2'))
            ->assertRedirect(route('login'));
    });
});

describe('Step 1: Which services are you using? (GET/POST /setup)', function () {
    it('renders the service picker with brand categories and services', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('setup.step1'));

        $response->assertOk()
            ->assertSee('Which services are you using?')
            ->assertSee('Authentication & Sign-In')
            ->assertSee('Performance & Speed')
            ->assertSee('Security & Malware Scans')
            ->assertSee('Managed WordPress Hosts')
            ->assertSee('Server Management Panels')
            ->assertSee('Cloud Infrastructure & VPS')
            ->assertSee('Notifications')
            ->assertSee('Miscellaneous')
            ->assertSee('Google')
            ->assertSee('GitHub')
            ->assertSee('Microsoft')
            ->assertSee('GTmetrix')
            ->assertSee('PageSpeed Insights')
            ->assertSee('Sucuri SiteCheck')
            ->assertSee('DigitalOcean')
            ->assertSee('SpinupWP')
            ->assertSee('Slack')
            ->assertSee('integrations')
            ->assertDontSee('platforms')
            ->assertDontSee('SiteCheck scans recorded')
            ->assertDontSee('Form tests configured')
            ->assertSee('Finish Setup &amp; Go to Dashboard', false);
    });

    it('renders every single bundled module on setup step 1 with toggle and cog button', function () {
        $user = User::factory()->create();
        $bundled = ModuleCatalog::bundled();

        expect(count($bundled))->toBeGreaterThanOrEqual(20);

        $response = $this->actingAs($user)->get(route('setup.step1'));

        $response->assertOk();

        foreach ($bundled as $id => $item) {
            $response->assertSee($item['manifest']->name);
            $response->assertSee("toggle-{$id}");
        }
    });

    it('renders a yellow beaker icon closer to the cog for services looking for testers', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('setup.step1'));

        $response->assertOk()
            ->assertSee('fa-solid fa-flask text-sm', false)
            ->assertSee('text-amber-500', false)
            ->assertSee('Looking for Testers')
            ->assertDontSee('status-pill status-yellow text-[10px]', false);
    });

    it('auto-detects and activates services already present in the fleet', function () {
        $user = User::factory()->create();

        // Create a server for DigitalOcean and a site for SpinupWP
        Server::factory()->create(['provider' => 'digitalocean']);
        Site::factory()->create(['hosting_provider' => 'spinupwp']);

        $response = $this->actingAs($user)->get(route('setup.step1'));

        $response->assertOk()
            ->assertSee('DigitalOcean')
            ->assertSee('server')
            ->assertSee('SpinupWP')
            ->assertSee('site')
            ->assertSee('bg-emerald-600')
            ->assertSee('bg-rose-600')
            ->assertDontSee('text-emerald-600 Active');
    });

    it('sorts all integrations in alphabetical order by name within each category', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('setup.step1'));

        $categories = $response->viewData('categories');

        // Verify fleet sources are strictly alphabetical: Cloudways, GridPane, Kinsta, Pressable, SpinupWP, WP Engine
        $fleetNames = array_column($categories['fleet_sources']['services'], 'name');
        expect($fleetNames)->toBe([
            'Cloudways',
            'GridPane',
            'Kinsta',
            'Pressable',
            'SpinupWP',
            'WP Engine',
        ]);

        // Verify every category has services sorted alphabetically by name
        foreach ($categories as $category) {
            $names = array_column($category['services'], 'name');
            $sortedNames = $names;
            usort($sortedNames, fn ($a, $b) => strcasecmp($a, $b));
            expect($names)->toEqual($sortedNames);
        }
    });

    it('saves selected services and finishes setup redirecting to dashboard', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('setup.step1.save'), [
            'services' => ['digitalocean', 'spinupwp', 'slack'],
        ]);

        $response->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Setup completed! Your active fleet integrations are ready.');

        expect(InstalledModule::where('module_id', 'digitalocean')->value('enabled'))->toBeTrue()
            ->and(InstalledModule::where('module_id', 'spinupwp')->value('enabled'))->toBeTrue()
            ->and(InstalledModule::where('module_id', 'slack')->value('enabled'))->toBeTrue()
            ->and(InstalledModule::where('module_id', 'azure')->value('enabled'))->toBeFalse();
    });

    it('can optionally advance to Step 2 if configure action is explicitly provided', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('setup.step1.save'), [
            'services' => ['digitalocean'],
            'action' => 'configure',
        ]);

        $response->assertRedirect(route('setup.step2'));
    });

    it('styles service cards with green gradient when configured and active, red border when active but not configured, and white when inactive', function () {
        $user = User::factory()->create();

        // DigitalOcean is enabled and configured (via token)
        InstalledModule::create(['module_id' => 'digitalocean', 'name' => 'DigitalOcean', 'enabled' => true]);
        app(EnvCredentialManager::class)->save('digitalocean', 'token', 'dop_v1_test_tok');

        // Hetzner is enabled but NOT configured
        InstalledModule::create(['module_id' => 'hetzner', 'name' => 'Hetzner', 'enabled' => true]);
        app(EnvCredentialManager::class)->remove('hetzner', 'token');

        // Azure is inactive (enabled = false)
        InstalledModule::create(['module_id' => 'azure', 'name' => 'Azure', 'enabled' => false]);

        $response = $this->actingAs($user)->get(route('setup.step1'));

        $response->assertOk()
            ->assertDontSee('Credentials configured')
            ->assertSee('border-emerald-500')
            ->assertSee('from-emerald-50/75')
            ->assertSee('border-rose-600')
            ->assertSee('from-rose-50/90')
            ->assertSee('bg-white');
    });
});

describe('Step 2: Configure selected services (GET/POST /setup/configure)', function () {
    it('only displays configuration cards for services selected in Step 1', function () {
        $user = User::factory()->create();

        // Enable only DigitalOcean and Slack
        InstalledModule::create(['module_id' => 'digitalocean', 'name' => 'DigitalOcean', 'enabled' => true]);
        InstalledModule::create(['module_id' => 'slack', 'name' => 'Slack', 'enabled' => true]);
        InstalledModule::create(['module_id' => 'azure', 'name' => 'Azure', 'enabled' => false]);
        InstalledModule::create(['module_id' => 'kinsta', 'name' => 'Kinsta', 'enabled' => false]);

        $response = $this->actingAs($user)->get(route('setup.step2'));

        $response->assertOk()
            ->assertSee('Configure your selected services')
            ->assertSee('DigitalOcean')
            ->assertSee('Slack')
            ->assertSee('Get API Key')
            ->assertDontSee('Azure (Virtual Machines)');
    });

    it('saves entered API credentials and redirects to servers.create when a cloud-only setup leaves the fleet empty', function () {
        // DigitalOcean alone has no "sites" of its own to import — it only
        // provides metrics for servers a hosting-panel import (SpinupWP/
        // Pressable/GridPane) already knows about. Regression coverage for
        // the bug where a fresh install with only cloud-VPS credentials
        // configured would bounce forever between /setup and the dashboard
        // (RedirectToSetupIfFreshInstall sends an empty fleet straight back
        // to /setup, and saving credentials alone never populates one).
        $user = User::factory()->create();

        InstalledModule::create(['module_id' => 'digitalocean', 'name' => 'DigitalOcean', 'enabled' => true]);

        $response = $this->actingAs($user)->post(route('setup.step2.save'), [
            'value_digitalocean_token' => 'dop_v1_test_token_12345',
        ]);

        $response->assertRedirect(route('servers.create'))
            ->assertSessionHas('status', 'Setup completed, but no servers were found yet. Add one manually below, or double-check your credentials and use "Refresh from SpinupWP" once you have a server.');

        $resolver = app(CredentialResolver::class);
        expect($resolver->get('digitalocean.token'))->toBe('dop_v1_test_token_12345');
        expect(Server::count())->toBe(0);
        expect(Site::count())->toBe(0);
    });

    it('auto-imports the fleet and redirects to dashboard when SpinupWP credentials are entered', function () {
        $user = User::factory()->create();

        InstalledModule::create(['module_id' => 'spinupwp', 'name' => 'SpinupWP', 'enabled' => true]);

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::server(['id' => 111, 'name' => 'web1', 'ip_address' => '203.0.113.5']),
                ]),
                200
            ),
            'api.spinupwp.app/v1/sites*' => Http::response(
                SpinupWpFixtures::listResponse([
                    SpinupWpFixtures::site(['id' => 222, 'server_id' => 111, 'domain' => 'client-one.example.com']),
                ]),
                200
            ),
        ]);

        $response = $this->actingAs($user)->post(route('setup.step2.save'), [
            'value_spinupwp_token' => 'swp-test-token',
        ]);

        $response->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Setup completed! Your active fleet integrations are ready.');

        expect(Server::count())->toBe(1);
        expect(Site::count())->toBe(1);
    });
});

describe('Step 1 toggle auto-save (POST /setup/toggle)', function () {
    it('redirects unauthenticated users to login', function () {
        $this->postJson(route('setup.toggle'), [
            'service' => 'gridpane',
            'enabled' => false,
        ])->assertUnauthorized();
    });

    it('immediately toggles an integration OFF and persists to installed_modules', function () {
        $user = User::factory()->create();

        // Initially enabled
        InstalledModule::create([
            'module_id' => 'gridpane',
            'name' => 'GridPane',
            'enabled' => true,
        ]);

        $response = $this->actingAs($user)->postJson(route('setup.toggle'), [
            'service' => 'gridpane',
            'enabled' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('service', 'gridpane')
            ->assertJsonPath('enabled', false);

        expect(InstalledModule::where('module_id', 'gridpane')->value('enabled'))->toBeFalse();
    });

    it('immediately toggles an integration ON and persists to installed_modules', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('setup.toggle'), [
            'service' => 'gridpane',
            'enabled' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('service', 'gridpane')
            ->assertJsonPath('enabled', true);

        expect(InstalledModule::where('module_id', 'gridpane')->value('enabled'))->toBeTrue();
    });

    it('returns 404 for an unknown service ID', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('setup.toggle'), [
            'service' => 'nonexistent_service_xyz',
            'enabled' => true,
        ]);

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    });
});
