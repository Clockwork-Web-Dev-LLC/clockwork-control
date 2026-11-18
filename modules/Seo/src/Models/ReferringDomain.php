<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * A domain that links to a domain.
 *
 * @property int $id
 * @property string $domain
 * @property string $provider
 * @property string $referring_domain
 * @property ?int $rank
 * @property ?int $backlinks
 * @property ?int $spam_score
 * @property ?Carbon $first_seen
 * @property ?Carbon $found_at
 * @property ?Carbon $seen_at
 * @property ?Carbon $lost_at
 */
class ReferringDomain extends Model
{
    public $timestamps = false;

    protected $table = 'seo_backlink_referring_domains';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rank' => 'integer', 'backlinks' => 'integer', 'spam_score' => 'integer', 'first_seen' => DateOnly::class,
            'found_at' => 'datetime', 'seen_at' => 'datetime', 'lost_at' => 'datetime',
        ];
    }
}
