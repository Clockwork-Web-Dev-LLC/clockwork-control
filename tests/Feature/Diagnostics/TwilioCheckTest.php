<?php

use App\Services\Diagnostics\CheckResult;
use Modules\Twilio\TwilioCheck;

/**
 * Coverage for TwilioCheck (App\Services\Diagnostics\DiagnosticCheck).
 *
 * Unlike every other Checks/* class, TwilioCheck does not go through
 * Illuminate\Support\Facades\Http — it hard-instantiates the official
 * Twilio SDK's `new \Twilio\Rest\Client($sid, $token)` directly, with no
 * injectable HTTP client and no container binding for it anywhere in the
 * app (confirmed by reading IntegrationServiceProvider — only
 * App\Services\Twilio\TwilioClient, a different class used for actually
 * sending SMS, is bound there). The SDK's default HTTP transport
 * (Twilio\Http\CurlClient) calls the raw \curl_init()/\curl_exec()/etc.
 * functions with a leading root-namespace backslash, which forces them to
 * resolve as the real global cURL functions — so Http::fake() cannot
 * intercept this check's request, and there is no seam to mock it from a
 * test without modifying app/ code, which is out of scope for this phase.
 *
 * Given that constraint:
 *   - The "skipped" branch is pure config logic and is fully covered below.
 *   - The "fail" branch is exercised with a real network round-trip to
 *     Twilio's real API using obviously-fake, non-secret credentials
 *     ("ACtest"/"badtoken" below are placeholders, not real credentials of
 *     any account). This is deterministic either way: a reachable Twilio
 *     API rejects them with a real 401, which the SDK turns into a
 *     TwilioException; an unreachable network throws a connection
 *     exception instead. Both land in TwilioCheck's catch(Throwable) and
 *     produce the same STATUS_FAIL shape, so the assertions below don't
 *     depend on which one actually happened.
 *   - The "ok" branch would require a real, valid Twilio Account
 *     SID/Auth Token pair to observe a genuine 200 — there is no way to
 *     fake that response, so it is intentionally not covered here rather
 *     than asserted against a mock that wouldn't reflect the real code path.
 */
describe('TwilioCheck', function () {
    it('skips cleanly when Twilio is disabled', function () {
        config([
            'clockwork.twilio.enabled' => false,
            'clockwork.twilio.account_sid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'clockwork.twilio.auth_token' => 'authtoken',
            'clockwork.twilio.from' => '+15555550100',
        ]);

        $result = app(TwilioCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toBe('TWILIO_ENABLED/ACCOUNT_SID/AUTH_TOKEN/FROM_NUMBER not fully set');
    });

    it('skips cleanly when enabled but one of the required credentials is missing', function () {
        config([
            'clockwork.twilio.enabled' => true,
            'clockwork.twilio.account_sid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'clockwork.twilio.auth_token' => '',
            'clockwork.twilio.from' => '+15555550100',
        ]);

        $result = app(TwilioCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toBe('TWILIO_ENABLED/ACCOUNT_SID/AUTH_TOKEN/FROM_NUMBER not fully set');
    });

    it('returns fail with a message when the account fetch is rejected', function () {
        config([
            'clockwork.twilio.enabled' => true,
            'clockwork.twilio.account_sid' => 'ACtest00000000000000000000000000',
            'clockwork.twilio.auth_token' => 'badtoken',
            'clockwork.twilio.from' => '+15555550100',
        ]);

        $result = app(TwilioCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->not->toBeEmpty();
    })->group('network');
});
