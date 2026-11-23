<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A domain that links to a competitor but not to the client.
 *
 * @property int $id
 * @property string $domain
 * @property string $competitor_domain
 * @property string $provider
 * @property string $referring_domain
 * @property ?int $rank
 * @property ?int $backlinks
 * @property ?int $spam_score
 * @property ?Carbon $fetched_at
 */
class BacklinkGapDomain extends Model
{
    public $timestamps = false;

    protected $table = 'seo_backlink_gap';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rank' => 'integer', 'backlinks' => 'integer', 'spam_score' => 'integer', 'fetched_at' => 'datetime'];
    }
}
