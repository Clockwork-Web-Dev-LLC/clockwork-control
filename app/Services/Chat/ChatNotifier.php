<?php

namespace App\Services\Chat;

use App\Models\BlockedIp;
use App\Models\PluginUpdateJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteSecurityScan;
use Illuminate\Support\Carbon;

/**
 * Contract for all chat/webhook notification channels.
 *
 * EVENTS is the canonical registry of notification types — both MattermostNotifier
 * and SlackNotifier honor the same set. Adding a new event type means: implement
 * the method in both notifiers, add the key here, gate each method with
 * isEventEnabled(). The settings UIs auto-discover entries from this constant.
 */
interface ChatNotifier
{
    public const EVENTS = [
        'site_went_down' => [
            'label' => 'Site went down',
            'description' => 'Uptime probe detected the site is down after the failure threshold.',
            'default' => true,
        ],
        'site_went_up' => [
            'label' => 'Site recovered',
            'description' => 'Uptime probe transitioned the site back to up.',
            'default' => true,
        ],
        'site_entered_maintenance' => [
            'label' => 'Site entered maintenance mode',
            'description' => 'Uptime probe detected the site entered scheduled maintenance (HTTP 503).',
            'default' => true,
        ],
        'site_exited_maintenance' => [
            'label' => 'Site exited maintenance mode',
            'description' => 'Uptime probe detected the site finished scheduled maintenance and is back up.',
            'default' => true,
        ],
        'ssl_state_changed' => [
            'label' => 'SSL state changed',
            'description' => 'Certificate state moved between green / yellow / red.',
            'default' => true,
        ],
        'plugin_update_failed' => [
            'label' => 'Plugin auto-update failed',
            'description' => 'Nightly plugin-update job failed on a care-plan site (manual bulk runs are always silent).',
            'default' => true,
        ],
        'ip_blocked' => [
            'label' => 'IP blocked',
            'description' => 'fail2ban auto-banned a noisy IP at the firewall.',
            'default' => true,
        ],
        'llar_installed' => [
            'label' => 'LLAR auto-installed',
            'description' => 'Limit Login Attempts Reloaded plugin auto-installed on a site that lacked it.',
            'default' => true,
        ],
        'contact_form_failed' => [
            'label' => 'Contact form failing',
            'description' => 'Contact form smoke test failed after the streak threshold.',
            'default' => true,
        ],
        'contact_form_recovered' => [
            'label' => 'Contact form recovered',
            'description' => 'Contact form smoke test came back green after a failing streak.',
            'default' => true,
        ],
        'companion_unreachable' => [
            'label' => 'Companion unreachable',
            'description' => 'Companion mu-plugin stopped responding to HMAC probes, or an install attempt failed and was never retried.',
            'default' => true,
        ],
        'companion_reachable' => [
            'label' => 'Companion recovered',
            'description' => 'A site previously flagged as stuck (failed install, or a long-silent Companion) is healthy again.',
            'default' => true,
        ],
        'malware_finding_detected' => [
            'label' => 'Malware finding detected',
            'description' => 'A security scan (Sucuri SiteCheck or the Companion in-WP probe) found malware on a site that was clean on its last scan.',
            'default' => true,
        ],
        'backup_relay_stale' => [
            'label' => 'Backup relay silent',
            'description' => 'The offsite (S3 Glacier) backup relay droplet hasn\'t reported a new run in longer than its Sun+Wed cadence allows.',
            'default' => true,
        ],
        'backup_relay_recovered' => [
            'label' => 'Backup relay recovered',
            'description' => 'The backup relay is reporting again after a silent stretch.',
            'default' => true,
        ],
        'server_update_failed' => [
            'label' => 'Server update failed',
            'description' => 'A queued apt-get update/upgrade failed, or got stuck running and was reaped (real incident: a stuck row once sat unnoticed for 4 weeks).',
            'default' => true,
        ],
        'queue_worker_restart_failed' => [
            'label' => 'Queue worker restart failed',
            'description' => 'The com.clockwork.queue launchd watchdog found the worker crashed and its own restart attempt (launchctl kickstart) also failed — every queued job in the app is stuck until this is fixed manually.',
            'default' => true,
        ],
        'scheduler_stale' => [
            'label' => 'Scheduler stopped ticking',
            'description' => 'The crontab-driven schedule:run heartbeat is older than 5 minutes. Uptime, ingest, and updates are frozen until cron is running again.',
            'default' => true,
        ],
        'scheduler_recovered' => [
            'label' => 'Scheduler recovered',
            'description' => 'schedule:run started ticking again after a stale stretch.',
            'default' => true,
        ],
        'domain_expiration_state_changed' => [
            'label' => 'Domain expiration state changed',
            'description' => 'Domain expiration tracking moved between green / yellow / red.',
            'default' => true,
        ],
        'seo_indexability_blocked' => [
            'label' => 'SEO indexability blocked',
            'description' => 'A production site is blocking search engines via meta tag, header, or robots.txt.',
            'default' => true,
        ],
        'seo_indexability_recovered' => [
            'label' => 'SEO indexability recovered',
            'description' => 'A site previously blocking search engines is now indexable again.',
            'default' => true,
        ],
    ];

    public function send(string $text, array $attachments = []): bool;

    public function ipBlocked(BlockedIp $blocked): bool;

    public function sslStateChanged(Site $site, string $from, string $to): bool;

    public function domainExpirationStateChanged(Site $site, string $from, string $to): bool;

    public function seoIndexabilityBlocked(Site $site, string $reason, string $snippet): bool;

    public function seoIndexabilityRecovered(Site $site): bool;

    public function llarInstalled(Site $site): bool;

    public function contactFormTestFailed(Site $site, string $reason, int $streak, ?string $formId = null): bool;

    public function contactFormTestRecovered(Site $site, ?string $formId = null): bool;

    public function companionUnreachable(Site $site, string $reason): bool;

    public function companionReachable(Site $site, ?int $stuckForSeconds = null): bool;

    public function siteWentDown(Site $site, ?int $statusCode, ?string $error, bool $likelyWafBlock = false, ?array $diagnosis = null): bool;

    public function siteWentUp(Site $site, ?int $downtimeSec): bool;

    public function siteEnteredMaintenance(Site $site, ?int $statusCode, ?string $reason, ?string $retryAfter = null): bool;

    public function siteExitedMaintenance(Site $site, ?int $maintenanceSec): bool;

    public function pluginUpdateFailed(Site $site, PluginUpdateJob $job): bool;

    public function malwareFindingDetected(Site $site, SiteSecurityScan $scan): bool;

    public function backupRelayStale(int $daysSinceLastRun, ?Carbon $lastRunAt = null): bool;

    public function backupRelayRecovered(): bool;

    public function serverUpdateFailed(Server $server, string $reason): bool;

    public function queueWorkerRestartFailed(string $reason): bool;

    public function schedulerStale(?int $ageSeconds = null, ?Carbon $lastRunAt = null): bool;

    public function schedulerRecovered(): bool;
}
