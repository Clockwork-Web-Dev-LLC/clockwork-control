<?php

namespace Modules\Seo\Mcp\Support;

use App\Models\User;
use App\Services\ActionLog\ActionLogger;

/**
 * Every SEO change a person makes, through a Claude tool or on a back-end
 * page, is logged with who made it and the before and after values, so it
 * can be traced and undone by hand whichever way it was made.
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

    public const KEYWORD_MAP_UPDATED = 'seo_keyword_map_updated';

    public const AI_PROMPTS_UPDATED = 'seo_ai_prompts_updated';

    /** Module-wide choices: spending caps, check frequencies, sync settings. */
    public const SETTINGS_UPDATED = 'seo_settings_updated';

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

    /**
     * A change saved on a back-end page. Nothing is written when nothing
     * changed, so re-saving a form leaves no row.
     *
     * @param  array<string, array{before: mixed, after: mixed}>  $changes
     */
    public function page(string $type, ?User $user, ?string $domain, string $summary, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $this->logger->record(
            actionType: $type,
            summary: $summary,
            site: null,
            target: $domain,
            details: ['via' => 'web', 'changes' => $changes],
            actor: $user !== null ? "user:{$user->id}" : 'manual',
        );
    }

    /**
     * The fields whose value differs, as before and after pairs.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changes[$key] = ['before' => $before[$key] ?? null, 'after' => $value];
            }
        }

        return $changes;
    }
}
