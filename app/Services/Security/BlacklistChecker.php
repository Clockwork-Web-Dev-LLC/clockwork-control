<?php

namespace App\Services\Security;

use App\Models\Site;
use App\Models\SiteSecurityScan;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Domain-blacklist lookups against free public sources. Replaces the only
 * useful part of Sucuri SiteCheck that survives a Cloudflare WAF block: the
 * "is this domain on a blocklist anywhere?" check. Sucuri's homepage-fetch
 * portion fails behind CF; this path doesn't fetch the site at all.
 *
 * Sources, in order of signal strength:
 *
 *   1. **Google Safe Browsing v4 Lookup** — the gold standard. Drives
 *      Chrome's "this site may harm your computer" warning. Free, but needs
 *      an API key (registered on Google Cloud, 10k req/day). Skipped if
 *      `CLOCKWORK_GOOGLE_SAFE_BROWSING_KEY` is unset.
 *   2. **URLHaus by abuse.ch** — open malware-host database, 4M+ entries.
 *      Free, but requires an Auth-Key header since 2024 (free registration at
 *      abuse.ch's account portal). Catches sites used as malware C2 or in
 *      phishing campaigns. Skipped if `CLOCKWORK_URLHAUS_AUTH_KEY` is unset.
 *   3. **Spamhaus DBL** — domain-reputation DNS list used by mail servers
 *      worldwide. Free, no key. DNS lookup against `<domain>.dbl.spamhaus.org`
 *      returns 127.0.1.x for hits (2=spam, 4=phish, 5=malware, 6=botnet).
 *      Always runs — the zero-config baseline.
 *
 * A "hit" on any source flags the site. We capture which list flagged it +
 * any hit-specific metadata in details so the UI can show "Blacklisted by
 * Google Safe Browsing — MALWARE" rather than just "blacklisted."
 *
 * Failure handling: a single source erroring is NOT a scan failure — we
 * record the partial result with a `source_errors` map so the user can see
 * "GSB unreachable, others ran clean." Total failure (every source errored)
 * collapses to status='failed'.
 */
class BlacklistChecker
{
    public const SOURCE_GSB = 'google_safe_browsing';

    public const SOURCE_URLHAUS = 'urlhaus';

    public const SOURCE_SPAMHAUS_DBL = 'spamhaus_dbl';

    public function __construct(
        protected ?string $gsbApiKey = null,
        protected ?string $urlhausAuthKey = null,
        protected ?int $httpTimeout = null,
    ) {
        $this->gsbApiKey ??= (string) config('clockwork.security_scans.google_safe_browsing_key', '');
        $this->urlhausAuthKey ??= (string) config('clockwork.security_scans.urlhaus_auth_key', '');
        $this->httpTimeout ??= (int) config('clockwork.security_scans.blacklist_timeout', 10);
    }

    public function check(Site $site): SecurityScanResult
    {
        $started = (int) (microtime(true) * 1000);
        $domain = strtolower(trim($site->domain));

        $hits = [];
        $errors = [];

        $gsb = $this->checkGoogleSafeBrowsing($domain);
        $this->recordSourceOutcome(self::SOURCE_GSB, $gsb, $hits, $errors);

        $urlhaus = $this->checkUrlhaus($domain);
        $this->recordSourceOutcome(self::SOURCE_URLHAUS, $urlhaus, $hits, $errors);

        $dbl = $this->checkSpamhausDbl($domain);
        $this->recordSourceOutcome(self::SOURCE_SPAMHAUS_DBL, $dbl, $hits, $errors);

        $sourcesAttempted = $this->sourcesAttempted();
        $elapsed = (int) (microtime(true) * 1000) - $started;

        // Every source errored — surface as failed, not clean. A clean result
        // implies "we asked, no hits"; if nobody answered, we can't claim that.
        if (count($errors) === count($sourcesAttempted)) {
            return new SecurityScanResult(
                site: $site,
                scanType: SiteSecurityScan::TYPE_BLACKLIST,
                status: SiteSecurityScan::STATUS_FAILED,
                summary: 'Blacklist check failed — every source errored.',
                details: ['source_errors' => $errors, 'sources_attempted' => $sourcesAttempted],
                error: substr(implode(' | ', $errors), 0, 480),
                elapsedMs: $elapsed,
            );
        }

        if ($hits !== []) {
            $sources = array_map(fn ($h) => $h['source'], $hits);

            return new SecurityScanResult(
                site: $site,
                scanType: SiteSecurityScan::TYPE_BLACKLIST,
                status: SiteSecurityScan::STATUS_ISSUES_FOUND,
                blacklistHit: true,
                summary: 'Blacklisted by '.implode(', ', $sources).'.',
                details: [
                    'hits' => $hits,
                    'source_errors' => $errors,
                    'sources_attempted' => $sourcesAttempted,
                ],
                elapsedMs: $elapsed,
            );
        }

        $cleanSources = array_diff($sourcesAttempted, array_keys($errors));

        return new SecurityScanResult(
            site: $site,
            scanType: SiteSecurityScan::TYPE_BLACKLIST,
            status: SiteSecurityScan::STATUS_CLEAN,
            summary: 'Clean against '.implode(', ', $cleanSources).'.',
            details: [
                'sources_attempted' => $sourcesAttempted,
                'sources_clean' => array_values($cleanSources),
                'source_errors' => $errors,
            ],
            elapsedMs: $elapsed,
        );
    }

    /**
     * @return array{hit: bool, error?: string, threat_types?: list<string>}
     */
    private function checkGoogleSafeBrowsing(string $domain): array
    {
        if ($this->gsbApiKey === '') {
            return ['hit' => false, 'error' => 'gsb_key_not_configured'];
        }

        try {
            $body = [
                'client' => ['clientId' => 'clockwork', 'clientVersion' => '1.0'],
                'threatInfo' => [
                    'threatTypes' => [
                        'MALWARE',
                        'SOCIAL_ENGINEERING',
                        'UNWANTED_SOFTWARE',
                        'POTENTIALLY_HARMFUL_APPLICATION',
                    ],
                    'platformTypes' => ['ANY_PLATFORM'],
                    'threatEntryTypes' => ['URL'],
                    'threatEntries' => [
                        ['url' => 'https://'.$domain.'/'],
                        ['url' => 'http://'.$domain.'/'],
                    ],
                ],
            ];

            $response = Http::timeout($this->httpTimeout)
                ->retry(2, 500, throw: false)
                ->asJson()
                ->acceptJson()
                ->post(
                    'https://safebrowsing.googleapis.com/v4/threatMatches:find?key='.urlencode($this->gsbApiKey),
                    $body,
                );

            if ($response->failed()) {
                return ['hit' => false, 'error' => "gsb_http_{$response->status()}"];
            }

            $matches = $response->json('matches');
            if (! is_array($matches) || $matches === []) {
                return ['hit' => false];
            }

            $threatTypes = array_values(array_unique(array_filter(array_map(
                fn ($m) => is_array($m) ? ($m['threatType'] ?? null) : null,
                $matches,
            ))));

            return ['hit' => true, 'threat_types' => $threatTypes];
        } catch (Throwable $e) {
            return ['hit' => false, 'error' => 'gsb_exception: '.substr($e->getMessage(), 0, 120)];
        }
    }

    /**
     * URLHaus host lookup. Returns "ok" (with url_count) when the domain has
     * any malicious URLs catalogued; "no_results" when clean.
     *
     * @return array{hit: bool, error?: string, url_count?: int, urls?: list<array<string, mixed>>}
     */
    private function checkUrlhaus(string $domain): array
    {
        if ($this->urlhausAuthKey === '') {
            return ['hit' => false, 'error' => 'urlhaus_key_not_configured'];
        }

        try {
            $response = Http::timeout($this->httpTimeout)
                ->retry(2, 500, throw: false)
                ->withHeaders(['Auth-Key' => $this->urlhausAuthKey])
                ->asForm()
                ->acceptJson()
                ->post('https://urlhaus-api.abuse.ch/v1/host/', ['host' => $domain]);

            if ($response->failed()) {
                return ['hit' => false, 'error' => "urlhaus_http_{$response->status()}"];
            }

            $status = (string) ($response->json('query_status') ?? '');
            if ($status === 'no_results') {
                return ['hit' => false];
            }
            if ($status !== 'ok') {
                return ['hit' => false, 'error' => "urlhaus_status_{$status}"];
            }

            $urlCount = (int) ($response->json('url_count') ?? 0);
            $urls = $response->json('urls');

            // Cap URL detail at first 3 entries — keeps the JSON small enough
            // for the details column without losing forensic value.
            $sample = is_array($urls) ? array_slice($urls, 0, 3) : [];

            return ['hit' => true, 'url_count' => $urlCount, 'urls' => $sample];
        } catch (Throwable $e) {
            return ['hit' => false, 'error' => 'urlhaus_exception: '.substr($e->getMessage(), 0, 120)];
        }
    }

    /**
     * Spamhaus DBL: lookup `<domain>.dbl.spamhaus.org` — a return of any
     * 127.0.1.x address means a hit. Codes:
     *   127.0.1.2 = spam, 127.0.1.4 = phish, 127.0.1.5 = malware,
     *   127.0.1.6 = botnet C&C, 127.0.1.102/104/105/106 = abused legit.
     * 127.255.255.252 = "blocked due to bulk lookup" (we ignore — not a hit).
     *
     * @return array{hit: bool, error?: string, code?: string, label?: string}
     */
    private function checkSpamhausDbl(string $domain): array
    {
        $hostname = $domain.'.dbl.spamhaus.org';

        // Suppress fopen-style notices; gethostbyname returns the input unchanged
        // on miss/error, which is the standard PHP idiom we rely on here.
        $resolved = @gethostbyname($hostname);
        if ($resolved === $hostname || ! filter_var($resolved, FILTER_VALIDATE_IP)) {
            return ['hit' => false];
        }

        // Spamhaus DBL only ever returns 127.0.1.x for hits and 127.255.255.x
        // for "you're abusing the lookup service" warnings. Anything else is
        // a transport oddity and we bail rather than guess.
        if (str_starts_with($resolved, '127.255.')) {
            return ['hit' => false, 'error' => 'spamhaus_query_blocked: '.$resolved];
        }
        if (! str_starts_with($resolved, '127.0.1.')) {
            return ['hit' => false];
        }

        $label = match ($resolved) {
            '127.0.1.2' => 'spam',
            '127.0.1.4' => 'phish',
            '127.0.1.5' => 'malware',
            '127.0.1.6' => 'botnet C&C',
            '127.0.1.102' => 'abused legit (spam)',
            '127.0.1.103' => 'abused redirector',
            '127.0.1.104' => 'abused legit (phish)',
            '127.0.1.105' => 'abused legit (malware)',
            '127.0.1.106' => 'abused legit (botnet C&C)',
            default => 'flagged ('.$resolved.')',
        };

        return ['hit' => true, 'code' => $resolved, 'label' => $label];
    }

    /**
     * @param  array<string, mixed>  $outcome
     * @param  array<int, array<string, mixed>>  $hits
     * @param  array<string, string>  $errors
     */
    private function recordSourceOutcome(string $source, array $outcome, array &$hits, array &$errors): void
    {
        if (! empty($outcome['error'])) {
            // "Not configured" isn't an error worth flagging — it's a
            // deliberate "this source is opt-in" state. Other errors are
            // recorded so the UI can show partial results honestly.
            $optInSentinels = [
                self::SOURCE_GSB => 'gsb_key_not_configured',
                self::SOURCE_URLHAUS => 'urlhaus_key_not_configured',
            ];
            if (isset($optInSentinels[$source]) && $outcome['error'] === $optInSentinels[$source]) {
                return;
            }
            $errors[$source] = (string) $outcome['error'];

            return;
        }

        if (! empty($outcome['hit'])) {
            $hits[] = ['source' => $source] + array_diff_key($outcome, ['hit' => 1]);
        }
    }

    /**
     * @return list<string>
     */
    private function sourcesAttempted(): array
    {
        $sources = [self::SOURCE_SPAMHAUS_DBL];
        if ($this->urlhausAuthKey !== '') {
            array_unshift($sources, self::SOURCE_URLHAUS);
        }
        if ($this->gsbApiKey !== '') {
            array_unshift($sources, self::SOURCE_GSB);
        }

        return $sources;
    }
}
