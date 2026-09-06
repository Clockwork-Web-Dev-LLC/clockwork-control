<?php

namespace App\Services\Docs;

use Illuminate\Support\Collection;

/**
 * Walks `resources/docs/`, reads frontmatter from every `.md` file, and
 * returns ordered tree structures the views can render. Two read paths:
 *
 *   - `tree()`        — full sidebar nav, grouped by section
 *   - `findBySlug()`  — single-page lookup with body rendered
 *
 * Files prefixed with `_` are skipped (used for `_conventions.md` etc.).
 *
 * Per-request memoization only — doc volume is bounded and disk reads are
 * cheap. No persistent cache to invalidate.
 */
class DocsManifest
{
    /** @var ?Collection<int, DocPage> */
    private ?Collection $cache = null;

    public function __construct(
        private readonly MarkdownRenderer $renderer,
        private readonly string $docsRoot,
    ) {}

    /**
     * Single page by URL slug (e.g. "features/uptime-monitoring"). Returns
     * null when the file doesn't exist OR the slug tries to escape the docs
     * root (path traversal guard).
     */
    public function findBySlug(string $slug): ?DocPage
    {
        $cleanSlug = $this->normalizeSlug($slug);
        if ($cleanSlug === null) {
            return null;
        }

        $absolute = $this->docsRoot.DIRECTORY_SEPARATOR.$cleanSlug.'.md';
        $real = realpath($absolute);
        $rootReal = realpath($this->docsRoot);
        if ($real === false || $rootReal === false || ! str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $content = @file_get_contents($real);
        if ($content === false) {
            return null;
        }

        return $this->renderer->render($cleanSlug, $content);
    }

    /**
     * Every doc page on disk, sorted by section + order + title. Body is
     * rendered for each — fine because the catalog is ~30 pages.
     *
     * @return Collection<int, DocPage>
     */
    public function pages(): Collection
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $rootReal = realpath($this->docsRoot);
        if ($rootReal === false || ! is_dir($rootReal)) {
            return $this->cache = collect();
        }

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootReal, \FilesystemIterator::SKIP_DOTS),
        );

        $pages = collect();
        foreach ($rii as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'md') {
                continue;
            }
            $name = $file->getBasename('.md');
            if (str_starts_with($name, '_')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($rootReal) + 1);
            $slug = str_replace([DIRECTORY_SEPARATOR, '\\'], '/', substr($relative, 0, -3));

            $content = @file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            $pages->push($this->renderer->render($slug, $content));
        }

        return $this->cache = $pages
            ->sortBy([
                ['section', 'asc'],
                ['order', 'asc'],
                ['title', 'asc'],
            ])
            ->values();
    }

    public const CATEGORY_ORDER = [
        'Integrations' => [
            'Overview' => 1,
            'Managed WordPress Hosts' => 2,
            'Cloud Infrastructure & VPS' => 3,
            'Security & Threat Intelligence' => 4,
            'Alerts & Communications' => 5,
            'Performance, DNS & Utilities' => 6,
        ],
        'Features' => [
            'Fleet & Asset Inventory' => 1,
            'Monitoring & Site Health' => 2,
            'Updates, Maintenance & Backups' => 3,
            'Platform & Administration' => 4,
        ],
    ];

    /**
     * Pages grouped by section, suitable for flat sidebar rendering.
     *
     * @return Collection<string, Collection<int, DocPage>>
     */
    public function tree(): Collection
    {
        return $this->pages()->groupBy('section');
    }

    /**
     * Pages grouped by section and then by functional category.
     *
     * @return Collection<int|string, Collection<int|string, Collection<int, DocPage>>>
     */
    public function groupedTree(): Collection
    {
        return $this->pages()
            ->groupBy('section')
            ->map(function (Collection $sectionPages, string $section) {
                $categoryOrders = self::CATEGORY_ORDER[$section] ?? [];

                return $sectionPages
                    ->groupBy(fn (DocPage $p) => $p->category ?? 'General')
                    ->sortBy(function (Collection $items, string $category) use ($categoryOrders) {
                        return $categoryOrders[$category] ?? 99;
                    });
            });
    }

    /**
     * Pages that share at least one tag or category with $page (excluding $page itself).
     * Used for the "Related" block at the bottom of show.blade.php.
     *
     * @return Collection<int, DocPage>
     */
    public function related(DocPage $page, int $limit = 4): Collection
    {
        $candidates = $this->pages()->filter(fn (DocPage $p) => $p->slug !== $page->slug);

        if ($page->tags !== []) {
            $tags = array_flip($page->tags);
            $tagged = $candidates
                ->filter(fn (DocPage $p) => array_intersect_key(array_flip($p->tags), $tags) !== []);
            if ($tagged->isNotEmpty()) {
                return $tagged->take($limit)->values();
            }
        }

        if ($page->category !== null) {
            return $candidates
                ->filter(fn (DocPage $p) => $p->category === $page->category)
                ->take($limit)
                ->values();
        }

        return collect();
    }

    /**
     * Validates and normalises an incoming slug. Returns null for any slug
     * that contains traversal chars or doesn't match `[a-z0-9\-/]+`.
     */
    private function normalizeSlug(string $slug): ?string
    {
        $trimmed = trim($slug, '/');
        if ($trimmed === '' || str_contains($trimmed, '..')) {
            return null;
        }
        if (! preg_match('#^[a-z0-9\-/]+$#', $trimmed)) {
            return null;
        }

        return $trimmed;
    }
}
