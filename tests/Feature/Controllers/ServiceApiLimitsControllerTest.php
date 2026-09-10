<?php

use App\Models\IntegrationCredential;
use App\Models\Server;
use App\Models\User;
use App\Support\EnvCredentialManager;
use App\Support\ServiceRateLimitRegistry;
use App\Support\Settings;
use Modules\DigitalOcean\DigitalOceanClient;
use Tests\Concerns\RendersAuthenticatedPages;

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
        'GITHUB_CLIENT_ID',
        'GITHUB_CLIENT_SECRET',
        'MICROSOFT_CLIENT_ID',
        'MICROSOFT_CLIENT_SECRET',
        'MICROSOFT_TENANT_ID',
        'GOOGLE_CLIENT_ID',
        'GOOGLE_CLIENT_SECRET',
        'GOOGLE_HD',
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
        $this->get(route('settings.integrations.limits', 'digitalocean'))
            ->assertRedirect(route('login'));

        $this->patch(route('settings.integrations.limits.update', 'digitalocean'), ['timeout' => 20])
            ->assertRedirect(route('login'));

        $this->post(route('settings.integrations.limits.reset', 'digitalocean'))
            ->assertRedirect(route('login'));

        $this->post(route('settings.integrations.credentials.remove', ['service' => 'digitalocean', 'field' => 'token']))
            ->assertRedirect(route('login'));
    });
});

describe('limits show endpoint', function () {
    it('returns 404 for an unknown service', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.integrations.limits', 'unknown-service-xyz'))
            ->assertNotFound();
    });

    it('renders the HTML dedicated limits page with official documentation and metrics', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('settings.integrations.limits', 'digitalocean'));

        $response->assertOk()
            ->assertSee('DigitalOcean API Limits &amp; Quotas', false)
            ->assertSee('5,000 requests / hour')
            ->assertSee('RateLimit-Limit')
            ->assertSee('RateLimit-Remaining')
            ->assertSee('RateLimit-Reset')
            ->assertSee('HTTP 429 Too Many Requests')
            ->assertSee('Fleet Impact &amp; Polling Telemetry Costs', false)
            ->assertSee('API Connection Tunables');
    });

    it('returns JSON response when requested via AJAX or Accept header', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson(route('settings.integrations.limits', 'digitalocean'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('service.id', 'digitalocean')
            ->assertJsonPath('service.name', 'DigitalOcean')
            ->assertJsonPath('service.category', 'Cloud VPS')
            ->assertJsonPath('tunables.timeout', 15)
            ->assertJsonPath('tunables.concurrency', 3)
            ->assertJsonPath('tunables.retry_attempts', 2);
    });

    it('renders setup step 1 with the cog button and limits modal', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('setup.step1'));

        $response->assertOk()
            ->assertSee('serviceLimitsModal()', false)
            ->assertDontSee('Limits &amp; docs', false)
            ->assertDontSee('fa-info text-[11px]', false)
            ->assertSee('DigitalOcean API Limits &amp; Settings', false)
            ->assertSee('showLimitsModal')
            ->assertSee('testConnection()', false)
            ->assertSee('Test Connection');
    });
});

