<?php

namespace Modules\Seo\Ai;

use App\Models\Site;
use Modules\Seo\Models\AiPrompt;

/** Adding to a site's list of tracked AI prompts. */
class AiPrompts
{
    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 500;

    /**
     * @param  list<string>  $prompts
     * @return array{added: int, existing: int, rejected: list<array{prompt: string, reason: string}>}
     */
    public function add(Site $site, array $prompts, ?int $userId = null): array
    {
        $summary = ['added' => 0, 'existing' => 0, 'rejected' => []];
        $active = AiPrompt::query()->where('site_id', $site->id)->where('is_active', true)->count();
        $seen = [];

        foreach ($prompts as $raw) {
            $prompt = trim((string) preg_replace('/\s+/u', ' ', (string) $raw));
            $hash = AiPrompt::hash($prompt);
            if ($prompt === '' || isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $short = mb_strlen($prompt) > 70 ? mb_substr($prompt, 0, 70).'…' : $prompt;

            if (mb_strlen($prompt) < self::MIN_LENGTH) {
                $summary['rejected'][] = ['prompt' => $short, 'reason' => 'too short to be a question'];

                continue;
            }
            if (mb_strlen($prompt) > self::MAX_LENGTH) {
                $summary['rejected'][] = ['prompt' => $short, 'reason' => 'longer than '.self::MAX_LENGTH.' characters'];

                continue;
            }

            $row = AiPrompt::query()->firstOrNew(['site_id' => $site->id, 'prompt_hash' => $hash]);
            if ($row->exists && $row->is_active) {
                $summary['existing']++;

                continue;
            }
            if ($active >= AiVisibilitySettings::MAX_PROMPTS_PER_SITE) {
                $summary['rejected'][] = ['prompt' => $short, 'reason' => 'this client already has '.AiVisibilitySettings::MAX_PROMPTS_PER_SITE.' active prompts'];

                continue;
            }

            $row->fill(['prompt' => $prompt, 'is_active' => true, 'added_by' => $row->exists ? $row->added_by : $userId])->save();
            $summary['added']++;
            $active++;
        }

        return $summary;
    }
}
