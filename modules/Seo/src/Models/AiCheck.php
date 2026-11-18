<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * One answer from one AI engine to one prompt on one day.
 *
 * @property int $id
 * @property int $prompt_id
 * @property int $site_id
 * @property string $engine
 * @property Carbon $checked_on
 * @property bool $mentioned
 * @property bool $cited
 * @property bool $answered
 * @property ?string $excerpt
 * @property ?list<string> $sources
 * @property ?array<string, array{mentioned: bool, cited: bool}> $competitors
 */
class AiCheck extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'seo_ai_checks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'checked_on' => DateOnly::class, 'mentioned' => 'boolean', 'cited' => 'boolean', 'answered' => 'boolean',
            'sources' => 'array', 'competitors' => 'array',
        ];
    }
}
