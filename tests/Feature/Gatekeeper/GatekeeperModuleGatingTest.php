<?php

namespace Tests\Feature\Gatekeeper;

use App\Models\Site;
use App\Models\User;
use App\Services\Fail2ban\IgnoreIpListBuilder;
use App\Services\Gatekeeper\GatekeeperSettingsPusher;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleStateResolver;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| Gatekeeper is optional — module off must mean genuinely off
|--------------------------------------------------------------------------
|
| The module toggle in /settings/modules is the agency's "I don't want this"
| switch. Every Gatekeeper entry point (settings page, fleet push, rollout,
| tab link) has to honour it without side effects or red noise. These tests
| flip the real installed_modules row rather than mocking the resolver so
| they exercise the same path the UI does.
*/

function disableGatekeeperModule(): void
{
    InstalledModule::create([
        'module_id' => 'gatekeeper',
        'name' => 'Gatekeeper',
        'enabled' => false,
    ]);

    app(ModuleStateResolver::class)->flush();
}

function gatekeeperCapableSite(array $attrs = []): Site
{
    return Site::factory()->spinupwp()->create(array_merge([
        'is_wordpress' => true,
        'is_inactive' => false,
        'companion_installed' => true,
        'companion_version' => '1.39.1',
        'companion_secret' => 'test-secret',
        'companion_capabilities' => ['gatekeeper', 'plugins'],
        'gatekeeper_settings' => ['enabled' => true],
        'llar_enabled' => false,
    ], $attrs));
}

beforeEach(function () {
    app(ModuleStateResolver::class)->flush();
    $this->mock(IgnoreIpListBuilder::class, fn ($mock) => $mock->shouldReceive('build')->andReturn([]));
});

afterEach(function () {
    app(ModuleStateResolver::class)->flush();
});

describe('module disabled', function () {
    it('makes clockwork:push-gatekeeper-settings skip cleanly instead of reporting failures', function () {
        gatekeeperCapableSite(['domain' => 'quiet.example']);
        disableGatekeeperModule();
        Http::fake();

        $this->artisan('clockwork:push-gatekeeper-settings')
            ->assertSuccessful()
            ->expectsOutputToContain('Gatekeeper module is disabled')
            ->doesntExpectOutputToContain('failed to sync');

        Http::assertNothingSent();
    });

    it('makes clockwork:gatekeeper-rollout refuse before touching any site', function () {
        gatekeeperCapableSite(['domain' => 'rollout.example', 'gatekeeper_settings' => null, 'llar_enabled' => true]);
        disableGatekeeperModule();
        Http::fake();

        $this->artisan('clockwork:gatekeeper-rollout', ['--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('The Gatekeeper module is disabled in the Module Directory.');

        Http::assertNothingSent();
        expect(Site::where('domain', 'rollout.example')->first()->llar_enabled)->toBeTrue();
    });

    it('makes GatekeeperSettingsPusher::maybePush a no-op', function () {
        $site = gatekeeperCapableSite();
        disableGatekeeperModule();
        Http::fake();

        expect(app(GatekeeperSettingsPusher::class)->maybePush($site))->toBeFalse();
        Http::assertNothingSent();
    });

    it('redirects /settings/gatekeeper to the Module Directory', function () {
        $this->mockIssueCounterZero();
        disableGatekeeperModule();

        $this->actingAs(User::factory()->create())
            ->get(route('settings.gatekeeper.index'))
            ->assertRedirect(route('settings.modules.index'))
            ->assertSessionHas('status_error');
    });

    it('refuses the fleet-policy update and sync actions', function () {
        $this->mockIssueCounterZero();
        disableGatekeeperModule();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('settings.gatekeeper.update'), ['enabled' => 1, 'threshold' => 5])
            ->assertRedirect(route('settings.modules.index'));

        expect(app(Settings::class)->get('gatekeeper.enabled'))->toBeNull();

        $this->actingAs($user)
            ->post(route('settings.gatekeeper.syncNow'))
            ->assertRedirect(route('settings.modules.index'));
    });

    it('hides the Login Lockouts tab from the settings navigation', function () {
        $this->mockIssueCounterZero();
        disableGatekeeperModule();

        $this->actingAs(User::factory()->create())
            ->get(route('settings.ingest.index'))
            ->assertOk()
            ->assertDontSee(route('settings.gatekeeper.index'));
    });
});

describe('module enabled (default)', function () {
    it('shows the Login Lockouts tab and renders the settings page', function () {
        $this->mockIssueCounterZero();

        $this->actingAs(User::factory()->create())
            ->get(route('settings.gatekeeper.index'))
            ->assertOk()
            ->assertSee(route('settings.gatekeeper.index'));
    });
});

describe('per-site override durability', function () {
    it('keeps enabled=true on the nightly push when the fleet default is off', function () {
        app(Settings::class)->put('gatekeeper.enabled', false);

        $migrated = gatekeeperCapableSite(['domain' => 'migrated.example']);
        $inherits = gatekeeperCapableSite(['domain' => 'inherits.example', 'gatekeeper_settings' => null]);

        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->artisan('clockwork:push-gatekeeper-settings')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'migrated.example')
            && str_ends_with($r->url(), '/gatekeeper-settings')
            && $r['enabled'] === true);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'inherits.example')
            && str_ends_with($r->url(), '/gatekeeper-settings')
            && $r['enabled'] === false);

        expect($migrated->fresh()->gatekeeperEnabled())->toBeTrue()
            ->and($inherits->fresh()->gatekeeperEnabled())->toBeFalse();
    });

    it('lets an explicit per-site enabled=false win over a fleet-on default in the pushed payload', function () {
        app(Settings::class)->put('gatekeeper.enabled', true);

        $sso = gatekeeperCapableSite(['domain' => 'sso.example', 'gatekeeper_settings' => ['enabled' => false]]);

        $payload = app(GatekeeperSettingsPusher::class)->buildPayload($sso);

        expect($payload['enabled'])->toBeFalse()
            ->and($sso->gatekeeperEnabled())->toBeFalse();
    });
});

describe('command alias', function () {
    it('accepts clockwork:pull-lockouts as an alias for clockwork:pull-llar-lockouts', function () {
        $this->artisan('clockwork:pull-lockouts', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('clockwork:pull-llar-lockouts', ['--dry-run' => true])->assertSuccessful();
    });
});
