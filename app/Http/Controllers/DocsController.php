<?php

namespace App\Http\Controllers;

use App\Services\Docs\CoverageChecker;
use App\Services\Docs\DocsManifest;
use App\Services\Docs\MarkdownRenderer;
use App\Services\Docs\StalenessChecker;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * In-app team documentation. Reads markdown files from `resources/docs/`,
 * renders them with frontmatter metadata + sidebar nav. See
 * `Plans/image-19-i-feel-dynamic-stallman.md` for context.
 */
class DocsController extends Controller
{
    /**
     * The DocsManifest service is constructed inline because it needs the
     * docs-root path and we want it set from a single canonical location
     * (resource_path()) — easier to read here than wired through the
     * container with a string binding.
     */
    private function manifest(): DocsManifest
    {
        return new DocsManifest(new MarkdownRenderer, resource_path('docs'));
    }

    public function index(): View
    {
        $manifest = $this->manifest();
        $checker = new StalenessChecker(base_path());
        $tree = $manifest->tree();
        $groupedTree = $manifest->groupedTree();

        // Walk every page once, build a per-section stale count for the
        // landing-page section cards. O(N) over docs but cached per-process
        // by the StalenessChecker so repeat lookups are free.
        $staleBySection = [];
        foreach ($manifest->pages() as $p) {
            $eval = $checker->evaluate($p);
            if ($eval['stale']) {
                $staleBySection[$p->section] = ($staleBySection[$p->section] ?? 0) + 1;
            }
        }

        $undocumented = (new CoverageChecker(base_path()))->undocumented($manifest->pages());

        return view('docs.index', [
            'tree' => $tree,
            'groupedTree' => $groupedTree,
            'sectionOrder' => self::canonicalSectionOrder(),
            'sectionDescriptions' => $this->sectionDescriptions(),
            'staleBySection' => $staleBySection,
            'undocumented' => $undocumented,
        ]);
    }

    public function show(string $path): View
    {
        $manifest = $this->manifest();
        $page = $manifest->findBySlug($path);

        if ($page === null) {
            throw new NotFoundHttpException("Doc page not found: {$path}");
        }

        $checker = new StalenessChecker(base_path());

        return view('docs.show', [
            'page' => $page,
            'tree' => $manifest->tree(),
            'groupedTree' => $manifest->groupedTree(),
            'sectionOrder' => self::canonicalSectionOrder(),
            'related' => $manifest->related($page),
            'staleness' => $checker->evaluate($page),
        ]);
    }

    /**
     * Canonical ordering of top-level documentation sections.
     *
     * @return list<string>
     */
    public static function canonicalSectionOrder(): array
    {
        return [
            'Getting Started',
            'Concepts',
            'Features',
            'Integrations',
            'Runbooks',
            'Architecture',
            'Reference',
            'Internal',
        ];
    }

    /**
     * Short blurbs for each canonical section, surfaced on the landing page.
     * Adding a new section means adding an entry here AND using that
     * section name in a markdown file's frontmatter.
     *
     * @return array<string, string>
     */
    private function sectionDescriptions(): array
    {
        return [
            'Getting Started' => "If you're new to Clockwork or just starting your day, start here.",
            'Concepts' => 'The mental model — what a server is, what a site is, what care plan means, how monitoring fits together.',
            'Features' => 'One page per major feature. Reference for "wait, how does this work again?"',
            'Integrations' => 'Every external cloud provider, hosting platform, security feed, and chat channel we talk to.',
            'Runbooks' => 'What to do when something happens. Site went down, a client got hacked, you need to onboard a new server.',
            'Architecture' => 'Under-the-hood plumbing — ingest pipelines, request lifecycles, and data models.',
            'Reference' => 'Commands, schedules, routes, and environment variable references.',
            'Internal' => 'Tooling and meta-documentation for Clockwork internals.',
        ];
    }
}
