<?php

namespace Modules\ClientSlack;

use App\Models\BlockedIp;
use App\Models\PluginUpdateJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends plain-English alerts to a per-site Slack webhook configured by the
 * client in their WP admin (Companion → Notifications page).
 *
 * Only fires for events the client cares about: form failures and site-down.
 * All Clockwork-internal events (IP blocks, SSL, plugin updates, etc.) are
 * silently skipped. Messages contain no internal fields — just what broke
 * and where to get help.
 *
 * The webhook URL is read from the site's companion_snapshot JSON so no
 * separate column or migration is required.
 */
class ClientSlackNotifier implements ChatNotifier
{
    public function send(string $text, array $attachments = []): bool
    {
        return false;
    }

    public function ipBlocked(BlockedIp $blocked): bool
    {
        return false;
    }

    public function sslStateChanged(Site $site, string $from, string $to): bool
    {
        return false;
    }

    public function domainExpirationStateChanged(Site $site, string $from, string $to): bool
    {
        return false;
    }

    public function seoIndexabilityBlocked(Site $site, string $reason, string $snippet): bool
    {
        return false;
    }

    public function seoIndexabilityRecovered(Site $site): bool
    {
        return false;
    }

    public function llarInstalled(Site $site): bool
    {
        return false;
    }

    public function pluginUpdateFailed(Site $site, PluginUpdateJob $job): bool
    {
        return false;
    }

    public function companionUnreachable(Site $site, string $reason): bool
    {
        return false;
    }

    public function companionReachable(Site $site, ?int $stuckForSeconds = null): bool
    {
        return false;
    }

    public function backupRelayStale(int $daysSinceLastRun, ?Carbon $lastRunAt = null): bool
    {
        return false;
    }

    public function backupRelayRecovered(): bool
    {
        return false;
    }

    public function serverUpdateFailed(Server $server, string $reason): bool
    {
        return false;
    }

    public function queueWorkerRestartFailed(string $reason): bool
    {
        return false;
    }

    public function malwareFindingDetected(Site $site, SiteSecurityScan $scan): bool
    {
        return false;
    }

    public function contactFormTestFailed(Site $site, string $reason, int $streak, ?string $formId = null): bool
    {
        $webhook = $this->webhookUrl($site);
        if ($webhook === '') {
            return false;
        }

        $title = sprintf('Your contact form on %s has stopped working.', $site->domain);

        return $this->post($webhook, $title, [
            [
                'fallback' => $title,
                'color' => '#cc3333',
                'title' => $title,
                'text' => sprintf(
                    "A test submission failed and your visitors may not be able to reach you.\n\nPlease contact %s to get it fixed: %s",
                    config('clockwork.operator.name'),
                    config('clockwork.operator.support_url')
                ),
            ],
        ]);
    }

    public function contactFormTestRecovered(Site $site, ?string $formId = null): bool
    {
        $webhook = $this->webhookUrl($site);
        if ($webhook === '') {
            return false;
        }

        $title = sprintf('Your contact form on %s is working again.', $site->domain);

        return $this->post($webhook, $title, [
            [
                'fallback' => $title,
                'color' => '#33aa33',
                'title' => $title,
                'text' => 'The form test is now passing. No further action needed.',
            ],
        ]);
    }

    public function siteWentDown(Site $site, ?int $statusCode, ?string $error, bool $likelyWafBlock = false, ?array $diagnosis = null): bool
    {
        $webhook = $this->webhookUrl($site);
        if ($webhook === '') {
            return false;
        }

        $title = sprintf('Your website %s is currently unreachable.', $site->domain);

        return $this->post($webhook, $title, [
            [
                'fallback' => $title,
                'color' => '#cc3333',
                'title' => $title,
                'text' => sprintf(
                    "Visitors are unable to access your site right now.\n\nPlease contact %s for assistance: %s",
                    config('clockwork.operator.name'),
                    config('clockwork.operator.support_url')
                ),
            ],
        ]);
    }

    public function siteWentUp(Site $site, ?int $downtimeSec): bool
    {
        $webhook = $this->webhookUrl($site);
        if ($webhook === '') {
            return false;
        }

        $title = sprintf('Your website %s is back online.', $site->domain);

        return $this->post($webhook, $title, [
            [
                'fallback' => $title,
                'color' => '#33aa33',
                'title' => $title,
                'text' => 'The site is responding normally again. No further action needed.',
            ],
        ]);
    }

    private function webhookUrl(Site $site): string
    {
        $snapshot = $site->companion_snapshot;
        if (! is_array($snapshot)) {
            return '';
        }

        $url = $snapshot['client_notifications']['slack_webhook_url'] ?? '';

        return is_string($url) && str_starts_with($url, 'https://') ? $url : '';
    }

    private function post(string $webhook, string $text, array $attachments): bool
    {
        try {
            $response = Http::timeout(10)->post($webhook, [
                'text' => $text,
                'attachments' => $attachments,
            ]);
        } catch (\Throwable $e) {
            Log::error('ClientSlackNotifier POST failed', ['exception' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('ClientSlackNotifier webhook returned non-2xx', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }
}
