<?php

use App\Models\User;
use App\Services\Chat\ChatNotifier;
use App\Support\Settings;
use Modules\Slack\SlackNotifier;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| SlackSettingsController
|--------------------------------------------------------------------------
|
| Same mechanics as MattermostSettingsController (confirmed by reading
| both side by side): index() redirects away when clockwork.slack.enabled
| is false, and toggleEvent() flips exactly one key while rehydrating the
| rest from their defaults — see that test file's header for the full
| explanation. Only the settings key (SlackNotifier::SETTINGS_KEY =
| 'notifications.slack.events'), the config namespace, and the view copy
| differ.
*/

beforeEach(function () {
    $this->mockIssueCounterZero();
});

describe('auth gate', function () {
    it('redirects guests away from the slack settings page', function () {
        $response = $this->get(route('settings.slack.index'));

        $response->assertRedirect(route('login'));
    });
});

describe('access when disabled', function () {
    it('redirects to integrations with a notice when slack is disabled in config', function () {
        config(['clockwork.slack.enabled' => false]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.slack.index'));

        $response->assertRedirect(route('settings.integrations.index'));
        $response->assertSessionHas('status_error');
    });

    it('404s a toggle attempt while slack is disabled in config', function () {
        config(['clockwork.slack.enabled' => false]);

        $response = $this->actingAs(User::factory()->create())
            ->patch(route('settings.slack.events.update', 'site_went_down'), ['enabled' => '1']);

        $response->assertNotFound();
    });
});

describe('index', function () {
    beforeEach(function () {
        config(['clockwork.slack.enabled' => true]);
    });

    it('defaults every event to enabled when nothing is stored', function () {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.slack.index'));

        $response->assertOk();

        foreach (array_keys(ChatNotifier::EVENTS) as $key) {
            $response->assertSee('aria-label="Toggle', false);
        }
    });

    it('reflects a stored disabled event as off', function () {
        app(Settings::class)->put(SlackNotifier::SETTINGS_KEY, [
            'site_went_down' => false,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.slack.index'));

        $response->assertOk();

        $html = $response->getContent();
        preg_match('/<tr>.*?site_went_down.*?<\/tr>/s', $html, $rowMatch);
        expect($rowMatch)->not->toBeEmpty();
        expect($rowMatch[0])->toContain('on: false');
    });

    it('shows the configured pill when the webhook url is set', function () {
        config(['clockwork.slack.webhook_url' => 'https://example.test/hook']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.slack.index'));

        $response->assertOk()->assertSee('Slack integration is configured.');
    });

    it('warns when no webhook url is set', function () {
        config(['clockwork.slack.webhook_url' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.slack.index'));

        $response->assertOk()->assertSee('No webhook URL is set.');
    });
});

describe('toggleEvent', function () {
    beforeEach(function () {
        config(['clockwork.slack.enabled' => true]);
    });

    it('enables a single event and leaves the rest at their defaults', function () {
        $response = $this->actingAs(User::factory()->create())
            ->patch(route('settings.slack.events.update', 'site_went_down'), ['enabled' => '1']);

        $response->assertOk()->assertJson(['ok' => true, 'enabled' => true]);

        $stored = app(Settings::class)->get(SlackNotifier::SETTINGS_KEY, []);

        expect($stored['site_went_down'])->toBeTrue();
        foreach (array_keys(ChatNotifier::EVENTS) as $key) {
            if ($key === 'site_went_down') {
                continue;
            }
            expect($stored[$key])->toBe((bool) ChatNotifier::EVENTS[$key]['default']);
        }
    });

    it('disables a single event without touching any other stored key', function () {
        app(Settings::class)->put(SlackNotifier::SETTINGS_KEY, [
            'site_went_down' => true,
            'ssl_state_changed' => true,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->patch(route('settings.slack.events.update', 'site_went_down'), ['enabled' => '0']);

        $response->assertOk()->assertJson(['ok' => true, 'enabled' => false]);

        $stored = app(Settings::class)->get(SlackNotifier::SETTINGS_KEY, []);

        expect($stored['site_went_down'])->toBeFalse();
        expect($stored['ssl_state_changed'])->toBeTrue();
    });

    it('404s for an unknown event key', function () {
        $response = $this->actingAs(User::factory()->create())
            ->patch(route('settings.slack.events.update', 'not_a_real_event'), ['enabled' => '1']);

        $response->assertNotFound();
    });
});
