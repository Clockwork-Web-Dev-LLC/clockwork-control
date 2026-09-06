<?php

namespace App\Services\Docs;

/**
 * Expands a single `tracks:` frontmatter entry (a plain path, a `dir/**`
 * recursive marker, a single-level glob, or a `{a,b,c}.php` brace list)
 * into concrete, repo-root-relative paths. Shared by StalenessChecker
 * (which git-log's the result) and CoverageChecker (which needs to know
 * exactly which files a page claims to track).
 *
 * Extracted from StalenessChecker 2026-08-31 while building the coverage
 * checker — building that required accurately enumerating covered files,
 * which surfaced a real bug: plain `glob()` doesn't expand `{a,b,c}` brace
 * patterns without the `GLOB_BRACE` flag, so every tracks: entry using that
 * syntax (7 doc pages, confirmed live) silently matched zero files and
 * never actually staleness-checked those commands at all.
 */
final class GlobExpander
{
    /**
     * @return list<string> paths relative to $repoRoot. For a `dir/**`
     *                      pattern this returns the directory itself (one entry, not
     *                      an enumeration) — callers that need per-file matching
     *                      (CoverageChecker) must treat that as a prefix match; callers
     *                      that just feed paths to `git log --` (StalenessChecker) can
     *                      pass a directory straight through, git covers everything
     *                      under it automatically.
     */
    public static function expand(string $repoRoot, string $pattern): array
    {
        $absPattern = $repoRoot.'/'.ltrim($pattern, '/');

        // Plain file or directory — pass through if it exists.
        if (! str_contains($pattern, '*') && ! str_contains($pattern, '{')) {
            return file_exists($absPattern) ? [$pattern] : [];
        }

        // Recursive glob (`**`). Strip the trailing /** and walk the dir.
        if (str_ends_with($pattern, '/**')) {
            $base = substr($pattern, 0, -3);
            $absBase = $repoRoot.'/'.ltrim($base, '/');
            if (! is_dir($absBase)) {
                return [];
            }

            return [$base];
        }

        // Single-level glob (`app/Services/*.php`) and/or brace list
        // (`app/Console/Commands/{A,B,C}.php`) — GLOB_BRACE handles both in
        // one pass; harmless no-op on patterns with no braces.
        $matches = glob($absPattern, GLOB_BRACE) ?: [];
        $rel = [];
        $prefixLen = strlen($repoRoot) + 1;
        foreach ($matches as $abs) {
            $rel[] = substr($abs, $prefixLen);
        }

        return $rel;
    }
}
