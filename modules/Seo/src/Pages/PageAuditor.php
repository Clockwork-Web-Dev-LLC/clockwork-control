<?php

namespace Modules\Seo\Pages;

use App\Models\Site;
use App\Services\Uptime\UptimeProber;
use App\Support\SsrfGuard;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Seo\Models\PageAudit;
use Modules\Seo\Ranks\SerpParser;
use RuntimeException;
use Throwable;

/**
 * Fetches pages on a client's own site and stores what each says about
 * itself. It only ever fetches https pages on the site's own domain, so it
 * can't be used to make Control fetch anything else.
 *
 * A page we could not read is stored as blocked or error, never as an
 * empty audit: "no title" must mean the page has no title.
 */
class PageAuditor
{
    public const MAX_URLS = 5;

    /** Read at most this much of a page. */
    private const MAX_BYTES = 3_000_000;

    /**
     * Why a URL can't be audited, or null when it can.
     */
    public function refusal(Site $site, string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return 'Only full https:// URLs can be audited.';
        }
        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return 'URLs with a login or a port are not audited.';
        }
        if (! $this->onSite($site, (string) $parts['host'])) {
            return "Only pages on {$site->domain} can be audited.";
        }
        if (mb_strlen($url) > 2000) {
            return 'That URL is too long.';
        }

        return null;
    }

    /**
     * @param  list<string>  $urls  already passed through refusal()
     * @return list<PageAudit> in the order asked
     */
    public function audit(Site $site, array $urls): array
    {
        $urls = array_slice(array_values(array_unique(array_map('trim', $urls))), 0, self::MAX_URLS);
        $timeout = (int) config('clockwork.seo.page_audit_timeout', 12);
        $blockedBefore = [];

        foreach ($urls as $index => $url) {
            try {
                SsrfGuard::assertPublic($url);
            } catch (Throwable $e) {
                $blockedBefore[$index] = $e->getMessage();
            }
        }

        $fetchable = array_diff_key($urls, $blockedBefore);

        /** @var array<int, Response|Throwable> $responses */
        $responses = $fetchable === [] ? [] : Http::pool(function (Pool $pool) use ($fetchable, $timeout, $site) {
            $requests = [];
            foreach ($fetchable as $index => $url) {
                $requests[] = $pool->as((string) $index)
                    ->timeout($timeout)
                    ->withUserAgent(UptimeProber::userAgent())
                    ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
                    ->withOptions(['allow_redirects' => [
                        'max' => 4,
                        'strict' => false,
                        'protocols' => ['https'],
                        // A redirect may not leave the site or reach a private address.
                        'on_redirect' => function ($request, $response, $uri) use ($site): void {
                            SsrfGuard::assertPublic((string) $uri);
                            if (! $this->onSite($site, (string) $uri->getHost())) {
                                throw new RuntimeException('Redirects to another site ('.$uri->getHost().').');
                            }
                        },
                        'track_redirects' => true,
                    ]])
                    ->get($url);
            }

            return $requests;
        });

        $audits = [];
        foreach ($urls as $index => $url) {
            $attributes = isset($blockedBefore[$index])
                ? ['fetch_status' => PageAudit::STATUS_ERROR, 'error' => mb_substr($blockedBefore[$index], 0, 480)]
                : $this->read($responses[(string) $index] ?? new RuntimeException('No response.'));

            $audits[] = PageAudit::query()->updateOrCreate(
                ['site_id' => $site->id, 'url_hash' => sha1($url)],
                $attributes + [
                    'url' => $url, 'http_status' => null, 'final_url' => null, 'title' => null, 'meta_description' => null,
                    'h1s' => null, 'h2s' => null, 'canonical' => null, 'meta_robots' => null, 'schema_types' => null,
                    'word_count' => null, 'error' => null, 'audited_at' => now(),
                ],
            );
        }

        return $audits;
    }

    /** @return array<string, mixed> */
    private function read(Response|Throwable $response): array
    {
        if ($response instanceof Throwable) {
            return ['fetch_status' => PageAudit::STATUS_ERROR, 'error' => mb_substr($response->getMessage(), 0, 480)];
        }

        $status = $response->status();
        $body = substr($response->body(), 0, self::MAX_BYTES);
        $history = $response->header('X-Guzzle-Redirect-History');
        $finalUrl = $history !== '' ? trim((string) last(explode(',', $history))) : null;
        $base = ['http_status' => $status, 'final_url' => $finalUrl];

        // A firewall or login stopped us. The page itself may be fine.
        if (in_array($status, [401, 403, 429], true) || PageParser::looksLikeChallenge($body) || $response->header('cf-mitigated') !== '') {
            return $base + ['fetch_status' => PageAudit::STATUS_BLOCKED, 'error' => "The site answered {$status} to Control's request, which usually means a firewall or bot protection blocked it. Open the page in a browser to check it."];
        }
        if (! $response->successful()) {
            return $base + ['fetch_status' => PageAudit::STATUS_ERROR, 'error' => "The page answered HTTP {$status}."];
        }
        if (! str_contains(strtolower($response->header('Content-Type')), 'html') && ! preg_match('/<html[\s>]/i', substr($body, 0, 2000))) {
            return $base + ['fetch_status' => PageAudit::STATUS_NOT_HTML, 'error' => 'This URL is not a web page ('.($response->header('Content-Type') ?: 'unknown type').').'];
        }

        return $base + ['fetch_status' => PageAudit::STATUS_OK] + PageParser::parse($body);
    }

    private function onSite(Site $site, string $host): bool
    {
        $domain = SerpParser::host((string) $site->domain);

        return $domain !== '' && SerpParser::host($host) === $domain;
    }
}
