<?php

use App\Services\Diagnostics\CheckResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Mattermost\MattermostCheck;

/**
 * Coverage for MattermostCheck (App\Services\Diagnostics\DiagnosticCheck).
 * Verified against the real class: it GETs the configured webhook URL
 * (never POSTs, so a diagnostics run never actually delivers a message into
 * the channel) and classifies purely on HTTP status:
 *
 *   - clockwork.mattermost.enabled === false  -> STATUS_SKIPPED, regardless
 *     of whether a webhook_url happens to be set.
 *   - enabled === true but webhook_url === '' -> STATUS_FAIL (this is a
 *     misconfiguration, not treated as "not configured").
 *   - response status < 500                   -> STATUS_OK (this includes
 *     4xx — a webhook GET reaching Mattermost with e.g. a 404 still proves
 *     the host is reachable, which is all this check claims to verify).
 *   - response status >= 500                   -> STATUS_FAIL.
 *   - the request itself throwing (DNS/connection failure) -> STATUS_FAIL
 *     with the exception message as detail.
 */
describe('MattermostCheck', function () {
    it('skips cleanly when Mattermost notifications are disabled', function () {
        config([
            'clockwork.mattermost.enabled' => false,
            'clockwork.mattermost.webhook_url' => 'https://mattermost.clockworkwd.com/hooks/abc123',
        ]);

        $result = app(MattermostCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toBe('Mattermost notifications disabled');
    });

    it('fails when enabled but no webhook URL is configured', function () {
        config([
            'clockwork.mattermost.enabled' => true,
            'clockwork.mattermost.webhook_url' => '',
        ]);

        $result = app(MattermostCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('No webhook URL configured');
    });

    it('returns ok when the GET reaches the webhook host', function () {
        config([
            'clockwork.mattermost.enabled' => true,
            'clockwork.mattermost.webhook_url' => 'https://mattermost.clockworkwd.com/hooks/abc123',
        ]);

        Http::fake([
            'mattermost.clockworkwd.com/hooks/abc123' => Http::response('ok', 200),
        ]);

        $result = app(MattermostCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('mattermost.clockworkwd.com')
            ->and($result->summary)->toContain('HTTP 200');
    });

    it('still returns ok on a sub-500 error status, since that still proves the host is reachable', function () {
        config([
            'clockwork.mattermost.enabled' => true,
            'clockwork.mattermost.webhook_url' => 'https://mattermost.clockworkwd.com/hooks/revoked',
        ]);

        Http::fake([
            'mattermost.clockworkwd.com/hooks/revoked' => Http::response('not found', 404),
        ]);

        $result = app(MattermostCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('HTTP 404');
    });

    it('returns fail with the status when the webhook host returns a server error', function () {
        config([
            'clockwork.mattermost.enabled' => true,
            'clockwork.mattermost.webhook_url' => 'https://mattermost.clockworkwd.com/hooks/abc123',
        ]);

        Http::fake([
            'mattermost.clockworkwd.com/hooks/abc123' => Http::response('boom', 502),
        ]);

        $result = app(MattermostCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Server error · HTTP 502');
    });

    it('returns fail with the exception message when the request itself throws', function () {
        config([
            'clockwork.mattermost.enabled' => true,
            'clockwork.mattermost.webhook_url' => 'https://mattermost.clockworkwd.com/hooks/abc123',
        ]);

        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: mattermost.clockworkwd.com');
        });

        $result = app(MattermostCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->toContain('Could not resolve host');
    });
});
