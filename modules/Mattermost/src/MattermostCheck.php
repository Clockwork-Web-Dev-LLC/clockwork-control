<?php

namespace Modules\Mattermost;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mattermost incoming webhooks accept GET with a short status response on
 * the URL — we hit it that way (not POST) to avoid actually delivering a
 * test message into the channel every time someone clicks "Run all".
 *
 * If you want to send a real test message, that's a separate explicit
 * action — not something this connectivity check should do silently.
 */
class MattermostCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'mattermost';
    }

    public function name(): string
    {
        return 'Mattermost webhook';
    }

    public function description(): string
    {
        return 'GET the webhook URL — should reach the server without posting a message.';
    }

    public function run(): CheckResult
    {
        if (! config('clockwork.mattermost.enabled', false)) {
            return CheckResult::skipped('Mattermost notifications disabled');
        }

        $url = (string) config('clockwork.mattermost.webhook_url');
        if ($url === '') {
            return CheckResult::fail('No webhook URL configured');
        }

        $start = microtime(true);
        try {
            $response = Http::timeout(8)->get($url);
            $ms = (int) ((microtime(true) - $start) * 1000);

            $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';

            return $response->status() < 500
                ? CheckResult::ok("Reached {$host} · HTTP {$response->status()}", null, $ms)
                : CheckResult::fail("Server error · HTTP {$response->status()}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
