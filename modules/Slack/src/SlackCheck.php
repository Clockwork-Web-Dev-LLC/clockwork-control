<?php

namespace Modules\Slack;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mirrors Modules\Mattermost\MattermostCheck: GET the incoming-webhook URL
 * rather than POST, so a diagnostics run never actually delivers a message
 * into the channel.
 */
class SlackCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'slack';
    }

    public function name(): string
    {
        return 'Slack webhook';
    }

    public function description(): string
    {
        return 'GET the webhook URL — should reach Slack without posting a message.';
    }

    public function run(): CheckResult
    {
        if (! config('clockwork.slack.enabled', false)) {
            return CheckResult::skipped('Slack notifications disabled');
        }

        $url = (string) config('clockwork.slack.webhook_url');
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
