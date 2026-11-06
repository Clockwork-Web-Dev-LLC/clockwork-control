<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Search Console (or, from Phase 1b, GA4) property the connection can see.
 * Filled by PropertyDiscovery; the profile page reads this table instead of
 * calling Google.
 *
 * @property int $id
 * @property int $connection_id
 * @property string $kind
 * @property string $property
 * @property ?string $display_name
 * @property ?string $permission_level
 * @property ?Carbon $last_seen_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class GoogleProperty extends Model
{
    public const KIND_GSC = 'gsc';

    public const KIND_GA4 = 'ga4';

    protected $table = 'seo_google_properties';

    protected $fillable = [
        'connection_id',
        'kind',
        'property',
        'display_name',
        'permission_level',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<GoogleConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleConnection::class, 'connection_id');
    }
}
