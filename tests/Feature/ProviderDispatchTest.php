<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Services\CloudProvider\CloudProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Core\NullCloudProvider;
use Tests\TestCase;

/**
 * Characterization tests for server-level provider dispatch. Originally
 * written ahead of the CloudProvider-contract refactor (modularization
 * Phase 0); updated in Phase 3 when that refactor landed, and again in
 * Phase 4 when the three providers moved into modules/.
 *
 * Phase 4 behavior change: an unrecognized provider string used to
 * silently dispatch to the DigitalOcean adapter (see the git history of
 * this file for that era's assertions). CloudProviderRegistry now returns
 * Modules\Core\NullCloudProvider instead — no HTTP calls, no metrics, no
 * silent misattribution to a cloud the server isn't actually on. This is
 * intentionally still distinct from Server::provider_label's fallback
 * (shows the raw string — see test_provider_label_accessor below), which
 * is a display concern, not a dispatch one.
 *
 * Also still true from Phase 3: Azure's size tier returns the raw slug
 * instead of running it through DigitalOcean's unrelated naming scheme
 * (see AzureCloudProvider::sizeTier() docblock).
 */
class ProviderDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function tier(Server $server): ?string
    {
        return app(CloudProviderRegistry::class)->resolve($server->provider)->sizeTier($server->size_slug);
    }

    public function test_size_tier_maps_digitalocean_slugs(): void
    {
        $this->assertSame('Basic', $this->tier($this->server('digitalocean', 's-2vcpu-4gb')));
        $this->assertSame('CPU-Optimized', $this->tier($this->server('digitalocean', 'c-4')));
        $this->assertSame('Memory-Optimized', $this->tier($this->server('digitalocean', 'm-2vcpu-16gb')));
        // Unknown slug falls back to the raw slug.
        $this->assertSame('weird-slug', $this->tier($this->server('digitalocean', 'weird-slug')));
    }

    public function test_size_tier_maps_hetzner_slugs(): void
    {
        $this->assertSame('Shared AMD', $this->tier($this->server('hetzner', 'cx21')));
        $this->assertSame('Shared AMD (EPYC)', $this->tier($this->server('hetzner', 'cpx31')));
        $this->assertSame('Dedicated vCPU', $this->tier($this->server('hetzner', 'ccx13')));
        $this->assertSame('Shared ARM', $this->tier($this->server('hetzner', 'cax11')));
    }

    public function test_size_tier_maps_vultr_slugs(): void
    {
        $this->assertSame('Cloud Compute', $this->tier($this->server('vultr', 'vc2-1c-1gb')));
        $this->assertSame('High Frequency', $this->tier($this->server('vultr', 'vhf-2c-4gb')));
        $this->assertSame('High Performance', $this->tier($this->server('vultr', 'vhp-4c-8gb')));
        $this->assertSame('Optimized Cloud (Dedicated)', $this->tier($this->server('vultr', 'voc-g-2c-8gb')));
        $this->assertSame('Dedicated Cloud', $this->tier($this->server('vultr', 'vdc-8c-32gb')));
        $this->assertSame('Bare Metal', $this->tier($this->server('vultr', 'vbm-4c-32gb')));
    }

    public function test_size_tier_maps_linode_slugs(): void
    {
        $this->assertSame('Shared CPU', $this->tier($this->server('linode', 'g6-standard-1')));
        $this->assertSame('Dedicated CPU', $this->tier($this->server('linode', 'g6-dedicated-2')));
        $this->assertSame('Nanode', $this->tier($this->server('linode', 'g6-nanode-1')));
        $this->assertSame('High Memory', $this->tier($this->server('linode', 'g6-highmem-1')));
        $this->assertSame('Dedicated GPU', $this->tier($this->server('linode', 'g6-gpu-1')));
    }

    public function test_size_tier_null_slug_and_unknown_provider_default(): void
    {
        $this->assertNull($this->tier($this->server('digitalocean', null)));

        // Azure has its own honest adapter (raw slug, not DO's mapping —
        // see class docblock).
        $this->assertSame('s-2vcpu-4gb', $this->tier($this->server('azure', 's-2vcpu-4gb')));

        // Truly unrecognized/legacy provider strings dispatch to
        // NullCloudProvider (Phase 4) — sizeTier() there also returns the
        // raw slug, since there's no provider-specific mapping to apply.
        $this->assertSame('s-2vcpu-4gb', $this->tier($this->server('some-future-cloud', 's-2vcpu-4gb')));
    }

    public function test_unrecognized_provider_resolves_to_null_cloud_provider(): void
    {
        $provider = app(CloudProviderRegistry::class)->resolve('some-future-cloud');

        $this->assertInstanceOf(NullCloudProvider::class, $provider);
        $this->assertSame('unknown', $provider->id());
        $this->assertFalse($provider->isConfigured());
    }

    public function test_provider_label_accessor(): void
    {
        $this->assertSame('DigitalOcean droplet', $this->server(Server::PROVIDER_DIGITALOCEAN)->provider_label);
        $this->assertSame('Hetzner server', $this->server(Server::PROVIDER_HETZNER)->provider_label);
        $this->assertSame('Azure VM', $this->server(Server::PROVIDER_AZURE)->provider_label);
        $this->assertSame('Vultr instance', $this->server(Server::PROVIDER_VULTR)->provider_label);
        $this->assertSame('Linode instance', $this->server(Server::PROVIDER_LINODE)->provider_label);
        // Unknown providers surface the raw string rather than erroring.
        $this->assertSame('some-future-cloud', $this->server('some-future-cloud')->provider_label);
    }

    public function test_poll_servers_dispatches_each_server_to_its_providers_api(): void
    {
        config([
            'clockwork.digitalocean.token' => 'test-do-token',
            'clockwork.hetzner.token' => 'test-hetzner-token',
            'clockwork.azure.tenant_id' => 'test-tenant',
            'clockwork.azure.client_id' => 'test-client',
            'clockwork.azure.client_secret' => 'test-secret',
            'clockwork.azure.subscription_id' => 'test-sub',
        ]);

        Http::fake([
            // Alive-droplet list must include our DO-polled ids or the
            // deleted-at-provider check marks them unknown and skips polling.
            'api.digitalocean.com/v2/droplets*' => Http::response([
                'droplets' => [['id' => 111], ['id' => 444]],
                'links' => [],
            ]),
            'api.digitalocean.com/*' => Http::response(['data' => ['result' => []]]),
            'api.hetzner.cloud/v1/servers/222/metrics*' => Http::response(['metrics' => ['time_series' => []]]),
            'api.hetzner.cloud/*' => Http::response([
                'servers' => [['id' => 222]],
                'meta' => ['pagination' => ['next_page' => null]],
            ]),
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
            'management.azure.com/*' => Http::response(['value' => []]),
        ]);

        $this->pollableServer(Server::PROVIDER_DIGITALOCEAN, '111');
        $this->pollableServer(Server::PROVIDER_HETZNER, '222');
        $this->pollableServer(Server::PROVIDER_AZURE, '/subscriptions/test-sub/resourceGroups/rg/providers/Microsoft.Compute/virtualMachines/vm1');
        // Legacy/unknown provider values dispatch to NullCloudProvider
        // (Phase 4) — no HTTP call for this server at all, unlike the old
        // "falls through to DigitalOcean" default.
        $unknown = $this->pollableServer('some-future-cloud', '444');

        $this->artisan('clockwork:poll-servers')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.hetzner.cloud/v1/servers/222/metrics'));
        // Azure has no per-poll existence check (its resource IDs are stable
        // long-lived paths, not ephemeral instance IDs like DO/Hetzner) — see
        // the isDeletedAtProvider() fix that made this true. Before that fix,
        // Azure servers fell through to the DO alive-list check and were
        // always misclassified as deleted, so this assertion also guards
        // against that regression.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'management.azure.com'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'monitoring/metrics/droplet/cpu') && ($r['host_id'] ?? null) === '111');
        // Nothing ever hits any provider's API for the unrecognized server.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'monitoring/metrics/droplet/cpu') && ($r['host_id'] ?? null) === '444');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.hetzner.cloud/v1/servers/111'));

        // NullCloudProvider::isDeletedAtProvider() always returns false — this
        // server's STATUS_UNKNOWN comes from a *successful* poll returning all-null
        // metrics (nothing to poll against), not the deleted-at-provider branch, so
        // provider_missing_since is correctly never set for it.
        $unknown->refresh();
        $this->assertSame(Server::STATUS_UNKNOWN, $unknown->status);
        $this->assertNull($unknown->provider_missing_since);
    }

    public function test_provider_missing_since_is_preserved_across_repeated_deleted_polls_and_cleared_on_recovery(): void
    {
        config(['clockwork.digitalocean.token' => 'test-do-token']);

        // A single Http::fake() using a sequence for the droplets list — a
        // *second* Http::fake() call for an already-faked URL pattern would
        // NOT override the first (Laravel checks fakes in registration
        // order and uses the first match), so three separate poll runs need
        // three queued responses on one fake registration.
        Http::fake([
            'api.digitalocean.com/v2/droplets*' => Http::sequence()
                ->push(['droplets' => [], 'links' => []])
                ->push(['droplets' => [], 'links' => []])
                ->push(['droplets' => [['id' => 999]], 'links' => []]),
            'api.digitalocean.com/*' => Http::response(['data' => ['result' => []]]),
        ]);

        $server = $this->pollableServer(Server::PROVIDER_DIGITALOCEAN, '999');

        $this->artisan('clockwork:poll-servers')->assertSuccessful();
        $server->refresh();
        $this->assertSame(Server::STATUS_UNKNOWN, $server->status);
        $firstDetected = $server->provider_missing_since;
        $this->assertNotNull($firstDetected);

        // A second poll while still absent from the provider must not
        // re-stamp the timestamp — it should keep recording when this was
        // FIRST noticed missing, not the most recent check.
        $this->travel(1)->hour();
        $this->artisan('clockwork:poll-servers')->assertSuccessful();
        $server->refresh();
        $this->assertTrue($firstDetected->equalTo($server->provider_missing_since));

        // Recovery: the droplet reappears in DO's own inventory (e.g. a
        // transient API blip, not a real deletion) — the flag must clear on
        // the next successful poll.
        $this->artisan('clockwork:poll-servers')->assertSuccessful();
        $server->refresh();
        $this->assertNull($server->provider_missing_since);
    }

    private function server(string $provider, ?string $sizeSlug = null): Server
    {
        return new Server([
            'name' => 'test.example.com',
            'hostname' => '203.0.113.10',
            'ssh_user' => 'clockwork-deploy',
            'provider' => $provider,
            'size_slug' => $sizeSlug,
        ]);
    }

    private static int $pollableServerSequence = 10;

    /**
     * random_int() for the hostname suffix used to be a real, if rare,
     * source of flakiness — a handful of calls drawing from a ~240-value
     * range has a non-trivial birthday-paradox collision chance against
     * servers.hostname's unique constraint, and it fired in CI. A per-test
     * incrementing sequence can never collide.
     */
    private function pollableServer(string $provider, string $providerId): Server
    {
        return Server::create([
            'name' => "srv-{$provider}.example.com",
            'hostname' => '203.0.113.'.(++self::$pollableServerSequence),
            'ssh_user' => 'clockwork-deploy',
            'provider' => $provider,
            'provider_id' => $providerId,
        ]);
    }
}
