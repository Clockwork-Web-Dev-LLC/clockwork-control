<?php

namespace Modules\Seo\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * Per-site SEO settings: which GSC and GA4 properties to sync, brand terms,
 * key events and the report notes the monthly report follows. No relation is
 * added to core Site; module code queries this model directly.
 *
 * @property int $id
 * @property int $site_id
 * @property ?string $gsc_site_url
 * @property ?string $gsc_permission_level
 * @property ?string $ga4_property_id
 * @property ?string $ga4_property_name
 * @property bool $ga4_custom_primary_channel
 * @property ?string $brand_regex
 * @property ?string $brand_terms
 * @property ?array<int, string> $key_events
 * @property bool $compare_yoy
 * @property ?string $report_notes
 * @property bool $is_enabled
 * @property ?Carbon $history_starts_on
 * @property ?Carbon $last_gsc_synced_at
 * @property ?Carbon $last_ga4_synced_at
 * @property ?string $last_sync_error
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Site $site
 */
class SiteProfile extends Model
{
    /** The three events the monthly report has always counted. */
    public const DEFAULT_KEY_EVENTS = ['generate_lead', 'form_submit', 'contact_link_click'];

    protected $table = 'seo_site_profiles';

    protected $fillable = [
        'site_id',
        'gsc_site_url',
        'gsc_permission_level',
        'ga4_property_id',
        'ga4_property_name',
        'ga4_custom_primary_channel',
        'brand_regex',
        'brand_terms',
        'key_events',
        'compare_yoy',
        'report_notes',
        'market_location_code',
        'market_language_code',
        'market_city',
        'is_enabled',
        'history_starts_on',
        'last_gsc_synced_at',
        'last_ga4_synced_at',
        'last_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'ga4_custom_primary_channel' => 'boolean',
            'key_events' => 'array',
            'compare_yoy' => 'boolean',
            'is_enabled' => 'boolean',
            'history_starts_on' => DateOnly::class,
            'last_gsc_synced_at' => 'datetime',
            'last_ga4_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return list<string> */
    public function keyEvents(): array
    {
        $events = $this->key_events;

        return is_array($events) && $events !== [] ? array_values($events) : self::DEFAULT_KEY_EVENTS;
    }

    public function hasGsc(): bool
    {
        return $this->gsc_site_url !== null && $this->gsc_site_url !== '';
    }

    public function hasGa4(): bool
    {
        return $this->ga4_property_id !== null && $this->ga4_property_id !== '';
    }

    /** not_set_up, sync_error, syncing (waiting for its first sync) or ok. */
    public function state(): string
    {
        return match (true) {
            ! $this->hasGsc() && ! $this->hasGa4() => 'not_set_up',
            filled($this->last_sync_error) => 'sync_error',
            ($this->hasGsc() && $this->last_gsc_synced_at === null)
                || ($this->hasGa4() && $this->last_ga4_synced_at === null) => 'syncing',
            default => 'ok',
        };
    }

    public function stateLabel(): string
    {
        return match ($this->state()) {
            'not_set_up' => 'Not set up',
            'sync_error' => 'Sync error',
            'syncing' => 'First sync pending',
            default => 'OK',
        };
    }

    /** The GA4 channel dimension: the custom primary group when the property has one. */
    public function ga4ChannelDimension(): string
    {
        return $this->ga4_custom_primary_channel ? 'sessionPrimaryChannelGroup' : 'sessionDefaultChannelGroup';
    }
}
