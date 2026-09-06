<?php

namespace Tests\Feature\Chat;

use App\Models\BlockedIp;
use App\Models\PluginUpdateJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Services\Chat\ChatNotifier;
use App\Services\Chat\ChatNotifierDispatcher;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| is_inactive gating, verified against the current source
|--------------------------------------------------------------------------
|
| tests/Feature/ChatNotifierDispatchTest.php already proves the tag-based
| fan-out mechanism (the container wiring calls every real channel). This
| file is only about ChatNotifierDispatcher's own gating logic: which event
| methods go through dispatchForSite() (short-circuits to false, calling
| zero notifiers, when Site::is_inactive is true) and which go through
| dispatch() directly (never gated on is_inactive at all).
|
| Read straight from App\Services\Chat\ChatNotifierDispatcher on 2026-09-02:
|
| Gated via dispatchForSite() — "routine maintenance" events, silenced for
| an inactive site:
|   sslStateChanged, llarInstalled, contactFormTestFailed,
|   contactFormTestRecovered, companionUnreachable, companionReachable,
|   pluginUpdateFailed
|
| NEVER gated — routed straight through dispatch() even for an inactive
| site, because the class's own comment block says these are active-incident
| signals (a real compromise, a real outage, a real attacker at the
| firewall) on infrastructure Clockwork still serves, not routine
| maintenance nags like an upcoming SSL renewal:
|   siteWentDown, siteWentUp, malwareFindingDetected, ipBlocked
|
| Not site-scoped at all — no Site parameter, so is_inactive can't apply:
|   send, backupRelayStale, backupRelayRecovered, serverUpdateFailed,
|   queueWorkerRestartFailed
|
| The regression this file exists to catch: someone "helpfully" moving one
| of the never-gated active-incident methods onto dispatchForSite() would
| silently stop alerting on a real compromise/outage/attacker for every
| inactive site. That's the case with real teeth below, not the gated-method
| cases (which just confirm the existing, intended behavior).
*/

/**
 * A ChatNotifier test double that records which of its own methods were
 * called (by name) and always returns a fixed, caller-chosen bool — so a
 * test can assert both "was this notifier ever invoked" and "what did the
 * dispatcher do with what it returned".
 */
function fakeChatNotifier(bool $returns): ChatNotifier
{
    return new class($returns) implements ChatNotifier
    {
        /** @var string[] */
        public array $calls = [];

        public function __construct(private readonly bool $returns) {}

        public function send(string $text, array $attachments = []): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function ipBlocked(BlockedIp $blocked): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function sslStateChanged(Site $site, string $from, string $to): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function domainExpirationStateChanged(Site $site, string $from, string $to): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function seoIndexabilityBlocked(Site $site, string $reason, string $snippet): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function seoIndexabilityRecovered(Site $site): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function llarInstalled(Site $site): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function contactFormTestFailed(Site $site, string $reason, int $streak, ?string $formId = null): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function contactFormTestRecovered(Site $site, ?string $formId = null): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function companionUnreachable(Site $site, string $reason): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function companionReachable(Site $site, ?int $stuckForSeconds = null): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function siteWentDown(Site $site, ?int $statusCode, ?string $error, bool $likelyWafBlock = false, ?array $diagnosis = null): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function siteWentUp(Site $site, ?int $downtimeSec): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function pluginUpdateFailed(Site $site, PluginUpdateJob $job): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function malwareFindingDetected(Site $site, SiteSecurityScan $scan): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function backupRelayStale(int $daysSinceLastRun, ?Carbon $lastRunAt = null): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function backupRelayRecovered(): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function serverUpdateFailed(Server $server, string $reason): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }

        public function queueWorkerRestartFailed(string $reason): bool
        {
            $this->calls[] = __FUNCTION__;

            return $this->returns;
        }
    };
}

/**
 * The seven dispatchForSite()-routed "routine maintenance" methods, each as
 * a closure that invokes it on a given dispatcher/site with plausible args.
 */
dataset('gatedMethods', [
    'sslStateChanged' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->sslStateChanged($s, 'green', 'red')],
    'domainExpirationStateChanged' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->domainExpirationStateChanged($s, 'green', 'yellow')],
    'seoIndexabilityBlocked' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->seoIndexabilityBlocked($s, 'meta_noindex', '<meta name="robots" content="noindex">')],
    'seoIndexabilityRecovered' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->seoIndexabilityRecovered($s)],
    'llarInstalled' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->llarInstalled($s)],
    'contactFormTestFailed' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->contactFormTestFailed($s, 'timeout', 3)],
    'contactFormTestRecovered' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->contactFormTestRecovered($s)],
    'companionUnreachable' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->companionUnreachable($s, 'HMAC probe timed out')],
    'companionReachable' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->companionReachable($s, 120)],
    'pluginUpdateFailed' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->pluginUpdateFailed(
        $s,
        PluginUpdateJob::factory()->create(['site_id' => $s->id])
    )],
]);

/**
 * The four dispatch()-routed "active incident" methods that must NEVER be
 * gated on is_inactive, each as a closure invoking it with plausible args.
 */
