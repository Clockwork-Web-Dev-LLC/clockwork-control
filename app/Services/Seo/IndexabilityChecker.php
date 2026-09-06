<?php

namespace App\Services\Seo;

use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Services\Uptime\UptimeProbeResult;
use App\Support\SsrfGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class IndexabilityChecker
{
    public function __construct(
        private readonly ChatNotifier $notifier,
        private readonly IndexabilityInspector $inspector,
    ) {}

    /**
     * Check homepage meta tags and X-Robots-Tag from an existing UptimeProbeResult.
     * Piggybacks on the 5-minute uptime probe cycle with zero extra HTTP requests.
     */
    public function checkFromProbeResult(Site $site, UptimeProbeResult $probeResult): void
    {
        if (! $site->seo_monitoring_enabled || ! config('clockwork.seo.monitoring_enabled', true)) {
            return;
        }

        // A failed/erroring probe (down, timed out, DNS failure, etc.) tells
        // us nothing about the site's real indexability — bail out entirely
        // rather than treating "couldn't check" as "clean," which would
        // clear a real block and fire a false recovery notification.
        if (! $probeResult->succeeded) {
            return;
        }

        $metaSnippet = $probeResult->body !== null
            ? $this->inspector->inspectMeta($probeResult->body)
            : $site->seo_meta_snippet;
        $headerSnippet = $this->inspector->inspectHeader($probeResult->xRobotsTagHeader);

        $this->updateAndClassify($site, $metaSnippet, $headerSnippet, $site->seo_robots_snippet);
    }

    /**
     * Check /robots.txt via a dedicated lightweight daily HTTP fetch.
     */
    public function checkRobotsTxt(Site $site): void
    {
        if (! $site->seo_monitoring_enabled || ! config('clockwork.seo.monitoring_enabled', true)) {
            return;
        }

        $timeout = (int) config('clockwork.seo.robots_txt_timeout', 10);

        try {
            $url = "https://{$site->domain}/robots.txt";
            SsrfGuard::assertPublic($url);
            $response = Http::timeout($timeout)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 3,
                        'strict' => false,
                        'protocols' => ['https'],
                        'on_redirect' => SsrfGuard::onRedirect(),
                    ],
                ])
                ->get($url);

            if (! $response->successful()) {
                // Inconclusive, not "clean" — a fetch failure/non-2xx tells
                // us nothing new, so preserve whatever robots.txt state was
                // last known instead of clearing a real block.
                return;
            }

            $robotsSnippet = $this->inspector->inspectRobotsTxt($response->body());
        } catch (Throwable) {
            // Network failure tells us nothing about indexability — preserve state.
            return;
        }

        $this->updateAndClassify($site, $site->seo_meta_snippet, $site->seo_header_snippet, $robotsSnippet);
    }

    /**
     * Synchronous on-demand pre-flight launch check running all Clockwork-side vectors.
     *
     * @return array{ok: bool, indexable: bool, status_label: string, reason: ?string, snippet: ?string, is_staging: bool, meta: ?string, header: ?string, robots: ?string}
     */
    public function preflightCheck(Site $site): array
    {
        $timeout = (int) config('clockwork.seo.robots_txt_timeout', 10);
        // Default to whatever's already known — a failed fetch below keeps
        // it that way instead of silently reporting "clean."
        $metaSnippet = $site->seo_meta_snippet;
        $headerSnippet = $site->seo_header_snippet;
        $robotsSnippet = $site->seo_robots_snippet;

        try {
            $homeUrl = "https://{$site->domain}/";
            SsrfGuard::assertPublic($homeUrl);
            $homeResponse = Http::timeout($timeout)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => false,
                        'protocols' => ['https'],
                        'on_redirect' => SsrfGuard::onRedirect(),
                    ],
                ])
                ->get($homeUrl);

            if ($homeResponse->successful()) {
                $metaSnippet = $this->inspector->inspectMeta($homeResponse->body());
                $headerSnippet = $this->inspector->inspectHeader($homeResponse->header('X-Robots-Tag'));
            }
        } catch (Throwable) {
            // Homepage fetch failed — keep the previously known meta/header state.
        }

        try {
            $robotsUrl = "https://{$site->domain}/robots.txt";
            SsrfGuard::assertPublic($robotsUrl);
            $robotsResponse = Http::timeout($timeout)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 3,
                        'strict' => false,
                        'protocols' => ['https'],
                        'on_redirect' => SsrfGuard::onRedirect(),
                    ],
                ])
                ->get($robotsUrl);

            if ($robotsResponse->successful()) {
                $robotsSnippet = $this->inspector->inspectRobotsTxt($robotsResponse->body());
            }
        } catch (Throwable) {
            // robots.txt fetch failed — keep the previously known state.
        }

        $this->updateAndClassify($site, $metaSnippet, $headerSnippet, $robotsSnippet);

        return [
            'ok' => true,
            'indexable' => $site->seo_indexable,
            'status_label' => $site->seoStatusLabel(),
            'reason' => $site->seo_blocked_reason,
            'snippet' => $site->seo_blocked_snippet,
            'is_staging' => $site->server?->isStaging() ?? false,
            'meta' => $metaSnippet,
            'header' => $headerSnippet,
            'robots' => $robotsSnippet,
        ];
    }

    /**
     * Persists the latest per-vector snapshot (meta/header/robots — each
     * independently tracked so an inconclusive or not-yet-rechecked vector
     * never gets silently dropped), recomputes the derived top-priority
     * reason from all three together, and fires a notification on any real
     * transition.
     */
    private function updateAndClassify(Site $site, ?string $metaSnippet, ?string $headerSnippet, ?string $robotsSnippet): void
    {
        $site->seo_meta_snippet = $metaSnippet;
        $site->seo_header_snippet = $headerSnippet;
        $site->seo_robots_snippet = $robotsSnippet;

        $classification = $this->inspector->classify($metaSnippet, $headerSnippet, $robotsSnippet);
        $this->applyClassification($site, $classification);

        $site->seo_checked_at = Carbon::now();
        $site->save();
    }

    /**
     * Apply classification to site model, updating state and firing notifications when state transitions.
     *
     * @param  array{blocked: bool, reason: ?string, snippet: ?string}  $classification
     */
    private function applyClassification(Site $site, array $classification): bool
    {
        $wasBlocked = ! $site->seo_indexable;
        $nowBlocked = $classification['blocked'];
        $reason = $classification['reason'];
        $snippet = $classification['snippet'];

        $stateChanged = ($wasBlocked !== $nowBlocked) || ($site->seo_blocked_reason !== $reason);

        if ($stateChanged) {
            $site->seo_indexable = ! $nowBlocked;
            $site->seo_blocked_reason = $reason;
            $site->seo_blocked_snippet = $snippet;
            $site->seo_state_changed_at = Carbon::now();
            $site->save();

            $isStaging = $site->server?->isStaging() ?? false;

            if (! $isStaging) {
                if (! $wasBlocked && $nowBlocked) {
                    $this->notifier->seoIndexabilityBlocked($site, $reason ?? 'unknown', $snippet ?? '');
                } elseif ($wasBlocked && ! $nowBlocked) {
                    $this->notifier->seoIndexabilityRecovered($site);
                }
            }

            return true;
        }

        return false;
    }
}
