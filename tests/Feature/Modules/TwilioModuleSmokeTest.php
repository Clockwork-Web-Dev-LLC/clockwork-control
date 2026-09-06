<?php

use App\Models\NotificationRecipient;
use App\Models\Site;
use App\Models\User;
use Modules\Core\Contracts\SmsNotifier;
use Modules\Core\NullSmsNotifier;
use Modules\Twilio\TwilioSmsNotifier;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/**
 * Live-verifies the Twilio module reached the app end to end after its
 * extraction into modules/Twilio/ — mirrors MattermostModuleSmokeTest.php
 * and SlackModuleSmokeTest.php, adapted for Twilio's real shape:
 * - No module-owned settings page (unlike Mattermost/Slack) — recipient/
 *   off-window CRUD is genuinely vendor-agnostic and stays on the core
 *   /settings/notifications page (NotificationSettingsController), which
 *   now depends on the SmsNotifier contract rather than concrete Twilio
 *   classes.
 * - Single-resolution ("at most one active vendor"), not a fan-out tag —
 *   so the meaningful thing to prove is that the container actually binds
 *   Modules\Core\Contracts\SmsNotifier to Modules\Twilio\TwilioSmsNotifier
 *   with the Twilio module installed, not to NullSmsNotifier.
 */
it('renders /settings/notifications with the SMS settings UI, resolved through the SmsNotifier contract', function () {
    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.notifications.index'));

    $response->assertOk()
        ->assertSee('SMS');
});

it('renders /settings/diagnostics with the Twilio check listed exactly once', function () {
    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.diagnostics.index'));

    $response->assertOk();
    $content = $response->getContent();
    expect(substr_count($content, 'Twilio API'))->toBe(1);
});

it('binds the SmsNotifier contract to the real Twilio module, not the null fallback, with the module installed', function () {
    expect(app(SmsNotifier::class))->toBeInstanceOf(TwilioSmsNotifier::class);
});

it('NullSmsNotifier no-ops every method rather than throwing, for the no-SMS-module-installed case', function () {
    $null = new NullSmsNotifier;

    expect($null->isConfigured())->toBeFalse()
        ->and($null->fromNumber())->toBe('')
        ->and($null->siteWentDown(Site::factory()->make(), 500, 'error'))->toBeFalse()
        ->and($null->siteWentUp(Site::factory()->make(), 120))->toBeFalse()
        ->and($null->test(NotificationRecipient::factory()->make()))->toBeFalse();
});
