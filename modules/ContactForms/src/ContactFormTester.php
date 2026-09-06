<?php

namespace Modules\ContactForms;

use App\Models\ContactFormTest;
use App\Models\ContactFormTestRun;
use App\Services\Chat\ChatNotifier;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Support\Str;
use Throwable;

class ContactFormTester
{
    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILED = 'failed';

    public const RESULT_SKIPPED = 'skipped';

    public function __construct(private readonly ChatNotifier $mattermost) {}

    /**
     * Run one test against one configured form, persist the result row,
     * evaluate state transition, fire Mattermost on the threshold-cross
     * or recovery.
     *
     * @return array{result: string, message: string, run_id?: int, transitioned?: bool}
     */
    public function test(ContactFormTest $cft, string $mode = 'lab'): array
    {
        if (! $this->eligible($cft)) {
            return ['result' => self::RESULT_SKIPPED, 'message' => $this->ineligibilityReason($cft)];
        }

        $site = $cft->site;
        $marker = '[Clockwork Health Check #'.Str::lower(Str::random(8)).']';

        try {
            $payload = (new ClockworkCompanionClient($site))->testContactForm(
                $cft->form_plugin,
                $cft->form_id,
                $marker,
                $mode,
            );
        } catch (Throwable $e) {
            return $this->recordAndTransition($cft, $mode, [
                'ok' => false,
                'accepted' => false,
                'mail_invoked' => false,
                'mail_outcome' => null,
                'plugin_status' => null,
                'error' => 'Companion call failed: '.$e->getMessage(),
            ]);
        }

        return $this->recordAndTransition($cft, $mode, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{result: string, message: string, run_id?: int, transitioned?: bool}
     */
    private function recordAndTransition(ContactFormTest $cft, string $mode, array $payload): array
    {
        $ok = (bool) ($payload['ok'] ?? false);
        $accepted = (bool) ($payload['accepted'] ?? false);
        $mailInvoked = (bool) ($payload['mail_invoked'] ?? false);
        $mailOutcome = $payload['mail_outcome'] ?? null;
        $error = $payload['error'] ?? null;

        $status = $ok ? self::RESULT_SUCCESS : self::RESULT_FAILED;

        $run = ContactFormTestRun::create([
            'site_id' => $cft->site_id,
            'contact_form_test_id' => $cft->id,
            'ran_at' => now(),
            'mode' => $mode,
            'accepted' => $accepted,
            'mail_invoked' => $mailInvoked,
            'mail_outcome' => is_string($mailOutcome) ? $mailOutcome : null,
            'status' => $status,
            'error' => is_string($error) ? Str::limit($error, 1000) : null,
            'response_excerpt' => Str::limit(json_encode($payload), 1000),
        ]);

        $oldState = $cft->state;
        $oldStreak = (int) $cft->failure_streak;

        $newStreak = $ok ? 0 : $oldStreak + 1;
        $newState = $status;

        $stateChanged = $oldState !== $newState;
        $cft->forceFill([
            'state' => $newState,
            'state_changed_at' => $stateChanged ? now() : $cft->state_changed_at,
            'last_test_at' => now(),
            'last_test_error' => $ok ? null : (is_string($error) ? Str::limit($error, 500) : null),
            'failure_streak' => $newStreak,
        ])->save();

        // Companion last-seen on the parent site is a useful side effect of
        // the test having returned at all — bump it.
        $cft->site->forceFill(['companion_last_seen_at' => now()])->save();

        $this->maybeNotify($cft, $oldState, $newState, $oldStreak, $newStreak, (string) ($error ?? ''));

        return [
            'result' => $status,
            'message' => $ok
                ? "Test passed (mail_outcome={$mailOutcome})."
                : 'Test failed: '.($error ?: 'unknown error'),
            'run_id' => $run->id,
            'transitioned' => $stateChanged,
        ];
    }

    private function maybeNotify(ContactFormTest $cft, ?string $oldState, string $newState, int $oldStreak, int $newStreak, string $reason): void
    {
        // Recovery: was failed (and someone got pinged), now success.
        if ($oldState === self::RESULT_FAILED && $newState === self::RESULT_SUCCESS && $oldStreak >= ContactFormTest::ALERT_STREAK_THRESHOLD) {
            $this->mattermost->contactFormTestRecovered($cft->site, $cft->form_id);

            return;
        }

        // Threshold cross: streak just hit the alert level.
        if ($newStreak === ContactFormTest::ALERT_STREAK_THRESHOLD) {
            $this->mattermost->contactFormTestFailed($cft->site, $reason ?: 'Form test failed.', $newStreak, $cft->form_id);
        }
    }

    public function eligible(ContactFormTest $cft): bool
    {
        $site = $cft->site;

        return $cft->enabled
            && $site
            && $site->care_plan_enabled
            && $site->companion_installed
            && is_string($site->companion_secret) && $site->companion_secret !== ''
            && is_string($cft->form_plugin) && $cft->form_plugin !== ''
            && is_string($cft->form_id) && $cft->form_id !== '';
    }

    private function ineligibilityReason(ContactFormTest $cft): string
    {
        $site = $cft->site;

        if (! $cft->enabled) {
            return 'This form-test row is disabled.';
        }
        if (! $site) {
            return 'Parent site missing — should not happen.';
        }
        if (! $site->care_plan_enabled) {
            return 'Contact form testing is part of the care plan; this site is not on a care plan.';
        }
        if (! $site->companion_installed) {
            return 'Companion plugin not installed.';
        }
        if (! $site->companion_secret) {
            return 'Companion secret missing.';
        }
        if (! $cft->form_plugin) {
            return 'No detected form plugin (run clockwork:detect-contact-forms).';
        }
        if (! $cft->form_id) {
            return 'No form ID set on this row.';
        }

        return 'Form-test not eligible.';
    }
}