describe('limits update and reset', function () {
    it('saves custom operator overrides and updates DigitalOceanClient behavior', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->patch(route('settings.integrations.limits.update', 'digitalocean'), [
                'timeout' => 45,
                'rate_limit' => 4800,
                'concurrency' => 5,
                'delay_ms' => 120,
                'retry_attempts' => 3,
            ]);

        $response->assertRedirect()
            ->assertSessionHas('status');

        $registry = app(ServiceRateLimitRegistry::class);
        $tunables = $registry->getTunables('digitalocean');

        expect($tunables['timeout'])->toBe(45)
            ->and($tunables['rate_limit'])->toBe(4800)
            ->and($tunables['concurrency'])->toBe(5)
            ->and($tunables['delay_ms'])->toBe(120)
            ->and($tunables['retry_attempts'])->toBe(3)
            ->and($tunables['is_custom'])->toBeTrue();

        // DigitalOceanClient should pick up the runtime override
        config(['clockwork.digitalocean.token' => 'dummy-token']);
        $client = new DigitalOceanClient;
        expect($client->getTimeout())->toBe(45)
            ->and($client->getDelayMs())->toBe(120)
            ->and($client->getRetryAttempts())->toBe(3);
    });

    it('updates tunables via JSON AJAX request', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->patchJson(route('settings.integrations.limits.update', 'digitalocean'), [
                'timeout' => 25,
                'delay_ms' => 50,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('tunables.timeout', 25)
            ->assertJsonPath('tunables.delay_ms', 50);
    });

    it('resets custom overrides back to vendor recommended defaults', function () {
        $user = User::factory()->create();
        $settings = app(Settings::class);
        $settings->put('services.digitalocean.timeout', 50);
        $settings->put('services.digitalocean.delay_ms', 200);

        $response = $this->actingAs($user)
            ->post(route('settings.integrations.limits.reset', 'digitalocean'));

        $response->assertRedirect()
            ->assertSessionHas('status');

        $registry = app(ServiceRateLimitRegistry::class);
        $tunables = $registry->getTunables('digitalocean');

        expect($tunables['timeout'])->toBe(15)
            ->and($tunables['delay_ms'])->toBe(50)
            ->and($tunables['is_custom'])->toBeFalse();

        config(['clockwork.digitalocean.token' => 'dummy-token']);
        $client = new DigitalOceanClient;
        expect($client->getTimeout())->toBe(15);
    });

    it('renders the dedicated limits screen for every single registered service in the registry', function () {
        $user = User::factory()->create();
        $registry = app(ServiceRateLimitRegistry::class);
        $allServices = $registry->all();

        expect(count($allServices))->toBeGreaterThanOrEqual(19);

        foreach ($allServices as $serviceId => $serviceMeta) {
            $response = $this->actingAs($user)
                ->get(route('settings.integrations.limits', $serviceId));

            $response->assertOk()
                ->assertSee($serviceMeta['name']);

            if ($serviceMeta['has_rate_limits'] ?? true) {
                $response->assertSee('API Connection Tunables');
            } else {
                $response->assertSee('Connection &amp; Delivery Settings', false);
            }
        }
    });

    it('renders OAuth configuration card and redirect URI helper for OAuth providers', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('settings.integrations.limits', 'auth_google'));

        $response->assertOk()
            ->assertSee('Google SSO Authentication Settings')
            ->assertSee('OAuth 2.0 Single Sign-On')
            ->assertSee('Authorized Redirect URI')
            ->assertSee(url('/auth/google/callback'))
            ->assertDontSee('Official Vendor Rate Limits')
            ->assertDontSee('Fleet Impact &amp; Polling Telemetry Costs', false);

        $json = $this->actingAs($user)
            ->getJson(route('settings.integrations.limits', 'auth_google'));

        $json->assertOk()
            ->assertJsonPath('service.type', 'oauth')
            ->assertJsonPath('service.has_rate_limits', false)
            ->assertJsonPath('redirect_uri', url('/auth/google/callback'));
    });

    it('renders outbound webhook card without fake limits for webhook providers', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('settings.integrations.limits', 'mattermost'));

        $response->assertOk()
            ->assertSee('Mattermost Webhook Settings')
            ->assertSee('Event-Driven Outbound Webhook')
            ->assertDontSee('Official Vendor Rate Limits')
            ->assertDontSee('Fleet Impact &amp; Polling Telemetry Costs', false);

        $json = $this->actingAs($user)
            ->getJson(route('settings.integrations.limits', 'mattermost'));

        $json->assertOk()
            ->assertJsonPath('service.type', 'webhook')
            ->assertJsonPath('service.has_rate_limits', false);
    });

    it('returns valid JSON metadata including tunables and credentials for every single registered service', function () {
        $user = User::factory()->create();
        $registry = app(ServiceRateLimitRegistry::class);
        $allServices = $registry->all();

        foreach ($allServices as $serviceId => $serviceMeta) {
            $response = $this->actingAs($user)
                ->getJson(route('settings.integrations.limits', $serviceId));

            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('service.id', $serviceId)
                ->assertJsonPath('service.name', $serviceMeta['name']);

            $data = $response->json();
            expect($data['tunables'])->toHaveKeys(['timeout', 'concurrency', 'delay_ms', 'retry_attempts'])
                ->and(is_array($data['credentials']))->toBeTrue();
        }
    });
});

