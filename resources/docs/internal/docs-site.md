---
title: The docs site itself
section: Internal
order: 10
updated: 2026-09-07
author: Aaron Reimann
tags: [internal, docs, meta, tooling]
tracks: [app/Http/Controllers/DocsController.php, app/Http/Controllers/Controller.php, app/Services/Docs/**, resources/views/docs/**]
---

How `/docs` — the page you're reading this on — actually works. It's a small, self-contained subsystem: no database table, no admin UI for authoring, just markdown files on disk parsed at request time. This page exists because that subsystem had gone completely undocumented, which is a little on the nose given it's also the thing that generates the staleness/coverage report this very page was written in response to.

## Every controller shares one trivial base class

`app/Http/Controllers/Controller.php` is an empty abstract class — every controller in the app, including `DocsController` below, extends it. It carries no app-specific logic; it exists purely as the conventional Laravel extension point.

## Reading the docs: DocsManifest + MarkdownRenderer

`App\Services\Docs\DocsManifest` walks `resources/docs/` with a `RecursiveDirectoryIterator`, reads every `.md` file (skipping any file whose name starts with `_`, e.g. `_conventions.md`), and hands each one to `App\Services\Docs\MarkdownRenderer::render()` to produce a `DocPage` value object. Two read paths:

- **`pages()`** — every page on disk, sorted by `section`, then `order`, then `title`. Memoized per-request (a `?Collection` property, not a persistent cache) — doc volume is small enough that re-reading everything once per request is cheap, and there's no invalidation problem to solve.
- **`findBySlug($slug)`** — a single page by URL slug (e.g. `features/uptime-monitoring`). Guards against path traversal explicitly: the slug is normalized against `^[a-z0-9\-/]+$` and rejected if it contains `..`, and the resolved `realpath()` must still sit under the docs root before the file is read.

`MarkdownRenderer` has two jobs. First, it splits YAML-ish frontmatter (between a leading `---` and the next `---`) from the markdown body with a hand-rolled parser — not a real YAML library, since doc frontmatter only ever needs scalars, inline arrays (`[a, b, c]`), and quoted strings. Second, it converts the remaining body to HTML via `league/commonmark`'s `GithubFlavoredMarkdownConverter` (tables, strikethrough, task lists, autolinks; `html_input: escape` so raw HTML in a doc file is neutered rather than executed).

Key parser behaviors handled here:

- **Brace-splitting** — `parseFrontmatterValue()` avoids naive splitting on commas so a `tracks:` entry using brace syntax like `app/Console/Commands/{ScanSiteCheck,CheckBlacklists}.php` does not break into fragments. `splitTopLevel()` tracks `{`/`}` depth and only splits on commas at depth 0.
- **`GLOB_BRACE`** — in `GlobExpander` (below), PHP `glob()` requires the `GLOB_BRACE` flag to expand `{a,b,c}` patterns properly.

## Frontmatter fields, and what drives what

Every doc page's frontmatter (see `DocPage`, `MarkdownRenderer::render()`) has: `title`, `section`, `order` (int, sort key within a section), `updated` (parsed via `Carbon::parse`, drives staleness), `author`, `tags` (drives the "Related" block — pages sharing at least one tag), and `tracks` — a list of glob patterns pointing at the source this page documents. `tracks:` is the field that powers everything in the rest of this page.

`section` is free text as far as the parser is concerned — it defaults to `"Misc"` if omitted. `DocsController::sectionDescriptions()` has a blurb for every one of the app's eight canonical sections (`Getting Started`, `Integrations`, `Concepts`, `Features`, `Runbooks`, `Architecture`, `Reference`, `Internal`); a section that isn't in that list (custom/misc) simply renders on the landing page without a description card.

## Staleness: comparing `updated:` against git, not the filesystem

`App\Services\Docs\StalenessChecker::evaluate($page)` decides whether a page is stale: for every glob in `tracks:`, expand it to concrete paths, run `git log -1 --format=%ct --` across all of them, and compare the newest resulting commit timestamp against the page's `updated:` date (day-granularity, so a same-day commit doesn't trip it). Git is the source of truth deliberately, not filesystem mtime — mtime resets on `git clone` and drifts across machines; commit timestamps don't. Results are cached per-process (`Cache::driver('array')`) since the index page evaluates every page in one request.

An empty `tracks:` list is never stale (nothing to compare — this is the normal, correct state for Getting Started/Concepts/Runbooks pages that don't track code). A `tracks:` entry that resolves to git history but has no page `updated:` date at all renders as "stale with unknown days-behind" rather than silently passing.

## The glob syntax `tracks:` actually supports

`App\Services\Docs\GlobExpander::expand($repoRoot, $pattern)` is the single shared implementation both `StalenessChecker` and `CoverageChecker` call, providing consistent expansion semantics across both checks. It supports exactly three shapes:

1. **A plain path** (no `*`, no `{`) — passed through if it exists on disk, e.g. `app/Services/Companion/ClockworkCompanionClient.php`.
2. **A `dir/**` recursive marker** — matched by string suffix, not real glob recursion. Returns the directory itself as a single entry (not an enumeration of every file under it); callers that need per-file matching (`CoverageChecker`) treat that as a prefix match, callers that just feed paths to `git log --` (`StalenessChecker`) can hand a directory straight to git, which walks it automatically.
3. **A single-level glob and/or brace list** — anything else goes through PHP's `glob($pattern, GLOB_BRACE)`. Note that PHP `glob()` has no true `**` recursion, so a pattern like `app/Services/**/Client.php` would match nothing; the working equivalent for "one level deep, suffix match" is `app/Services/*/*Client.php`.

## Coverage: catching files with zero tracking page at all

Staleness can only ever evaluate pages that already exist — a brand-new command with no doc page looks identical to one that was simply never built. `App\Services\Docs\CoverageChecker::undocumented($pages)` is what catches that blind spot. It checks three things:

1. **`app/Console/Commands`** and **`app/Http/Controllers`** (non-recursive — both are flat in this codebase) — a file counts as covered if it's an exact `tracks:` entry, or falls under a `dir/**` prefix. Deliberately scoped to just these two directories rather than every PHP file in the app: a new command or controller is a new feature/entry-point a human would expect documented; models and services are usually covered indirectly through a broad directory glob on whichever page owns that feature, and flagging every individual service/model file would be noisy without being useful.
2. **`app/Services/{Anything}/{Anything}Client.php`** — every outbound API integration. **This one is checked differently, on purpose, not via the same glob-membership test as #1.** `resources/docs/reference/api-endpoints-we-call.md`'s own `tracks:` is the wildcard `app/Services/*/*Client.php` — which means *every* Client.php file is structurally matched by that glob. A glob-only check here would be vacuous, so this scope instead checks whether the file's bare name (`FooClient.php`/`FooClient`) actually appears in some page's *rendered body text* — a real signal that someone wrote about this specific integration, not just that an existing wildcard happens to sweep it up.


`DocsController::index()` runs this once per request and passes the list to the landing page.

## Search

The sidebar search box (every page, `resources/views/docs/_sidebar.blade.php`) is entirely client-side Alpine.js — no search route, no server round-trip. `DocsManifest::tree()` is flattened into a small JSON index (`title`, `section`, `excerpt`, `url` per page) and embedded directly in the page via `Illuminate\Support\Js::from()`; typing 2+ characters filters that in-memory array by substring match against title/section/excerpt, capped at 8 results. This is intentionally the simplest thing that works — dozens of pages, not thousands, so a JS search library or a server-side search endpoint would be solving a problem this app doesn't have.

## Routes

Two routes, both inside the app's standard `auth`-gated group in `routes/web.php` — no public or unauthenticated doc access:

| Method | Path | Controller action |
|---|---|---|
| GET | `/docs` | `DocsController::index` — landing page: full section tree, per-section stale counts, the undocumented-files list. |
| GET | `/docs/{path}` | `DocsController::show` — single page by slug, constrained to `[a-z0-9\-/]+` at the route level (belt-and-suspenders alongside `DocsManifest`'s own traversal guard). |

`DocsController` constructs `DocsManifest` inline (`new DocsManifest(new MarkdownRenderer, resource_path('docs'))`) rather than resolving it through the container, specifically so the docs-root path has one canonical, readable source rather than a string binding buried in a service provider.
