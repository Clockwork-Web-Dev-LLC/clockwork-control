<?php

namespace App\Support\Monitoring;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Fleet-wide domain ignore list for uptime monitoring, managed from
 * Monitoring → Settings. Patterns support a `*` wildcard so an operator can
 * suppress whole staging TLDs (`*.mystagingwebsite.com`) instead of toggling
 * `uptime_monitoring_enabled` site-by-site as staging sites come and go.
 *
 * Matched sites are skipped by the probe runner (no checks, no alerts, no
 * state churn) and hidden from the Monitoring board and the /issues down
 * list. This is a *display + probe* suppression only — the sites stay fully
 * managed everywhere else (updates, scans, backups).
 *
 * Stored in `app_settings` under `monitoring.ignored_domain_patterns` as a
 * JSON array of normalized (lowercase, trimmed) patterns.
 */
class DomainIgnoreList
{
    public const SETTING_KEY = 'monitoring.ignored_domain_patterns';

    /**
     * A pattern is hostname-ish: lowercase alphanumerics, dots, hyphens, and
     * `*` wildcards, with at least one dot. The dot requirement makes a bare
     * `*` (which would silently ignore the entire fleet) invalid.
     */
    private const PATTERN_REGEX = '/^[a-z0-9*][a-z0-9.*-]*\.[a-z0-9*-]+$/';

    public function __construct(private Settings $settings) {}

    /**
     * Normalized patterns from Settings. Anything non-string or outside the
     * allowed charset is dropped defensively — patterns() output is spliced
     * into SQL LIKE clauses by applyExclusion(), so garbage must not pass.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        try {
            $raw = $this->settings->get(self::SETTING_KEY, []);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $patterns = [];
        foreach ($raw as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }
            $pattern = mb_strtolower(trim($pattern));
            if ($pattern !== '' && preg_match(self::PATTERN_REGEX, $pattern)) {
                $patterns[] = $pattern;
            }
        }

        return array_values(array_unique($patterns));
    }

    public function hasPatterns(): bool
    {
        return $this->patterns() !== [];
    }

    public function matches(?string $domain): bool
    {
        $domain = mb_strtolower(trim((string) $domain));
        if ($domain === '') {
            return false;
        }

        foreach ($this->patterns() as $pattern) {
            if (Str::is($pattern, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exclude ignored domains at the query level so the probe runner and the
     * dashboards never even load them. `*` maps to SQL LIKE's `%`; the
     * charset guard in patterns() guarantees no literal `%`/`_` can leak in
     * (SQLite LIKE has no default escape character, so escaping isn't an option).
     *
     * @param  Builder<\App\Models\Site>  $query
     * @return Builder<\App\Models\Site>
     */
    public function applyExclusion(Builder $query): Builder
    {
        foreach ($this->patterns() as $pattern) {
            $query->where('domain', 'not like', str_replace('*', '%', $pattern));
        }

        return $query;
    }

    /**
     * Parse the settings-form textarea (one pattern per line; commas also
     * accepted) into normalized patterns, separating out anything invalid so
     * the controller can bounce the whole submission with a useful message.
     *
     * @return array{patterns: list<string>, invalid: list<string>}
     */
    public static function parseInput(?string $raw): array
    {
        $patterns = [];
        $invalid = [];

        foreach (preg_split('/[\r\n,]+/', (string) $raw) ?: [] as $line) {
            $pattern = mb_strtolower(trim($line));
            if ($pattern === '') {
                continue;
            }

            if (preg_match(self::PATTERN_REGEX, $pattern)) {
                $patterns[] = $pattern;
            } else {
                $invalid[] = $pattern;
            }
        }

        return [
            'patterns' => array_values(array_unique($patterns)),
            'invalid' => array_values(array_unique($invalid)),
        ];
    }
}
