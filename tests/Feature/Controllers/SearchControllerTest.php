<?php

namespace Tests\Feature\Controllers;

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_requires_authentication(): void
    {
        $response = $this->get(route('search.global', ['q' => 'spinup']));
        $response->assertRedirect(route('login'));
    }

    public function test_short_query_returns_empty_arrays(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('search.global', ['q' => 'a']));
        $response->assertOk();
        $response->assertJson([
            'servers' => [],
            'sites' => [],
        ]);
    }

    public function test_global_search_finds_servers_by_name_and_ip(): void
    {
        $user = User::factory()->create();

        $server = Server::create([
            'name' => 'web-01.example.test',
            'hostname' => '203.0.113.10',
            'ssh_user' => 'systemsgo',
            'provider' => Server::PROVIDER_DIGITALOCEAN,
            'status' => 'red',
        ]);

        // Search by name prefix
        $response = $this->actingAs($user)->get(route('search.global', ['q' => 'web-01']));
        $response->assertOk();
        $response->assertJsonFragment([
            'id' => 'server-'.$server->id,
            'section' => 'Servers',
            'label' => $server->display_name,
            'sublabel' => '203.0.113.10 · Digitalocean',
            'url' => route('servers.show', $server),
        ]);

        // Search by IP
        $responseIp = $this->actingAs($user)->get(route('search.global', ['q' => '203.0.113']));
        $responseIp->assertOk();
        $responseIp->assertJsonFragment([
            'id' => 'server-'.$server->id,
            'section' => 'Servers',
        ]);
    }

    public function test_global_search_finds_sites_and_excludes_archived(): void
    {
        $user = User::factory()->create();

        $server = Server::create([
            'name' => 'web01.example.com',
            'hostname' => '192.0.2.1',
            'ssh_user' => 'deploy',
            'provider' => Server::PROVIDER_DIGITALOCEAN,
        ]);

        $activeSite = Site::create([
            'domain' => 'acme-example.org',
            'server_id' => $server->id,
            'hosting_provider' => 'spinupwp',
        ]);

        $archivedSite = Site::create([
            'domain' => 'archivedproject.org',
            'server_id' => $server->id,
            'hosting_provider' => 'spinupwp',
            'archived_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('search.global', ['q' => 'acme']));
        $response->assertOk();

        $response->assertJsonFragment([
            'id' => 'site-'.$activeSite->id,
            'section' => 'Sites',
            'label' => 'acme-example.org',
            'url' => route('sites.show', $activeSite),
        ]);

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringNotContainsString('archivedproject.org', $content);
    }
}
