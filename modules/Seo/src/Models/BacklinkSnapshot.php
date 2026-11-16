<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * A domain's backlink totals for one month.
 *
 * @property int $id
 * @property string $domain
 * @property Carbon $month
 * @property string $provider
 * @property ?int $backlinks
 * @property ?int $referring_domains
 * @property ?int $referring_main_domains
 * @property ?int $referring_domains_nofollow
 * @property ?int $broken_backlinks
 * @property ?int $rank
 * @property ?int $spam_score
 * @property ?Carbon $fetched_at
 */
class BacklinkSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'seo_backlink_snapshots';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'month' => DateOnly::class, 'backlinks' => 'integer', 'referring_domains' => 'integer', 'referring_main_domains' => 'integer',
            'referring_domains_nofollow' => 'integer', 'broken_backlinks' => 'integer', 'rank' => 'integer', 'spam_score' => 'integer', 'fetched_at' => 'datetime',
        ];
    }
}
