<?php

namespace Modules\Seo\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One dated version of a site's keyword map.
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property string $status
 * @property string $source
 * @property ?int $copied_from_id
 * @property ?int $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class KeywordMap extends Model
{
    public const STATUS_CURRENT = 'current';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'seo_keyword_maps';

    protected $fillable = ['site_id', 'name', 'status', 'source', 'copied_from_id', 'created_by'];

    public function isCurrent(): bool
    {
        return $this->status === self::STATUS_CURRENT;
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<KeywordMapRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(KeywordMapRow::class, 'map_id')->orderBy('sort')->orderBy('id');
    }
}
