<?php

use App\Services\Diagnostics\CheckResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Slack\SlackCheck;

/**
 * Coverage for SlackCheck (App\Services\Diagnostics\DiagnosticCheck).
 * Mirrors MattermostCheck exactly — a GET (never POST) against the
 * configured incoming-webhook URL, classified purely on HTTP status. See
 * MattermostCheckTest for the shared status-mapping rationale:
 *
 *   - clockwork.slack.enabled === false       -> STATUS_SKIPPED.
 *   - enabled === true but webhook_url === '' -> STATUS_FAIL.
 *   - response status < 500                   -> STATUS_OK.
 *   - response status >= 500                   -> STATUS_FAIL.
 *   - the request itself throwing              -> STATUS_FAIL with the
 *     exception message as detail.
 */
describe('SlackCheck', function () {
    it('skips cleanly when Slack notifications are disabled', function () {
        config([
            'clockwork.slack.enabled' => false,
            'clockwork.slack.webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX',
        ]);

        $result = app(SlackCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toBe('Slack notifications disabled');
    });

    it('fails when enabled but no webhook URL is configured', function () {
        config([
            'clockwork.slack.enabled' => true,
            'clockwork.slack.webhook_url' => '',
        ]);

        $result = app(SlackCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('No webhook URL configured');
    });

    it('returns ok when the GET reaches the webhook host', function () {
        config([
            'clockwork.slack.enabled' => true,
            'clockwork.slack.webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX',
        ]);

        Http::fake([
            'hooks.slack.com/services/T000/B000/XXXX' => Http::response('ok', 200),
        ]);

        $result = app(SlackCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('hooks.slack.com')
            ->and($result->summary)->toContain('HTTP 200');
    });

    it('still returns ok on a sub-500 error status, since that still proves the host is reachable', function () {
        config([
            'clockwork.slack.enabled' => true,
            'clockwork.slack.webhook_url' => 'https://hooks.slack.com/services/T000/B000/REVOKED',
        ]);

        Http::fake([
            'hooks.slack.com/services/T000/B000/REVOKED' => Http::response(['error' => 'invalid_token'], 403),
        ]);

        $result = app(SlackCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('HTTP 403');
    });

    it('returns fail with the status when the webhook host returns a server error', function () {
        config([
            'clockwork.slack.enabled' => true,
            'clockwork.slack.webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX',
        ]);

        Http::fake([
            'hooks.slack.com/services/T000/B000/XXXX' => Http::response('boom', 503),
        ]);

        $result = app(SlackCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Server error · HTTP 503');
    });

    it('returns fail with the exception message when the request itself throws', function () {
        config([
            'clockwork.slack.enabled' => true,
            'clockwork.slack.webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX',
        ]);

        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $result = app(SlackCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->toContain('Connection timed out');
    });
});
