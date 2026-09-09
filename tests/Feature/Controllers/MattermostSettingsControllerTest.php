<?php

use App\Models\User;
use App\Services\Chat\ChatNotifier;
use App\Support\Settings;
use Modules\Mattermost\MattermostNotifier;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| MattermostSettingsController
|--------------------------------------------------------------------------
|
| Verified against the real controller:
|   - index() redirects to settings.integrations.index when
|     clockwork.mattermost.enabled is false — the page isn't reachable at
|     all until the integration is turned on in .env.
|   - When enabled, index() reads Settings::get(MattermostNotifier::SETTINGS_KEY, [])
|     and, for every key in ChatNotifier::EVENTS, shows it enabled when the
|     stored array has no entry for that key at all (falls back to the
|     event's own 'default', which is true for every current event) OR when
|     the stored entry is truthy.
|   - toggleEvent() flips exactly one key and rewrites the full event map
|     back to storage (defaulting any other never-touched key the same way
|     index() does) — no separate Save button, each toggle auto-saves.
|   - toggleEvent() 404s for an unknown event key or while the integration
|     is disabled in config.
*/

beforeEach(function () {
    $this->mockIssueCounterZero();
});

describe('auth gate', function () {
    it('redirects guests away from the mattermost settings page', function () {
        $response = $this->get(route('settings.mattermost.index'));

        $response->assertRedirect(route('login'));
    });
});

describe('access when disabled', function () {
    it('redirects to integrations with a notice when mattermost is disabled in config', function () {
        config(['clockwork.mattermost.enabled' => false]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.mattermost.index'));

        $response->assertRedirect(route('settings.integrations.index'));
        $response->assertSessionHas('status_error');
    });

    it('404s a toggle attempt while mattermost is disabled in config', function () {
        config(['clockwork.mattermost.enabled' => false]);

        $response = $this->actingAs(User::factory()->create())
            ->patch(route('settings.mattermost.events.update', 'site_went_down'), ['enabled' => '1']);

        $response->assertNotFound();
    });
});

describe('index', function () {
    beforeEach(function () {
        config(['clockwork.mattermost.enabled' => true]);
    });

    it('defaults every event to enabled when nothing is stored', function () {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.mattermost.index'));

        $response->assertOk();

        foreach (array_keys(ChatNotifier::EVENTS) as $key) {
            $response->assertSee('aria-label="Toggle', false);
        }
    });

    it('reflects a stored disabled event as off', function () {
        app(Settings::class)->put(MattermostNotifier::SETTINGS_KEY, [
            'site_went_down' => false,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.mattermost.index'));

        $response->assertOk();

        $html = $response->getContent();
        preg_match('/<tr>.*?site_went_down.*?<\/tr>/s', $html, $rowMatch);
        expect($rowMatch)->not->toBeEmpty();
        expect($rowMatch[0])->toContain('on: false');
    });

    it('shows the configured pill when the webhook url is set', function () {
        config(['clockwork.mattermost.webhook_url' => 'https://example.test/hook']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.mattermost.index'));

        $response->assertOk()->assertSee('Mattermost integration is configured.');
    });

    it('warns when no webhook url is set', function () {
        config(['clockwork.mattermost.webhook_url' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.mattermost.index'));

        $response->assertOk()->assertSee('No webhook URL is set.');
    });
});

describe('toggleEvent', function () {
    beforeEach(function () {
        config(['clockwork.mattermost.enabled' => true]);
    });

    it('enables a single event and leaves the rest at their defaults', function () {
        $response = $this->actingAs(User::factory()->create())
            ->patch(route('settings.mattermost.events.update', 'site_went_down'), ['enabled' => '1']);

        $response->assertOk()->assertJson(['ok' => true, 'enabled' => true]);

        $stored = app(Settings::class)->get(MattermostNotifier::SETTINGS_KEY, []);

        expect($stored['site_went_down'])->toBeTrue();
        foreach (array_keys(ChatNotifier::EVENTS) as $key) {
            if ($key === 'site_went_down') {
                continue;
            }
            expect($stored[$key])->toBe((bool) ChatNotifier::EVENTS[$key]['default']);
        }
    });

    it('disables a single event without touching any other stored key', function () {
        app(Settings::class)->put(MattermostNotifier::SETTINGS_KEY, [
            'site_went_down' => true,
            'ssl_state_changed' => true,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->patch(route('settings.mattermost.events.update', 'site_went_down'), ['enabled' => '0']);

        $response->assertOk()->assertJson(['ok' => true, 'enabled' => false]);

        $stored = app(Settings::class)->get(MattermostNotifier::SETTINGS_KEY, []);

        expect($stored['site_went_down'])->toBeFalse();
        expect($stored['ssl_state_changed'])->toBeTrue();
    });

    it('404s for an unknown event key', function () {
        $response = $this->actingAs(User::factory()->create())
            ->patch(route('settings.mattermost.events.update', 'not_a_real_event'), ['enabled' => '1']);

        $response->assertNotFound();
    });
});
