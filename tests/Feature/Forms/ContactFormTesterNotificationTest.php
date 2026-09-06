<?php

namespace Tests\Feature\Forms;

use App\Models\ContactFormTest;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Services\Forms\ContactFormTester;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| ContactFormTester::maybeNotify() call-site coverage
|--------------------------------------------------------------------------
|
| This is Phase 4 "notification event call-site" coverage — it confirms
| the two ChatNotifier events fired from ContactFormTester::maybeNotify()
| (read directly from app/Services/Forms/ContactFormTester.php on
| 2026-09-03) fire under their real triggering condition and respect their
| gating. It does NOT re-test ChatNotifierDispatcher's own is_inactive
| gating — that's tests/Feature/Chat/ChatNotifierGatingTest.php's job.
|
| Gating confirmed by reading maybeNotify() (and ContactFormTest's
| ALERT_STREAK_THRESHOLD = 2):
|
|   contactFormTestFailed    fires ONLY when newStreak === ALERT_STREAK_THRESHOLD
|                             exactly — the moment the streak crosses the
|                             threshold. A streak of THRESHOLD-1 must not
|                             fire, and a streak of THRESHOLD+1 (still
|                             failing on the *next* test after the alert
|                             already fired) must not re-fire either.
|
|   contactFormTestRecovered fires ONLY when oldState was RESULT_FAILED,
|                             newState is RESULT_SUCCESS, AND oldStreak was
|                             already >= ALERT_STREAK_THRESHOLD — i.e. only
|                             a recovery from a streak that actually paged
|                             someone. A short failure streak that recovers
|                             before ever reaching the threshold must not
|                             fire a recovery notice, since nobody was ever
|                             notified of a problem in the first place.
|
| The public entry point is ContactFormTester::test(), which calls
| ClockworkCompanionClient::testContactForm() (an HMAC-signed POST to
| /wp-json/clockwork/v1/test-contact-form on the site) and then
| recordAndTransition() -> maybeNotify(). Http::fake() supplies that
| response so no real network call is made.
*/

function fakeContactFormTestEndpoint(Site $site, bool $ok, string $error = ''): void
{
    Http::fake([
        "https://{$site->domain}/wp-json/clockwork/v1/test-contact-form" => Http::response([
            'ok' => $ok,
            'accepted' => true,
            'mail_invoked' => true,
            'mail_outcome' => $ok ? 'received' : 'no_email_received',
            'plugin_status' => 'active',
            'error' => $ok ? null : ($error ?: 'No email received within timeout window.'),
        ], 200, ['Content-Type' => 'application/json']),
    ]);
}

function eligibleContactFormTest(array $overrides = []): ContactFormTest
{
    $site = Site::factory()->carePlan()->withCompanionInstalled()->create();

    return ContactFormTest::factory()->for($site)->create(array_merge([
        'form_plugin' => Site::CONTACT_FORM_PLUGIN_CF7,
        'form_id' => '42',
    ], $overrides));
}

