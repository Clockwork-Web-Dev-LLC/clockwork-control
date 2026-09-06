<?php

namespace Tests\Feature\Companion;

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteTrafficCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_spinup_wp_site_with_working_ssh_supports_traffic_report(): void
    {
        $server = Server::factory()->create([
            'last_ssh_ok_at' => now(),
            'ssh_private_key' => null,
            'ssh_password' => null,
        ]);

        $site = Site::factory()->create([
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'server_id' => $server->id,
        ]);

        $this->assertTrue($site->supportsTrafficReport());
    }

    public function test_spinup_wp_site_with_configured_private_key_supports_traffic_report(): void
    {
        $server = Server::factory()->create([
            'last_ssh_ok_at' => null,
            'ssh_private_key' => 'fake-private-key',
            'ssh_password' => null,
        ]);

        $site = Site::factory()->create([
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'server_id' => $server->id,
        ]);

        $this->assertTrue($site->supportsTrafficReport());
    }

    public function test_spinup_wp_site_without_ssh_does_not_support_traffic_report(): void
    {
        $server = Server::factory()->create([
            'last_ssh_ok_at' => null,
            'ssh_private_key' => null,
            'ssh_password' => null,
        ]);

        $site = Site::factory()->create([
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'server_id' => $server->id,
        ]);

        $this->assertFalse($site->supportsTrafficReport());
    }

    public function test_serverless_provider_without_cap_ssh_does_not_support_traffic_report(): void
    {
        $site = Site::factory()->create([
            'hosting_provider' => Site::HOSTING_PROVIDER_WPENGINE,
            'server_id' => null,
        ]);

        $this->assertFalse($site->supportsTrafficReport());
    }

    public function test_sites_controller_push_companion_pushes_disabled_when_site_lacks_ssh(): void
    {
        $user = User::factory()->create();

        $serverNoSsh = Server::factory()->create([
            'last_ssh_ok_at' => null,
            'ssh_private_key' => null,
            'ssh_password' => null,
        ]);

        $site = Site::factory()->withCompanionInstalled()->create([
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'server_id' => $serverNoSsh->id,
            'companion_capabilities' => ['traffic-report', 'snapshot', 'backups'],
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/snapshot" => Http::response([
                'ok' => true,
                'plugins' => ['active' => []],
            ]),
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response([
                'ok' => true,
            ]),
            "https://{$site->domain}/wp-json/clockwork/v1/traffic-report" => Http::response([
                'ok' => true,
                'supported' => false,
            ]),
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('sites.companion.push-update', $site));

        $response->assertOk();
        $data = $response->json();

        $this->assertTrue($data['ok']);
        $this->assertTrue($data['results']['traffic']['ok']);
        $this->assertFalse($data['results']['traffic']['supported']);
        $this->assertTrue($data['results']['traffic']['disabled']);
        $this->assertStringContainsString('traffic ✓ (disabled — no SSH)', $data['message']);

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/traffic-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $body['supported'] === false
                && $body['source'] === 'clockwork-monitoring';
        });
    }
}
