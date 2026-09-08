<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

beforeEach(function () {
    $this->mockIssueCounterZero();
});

describe('auth gate', function () {
    it('redirects unauthenticated users to login', function () {
        $this->get(route('settings.modules.index'))
            ->assertRedirect(route('login'));

        $this->post(route('settings.modules.refresh'))
            ->assertRedirect(route('login'));
    });
});

describe('index (GET /settings/modules)', function () {
    it('renders the module directory with live feed data', function () {
        $user = User::factory()->create();

        Http::fake([
            'https://clockworkcontrol.com/api/modules.json' => Http::response([
                'schema_version' => '1.0',
                'generated_at' => '2026-09-04T12:00:00Z',
                'total_modules' => 2,
                'modules' => [
                    [
                        'id' => 'digitalocean',
                        'name' => 'DigitalOcean',
                        'description' => 'Virtual server telemetry',
                        'category' => 'cloud_vps',
                        'author' => 'Clockwork Web Dev',
                        'status' => 'official',
                        'capabilities' => ['Telemetry'],
                        'tags' => ['cloud'],
                    ],
                    [
                        'id' => 'community-runcloud',
                        'name' => 'RunCloud Hosting',
                        'description' => 'Community-contributed RunCloud panel',
                        'category' => 'control_panels',
                        'author' => 'Community Contributor',
                        'status' => 'community',
                        'capabilities' => ['Server Linkage'],
                        'tags' => ['community'],
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($user)->get(route('settings.modules.index'));

        $response->assertOk()
            ->assertSee('Module directory')
            ->assertSee('DigitalOcean')
            ->assertSee('RunCloud Hosting')
            ->assertSee('Community')
            ->assertSee('Official')
            ->assertSee('Check for updates')
            ->assertDontSee('CPU &amp; RAM Telemetry', false)
            ->assertSee(route('settings.integrations.index').'#integration-digitalocean', false);
    });

    it('gracefully renders fallback directory when remote API is unreachable', function () {
        $user = User::factory()->create();

        Http::fake([
            'https://clockworkcontrol.com/api/modules.json' => Http::response('Server Error', 500),
        ]);

        $response = $this->actingAs($user)->get(route('settings.modules.index'));

        $response->assertOk()
            ->assertSee('Module directory')
            ->assertSee('DigitalOcean')
            ->assertSee('GTmetrix')
            ->assertSee('Google');
    });
});

describe('refresh (POST /settings/modules/refresh)', function () {
    it('flushes cache, fetches fresh modules and redirects with status message', function () {
        $user = User::factory()->create();

        Http::fake([
            'https://clockworkcontrol.com/api/modules.json' => Http::response([
                'schema_version' => '1.0',
                'generated_at' => '2026-09-04T13:00:00Z',
                'total_modules' => 1,
                'modules' => [
                    [
                        'id' => 'digitalocean',
                        'name' => 'DigitalOcean',
                        'category' => 'cloud_vps',
                        'author' => 'Clockwork Web Dev',
                        'status' => 'official',
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($user)->post(route('settings.modules.refresh'));

        $response->assertRedirect(route('settings.modules.index'))
            ->assertSessionHas('status', 'Module directory refreshed successfully from clockworkcontrol.com.');
    });
});
