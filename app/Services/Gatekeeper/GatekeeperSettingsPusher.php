<?php

namespace App\Services\Gatekeeper;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Fail2ban\IgnoreIpListBuilder;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Throwable;

class GatekeeperSettingsPusher
{
    public const DEFAULT_ENABLED = false;

    public const DEFAULT_THRESHOLD = 4;

    public const DEFAULT_WINDOW_SECONDS = 1200;

    public const DEFAULT_LOCKOUT_SECONDS = 1200;

    public const DEFAULT_CONSECUTIVE_FOR_EXTENDED = 4;

    public const DEFAULT_EXTENDED_LOCKOUT_SECONDS = 86400;

    public const DEFAULT_HEADLINE = 'Too many failed login attempts';

    public const DEFAULT_BODY = 'Please wait {duration} before trying again.';

    public const DEFAULT_SUPPORT_LABEL = 'IT Helpdesk';

    public const DEFAULT_SUPPORT_EMAIL = '';

    public const DEFAULT_SUPPORT_URL = '';

    public const DEFAULT_SHOW_IP = true;

    public const DEFAULT_SHOW_UNLOCK_LINK = true;

    public const DEFAULT_UNLOCK_URL = 'https://clockworkwd.com/wp-admin/admin.php?page=clockwork-unlock';

    public function __construct(
        private readonly Settings $settings,
        private readonly IgnoreIpListBuilder $ignoreListBuilder,
    ) {}