describe('.env credential management', function () {
    it('returns credential metadata in show response for supported services', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson(route('settings.integrations.limits', 'digitalocean'));

        $response->assertOk()
            ->assertJsonPath('credentials.0.field', 'token')
            ->assertJsonPath('credentials.0.env_var', 'CLOCKWORK_DIGITALOCEAN_TOKEN')
            ->assertJsonPath('credentials.0.secret', true);
    });

    it('saves API key directly to .env file and updates runtime config without storing in database', function () {
        $user = User::factory()->create();

        // Create a dummy DB row to ensure it gets cleared when saved to .env
        IntegrationCredential::create([
            'integration' => 'digitalocean',
            'key' => 'token',
            'value' => 'old_db_secret_value',
        ]);

        $response = $this->actingAs($user)
            ->patchJson(route('settings.integrations.limits.update', 'digitalocean'), [
                'credentials' => [
                    'token' => 'dop_v1_live_test_key_xyz987654',
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('credentials.0.configured', true)
            ->assertJsonPath('credentials.0.preview', '••••••••7654');

        $envManager = app(EnvCredentialManager::class);
        expect($envManager->getEnvValue('CLOCKWORK_DIGITALOCEAN_TOKEN'))->toBe('dop_v1_live_test_key_xyz987654')
            ->and(config('clockwork.digitalocean.token'))->toBe('dop_v1_live_test_key_xyz987654');

        // Database row should have been purged to enforce single source of truth
        expect(IntegrationCredential::where('integration', 'digitalocean')->count())->toBe(0);
    });

    it('removes API credential from .env file via dedicated remove endpoint', function () {
        $user = User::factory()->create();
        $envManager = app(EnvCredentialManager::class);
        $envManager->save('digitalocean', 'token', 'temp_key_to_delete_1234');

        expect($envManager->getEnvValue('CLOCKWORK_DIGITALOCEAN_TOKEN'))->toBe('temp_key_to_delete_1234');

        $response = $this->actingAs($user)
            ->postJson(route('settings.integrations.credentials.remove', ['service' => 'digitalocean', 'field' => 'token']));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('credentials.0.configured', false);

        expect($envManager->getEnvValue('CLOCKWORK_DIGITALOCEAN_TOKEN'))->toBeNull()
            ->and(config('clockwork.digitalocean.token'))->toBeNull();
    });

    it('clears credential via standard HTML form submission flag', function () {
        $user = User::factory()->create();
        $envManager = app(EnvCredentialManager::class);
        $envManager->save('digitalocean', 'token', 'initial_token_val_5678');

        $response = $this->actingAs($user)
            ->patch(route('settings.integrations.limits.update', 'digitalocean'), [
                'clear_cred_token' => '1',
            ]);

        $response->assertRedirect()
            ->assertSessionHas('status');

        expect($envManager->getEnvValue('CLOCKWORK_DIGITALOCEAN_TOKEN'))->toBeNull();
    });

    it('manages multi-field credentials for Azure App Registration', function () {
        $user = User::factory()->create();
        $envManager = app(EnvCredentialManager::class);

        $response = $this->actingAs($user)
            ->patchJson(route('settings.integrations.limits.update', 'azure'), [
                'credentials' => [
                    'tenant_id' => 'tenant-uuid-1111-2222',
                    'client_id' => 'client-uuid-3333-4444',
                    'client_secret' => 'super-secret-azure-key-9999',
                    'subscription_id' => 'sub-uuid-5555-6666',
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        expect($envManager->getEnvValue('CLOCKWORK_AZURE_TENANT_ID'))->toBe('tenant-uuid-1111-2222')
            ->and($envManager->getEnvValue('CLOCKWORK_AZURE_CLIENT_ID'))->toBe('client-uuid-3333-4444')
            ->and($envManager->getEnvValue('CLOCKWORK_AZURE_CLIENT_SECRET'))->toBe('super-secret-azure-key-9999')
            ->and($envManager->getEnvValue('CLOCKWORK_AZURE_SUBSCRIPTION_ID'))->toBe('sub-uuid-5555-6666');

        // Remove only the client_secret; other fields must remain intact
        $removeResp = $this->actingAs($user)
            ->postJson(route('settings.integrations.credentials.remove', ['service' => 'azure', 'field' => 'client_secret']));

        $removeResp->assertOk()->assertJsonPath('success', true);

        expect($envManager->getEnvValue('CLOCKWORK_AZURE_CLIENT_SECRET'))->toBeNull()
            ->and($envManager->getEnvValue('CLOCKWORK_AZURE_TENANT_ID'))->toBe('tenant-uuid-1111-2222')
            ->and($envManager->getEnvValue('CLOCKWORK_AZURE_CLIENT_ID'))->toBe('client-uuid-3333-4444');
    });

    it('manages multi-field credentials for Twilio SMS alerts', function () {
        $user = User::factory()->create();
        $envManager = app(EnvCredentialManager::class);

        $response = $this->actingAs($user)
            ->patchJson(route('settings.integrations.limits.update', 'twilio'), [
                'credentials' => [
                    'account_sid' => 'ACtestaccountsid1234567890abcdef',
                    'auth_token' => 'testauthtoken1234567890abcdef',
                    'from_number' => '+15551234567',
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        expect($envManager->getEnvValue('TWILIO_ACCOUNT_SID'))->toBe('ACtestaccountsid1234567890abcdef')
            ->and($envManager->getEnvValue('TWILIO_AUTH_TOKEN'))->toBe('testauthtoken1234567890abcdef')
            ->and($envManager->getEnvValue('TWILIO_FROM_NUMBER'))->toBe('+15551234567');
    });

    it('manages webhook URL credentials for Slack and Mattermost', function () {
        $user = User::factory()->create();
        $envManager = app(EnvCredentialManager::class);

        $this->actingAs($user)
            ->patchJson(route('settings.integrations.limits.update', 'slack'), [
                'credentials' => [
                    'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        expect($envManager->getEnvValue('CLOCKWORK_SLACK_WEBHOOK_URL'))->toBe('https://hooks.slack.com/services/T000/B000/XXXX')
            ->and(config('clockwork.slack.webhook_url'))->toBe('https://hooks.slack.com/services/T000/B000/XXXX');

        $this->actingAs($user)
            ->patchJson(route('settings.integrations.limits.update', 'mattermost'), [
                'credentials' => [
                    'webhook_url' => 'https://mattermost.example.com/hooks/yyyy',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        expect($envManager->getEnvValue('CLOCKWORK_MATTERMOST_WEBHOOK_URL'))->toBe('https://mattermost.example.com/hooks/yyyy')
            ->and(config('clockwork.mattermost.webhook_url'))->toBe('https://mattermost.example.com/hooks/yyyy');
    });

    it('loads and manages credentials for GitHub, Microsoft, and Google auth providers', function () {
        $user = User::factory()->create();
        $envManager = app(EnvCredentialManager::class);

        // GitHub limits & credentials
        $this->actingAs($user)
            ->getJson(route('settings.integrations.limits', 'auth_github'))
            ->assertOk()
            ->assertJsonPath('service.name', 'GitHub')
            ->assertJsonPath('testable', true)
            ->assertJsonCount(2, 'credentials');

        $this->actingAs($user)
            ->patchJson(route('settings.integrations.limits.update', 'auth_github'), [
                'credentials' => [
                    'client_id' => 'gh_client_12345',
                    'client_secret' => 'gh_secret_67890',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        expect($envManager->getEnvValue('GITHUB_CLIENT_ID'))->toBe('gh_client_12345')
            ->and($envManager->getEnvValue('GITHUB_CLIENT_SECRET'))->toBe('gh_secret_67890');

        // Microsoft limits & credentials
        $this->actingAs($user)
            ->getJson(route('settings.integrations.limits', 'auth_microsoft'))
            ->assertOk()
            ->assertJsonPath('service.name', 'Microsoft')
            ->assertJsonPath('testable', true)
            ->assertJsonCount(3, 'credentials');

        $this->actingAs($user)
            ->patchJson(route('settings.integrations.limits.update', 'auth_microsoft'), [
                'credentials' => [
                    'client_id' => 'ms_client_111',
                    'client_secret' => 'ms_secret_222',
                    'tenant_id' => 'ms_tenant_333',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        expect($envManager->getEnvValue('MICROSOFT_CLIENT_ID'))->toBe('ms_client_111')
            ->and($envManager->getEnvValue('MICROSOFT_CLIENT_SECRET'))->toBe('ms_secret_222')
            ->and($envManager->getEnvValue('MICROSOFT_TENANT_ID'))->toBe('ms_tenant_333');

        // Google limits & credentials
        $this->actingAs($user)
            ->getJson(route('settings.integrations.limits', 'auth_google'))
            ->assertOk()
            ->assertJsonPath('service.name', 'Google')
            ->assertJsonPath('testable', true)
            ->assertJsonCount(3, 'credentials');

        $this->actingAs($user)
            ->patchJson(route('settings.integrations.limits.update', 'auth_google'), [
                'credentials' => [
                    'client_id' => 'goog_client_abc',
                    'client_secret' => 'goog_secret_xyz',
                    'hosted_domain' => 'myagency.com',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        expect($envManager->getEnvValue('GOOGLE_CLIENT_ID'))->toBe('goog_client_abc')
            ->and($envManager->getEnvValue('GOOGLE_CLIENT_SECRET'))->toBe('goog_secret_xyz')
            ->and($envManager->getEnvValue('GOOGLE_HD'))->toBe('myagency.com');
    });
});

describe('cloud instance discovery & actions', function () {
    it('discovers live Vultr instances and detects link status in show endpoint', function () {
        config(['clockwork.vultr.api_key' => 'vultr-test-key']);
        $user = User::factory()->create();

        Http::fake([
            'api.vultr.com/v2/instances*' => Http::response([
                'instances' => [
                    [
                        'id' => 'vultr-uuid-1234',
                        'label' => 'web1.clockworkwp.com',
                        'main_ip' => '198.51.100.25',
                        'plan' => 'voc-g-1c-4gb-30s-amd',
                        'vcpu_count' => 1,
                        'ram' => 4096,
                        'disk' => 30,
                        'status' => 'active',
                        'region' => 'dfw',
                        'tags' => ['spinupwp'],
                    ],
                ],
                'meta' => ['total' => 1, 'links' => ['next' => '']],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('settings.integrations.limits', 'vultr'));

        $response->assertOk()
            ->assertJsonPath('is_cloud_provider', true)
            ->assertJsonPath('detected_instances.0.id', 'vultr-uuid-1234')
            ->assertJsonPath('detected_instances.0.name', 'web1.clockworkwp.com')
            ->assertJsonPath('detected_instances.0.ip', '198.51.100.25')
            ->assertJsonPath('detected_instances.0.is_linked', false)
            ->assertJsonPath('detected_instances.0.suggested_panel', 'spinupwp')
            ->assertJsonPath('hosting_panels.spinupwp.enabled', true);
    });

    it('cross-references detected instances against existing servers', function () {
        config(['clockwork.vultr.api_key' => 'vultr-test-key']);
        $user = User::factory()->create();

        $server = Server::factory()->create([
            'name' => 'web1.clockworkwp.com',
            'hostname' => '198.51.100.25',
            'provider' => Server::PROVIDER_VULTR,
            'provider_id' => 'vultr-uuid-1234',
        ]);

        Http::fake([
            'api.vultr.com/v2/instances*' => Http::response([
                'instances' => [
                    [
                        'id' => 'vultr-uuid-1234',
                        'label' => 'web1.clockworkwp.com',
                        'main_ip' => '198.51.100.25',
                        'plan' => 'voc-g-1c-4gb-30s-amd',
                        'status' => 'active',
                    ],
                ],
                'meta' => ['total' => 1, 'links' => ['next' => '']],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('settings.integrations.limits', 'vultr'));

        $response->assertOk()
            ->assertJsonPath('detected_instances.0.is_linked', true)
            ->assertJsonPath('detected_instances.0.is_fully_linked', true)
            ->assertJsonPath('detected_instances.0.linked_server.id', $server->id);
    });

    it('reconciles hardware specs via POST /settings/integrations/{service}/reconcile', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('settings.integrations.reconcile', 'vultr'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'output']);
    });

    it('imports an unlinked cloud instance via POST /settings/integrations/{service}/import-instance', function () {
        config(['clockwork.vultr.api_key' => 'vultr-test-key']);
        $user = User::factory()->create();

        Http::fake([
            'api.vultr.com/v2/instances/vultr-uuid-9999' => Http::response([
                'instance' => [
                    'id' => 'vultr-uuid-9999',
                    'label' => 'standalone-app',
                    'main_ip' => '198.51.100.99',
                    'plan' => 'vc2-1c-1gb',
                    'vcpu_count' => 1,
                    'ram' => 1024,
                    'disk' => 25,
                    'status' => 'active',
                    'os' => 'Ubuntu 24.04 LTS x64',
                ],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('settings.integrations.importInstance', 'vultr'), [
                'instance_id' => 'vultr-uuid-9999',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('server.name', 'standalone-app')
            ->assertJsonPath('server.hostname', '198.51.100.99');

        $server = Server::where('provider_id', 'vultr-uuid-9999')->first();
        expect($server)->not->toBeNull()
            ->and($server->name)->toBe('standalone-app')
            ->and($server->hostname)->toBe('198.51.100.99')
            ->and($server->provider)->toBe(Server::PROVIDER_VULTR)
            ->and($server->size_slug)->toBe('vc2-1c-1gb')
            ->and($server->vcpus)->toBe(1)
            ->and($server->memory_mb)->toBe(1024)
            ->and($server->disk_gb)->toBe(25);
    });

    it('renders cloud provider architecture guide and detected instances on HTML limits page', function () {
        config(['clockwork.vultr.api_key' => 'vultr-test-key']);
        $user = User::factory()->create();

        Http::fake([
            'api.vultr.com/v2/instances*' => Http::response([
                'instances' => [
                    [
                        'id' => 'vultr-uuid-5555',
                        'label' => 'cloud-box.example.com',
                        'main_ip' => '198.51.100.55',
                        'plan' => 'voc-g-1c-4gb-30s-amd',
                        'vcpu_count' => 1,
                        'ram' => 4096,
                        'disk' => 30,
                        'status' => 'active',
                    ],
                ],
                'meta' => ['total' => 1, 'links' => ['next' => '']],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->get(route('settings.integrations.limits', 'vultr'));

        $response->assertOk()
            ->assertSee('How Vultr Integrates with Clockwork Control')
            ->assertSee('Detected Vultr Instances')
            ->assertSee('cloud-box.example.com')
            ->assertSee('198.51.100.55')
            ->assertSee('Reconcile Hardware Specs')
            ->assertSee('Import as Standalone Server');
    });

    it('does not render get api key link for non-api-key credential fields like cloudways account email', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('settings.integrations.limits', 'cloudways'));

        $response->assertOk()
            ->assertSee('Account Email')
            ->assertSee('CLOCKWORK_CLOUDWAYS_EMAIL')
            ->assertSee('Email address associated with your Cloudways account');

        $content = $response->getContent();
        // API Key field gets the link; Account Email does not offer a link to API keys
        expect(substr_count($content, 'https://platform.cloudways.com/api'))->toBe(1)
            ->and(substr_count($content, 'Get API Key'))->toBe(1);
    });
});
