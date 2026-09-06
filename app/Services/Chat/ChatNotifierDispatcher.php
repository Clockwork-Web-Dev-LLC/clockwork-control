<?php

namespace App\Services\Chat;

use App\Models\BlockedIp;
use App\Models\PluginUpdateJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteSecurityScan;
use Illuminate\Support\Carbon;

/**
 * Fans out every notification call to all configured chat channels in turn.
 * Returns true if at least one channel accepted the message.
 *
 * Resolved from the container as the concrete ChatNotifier — callers never
 * need to know which channels are active. To add a channel, implement
 * ChatNotifier and tag it 'clockwork.notifiers' (see AppServiceProvider) —
 * a module can do this from its own service provider too, the same way it
 * contributes a CloudProvider or DiagnosticCheck.
 */
class ChatNotifierDispatcher implements ChatNotifier
{
    /** @param ChatNotifier[] $notifiers */
    public function __construct(private readonly array $notifiers) {}

    public function send(string $text, array $attachments = []): bool
    {
        return $this->dispatch(fn (ChatNotifier $n) => $n->send($text, $attachments));
    }

    public function ipBlocked(BlockedIp $blocked): bool
    {
        return $this->dispatch(fn (ChatNotifier $n) => $n->ipBlocked($blocked));
    }

    public function sslStateChanged(Site $site, string $from, string $to): bool
    {
        return $this->dispatchForSite($site, fn (ChatNotifier $n) => $n->sslStateChanged($site, $from, $to));
    }

    public function domainExpirationStateChanged(Site $site, string $from, string $to): bool
    {
        return $this->dispatchForSite($site, fn (ChatNotifier $n) => $n->domainExpirationStateChanged($site, $from, $to));
    }

    public function seoIndexabilityBlocked(Site $site, string $reason, string $snippet): bool
    {
        return $this->dispatchForSite($site, fn (ChatNotifier $n) => $n->seoIndexabilityBlocked($site, $reason, $snippet));
    }

    public function seoIndexabilityRecovered(Site $site): bool
    {
        return $this->dispatchForSite($site, fn (ChatNotifier $n) => $n->seoIndexabilityRecovered($site));
    }

    public function llarInstalled(Site $site): bool
    {
        return $this->dispatchForSite($site, fn (ChatNotifier $n) => $n->llarInstalled($site));
    }

    public function contactFormTestFailed(Site $site, string $reason, int $streak, ?string $formId = null): bool
    {
        return $this->dispatchForSite($site, fn (ChatNotifier $n) => $n->contactFormTestFailed($site, $reason, $streak, $formId));
    }

    public function contactFormTestRecovered(Site $site, ?string $formId = null): bool
    {
        return $this->dispatchForSite($site, fn (ChatNotifier $n) => $n->contactFormTestRecovered($site, $formId));
    }

    public function companionUnreachable(Site $site, string $reason): bool
    {
        return $this->dispatchForSite($site, fn (ChatNotifier $n) => $n->companionUnreachable($site, $reason));
    }

    public function companionReachable(Site $site, ?int $stuckForSeconds = null): bool
    {
        return $this->dispatchForSite($site, fn (ChatNotifier $n) => $n->companionReachable($site, $stuckForSeconds));
    }

    public function backupRelayStale(int $daysSinceLastRun, ?Carbon $lastRunAt = null): bool
    {
        return $this->dispatch(fn (ChatNotifier $n) => $n->backupRelayStale($daysSinceLastRun, $lastRunAt));
    }

    public function backupRelayRecovered(): bool
    {
        return $this->dispatch(fn (ChatNotifier $n) => $n->backupRelayRecovered());
    }

    public function serverUpdateFailed(Server $server, string $reason): bool
    {
        return $this->dispatch(fn (ChatNotifier $n) => $n->serverUpdateFailed($server, $reason));
    }

    public function queueWorkerRestartFailed(string $reason): bool
    {
        return $this->dispatch(fn (ChatNotifier $n) => $n->queueWorkerRestartFailed($reason));
    }

    public function siteWentDown(Site $site, ?int $statusCode, ?string $error, bool $likelyWafBlock = false, ?array $diagnosis = null): bool
    {
        return $this->dispatch(fn (ChatNotifier $n) => $n->siteWentDown($site, $statusCode, $error, $likelyWafBlock, $diagnosis));
    }

    public function siteWentUp(Site $site, ?int $downtimeSec): bool
    {
        return $this->dispatch(fn (ChatNotifier $n) => $n->siteWentUp($site, $downtimeSec));
    }

    public function pluginUpdateFailed(Site $site, PluginUpdateJob $job): bool
    {
        return $this->dispatchForSite($site, fn (ChatNotifier $n) => $n->pluginUpdateFailed($site, $job));
    }

    // malwareFindingDetected, siteWentDown/Up, and ipBlocked are deliberately
    // NOT gated on is_inactive — those are active-incident signals (a real
    // compromise, a real outage, a real attacker at the firewall) on
    // infrastructure Clockwork still serves, not routine maintenance nags
    // like an upcoming SSL renewal. is_inactive quiets the latter, not the
    // former. Uptime already has its own dedicated per-site mute
    // (uptime_ignored_at / toggleUptimeIgnore) for the rare case a known-down
    // inactive site's outage alerts genuinely aren't wanted either.
    public function malwareFindingDetected(Site $site, SiteSecurityScan $scan): bool
    {
        return $this->dispatch(fn (ChatNotifier $n) => $n->malwareFindingDetected($site, $scan));
    }

    /**
     * Same as dispatch(), but short-circuits entirely for a site marked
     * is_inactive — used only by the "routine maintenance" event methods
     * above, not the active-incident ones below.
     */
    private function dispatchForSite(Site $site, callable $call): bool
    {
        if ($site->is_inactive) {
            return false;
        }

        return $this->dispatch($call);
    }

    private function dispatch(callable $call): bool
    {
        $sent = false;
        foreach ($this->notifiers as $notifier) {
            if ($call($notifier)) {
                $sent = true;
            }
        }

        return $sent;
    }
}