    /**
     * Build the merged Gatekeeper configuration payload for a site.
     * Fleet policy defaults are overlaid by any site-level overrides.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(?Site $site = null): array
    {
        $fleet = [
            'enabled' => (bool) $this->settings->get('gatekeeper.enabled', self::DEFAULT_ENABLED),
            'threshold' => (int) $this->settings->get('gatekeeper.threshold', self::DEFAULT_THRESHOLD),
            'window_seconds' => (int) $this->settings->get('gatekeeper.window_seconds', self::DEFAULT_WINDOW_SECONDS),
            'lockout_seconds' => (int) $this->settings->get('gatekeeper.lockout_seconds', self::DEFAULT_LOCKOUT_SECONDS),
            'consecutive_lockouts_for_extended' => (int) $this->settings->get('gatekeeper.consecutive_lockouts_for_extended', self::DEFAULT_CONSECUTIVE_FOR_EXTENDED),
            'extended_lockout_seconds' => (int) $this->settings->get('gatekeeper.extended_lockout_seconds', self::DEFAULT_EXTENDED_LOCKOUT_SECONDS),
            'headline' => (string) $this->settings->get('gatekeeper.headline', self::DEFAULT_HEADLINE),
            'body' => (string) $this->settings->get('gatekeeper.body', self::DEFAULT_BODY),
            'support_label' => (string) $this->settings->get('gatekeeper.support_label', self::DEFAULT_SUPPORT_LABEL),
            'support_email' => (string) $this->settings->get('gatekeeper.support_email', self::DEFAULT_SUPPORT_EMAIL),
            'support_url' => (string) $this->settings->get('gatekeeper.support_url', self::DEFAULT_SUPPORT_URL),
            'show_ip' => (bool) $this->settings->get('gatekeeper.show_ip', self::DEFAULT_SHOW_IP),
            'show_unlock_link' => (bool) $this->settings->get('gatekeeper.show_unlock_link', self::DEFAULT_SHOW_UNLOCK_LINK),
            'unlock_url' => (string) $this->settings->get('gatekeeper.unlock_url', self::DEFAULT_UNLOCK_URL),
        ];

        // Merge site-level overrides if present
        $siteOverrides = $site?->gatekeeper_settings;
        if (is_array($siteOverrides)) {
            if (isset($siteOverrides['enabled']) && $siteOverrides['enabled'] !== '') {
                $fleet['enabled'] = (bool) $siteOverrides['enabled'];
            }
            if (! empty($siteOverrides['threshold'])) {
                $fleet['threshold'] = (int) $siteOverrides['threshold'];
            }
            if (! empty($siteOverrides['window_seconds'])) {
                $fleet['window_seconds'] = (int) $siteOverrides['window_seconds'];
            }
            if (! empty($siteOverrides['lockout_seconds'])) {
                $fleet['lockout_seconds'] = (int) $siteOverrides['lockout_seconds'];
            }
            if (! empty($siteOverrides['consecutive_lockouts_for_extended'])) {
                $fleet['consecutive_lockouts_for_extended'] = (int) $siteOverrides['consecutive_lockouts_for_extended'];
            }
            if (! empty($siteOverrides['extended_lockout_seconds'])) {
                $fleet['extended_lockout_seconds'] = (int) $siteOverrides['extended_lockout_seconds'];
            }
            if (isset($siteOverrides['headline']) && trim((string) $siteOverrides['headline']) !== '') {
                $fleet['headline'] = trim((string) $siteOverrides['headline']);
            }
            if (isset($siteOverrides['body']) && trim((string) $siteOverrides['body']) !== '') {
                $fleet['body'] = trim((string) $siteOverrides['body']);
            }
            if (isset($siteOverrides['support_label']) && trim((string) $siteOverrides['support_label']) !== '') {
                $fleet['support_label'] = trim((string) $siteOverrides['support_label']);
            }
            if (isset($siteOverrides['support_email']) && trim((string) $siteOverrides['support_email']) !== '') {
                $fleet['support_email'] = trim((string) $siteOverrides['support_email']);
            }
            if (isset($siteOverrides['support_url']) && trim((string) $siteOverrides['support_url']) !== '') {
                $fleet['support_url'] = trim((string) $siteOverrides['support_url']);
            }
            if (isset($siteOverrides['show_ip']) && $siteOverrides['show_ip'] !== '') {
                $fleet['show_ip'] = (bool) $siteOverrides['show_ip'];
            }
            if (isset($siteOverrides['show_unlock_link']) && $siteOverrides['show_unlock_link'] !== '') {
                $fleet['show_unlock_link'] = (bool) $siteOverrides['show_unlock_link'];
            }
            if (isset($siteOverrides['unlock_url']) && trim((string) $siteOverrides['unlock_url']) !== '') {
                $fleet['unlock_url'] = trim((string) $siteOverrides['unlock_url']);
            }
        }

        // Build ignore_cidrs and ignore_ips from Cloudflare / Fleet public IP list
        $builderEntries = $this->ignoreListBuilder->build();
        $ignoreCidrs = [];
        $ignoreIps = [];

        foreach ($builderEntries as $entry) {
            if (str_contains($entry, '/')) {
                $ignoreCidrs[] = $entry;
            } elseif (filter_var($entry, FILTER_VALIDATE_IP)) {
                $ignoreIps[] = $entry;
            }
        }

        // Add any fleet/site custom ignore lists
        $fleetIgnoreIps = $this->settings->get('gatekeeper.ignore_ips', []);
        if (is_array($fleetIgnoreIps)) {
            $ignoreIps = array_merge($ignoreIps, $fleetIgnoreIps);
        }

        $fleetIgnoreCidrs = $this->settings->get('gatekeeper.ignore_cidrs', []);
        if (is_array($fleetIgnoreCidrs)) {
            $ignoreCidrs = array_merge($ignoreCidrs, $fleetIgnoreCidrs);
        }

        if (is_array($siteOverrides)) {
            if (! empty($siteOverrides['ignore_ips']) && is_array($siteOverrides['ignore_ips'])) {
                $ignoreIps = array_merge($ignoreIps, $siteOverrides['ignore_ips']);
            }
            if (! empty($siteOverrides['ignore_cidrs']) && is_array($siteOverrides['ignore_cidrs'])) {
                $ignoreCidrs = array_merge($ignoreCidrs, $siteOverrides['ignore_cidrs']);
            }
        }

        $fleet['ignore_ips'] = array_values(array_unique(array_filter($ignoreIps, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP))));
        $fleet['ignore_cidrs'] = array_values(array_unique(array_filter($ignoreCidrs, fn ($c) => str_contains($c, '/'))));

        return $fleet;
    }

    /**
     * Push Gatekeeper settings to WordPress via Companion or Renegade.
     * Skips silently if site does not have Companion or gatekeeper capability.
     */
    public function maybePush(Site $site): bool
    {
        if (! $site->companion_installed || $site->is_inactive) {
            return false;
        }

        $capabilities = is_array($site->companion_capabilities) ? $site->companion_capabilities : [];
        if (! in_array('gatekeeper', $capabilities, true)) {
            return false;
        }

        $payload = $this->buildPayload($site);

        try {
            $client = new ClockworkCompanionClient($site);
            $client->pushGatekeeperSettings($payload);

            return true;
        } catch (Throwable $e) {
            Log::warning('gatekeeper.push_settings_failed', [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
