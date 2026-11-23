<?php

namespace Modules\Seo\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * A keyword whose Google position is checked on a schedule.
 *
 * @property int $id
 * @property int $site_id
 * @property string $keyword
 * @property string $device
 * @property int $location_code
 * @property string $language_code
 * @property ?string $location_name
 * @property string $location_key
 * @property ?list<string> $tags
 * @property bool $is_active
 * @property ?int $added_by
 * @property ?Carbon $last_posted_on
 */
class TrackedKeyword extends Model
{
    public const DEVICES = ['desktop', 'mobile'];

    protected $table = 'seo_tracked_keywords';

    protected $fillable = [
        'site_id', 'keyword', 'device', 'location_code', 'language_code', 'location_name',
        'location_key', 'tags', 'is_active', 'added_by', 'last_posted_on',
    ];

    protected function casts(): array
    {
        return [
            'location_code' => 'integer',
            'tags' => 'array',
            'is_active' => 'boolean',
            'last_posted_on' => DateOnly::class,
        ];
    }

    public static function locationKey(int $locationCode, ?string $locationName): string
    {
        return filled($locationName) ? mb_strtolower(trim((string) $locationName)) : (string) $locationCode;
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<RankCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(RankCheck::class);
    }
}
