<?php

use App\Models\Server;
use App\Models\User;
use App\Services\Docs\DocsManifest;
use App\Services\Docs\MarkdownRenderer;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| DocsController — HTTP-level coverage
|--------------------------------------------------------------------------
|
| tests/Feature/DocsPagesRenderTest.php already covers 3 specific doc pages
| rendering correctly (concepts/server-site-care-plan, integrations/overview,
| the 3 new hosting-provider integration pages) — not duplicated here.
|
| This file covers:
|   - the /docs index page rendering the section list
|   - the route comment's claim ("Path-traversal guarded inside DocsManifest")
|     verified two ways: an HTTP-level traversal attempt (blocked by the
|     route's [a-z0-9\-/]+ constraint before it even reaches the controller,
|     since the charset excludes '.'), AND a direct call into DocsManifest
|     itself (bypassing the route regex entirely) to confirm the internal
|     guard in normalizeSlug()/findBySlug() actually rejects traversal on
|     its own, rather than relying solely on the outer route constraint.
*/

beforeEach(function () {
    // AppServiceProvider's layouts.app view composer calls IssueCounter::total()
    // on every authenticated page render.
    Server::factory()->create();
    $this->mockIssueCounterZero();

    $this->actingAs(User::factory()->create());
});

describe('GET /docs (index)', function () {
    it('renders the docs list grouped by section and subcategories', function () {
        $response = $this->get(route('docs.index'));

        $response->assertOk();
        $response->assertSee('Documentation');
        // Canonical sections from DocsController::canonicalSectionOrder().
        $response->assertSee('Getting Started');
        $response->assertSee('Concepts');
        $response->assertSee('Features');
        $response->assertSee('Integrations');
        $response->assertSee('Runbooks');

        // Functional categories
        $response->assertSee('Managed WordPress Hosts');
        $response->assertSee('Cloud Infrastructure &amp; VPS', false);
        $response->assertSee('Updates, Maintenance &amp; Backups', false);
    });

    it('provides groupedTree with categorized pages from DocsManifest', function () {
        $manifest = new DocsManifest(new MarkdownRenderer, resource_path('docs'));
        $grouped = $manifest->groupedTree();

        expect($grouped)->toHaveKey('Integrations');
        expect($grouped)->toHaveKey('Features');

        $integrations = $grouped->get('Integrations');
        expect($integrations)->toHaveKey('Managed WordPress Hosts');
        expect($integrations)->toHaveKey('Cloud Infrastructure & VPS');

        $features = $grouped->get('Features');
        expect($features)->toHaveKey('Updates, Maintenance & Backups');
    });
});

describe('GET /docs/{slug} (show)', function () {
    it('renders category breadcrumb and category pill on doc page', function () {
        $response = $this->get('/docs/features/backup-relay');

        $response->assertOk();
        $response->assertSee('Backup relay');
        $response->assertSee('Features');
        $response->assertSee('Updates, Maintenance &amp; Backups', false);
    });
});

describe('path traversal is blocked', function () {
    it('404s at the HTTP layer for a dotted traversal attempt (route charset excludes "." )', function () {
        $response = $this->get('/docs/'.'..%2F..%2F..%2F..%2Fetc%2Fpasswd');

        $response->assertNotFound();
    });

    it('404s for an absolute-path-style traversal attempt', function () {
        $response = $this->get('/docs/'.'%2Fetc%2Fpasswd');

        $response->assertNotFound();
    });

    it('DocsManifest itself rejects a literal ".." slug, independent of the route constraint', function () {
        $manifest = new DocsManifest(new MarkdownRenderer, resource_path('docs'));

        // Calling the service directly bypasses the route's [a-z0-9\-/]+
        // constraint entirely, so this exercises normalizeSlug()'s own
        // traversal guard rather than trusting the route comment.
        $page = $manifest->findBySlug('../../../../composer.json');

        expect($page)->toBeNull();
    });

    it('DocsManifest rejects a slug that resolves outside the docs root even without ".." literally reaching realpath', function () {
        $manifest = new DocsManifest(new MarkdownRenderer, resource_path('docs'));

        // No ".." substring, but not a real file under resources/docs either
        // — confirms findBySlug() doesn't escape the root for any input,
        // traversal-shaped or not.
        $page = $manifest->findBySlug('etc/passwd');

        expect($page)->toBeNull();
    });
});
