<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleStateResolver;
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
            ->assertSee('Feedback')
            ->assertSee('Community')
            ->assertSee('Official')
            ->assertSee('Check for updates')
            ->assertDontSee('CPU &amp; RAM Telemetry', false)
            ->assertSee(route('settings.integrations.index').'#integration-digitalocean', false)
            ->assertSee(route('feedback.index'), false);
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
            ->assertSee('Feedback')
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

describe('toggle (POST /settings/modules/toggle)', function () {
    it('requires authentication to toggle a module', function () {
        $this->post(route('settings.modules.toggle'), ['module' => 'feedback', 'enabled' => false])
            ->assertRedirect(route('login'));
    });

    it('immediately disables an active module and returns json', function () {
        $user = User::factory()->create();

        InstalledModule::updateOrCreate(
            ['module_id' => 'feedback'],
            ['name' => 'Feedback', 'enabled' => true, 'status' => 'active']
        );

        $response = $this->actingAs($user)->postJson(route('settings.modules.toggle'), [
            'module' => 'feedback',
            'enabled' => false,
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'module' => 'feedback',
                'enabled' => false,
            ]);

        expect(InstalledModule::where('module_id', 'feedback')->value('enabled'))->toBeFalse();
        expect(app(ModuleStateResolver::class)->isEnabled('feedback'))->toBeFalse();
    });

    it('immediately enables a disabled module', function () {
        $user = User::factory()->create();

        InstalledModule::updateOrCreate(
            ['module_id' => 'feedback'],
            ['name' => 'Feedback', 'enabled' => false, 'status' => 'active']
        );

        $response = $this->actingAs($user)->post(route('settings.modules.toggle'), [
            'module' => 'feedback',
            'enabled' => true,
        ]);

        $response->assertRedirect()
            ->assertSessionHas('status');

        expect(InstalledModule::where('module_id', 'feedback')->value('enabled'))->toBeTrue();
        expect(app(ModuleStateResolver::class)->isEnabled('feedback'))->toBeTrue();
    });

    it('returns 404 for unknown modules', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('settings.modules.toggle'), [
            'module' => 'nonexistent_module',
            'enabled' => true,
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    });
});
