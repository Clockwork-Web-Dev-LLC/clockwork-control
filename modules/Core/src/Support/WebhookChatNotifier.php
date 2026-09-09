<?php

namespace Modules\Core\Support;

use App\Models\BlockedIp;
use App\Models\PluginUpdateJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Services\Chat\ChatNotifier;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared behavior for a simple incoming-webhook chat channel (Mattermost,
 * Slack — both post an identical Slack-compatible-attachment JSON payload
 * to a webhook URL, and both gate every event on the same per-event toggle
 * mechanism). Extracted while modularizing both into modules/Mattermost/
 * and modules/Slack/ — their notifier classes were ~85% byte-for-byte
 * identical before this, differing only in which config('clockwork.*')
 * namespace they read and a couple of log-message strings.
 *
 * A subclass supplies only configNamespace() (drives both the config keys
 * this reads AND the Settings key event toggles are stored under) and
 * channelLabel() (used in log messages only, e.g. "Mattermost POST failed"
 * vs "Slack POST failed"). Every ChatNotifier method — all 14 domain events
 * plus send()/isEventEnabled() — lives here since none of them differ
 * between channels; a subclass that ever needs channel-specific event
 * behavior can still override an individual method.
 */
abstract class WebhookChatNotifier implements ChatNotifier
{
    /** config('clockwork.{this}.*') — e.g. 'mattermost', 'slack'. */
    abstract protected function configNamespace(): string;

    /** Human-readable name for log messages only, e.g. 'Mattermost', 'Slack'. */
    abstract protected function channelLabel(): string;

    protected function settingsKey(): string
    {
        return 'notifications.'.$this->configNamespace().'.events';
    }

    protected function isEventEnabled(string $key): bool
    {
        $config = self::EVENTS[$key] ?? null;
        $default = (bool) ($config['default'] ?? true);

        $stored = app(Settings::class)->get($this->settingsKey(), []);
        if (! is_array($stored) || ! array_key_exists($key, $stored)) {
            return $default;
        }

        return (bool) $stored[$key];
    }

