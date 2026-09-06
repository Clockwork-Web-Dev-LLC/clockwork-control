<?php

namespace App\Services\Docs;

use Illuminate\Support\Collection;

/**
 * Finds source files with NO doc page tracking them at all — the blind
 * spot StalenessChecker structurally can't see, since it only ever
 * evaluates pages that already exist. A brand-new command with zero
 * documentation looks identical to StalenessChecker as one that was never
 * built; this is what actually catches that case.
 *
 * Scoped to Artisan commands, HTTP controllers, and outbound API clients
 * on purpose, not every PHP file in the app — those are the natural
 * "feature entry points" a human would expect to find documented (a new
 * command is a new job/feature; a new controller is a new page/API; a new
 * `*Client.php` is a new external integration — see
 * `resources/docs/reference/api-endpoints-we-call.md`). Models and other
 * services are usually covered indirectly via a broad directory glob on
 * whichever command/controller page owns that feature (e.g.
 * `app/Services/Security/**` on the security-scans page) — flagging every
 * individual service/model file would be noisy without being useful.
 *
 * The `*Client.php` scope was added 2026-09-01 after Azure, Twilio, and
 * wpvulnerability.net all shipped as real, live, credentialed integrations
 * with zero doc-page coverage each — caught only by chance, while fixing an
 * unrelated glob bug in the API-endpoints catalog. Without a structural
 * check for this exact shape, a new integration going undocumented was
 * invisible until someone happened to go looking.
 */
class CoverageChecker
{
    private const SCOPE_DIRS = [
        'app/Console/Commands',
        'app/Http/Controllers',
    ];

    public function __construct(
        private readonly string $repoRoot,
    ) {}

    /**
     * @param  Collection<int, DocPage>  $pages
     * @return list<string> repo-relative paths with no tracking page at all
     */
    public function undocumented(Collection $pages): array
    {
        [$coveredExact, $coveredPrefixes] = $this->buildCoverageSets($pages);

        $candidates = [];
        foreach (self::SCOPE_DIRS as $dir) {
            $candidates = array_merge($candidates, $this->phpFilesIn($dir));
        }

        $undocumented = [];
        foreach ($candidates as $file) {
            if (isset($coveredExact[$file])) {
                continue;
            }
            if ($this->matchesAnyPrefix($file, $coveredPrefixes)) {
                continue;
            }
            $undocumented[] = $file;
        }

        // Client files are checked differently, on purpose: this repo's own
        // API catalog (resources/docs/reference/api-endpoints-we-call.md)
        // tracks them via the wildcard app/Services/*/*Client.php, which
        // structurally "covers" any brand-new Client.php the instant it's
        // created — before a single word gets written about it. A glob-only
        // check would be vacuous here (confirmed live: it silently passed a
        // throwaway test file with zero real coverage). So instead of
        // trusting tracks: glob membership, require the file's bare name to
        // actually appear in some page's rendered prose — a real signal
        // that someone wrote about this specific integration, not just that
        // an existing wildcard happens to sweep it up.
        foreach ($this->clientFilesInServices() as $file) {
            if (! $this->mentionedInAnyPageBody($file, $pages)) {
                $undocumented[] = $file;
            }
        }

        sort($undocumented);

        return $undocumented;
    }

    /**
     * @param  Collection<int, DocPage>  $pages
     */
    private function mentionedInAnyPageBody(string $file, Collection $pages): bool
    {
        $bareName = basename($file); // e.g. "AzureClient.php"
        $bareClass = substr($bareName, 0, -4); // strip ".php" -> "AzureClient"

        foreach ($pages as $page) {
            if (str_contains($page->htmlBody, $bareName) || str_contains($page->htmlBody, $bareClass)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, DocPage>  $pages
     * @return array{0: array<string, true>, 1: list<string>} [exactPaths, directoryPrefixes]
     */
    private function buildCoverageSets(Collection $pages): array
    {
        $exact = [];
        $prefixes = [];

        foreach ($pages as $page) {
            foreach ($page->tracks as $trackPattern) {
                foreach (GlobExpander::expand($this->repoRoot, $trackPattern) as $resolved) {
                    $abs = $this->repoRoot.'/'.$resolved;
                    if (is_dir($abs)) {
                        // A `dir/**` entry expands to the directory itself
                        // (see GlobExpander) — every file under it counts
                        // as covered, not just files that literally exist
                        // today.
                        $prefixes[] = rtrim($resolved, '/').'/';
                    } else {
                        $exact[$resolved] = true;
                    }
                }
            }
        }

        return [$exact, array_values(array_unique($prefixes))];
    }

    private function matchesAnyPrefix(string $file, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every `app/Services/{Anything}/{Anything}Client.php` — one level of
     * subdirectory, filtered by filename suffix. Matches the exact `tracks:`
     * glob shape `resources/docs/reference/api-endpoints-we-call.md` uses
     * (`app/Services/*​/*​Client.php`) so "does this integration have a doc
     * page" and "does this integration appear in the API catalog" stay
     * structurally the same question. Not recursive beyond one level —
     * deeper client files (if any ever exist) aren't this convention.
     *
     * @return list<string> paths relative to repoRoot
     */
    private function clientFilesInServices(): array
    {
        $servicesDir = $this->repoRoot.'/app/Services';
        if (! is_dir($servicesDir)) {
            return [];
        }

        $files = [];
        foreach (scandir($servicesDir) ?: [] as $subdir) {
            if ($subdir === '.' || $subdir === '..') {
                continue;
            }
            $subdirAbs = $servicesDir.'/'.$subdir;
            if (! is_dir($subdirAbs)) {
                continue;
            }
            foreach (scandir($subdirAbs) ?: [] as $name) {
                if (str_ends_with($name, 'Client.php')) {
                    $files[] = 'app/Services/'.$subdir.'/'.$name;
                }
            }
        }

        return $files;
    }

    /**
     * Direct .php files in a directory — not recursive. Both scope dirs
     * are flat in this codebase (no Controllers/Api subdirectory, no
     * Commands subfolders), so this matches how tracks: entries actually
     * reference them.
     *
     * @return list<string> paths relative to repoRoot
     */
    private function phpFilesIn(string $relativeDir): array
    {
        $abs = $this->repoRoot.'/'.$relativeDir;
        if (! is_dir($abs)) {
            return [];
        }

        $files = [];
        foreach (scandir($abs) ?: [] as $name) {
            if (! str_ends_with($name, '.php')) {
                continue;
            }
            $files[] = $relativeDir.'/'.$name;
        }

        return $files;
    }
}
