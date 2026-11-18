<?php

namespace Modules\Seo\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question put to AI assistants on a schedule, for one client.
 *
 * @property int $id
 * @property int $site_id
 * @property string $prompt
 * @property string $prompt_hash
 * @property bool $is_active
 * @property ?int $added_by
 * @property ?array<string, string> $last_posted
 */
class AiPrompt extends Model
{
    protected $table = 'seo_ai_prompts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_posted' => 'array'];
    }

    public static function hash(string $prompt): string
    {
        return sha1(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $prompt))));
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<AiCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(AiCheck::class, 'prompt_id');
    }
}