    public function send(string $text, array $attachments = []): bool
    {
        $ns = $this->configNamespace();

        if (! config("clockwork.{$ns}.enabled")) {
            return false;
        }

        $webhook = config("clockwork.{$ns}.webhook_url");

        if (empty($webhook)) {
            Log::warning("{$this->channelLabel()} notifier called but webhook URL is not configured.");

            return false;
        }

        $payload = array_filter([
            'text' => $text,
            'channel' => config("clockwork.{$ns}.channel") ?: null,
            'username' => config("clockwork.{$ns}.username"),
            'icon_emoji' => config("clockwork.{$ns}.icon_emoji"),
            'attachments' => $attachments ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $response = Http::timeout(10)->post($webhook, $payload);
        } catch (\Throwable $e) {
            Log::error("{$this->channelLabel()} POST failed", ['exception' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::warning("{$this->channelLabel()} webhook returned non-2xx", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    public function ipBlocked(BlockedIp $blocked): bool
    {
        if (! $this->isEventEnabled('ip_blocked')) {
            return false;
        }
        $server = $blocked->server?->name ?? 'unknown server';
        $site = $blocked->site?->domain;
        $verdict = $blocked->llm_verdict ?? 'n/a';

        $title = sprintf('Blocked %s on %s', $blocked->ip, $server);
        $fields = array_filter([
            ['title' => 'Source', 'value' => $blocked->source, 'short' => true],
            ['title' => 'Verdict', 'value' => $verdict, 'short' => true],
            $site ? ['title' => 'Site', 'value' => $site, 'short' => true] : null,
            ['title' => 'Decided by', 'value' => $blocked->decided_by ?? 'auto', 'short' => true],
        ]);

        $attachment = array_filter([
            'fallback' => $title,
            'color' => '#cc3333',
            'title' => $title,
            'text' => $blocked->llm_reasoning ?: $blocked->reason,
            'fields' => $fields,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $this->send($title, [$attachment]);
    }

    /**
     * Notify on an SSL state transition for a single site.
     *
     * @param  string  $from  previous cert_state
     * @param  string  $to  new cert_state
     */
    public function sslStateChanged(Site $site, string $from, string $to): bool
    {
        if (! $this->isEventEnabled('ssl_state_changed')) {
            return false;
        }
        $colors = [
            Site::SSL_STATE_GREEN => '#33aa33',
            Site::SSL_STATE_YELLOW => '#dda32a',
            Site::SSL_STATE_RED => '#cc3333',
            Site::SSL_STATE_NONE => '#888888',
        ];

        $emoji = match ($to) {
            Site::SSL_STATE_RED => ':rotating_light:',
            Site::SSL_STATE_YELLOW => ':warning:',
            Site::SSL_STATE_GREEN => ':white_check_mark:',
            default => ':information_source:',
        };

        $title = sprintf('%s SSL: %s → %s on %s', $emoji, $from, $to, $site->domain);

        $expires = $site->cert_expires_at?->format('M j, Y H:i') ?? 'unknown';
        $renews = $site->cert_renews_at?->format('M j, Y H:i') ?? 'n/a';

        $fields = [
            ['title' => 'Site', 'value' => $site->domain, 'short' => true],
            ['title' => 'Server', 'value' => $site->server?->name ?? 'n/a', 'short' => true],
            ['title' => 'Expires', 'value' => $expires, 'short' => true],
            ['title' => 'Renews', 'value' => $renews, 'short' => true],
            ['title' => 'Cert source', 'value' => $site->cert_source, 'short' => true],
        ];

        $body = match ($to) {
            Site::SSL_STATE_YELLOW => "Renewal window passed without the cert rolling forward. ~6 weeks before expiry — investigate Let's Encrypt renewal or schedule a manual replacement.",
            Site::SSL_STATE_RED => 'Cert has expired. The site is serving an invalid certificate to visitors right now.',
            Site::SSL_STATE_GREEN => 'Cert is healthy again — the renewal succeeded.',
            default => '',
        };

        $attachment = array_filter([
            'fallback' => $title,
            'color' => $colors[$to] ?? '#888888',
            'title' => $title,
            'text' => $body,
            'fields' => $fields,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $this->send($title, [$attachment]);
    }

    /**
     * Notify on a domain expiration state transition for a single site.
     *
     * @param  string  $from  previous domain_expiration_state
     * @param  string  $to  new domain_expiration_state
     */
    public function domainExpirationStateChanged(Site $site, string $from, string $to): bool
    {
        if (! $this->isEventEnabled('domain_expiration_state_changed')) {
            return false;
        }

        $colors = [
            Site::DOMAIN_EXPIRATION_STATE_GREEN => '#33aa33',
            Site::DOMAIN_EXPIRATION_STATE_YELLOW => '#dda32a',
            Site::DOMAIN_EXPIRATION_STATE_RED => '#cc3333',
            Site::DOMAIN_EXPIRATION_STATE_NONE => '#888888',
        ];

        $emoji = match ($to) {
            Site::DOMAIN_EXPIRATION_STATE_RED => ':rotating_light:',
            Site::DOMAIN_EXPIRATION_STATE_YELLOW => ':warning:',
            Site::DOMAIN_EXPIRATION_STATE_GREEN => ':white_check_mark:',
            default => ':information_source:',
        };

        $title = sprintf('%s Domain: %s → %s on %s', $emoji, $from, $to, $site->domain);

        $expires = $site->domain_expires_at?->format('M j, Y') ?? 'unknown';
        $registrar = $site->domain_registrar ?? 'unknown';

        $fields = [
            ['title' => 'Site', 'value' => $site->domain, 'short' => true],
            ['title' => 'Server', 'value' => $site->server?->name ?? 'n/a', 'short' => true],
            ['title' => 'Expires', 'value' => $expires, 'short' => true],
            ['title' => 'Registrar', 'value' => $registrar, 'short' => true],
        ];

        $body = match ($to) {
            Site::DOMAIN_EXPIRATION_STATE_YELLOW => 'Domain expires within 30 days. Verify auto-renew is active with the registrar.',
            Site::DOMAIN_EXPIRATION_STATE_RED => 'Domain expires within 7 days, or is in redemption/pending delete! Immediate action required.',
            Site::DOMAIN_EXPIRATION_STATE_GREEN => 'Domain registration renewed successfully.',
            default => '',
        };

        $attachment = array_filter([
            'fallback' => $title,
            'color' => $colors[$to] ?? '#888888',
            'title' => $title,
            'text' => $body,
            'fields' => $fields,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $this->send($title, [$attachment]);
    }

    /**
     * Notify that a production site has search engine indexing blocked.
     */
    public function seoIndexabilityBlocked(Site $site, string $reason, string $snippet): bool
    {
        if (! $this->isEventEnabled('seo_indexability_blocked')) {
            return false;
        }

        $title = sprintf(':rotating_light: SEO Blocked on %s', $site->domain);

        $fields = [
            ['title' => 'Site', 'value' => $site->domain, 'short' => true],
            ['title' => 'Server', 'value' => $site->server?->name ?? 'n/a', 'short' => true],
            ['title' => 'Reason', 'value' => $reason, 'short' => true],
            ['title' => 'Snippet', 'value' => $snippet, 'short' => false],
        ];

        $body = 'Search engine indexing is blocked on production! Google and other crawlers may drop pages from search results.';

        $attachment = array_filter([
            'fallback' => $title,
            'color' => '#cc3333',
            'title' => $title,
            'text' => $body,
            'fields' => $fields,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $this->send($title, [$attachment]);
    }

    /**
     * Notify that a site previously blocking indexing is now clean and indexable again.
     */
    public function seoIndexabilityRecovered(Site $site): bool
    {
        if (! $this->isEventEnabled('seo_indexability_recovered')) {
            return false;
        }

        $title = sprintf(':white_check_mark: SEO Indexability Restored on %s', $site->domain);

        $fields = [
            ['title' => 'Site', 'value' => $site->domain, 'short' => true],
            ['title' => 'Server', 'value' => $site->server?->name ?? 'n/a', 'short' => true],
        ];

        $body = 'Search engine indexing directives are clean again. Crawlers can index the site normally.';

        $attachment = array_filter([
            'fallback' => $title,
            'color' => '#33aa33',
            'title' => $title,
            'text' => $body,
            'fields' => $fields,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $this->send($title, [$attachment]);
    }

    /**
     * Notify the channel that LLAR was just installed on a site (and that
     * its email-on-lockout feature is off — the part that scares users).
     */
    public function llarInstalled(Site $site): bool
    {
        if (! $this->isEventEnabled('llar_installed')) {
            return false;
        }
        $title = sprintf(':lock: LLAR installed on %s', $site->domain);

        $attachment = [
            'fallback' => $title,
            'color' => '#33aa33',
            'title' => $title,
            'text' => 'Limit Login Attempts Reloaded was installed and activated. Email lockout notifications are disabled — only the log channel is on, which is what feeds the Clockwork review queue.',
            'fields' => [
                ['title' => 'Site', 'value' => $site->domain, 'short' => true],
                ['title' => 'Server', 'value' => $site->server?->name ?? 'n/a', 'short' => true],
            ],
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Daily contact-form test on a care-plan site failed twice in a row.
     * (One-off blips don't fire — we only ping when streak reaches 2 to avoid
     * waking the channel for transient network problems.)
     */
    public function contactFormTestFailed(Site $site, string $reason, int $streak, ?string $formId = null): bool
    {
        if (! $this->isEventEnabled('contact_form_failed')) {
            return false;
        }
        $title = sprintf(':warning: Contact form failing on %s', $site->domain);

        $attachment = [
            'fallback' => $title,
            'color' => '#cc3333',
            'title' => $title,
            'text' => "The Companion plugin reports the contact form test is failing.\n\nReason: {$reason}",
            'fields' => array_values(array_filter([
                ['title' => 'Site', 'value' => $site->domain, 'short' => true],
                ['title' => 'Server', 'value' => $site->server?->name ?? 'n/a', 'short' => true],
                $formId ? ['title' => 'Form', 'value' => $formId, 'short' => true] : null,
                ['title' => 'Streak', 'value' => "{$streak} consecutive failures", 'short' => true],
            ])),
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Form test recovered after at least one failure. Always pings — the
     * "we're back to working" signal is short and high-value.
     */
    public function contactFormTestRecovered(Site $site, ?string $formId = null): bool
    {
        if (! $this->isEventEnabled('contact_form_recovered')) {
            return false;
        }
        $title = sprintf(':white_check_mark: Contact form recovered on %s', $site->domain);

        $attachment = [
            'fallback' => $title,
            'color' => '#33aa33',
            'title' => $title,
            'text' => 'The Companion plugin reports the contact form test is now passing.',
            'fields' => array_values(array_filter([
                ['title' => 'Site', 'value' => $site->domain, 'short' => true],
                ['title' => 'Server', 'value' => $site->server?->name ?? 'n/a', 'short' => true],
                $formId ? ['title' => 'Form', 'value' => $formId, 'short' => true] : null,
            ])),
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Companion plugin hasn't been reachable in a while, or an install
     * attempt failed and was never retried. Two distinct callers today:
     * ContactFormTester (form testing enabled but no response) and
     * DetectStuckCompanionState (fleet-wide daily sweep — see that command
     * for the "why 24h/why 3 days" thresholds).
     */
    public function companionUnreachable(Site $site, string $reason): bool
    {
        if (! $this->isEventEnabled('companion_unreachable')) {
            return false;
        }
        $title = sprintf(':electric_plug: Companion unreachable on %s', $site->domain);

        $attachment = [
            'fallback' => $title,
            'color' => '#dda32a',
            'title' => $title,
            'text' => "Clockwork can't reach the Companion mu-plugin on {$site->domain}.\n\n{$reason}",
            'fields' => [
                ['title' => 'Site', 'value' => $site->domain, 'short' => true],
                ['title' => 'Server', 'value' => $site->server?->name ?? 'n/a', 'short' => true],
                ['title' => 'Last seen', 'value' => $site->companion_last_seen_at?->diffForHumans() ?? 'never', 'short' => true],
            ],
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Recovery counterpart to companionUnreachable — fired by
     * DetectStuckCompanionState when a previously-stuck site's
     * companion_stuck_since clears (install succeeded, or the snapshot is
     * fresh again). Mirrors siteWentUp's shape.
     */
    public function companionReachable(Site $site, ?int $stuckForSeconds = null): bool
    {
        if (! $this->isEventEnabled('companion_reachable')) {
            return false;
        }
        $title = sprintf(':white_check_mark: Companion recovered on %s', $site->domain);

        $stuckLabel = $stuckForSeconds !== null ? $this->formatDowntime($stuckForSeconds) : 'unknown duration';

        $attachment = [
            'fallback' => $title,
            'color' => '#33aa33',
            'title' => $title,
            'text' => 'Companion is reachable again — no further action needed.',
            'fields' => [
                ['title' => 'Site', 'value' => $site->domain, 'short' => true],
                ['title' => 'Server', 'value' => $site->server?->name ?? 'n/a', 'short' => true],
                ['title' => 'Was stuck for', 'value' => $stuckLabel, 'short' => true],
            ],
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Notify on a site going down (state transition up→down or unknown→down).
     * Fires once per outage; no repeat alerts while still down.
     *
     * @param  ?array<string, mixed>  $diagnosis  Output of UptimeDiagnostician::diagnose() —
     *                                            adds a "Diagnosis" field with the SSH-derived
     *                                            cause when available.
     */
    public function siteWentDown(Site $site, ?int $statusCode, ?string $error, bool $likelyWafBlock = false, ?array $diagnosis = null): bool
    {
        if (! $this->isEventEnabled('site_went_down')) {
            return false;
        }
        $detail = $statusCode !== null ? "HTTP {$statusCode}" : ($error ?: 'unreachable');
        $title = sprintf(':red_circle: %s is unreachable (%s)', $site->domain, $detail);

        // Diagnosis summary trumps the generic copy when we have one — it's
        // the actionable line ("SpinupWP maintenance mode is active…").
        $diagnosisSummary = is_array($diagnosis) ? (string) ($diagnosis['summary'] ?? '') : '';
        if ($diagnosisSummary !== '') {
            $body = $diagnosisSummary;
        } elseif ($likelyWafBlock) {
            $body = 'HTTP 401/403 — possibly a WAF block on the Clockwork-Uptime probe rather than a true outage. Check Cloudflare WAF rules and consider whitelisting our User-Agent (Clockwork-Uptime/1.0) if the site is fine for real visitors.';
        } else {
            $body = 'Failed 2 probes in a row at the every-5-minute cadence. Will fire a recovery alert when the next probe succeeds.';
        }

        $attachment = [
            'fallback' => $title,
            'color' => '#cc3333',
            'title' => $title,
            'text' => $body,
            'fields' => array_values(array_filter([
                ['title' => 'Site', 'value' => $site->domain, 'short' => true],
                ['title' => 'Server', 'value' => $site->server !== null ? $site->server->name : 'n/a', 'short' => true],
                $statusCode !== null ? ['title' => 'Status', 'value' => (string) $statusCode, 'short' => true] : null,
                $error !== null ? ['title' => 'Detail', 'value' => mb_strimwidth($error, 0, 200, '…'), 'short' => false] : null,
                $this->diagnosisField($diagnosis),
            ])),
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Render the structured diagnosis signals as an attachment field.
     * Returns null when there's nothing useful to show (no diagnosis, or
     * only the summary line which is already in the body).
     *
     * @param  ?array<string, mixed>  $diagnosis
     * @return ?array{title: string, value: string, short: bool}
     */
    private function diagnosisField(?array $diagnosis): ?array
    {
        if (! is_array($diagnosis)) {
            return null;
        }
        if (($diagnosis['error'] ?? null) !== null) {
            return ['title' => 'Diagnosis', 'value' => 'SSH probe failed: '.mb_strimwidth((string) $diagnosis['error'], 0, 200, '…'), 'short' => false];
        }

        $lines = [];
        if (! empty($diagnosis['fpm_service'])) {
            $active = $diagnosis['fpm_active'] ?? 'unknown';
            $lines[] = "FPM service: {$diagnosis['fpm_service']} ({$active})";
        }
        if (is_array($diagnosis['loadavg'] ?? null) && isset($diagnosis['cores'])) {
            $loadavg = $diagnosis['loadavg'];
            $lines[] = sprintf('Load: %.2f / %.2f / %.2f on %d cores', $loadavg[0], $loadavg[1], $loadavg[2], $diagnosis['cores']);
        }
        if (! empty($diagnosis['recent_errors'])) {
            $tail = (string) $diagnosis['recent_errors'];
            $lines[] = "Recent error log:\n```\n".mb_strimwidth($tail, 0, 800, '…')."\n```";
        }

        if ($lines === []) {
            return null;
        }

        return ['title' => 'Diagnosis', 'value' => implode("\n", $lines), 'short' => false];
    }

    /**
     * Notify on a site recovering (state transition down→up). Includes the
     * downtime window so it's clear how long the outage lasted.
     */
    public function siteWentUp(Site $site, ?int $downtimeSec): bool
    {
        if (! $this->isEventEnabled('site_went_up')) {
            return false;
        }
        $downtimeLabel = $downtimeSec !== null
            ? $this->formatDowntime($downtimeSec)
            : 'unknown duration';

        $title = sprintf(':large_green_circle: %s is back up (down for %s)', $site->domain, $downtimeLabel);

        $attachment = [
            'fallback' => $title,
            'color' => '#33aa33',
            'title' => $title,
            'fields' => [
                ['title' => 'Site', 'value' => $site->domain, 'short' => true],
                ['title' => 'Server', 'value' => $site->server !== null ? $site->server->name : 'n/a', 'short' => true],
                ['title' => 'Downtime', 'value' => $downtimeLabel, 'short' => true],
            ],
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Notify that a site entered scheduled maintenance mode.
     */
    public function siteEnteredMaintenance(Site $site, ?int $statusCode, ?string $reason, ?string $retryAfter = null): bool
    {
        if (! $this->isEventEnabled('site_entered_maintenance')) {
            return false;
        }

        $detail = $statusCode !== null ? "HTTP {$statusCode}" : '503';
        $title = sprintf(':wrench: %s entered scheduled maintenance (%s)', $site->domain, $detail);

        $body = 'Uptime probe detected scheduled maintenance mode. Outage alerts and SMS notifications are suppressed while maintenance is active.';

        $fields = [
            ['title' => 'Site', 'value' => $site->domain, 'short' => true],
            ['title' => 'Server', 'value' => $site->server !== null ? $site->server->name : 'n/a', 'short' => true],
            $statusCode !== null ? ['title' => 'Status', 'value' => (string) $statusCode, 'short' => true] : null,
            $retryAfter !== null ? ['title' => 'Retry-After', 'value' => (string) $retryAfter, 'short' => true] : null,
            $reason !== null ? ['title' => 'Detail', 'value' => mb_strimwidth($reason, 0, 200, '…'), 'short' => false] : null,
        ];

        $attachment = [
            'fallback' => $title,
            'color' => '#3498db',
            'title' => $title,
            'text' => $body,
            'fields' => array_values(array_filter($fields)),
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Notify that a site exited scheduled maintenance mode and is back up.
     */
    public function siteExitedMaintenance(Site $site, ?int $maintenanceSec): bool
    {
        if (! $this->isEventEnabled('site_exited_maintenance')) {
            return false;
        }

        $durationLabel = $maintenanceSec !== null
            ? $this->formatDowntime($maintenanceSec)
            : 'unknown duration';

        $title = sprintf(':white_check_mark: %s exited maintenance mode (was in maintenance for %s)', $site->domain, $durationLabel);

        $attachment = [
            'fallback' => $title,
            'color' => '#33aa33',
            'title' => $title,
            'fields' => [
                ['title' => 'Site', 'value' => $site->domain, 'short' => true],
                ['title' => 'Server', 'value' => $site->server !== null ? $site->server->name : 'n/a', 'short' => true],
                ['title' => 'Maintenance duration', 'value' => $durationLabel, 'short' => true],
            ],
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Per-failure ping for the nightly auto-update loop. Fires only when
     * the queued job is part of a `nightly-*` batch — manual bulk-update
     * failures stay quiet because the operator is watching the page in
     * real time. Mirrors siteWentDown's red-pill shape so a glance at the
     * channel makes "site down" and "auto-update failed" feel like
     * siblings on the same severity ladder.
     */
    public function pluginUpdateFailed(Site $site, PluginUpdateJob $job): bool
    {
        if (! $this->isEventEnabled('plugin_update_failed')) {
            return false;
        }
        $domain = $site->domain;
        $slug = $job->target_slug ?? '(no slug)';
        $before = $job->before_version ?: '?';
        $target = $job->target_version ?: '?';

        // Trim error to ~280 chars so the card stays scannable; the full
        // error stays in plugin_update_jobs.error and the morning summary email.
        $errorExcerpt = $job->error
            ? mb_strimwidth((string) $job->error, 0, 280, '…')
            : 'no error message captured';

        $title = sprintf(':rotating_light: Auto-update failed: %s on %s', $slug, $domain);
        $attachment = [
            'fallback' => $title,
            'color' => '#cc3333',
            'title' => $title,
            'text' => $errorExcerpt,
            'fields' => [
                ['title' => 'Site', 'value' => $domain, 'short' => true],
                ['title' => 'Server', 'value' => $site->server !== null ? $site->server->name : 'n/a', 'short' => true],
                ['title' => 'Plugin', 'value' => $slug, 'short' => true],
                ['title' => 'Tried', 'value' => "{$before} → {$target}", 'short' => true],
                ['title' => 'Job', 'value' => '#'.$job->id, 'short' => true],
                ['title' => 'Batch', 'value' => $job->batch_id ?? 'n/a', 'short' => true],
            ],
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Fires only on the clean → malware-hit transition (see
     * SecurityScanRecorder) — a site that's still infected on the next
     * scan doesn't re-alert every run, same suppression shape as
     * sslStateChanged/siteWentDown.
     */
    public function malwareFindingDetected(Site $site, SiteSecurityScan $scan): bool
    {
        if (! $this->isEventEnabled('malware_finding_detected')) {
            return false;
        }
        $domain = $site->domain;
        $scanTypeLabel = match ($scan->scan_type) {
            SiteSecurityScan::TYPE_SITECHECK => 'Sucuri SiteCheck',
            SiteSecurityScan::TYPE_COMPANION_MALWARE => 'Companion in-WP probe',
            default => $scan->scan_type,
        };

        $title = sprintf(':rotating_light: Malware finding on %s', $domain);
        $attachment = [
            'fallback' => $title,
            'color' => '#cc3333',
            'title' => $title,
            'text' => mb_strimwidth((string) ($scan->summary ?: 'No summary captured.'), 0, 280, '…'),
            'fields' => [
                ['title' => 'Site', 'value' => $domain, 'short' => true],
                ['title' => 'Server', 'value' => $site->server !== null ? $site->server->name : 'n/a', 'short' => true],
                ['title' => 'Scanner', 'value' => $scanTypeLabel, 'short' => true],
                ['title' => 'Scan', 'value' => '#'.$scan->id, 'short' => true],
            ],
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Fleet-level, not site-scoped — the backup relay droplet has no
     * connection back to this app (see PushBackupRelayTargets's docblock),
     * so PullBackupRelayReport can only infer silence from "no new
     * BackupRelayRun row in longer than the Sun+Wed cadence allows."
     */
    public function backupRelayStale(int $daysSinceLastRun, ?Carbon $lastRunAt = null): bool
    {
        if (! $this->isEventEnabled('backup_relay_stale')) {
            return false;
        }
        $title = sprintf(':rotating_light: Backup relay silent for %d days', $daysSinceLastRun);

        $attachment = [
            'fallback' => $title,
            'color' => '#cc3333',
            'title' => $title,
            'text' => "The offsite backup relay droplet hasn't reported a new run — check the droplet's crontab, AWS credentials, and Pressable API status.",
            'fields' => [
                ['title' => 'Last run', 'value' => $lastRunAt?->format('M j, Y H:i').' ('.$lastRunAt?->diffForHumans().')' ?? 'never', 'short' => true],
                ['title' => 'Expected cadence', 'value' => 'Sun + Wed', 'short' => true],
            ],
        ];

        return $this->send($title, [$attachment]);
    }

    public function backupRelayRecovered(): bool
    {
        if (! $this->isEventEnabled('backup_relay_recovered')) {
            return false;
        }
        $title = ':white_check_mark: Backup relay recovered';

        $attachment = [
            'fallback' => $title,
            'color' => '#33aa33',
            'title' => $title,
            'text' => 'The backup relay is reporting again. No further action needed.',
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Two callers: ProcessServerUpdates (live apt-get failure) and
     * ReapStaleServerUpdates (stuck >2h and reaped — real incident:
     * web47 sat in `running` for 4 weeks unnoticed before this existed).
     */
    public function serverUpdateFailed(Server $server, string $reason): bool
    {
        if (! $this->isEventEnabled('server_update_failed')) {
            return false;
        }
        $title = sprintf(':rotating_light: Server update failed on %s', $server->display_name);

        $attachment = [
            'fallback' => $title,
            'color' => '#cc3333',
            'title' => $title,
            'text' => $reason,
            'fields' => [
                ['title' => 'Server', 'value' => $server->name, 'short' => true],
                ['title' => 'Hostname', 'value' => $server->hostname, 'short' => true],
            ],
        ];

        return $this->send($title, [$attachment]);
    }

    /**
     * Fleet-level, no Site or Server involved — this is about the operator's
     * own machine, where the queue worker (and this app's own scheduler)
     * live. Fires from EnsureQueueWorker only when its own recovery attempt
     * (launchctl kickstart) also failed — the worst case, since every queued
     * job in the app is stuck until someone intervenes by hand. Not
     * deduped/state-tracked like the other alerts here — this is severe
     * enough that repeating every 5 minutes while still broken is correct,
     * not spam.
     */
    public function queueWorkerRestartFailed(string $reason): bool
    {
        if (! $this->isEventEnabled('queue_worker_restart_failed')) {
            return false;
        }
        $title = ':rotating_light: Queue worker restart failed';

        $attachment = [
            'fallback' => $title,
            'color' => '#cc3333',
            'title' => $title,
            'text' => "com.clockwork.queue crashed and the watchdog's own restart attempt also failed. Every queued job (plugin updates, malware scans, etc.) is stuck until this is fixed by hand.\n\n{$reason}",
        ];

        return $this->send($title, [$attachment]);
    }

    public function schedulerStale(?int $ageSeconds = null, ?Carbon $lastRunAt = null): bool
    {
        if (! $this->isEventEnabled('scheduler_stale')) {
            return false;
        }

        $ageLabel = $ageSeconds !== null ? $this->formatDowntime($ageSeconds) : 'unknown';
        $title = sprintf(':rotating_light: Scheduler has not ticked in %s', $ageLabel);

        $attachment = [
            'fallback' => $title,
            'color' => '#cc3333',
            'title' => $title,
            'text' => 'crontab is not spawning `php artisan schedule:run`. Uptime probes, ingest, bans, and updates are frozen until that cron entry is running again. See the Scheduler stuck runbook.',
            'fields' => [
                ['title' => 'Last tick', 'value' => $lastRunAt !== null ? $lastRunAt->format('M j, H:i').' ('.$lastRunAt->diffForHumans().')' : 'never', 'short' => true],
                ['title' => 'Expected', 'value' => 'every minute', 'short' => true],
            ],
        ];

        return $this->send($title, [$attachment]);
    }

    public function schedulerRecovered(): bool
    {
        if (! $this->isEventEnabled('scheduler_recovered')) {
            return false;
        }

        $title = ':white_check_mark: Scheduler recovered';

        $attachment = [
            'fallback' => $title,
            'color' => '#33aa33',
            'title' => $title,
            'text' => '`schedule:run` is ticking again. Uptime, ingest, and updates will catch up on their next due times.',
        ];

        return $this->send($title, [$attachment]);
    }

    private function formatDowntime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes} min";
        }
        $hours = (int) floor($minutes / 60);
        $remainder = $minutes % 60;

        return $remainder === 0 ? "{$hours}h" : "{$hours}h {$remainder}m";
    }
}
