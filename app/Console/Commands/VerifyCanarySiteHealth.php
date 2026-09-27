<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Post-deploy sanity check: verify that each site's homepage and
 * wp-login.php are still reachable after a Companion deploy.
 *
 *   1. The home page responds with a 2xx or 3xx (site is up)
 *   2. /wp-login.php responds with 200/30x (login page accessible)
 *
 * Gatekeeper must never block the login page — it only fires on
 * failed login _attempts_, not page loads. This check catches any
 * misconfiguration or fatal error introduced by a Companion deploy.
 *
 * Run against canary only (default) or the full fleet (--all-installed).
 */
class VerifyCanarySiteHealth extends Command
{
    protected $signature = 'clockwork:verify-canary-health
        {--all-installed : Check every companion_installed site instead of just the canary set}
        {--timeout=10 : HTTP request timeout in seconds}
        {--skip= : Comma-separated domains to skip (e.g. sites behind HTTP auth)}';

    protected $description = 'Verify homepage + wp-login.php are reachable on every canary site (or the full fleet with --all-installed) after a deploy.';

    public function handle(Settings $settings): int
    {
        $allInstalled = (bool) $this->option('all-installed');
        $skipDomains = array_filter(array_map('trim', explode(',', (string) $this->option('skip'))));
        $timeout = max(5, (int) $this->option('timeout'));

        if ($allInstalled) {
            $sites = Site::where('companion_installed', true)
                ->where('is_inactive', false)
                ->whereNull('consolidated_into_site_id')
                ->orderBy('domain')
                ->get(['id', 'domain']);
        } else {
            $ids = (array) $settings->get('companion.canary_site_ids', []);
            if (empty($ids)) {
                $this->warn('Canary set is empty. Pass --all-installed to check all sites.');

                return self::SUCCESS;
            }
            $sites = Site::whereIn('id', $ids)
                ->where('is_inactive', false)
                ->orderBy('domain')
                ->get(['id', 'domain']);
        }

        if ($skipDomains !== []) {
            $sites = $sites->filter(fn ($s) => ! in_array($s->domain, $skipDomains, true))->values();
        }

        $this->info("Checking {$sites->count()} canary site(s) — homepage + wp-login.php…");
        $this->newLine();

        $failures = [];

        foreach ($sites as $site) {
            $domain = $site->domain;
            $base = "https://{$domain}";

            [$homeOk, $homeStatus, $homeNote] = $this->probe("{$base}/", $timeout, allowRedirects: true);
            [$loginOk, $loginStatus, $loginNote] = $this->probe("{$base}/wp-login.php", $timeout, allowRedirects: false);

            $homeIcon = $homeOk ? '✓' : '✗';
            $loginIcon = $loginOk ? '✓' : '✗';

            $line = sprintf(
                '  %s home=%s  %s login=%s  %s',
                $homeIcon,
                $homeStatus,
                $loginIcon,
                $loginStatus,
                $domain,
            );

            if (! $homeOk || ! $loginOk) {
                $this->error($line);
                $note = implode(' | ', array_filter([$homeNote, $loginNote]));
                if ($note) {
                    $this->line("       ↳ {$note}");
                }
                $failures[] = $domain;
            } else {
                $this->line($line);
            }
        }

        $this->newLine();

        if (empty($failures)) {
            $this->info('All canary sites healthy ✓');

            return self::SUCCESS;
        }

        $this->error(count($failures).' site(s) failed health check:');
        foreach ($failures as $domain) {
            $this->line("  · {$domain}");
        }

        return self::FAILURE;
    }

    /**
     * @return array{bool, string, string} [ok, statusLabel, note]
     */
    private function probe(string $url, int $timeout, bool $allowRedirects): array
    {
        try {
            $request = Http::timeout($timeout)
                ->withUserAgent('ClockworkControl-HealthCheck/1.0')
                ->withOptions(['verify' => false]); // skip cert errors for staging sites

            $response = $allowRedirects
                ? $request->get($url)
                : $request->withOptions(['allow_redirects' => false])->get($url);

            $status = $response->status();
            $label = (string) $status;

            if ($allowRedirects) {
                $ok = $status >= 200 && $status < 400;
            } else {
                // wp-login.php: 200/301/302 = fine.
                // 403 with cf-mitigated header = Cloudflare bot challenge protecting
                // the login page — that's correct behaviour, not a failure.
                $isCloudflareBotChallenge = $status === 403
                    && str_contains($response->header('cf-mitigated') ?? '', 'challenge');

                $ok = ($status >= 200 && $status < 400) || $isCloudflareBotChallenge;

                if ($isCloudflareBotChallenge) {
                    $label = '403/CF✓';
                }
            }

            $note = $ok ? '' : "HTTP {$status}";

            // Flag if login page looks like it returned an error body
            if (! $allowRedirects && $status === 200) {
                $body = $response->body();
                if (str_contains($body, 'critical error') || str_contains($body, 'There has been')) {
                    $ok = false;
                    $note = 'Login page returned 200 but contains critical-error text';
                }
            }

            return [$ok, $label, $note];
        } catch (ConnectionException $e) {
            return [false, 'timeout', $e->getMessage()];
        } catch (\Throwable $e) {
            return [false, 'error', $e->getMessage()];
        }
    }
}
