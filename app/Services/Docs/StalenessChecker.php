<?php

namespace App\Services\Docs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * Decides whether a DocPage is "stale" relative to the code it tracks.
 *
 * A doc is stale when ANY file matching its `tracks:` globs has a newer
 * git-commit date than the doc's `updated:` frontmatter. This catches
 * the common rot pattern: ship a feature, forget to bump the doc.
 *
 * Source of truth is git, not filesystem mtime — mtime gets reset on
 * `git clone` and varies between machines. Git dates are deterministic
 * across the team — but note "date" here means the commit's own local
 * calendar day (its own recorded committer timezone offset), not a UTC
 * conversion. `updated:` frontmatter is always a human's local calendar
 * day; comparing against a UTC-normalized timestamp made evening commits
 * misreport as landing "the next day" (see `latestCommitFor()`).
 *
 * Cached per-process for the request lifecycle (Cache::driver('array')).
 * Doc rendering is many-pages-per-request on the index, so caching avoids
 * forking `git log` once per page.
 */
class StalenessChecker
{
    public function __construct(
        private readonly string $repoRoot,
    ) {}

    /**
     * @return array{stale: bool, latest_code_commit: ?Carbon, days_behind: ?int, tracked_paths: list<string>}
     */
    public function evaluate(DocPage $page): array
    {
        $tracks = $page->tracks;
        if ($tracks === []) {
            return ['stale' => false, 'latest_code_commit' => null, 'days_behind' => null, 'tracked_paths' => []];
        }

        $latest = $this->latestCommitAcross($tracks);
        if ($latest === null) {
            return ['stale' => false, 'latest_code_commit' => null, 'days_behind' => null, 'tracked_paths' => $tracks];
        }

        if ($page->updated === null) {
            // No `updated:` to compare against — treat as stale-with-unknown.
            return [
                'stale' => true,
                'latest_code_commit' => $latest,
                'days_behind' => null,
                'tracked_paths' => $tracks,
            ];
        }

        // Doc is stale if the latest tracked code commit is AFTER the doc's
        // `updated:` date. We compare at day-granularity — `updated:` is a
        // date, not a timestamp, so an intra-day commit on the same day
        // shouldn't trip the indicator.
        $stale = $latest->copy()->startOfDay()->gt($page->updated->copy()->startOfDay());

        return [
            'stale' => $stale,
            'latest_code_commit' => $latest,
            'days_behind' => $stale ? (int) $page->updated->diffInDays($latest) : 0,
            'tracked_paths' => $tracks,
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    private function latestCommitAcross(array $paths): ?Carbon
    {
        $latest = null;
        foreach ($paths as $path) {
            $ts = $this->latestCommitFor($path);
            if ($ts === null) {
                continue;
            }
            if ($latest === null || $ts->gt($latest)) {
                $latest = $ts;
            }
        }

        return $latest;
    }

    private function latestCommitFor(string $path): ?Carbon
    {
        return Cache::driver('array')->rememberForever('docs.staleness.path.'.$path, function () use ($path) {
            // Glob expansion is cheap and covers paths like "app/Services/Performance/**".
            // We DON'T trust the input to be safe-as-shell — it comes from
            // doc frontmatter committed by a human — but glob() is filesystem
            // resolution, not exec, so injection isn't a vector here.
            $expanded = GlobExpander::expand($this->repoRoot, $path);
            if ($expanded === []) {
                return null;
            }

            // git log -1 --date=format:%Y-%m-%d --format=%cd -- <paths...>
            // returns the committer's own LOCAL calendar date for the most
            // recent commit touching ANY of the paths — using the commit's
            // own recorded timezone offset, not converted to UTC.
            //
            // This used to ask git for %ct (a UTC unix timestamp) and diff
            // that against the doc's `updated:` frontmatter date. A doc's
            // `updated:` date is always a human's local calendar day — the
            // day they actually reviewed it — never a UTC one. A commit made
            // at 10pm US-Eastern is already past midnight UTC, so the old
            // UTC-timestamp comparison reported it as "the next day" and
            // flagged same-session docs as stale. Asking git for the
            // commit's own local date sidesteps the conversion entirely:
            // both sides of the comparison are now plain calendar dates with
            // no time-of-day or timezone math involved.
            $args = array_merge(
                ['git', 'log', '-1', '--date=format:%Y-%m-%d', '--format=%cd', '--'],
                $expanded
            );
            $process = new Process($args, $this->repoRoot);
            $process->setTimeout(5);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }

            $stdout = trim($process->getOutput());
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $stdout)) {
                return null;
            }

            return Carbon::createFromFormat('Y-m-d', $stdout)->startOfDay();
        });
    }
}