dataset('neverGatedMethods', [
    'siteWentDown' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->siteWentDown($s, 503, 'Connection timed out')],
    'siteWentUp' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->siteWentUp($s, 240)],
    'malwareFindingDetected' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->malwareFindingDetected(
        $s,
        SiteSecurityScan::factory()->malwareFound()->create(['site_id' => $s->id])
    )],
    'ipBlocked' => [fn (ChatNotifierDispatcher $d, Site $s) => $d->ipBlocked(BlockedIp::factory()->create())],
]);

describe('dispatchForSite()-routed methods are gated on is_inactive', function () {
    it('short-circuits to false and calls no notifier on an inactive site', function (\Closure $call) {
        $notifier = fakeChatNotifier(true);
        $dispatcher = new ChatNotifierDispatcher([$notifier]);
        $site = Site::factory()->inactive()->create();

        $result = $call($dispatcher, $site);

        expect($result)->toBeFalse();
        expect($notifier->calls)->toBeEmpty();
    })->with('gatedMethods');

    it('calls through and returns true on an active site', function (\Closure $call) {
        $notifier = fakeChatNotifier(true);
        $dispatcher = new ChatNotifierDispatcher([$notifier]);
        $site = Site::factory()->create(['is_inactive' => false]);

        $result = $call($dispatcher, $site);

        expect($result)->toBeTrue();
        expect($notifier->calls)->not->toBeEmpty();
    })->with('gatedMethods');
});

describe('dispatch()-routed active-incident methods are never gated on is_inactive', function () {
    it('still fires on an inactive site — these are active-incident signals, not routine maintenance nags', function (\Closure $call) {
        $notifier = fakeChatNotifier(true);
        $dispatcher = new ChatNotifierDispatcher([$notifier]);
        $site = Site::factory()->inactive()->create();

        $result = $call($dispatcher, $site);

        // The regression this guards against: if this method were "helpfully"
        // switched to dispatchForSite() by mistake, a real compromise/outage/
        // attacker on an inactive site would silently stop alerting anyone.
        expect($result)->toBeTrue();
        expect($notifier->calls)->not->toBeEmpty();
    })->with('neverGatedMethods');
});

describe('non-site-scoped methods always dispatch (no Site parameter, so is_inactive cannot apply)', function () {
    it('send() calls through', function () {
        $notifier = fakeChatNotifier(true);
        $dispatcher = new ChatNotifierDispatcher([$notifier]);

        expect($dispatcher->send('hello'))->toBeTrue();
        expect($notifier->calls)->toBe(['send']);
    });

    it('backupRelayStale() calls through', function () {
        $notifier = fakeChatNotifier(true);
        $dispatcher = new ChatNotifierDispatcher([$notifier]);

        expect($dispatcher->backupRelayStale(5, now()->subDays(5)))->toBeTrue();
        expect($notifier->calls)->toBe(['backupRelayStale']);
    });

    it('backupRelayRecovered() calls through', function () {
        $notifier = fakeChatNotifier(true);
        $dispatcher = new ChatNotifierDispatcher([$notifier]);

        expect($dispatcher->backupRelayRecovered())->toBeTrue();
        expect($notifier->calls)->toBe(['backupRelayRecovered']);
    });

    it('serverUpdateFailed() calls through', function () {
        $notifier = fakeChatNotifier(true);
        $dispatcher = new ChatNotifierDispatcher([$notifier]);
        $server = Server::factory()->create();

        expect($dispatcher->serverUpdateFailed($server, 'apt-get upgrade failed'))->toBeTrue();
        expect($notifier->calls)->toBe(['serverUpdateFailed']);
    });

    it('queueWorkerRestartFailed() calls through', function () {
        $notifier = fakeChatNotifier(true);
        $dispatcher = new ChatNotifierDispatcher([$notifier]);

        expect($dispatcher->queueWorkerRestartFailed('launchctl kickstart failed'))->toBeTrue();
        expect($notifier->calls)->toBe(['queueWorkerRestartFailed']);
    });
});

describe('dispatch() fan-out: true if AT LEAST ONE channel accepted, without short-circuiting', function () {
    it('returns true when one of two notifiers accepts, and both are actually called', function () {
        $accepting = fakeChatNotifier(true);
        $refusing = fakeChatNotifier(false);
        $dispatcher = new ChatNotifierDispatcher([$refusing, $accepting]);

        $result = $dispatcher->send('incident update');

        expect($result)->toBeTrue();
        expect($refusing->calls)->toBe(['send']);
        expect($accepting->calls)->toBe(['send']);
    });

    it('returns false when both notifiers refuse, and both are still called', function () {
        $first = fakeChatNotifier(false);
        $second = fakeChatNotifier(false);
        $dispatcher = new ChatNotifierDispatcher([$first, $second]);

        $result = $dispatcher->send('incident update');

        expect($result)->toBeFalse();
        expect($first->calls)->toBe(['send']);
        expect($second->calls)->toBe(['send']);
    });
});
