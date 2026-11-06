<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A dated event that makes some comparisons misleading: a Google change
 * (fleet-wide, site_id null) or a methodology change on one site.
 *
 * `suppresses` lists what a comparison straddling it loses: 'position' and
 * 'impressions' are hidden; 'impressions_flag' and 'nonbranded' only add a
 * caveat.
 *
 * @property int $id
 * @property ?int $site_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $kind
 * @property string $label
 * @property ?array<int, string> $suppresses
 */
class Annotation extends Model
{
    public const KIND_GOOGLE_CHANGE = 'google_change';

    public const KIND_METHODOLOGY = 'methodology';

    public const KIND_NOTE = 'note';

    protected $table = 'seo_annotations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'suppresses' => 'array',
        ];
    }

    /** Fleet-wide annotations plus this site's own. */
    public function scopeForSite(Builder $query, int $siteId): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('site_id')->orWhere('site_id', $siteId));
    }

    public function suppressesAny(string ...$what): bool
    {
        return array_intersect($what, $this->suppresses ?? []) !== [];
    }
}