describe('contactFormTestFailed fires only on the exact threshold crossing', function () {
    it('fires with the site, reason, streak, and form id the moment the streak hits ALERT_STREAK_THRESHOLD', function () {
        $cft = eligibleContactFormTest([
            'state' => ContactFormTest::STATE_FAILED,
            'failure_streak' => ContactFormTest::ALERT_STREAK_THRESHOLD - 1,
        ]);
        fakeContactFormTestEndpoint($cft->site, ok: false, error: 'SMTP relay timed out.');

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('contactFormTestFailed')
                ->once()
                ->withArgs(fn (Site $s, string $reason, int $streak, ?string $formId) => $streak === ContactFormTest::ALERT_STREAK_THRESHOLD
                    && str_contains($reason, 'SMTP relay timed out')
                    && $formId === '42'
                )
                ->andReturn(true);
            $mock->shouldReceive('contactFormTestRecovered')->never();
        });

        $result = app(ContactFormTester::class)->test($cft);

        expect($result['result'])->toBe(ContactFormTester::RESULT_FAILED)
            ->and($cft->refresh()->failure_streak)->toBe(ContactFormTest::ALERT_STREAK_THRESHOLD);
    });

    it('does NOT fire when the failure streak is still below the threshold', function () {
        $cft = eligibleContactFormTest([
            'state' => ContactFormTest::STATE_PENDING,
            'failure_streak' => 0,
        ]);
        fakeContactFormTestEndpoint($cft->site, ok: false);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('contactFormTestFailed')->never();
            $mock->shouldReceive('contactFormTestRecovered')->never();
        });

        $result = app(ContactFormTester::class)->test($cft);

        expect($result['result'])->toBe(ContactFormTester::RESULT_FAILED)
            ->and($cft->refresh()->failure_streak)->toBe(1); // one below ALERT_STREAK_THRESHOLD (2)
    });

    it('does NOT re-fire when the streak keeps climbing past a threshold already alerted on', function () {
        $cft = eligibleContactFormTest([
            'state' => ContactFormTest::STATE_FAILED,
            'failure_streak' => ContactFormTest::ALERT_STREAK_THRESHOLD, // already alerted last run
        ]);
        fakeContactFormTestEndpoint($cft->site, ok: false);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('contactFormTestFailed')->never();
            $mock->shouldReceive('contactFormTestRecovered')->never();
        });

        $result = app(ContactFormTester::class)->test($cft);

        expect($result['result'])->toBe(ContactFormTester::RESULT_FAILED)
            ->and($cft->refresh()->failure_streak)->toBe(ContactFormTest::ALERT_STREAK_THRESHOLD + 1);
    });
});

describe('contactFormTestRecovered fires only when the recovered streak actually crossed the alert threshold', function () {
    it('fires with the site and form id when a streak that hit the threshold then succeeds', function () {
        $cft = eligibleContactFormTest([
            'state' => ContactFormTest::STATE_FAILED,
            'failure_streak' => ContactFormTest::ALERT_STREAK_THRESHOLD,
        ]);
        fakeContactFormTestEndpoint($cft->site, ok: true);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('contactFormTestRecovered')
                ->once()
                ->withArgs(fn (Site $s, ?string $formId) => $formId === '42')
                ->andReturn(true);
            $mock->shouldReceive('contactFormTestFailed')->never();
        });

        $result = app(ContactFormTester::class)->test($cft);

        expect($result['result'])->toBe(ContactFormTester::RESULT_SUCCESS)
            ->and($cft->refresh())
            ->state->toBe(ContactFormTest::STATE_SUCCESS)
            ->failure_streak->toBe(0);
    });

    it('does NOT fire when recovering from a streak that never reached the threshold', function () {
        $cft = eligibleContactFormTest([
            'state' => ContactFormTest::STATE_FAILED,
            'failure_streak' => ContactFormTest::ALERT_STREAK_THRESHOLD - 1,
        ]);
        fakeContactFormTestEndpoint($cft->site, ok: true);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('contactFormTestRecovered')->never();
            $mock->shouldReceive('contactFormTestFailed')->never();
        });

        $result = app(ContactFormTester::class)->test($cft);

        expect($result['result'])->toBe(ContactFormTester::RESULT_SUCCESS)
            ->and($cft->refresh())
            ->state->toBe(ContactFormTest::STATE_SUCCESS)
            ->failure_streak->toBe(0);
    });

    it('does NOT fire on a success-to-success run (no prior failure to recover from)', function () {
        $cft = eligibleContactFormTest([
            'state' => ContactFormTest::STATE_SUCCESS,
            'failure_streak' => 0,
        ]);
        fakeContactFormTestEndpoint($cft->site, ok: true);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('contactFormTestRecovered')->never();
            $mock->shouldReceive('contactFormTestFailed')->never();
        });

        $result = app(ContactFormTester::class)->test($cft);

        expect($result['result'])->toBe(ContactFormTester::RESULT_SUCCESS);
    });
});

describe('ineligible rows never reach maybeNotify() at all', function () {
    it('skips the test (and calls neither notifier) when the row is disabled', function () {
        $cft = eligibleContactFormTest(['enabled' => false]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldNotReceive('contactFormTestFailed');
            $mock->shouldNotReceive('contactFormTestRecovered');
        });

        $result = app(ContactFormTester::class)->test($cft);

        expect($result['result'])->toBe(ContactFormTester::RESULT_SKIPPED);
    });
});
