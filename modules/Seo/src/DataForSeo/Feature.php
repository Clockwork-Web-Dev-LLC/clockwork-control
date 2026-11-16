<?php

namespace Modules\Seo\DataForSeo;

/**
 * The parts of the suite that spend money. Each has its own monthly cap, so
 * a busy month of research can never starve nightly rank tracking, and a
 * mis-set tracking job can never spend the whole budget.
 */
final class Feature
{
    public const KEYWORDS = 'keywords';

    public const RANK_TRACKING = 'rank_tracking';

    public const RESEARCH = 'research';

    public const SITE_AUDIT = 'site_audit';

    public const BACKLINKS = 'backlinks';

    public const LOCAL = 'local';

    public const AI_VISIBILITY = 'ai_visibility';

    /** Label and default monthly cap in USD. Caps are editable on /seo/settings. */
    public const ALL = [
        self::KEYWORDS => ['label' => 'Keyword metrics and ideas', 'default_cap' => 20.0],
        self::RANK_TRACKING => ['label' => 'Position tracking', 'default_cap' => 80.0],
        self::RESEARCH => ['label' => 'Ranking counts and competitor research', 'default_cap' => 20.0],
        self::SITE_AUDIT => ['label' => 'Site audit', 'default_cap' => 40.0],
        self::BACKLINKS => ['label' => 'Backlinks', 'default_cap' => 10.0],
        self::LOCAL => ['label' => 'Local (map rank and reviews)', 'default_cap' => 10.0],
        self::AI_VISIBILITY => ['label' => 'AI visibility', 'default_cap' => 20.0],
    ];

    public static function exists(string $feature): bool
    {
        return isset(self::ALL[$feature]);
    }

    public static function label(string $feature): string
    {
        return self::ALL[$feature]['label'] ?? $feature;
    }
}
