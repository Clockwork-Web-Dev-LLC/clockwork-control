<?php

namespace Modules\Seo\Mcp\Support;

use App\Models\User;
use App\Services\ActionLog\ActionLogger;

/**
 * Every write a tool makes is logged with who made it and the before and
 * after values, so it can be undone by hand.
 *
 * The row is never site-scoped: ActionLogger pushes any row with a site to
 * the client's own WordPress activity log, and agency SEO edits don't
 * belong there. The domain goes in "target" instead.
 */
class AuditLog
{
    public const AI_IMPRESSIONS_RECORDED = 'seo_ai_impressions_recorded';

    public const PROFILE_UPDATED = 'seo_profile_updated';

    public const TRACKED_KEYWORDS_UPDATED = 'seo_tracked_keywords_updated';

    public function __construct(private readonly ActionLogger $logger) {}

    /**
     * @param  array<string, array{before: mixed, after: mixed}>  $changes
     */
    public function record(string $type, User $user, string $tool, ?string $domain, string $summary, array $changes): void
    {
        $this->logger->record(
            actionType: $type,
            summary: $summary,
            site: null,
            target: $domain,
            details: ['via' => 'mcp', 'tool' => $tool, 'changes' => $changes],
            actor: "user:{$user->id}",
        );
    }
}
