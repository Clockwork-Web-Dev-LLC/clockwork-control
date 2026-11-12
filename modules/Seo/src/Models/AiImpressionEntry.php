<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * The month's GSC Generative AI impressions, typed in or recorded by Claude,
 * or "not available". Never stored as a guessed 0.
 *
 * @property int $id
 * @property int $site_id
 * @property Carbon $month
 * @property string $status
 * @property ?int $ai_impressions
 * @property ?array<int, array<string, mixed>> $top_pages
 * @property string $source
 * @property ?string $note
 * @property ?int $entered_by_user_id
 */
class AiImpressionEntry extends Model
{
    public const STATUS_ENTERED = 'entered';

    public const STATUS_NOT_AVAILABLE = 'not_available';

    protected $table = 'seo_ai_impressions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'month' => DateOnly::class,
            'ai_impressions' => 'integer',
            'top_pages' => 'array',
        ];
    }
}
