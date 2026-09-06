<?php

namespace App\Services\Docs;

use Illuminate\Support\Carbon;

/**
 * One markdown doc page after parsing. Pure value — no DB, no caching beyond
 * the request lifecycle. Constructed by MarkdownRenderer + DocsManifest.
 */
final class DocPage
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $tracks  Code paths/globs this doc is supposed to mirror.
     *                                Drives the staleness indicator: if any tracked
     *                                file has a newer git commit than `updated`, the
     *                                page renders a "may be stale" banner. Empty list
     *                                means "no auto-staleness signal" (Getting Started
     *                                + Concepts + Runbooks pages typically don't track
     *                                code).
     */
    public function __construct(
        public readonly string $slug,        // URL-relative path (e.g. "features/uptime-monitoring")
        public readonly string $title,
        public readonly string $section,     // "Getting Started" / "Concepts" / "Features" / "Runbooks"
        public readonly int $order,
        public readonly ?Carbon $updated,
        public readonly ?string $author,
        public readonly array $tags,
        public readonly array $tracks,
        public readonly string $htmlBody,
        public readonly string $excerpt,     // First paragraph, plain text, for cards/related
        public readonly ?string $category = null,
    ) {}

    public function url(): string
    {
        return route('docs.show', ['path' => $this->slug]);
    }
}
