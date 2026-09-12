<?php

namespace App\Models;

use App\Support\SiteIngestExclusionSet;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sticky "do not re-import this hosted site" record.
 *
 * Archive hides the `sites` row; ingest (`clockwork:import-spinupwp` /
 * `clockwork:import-pressable`) looks up by domain with the archived scope
 * off and would otherwise refill `spinupwp_id` / `pressable_site_id` on the
 * hidden row. An exclusion is what makes Remove from monitoring stick while
 * the WordPress site is still live at the host.
 *
 * Matching is domain-global (either importer skips that domain) or
 * provider + host site id (a rename on Spinup/Pressable still stays dropped).
 *
 * @property int $id
 * @property string $hosting_provider
 * @property ?string $provider_site_id
 * @property string $domain
 * @property ?int $site_id
 * @property ?string $reason
 * @property ?string $excluded_by
 */
class SiteIngestExclusion extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const INGEST_PROVIDERS = [
        Site::HOSTING_PROVIDER_SPINUPWP,
        Site::HOSTING_PROVIDER_PRESSABLE,
    ];

    protected $fillable = [
        'hosting_provider',
        'provider_site_id',
        'domain',
        'site_id',
        'reason',
        'excluded_by',
    ];

    public static function appliesTo(Site $site): bool
    {
        return in_array($site->hosting_provider, self::INGEST_PROVIDERS, true);
    }

    public static function recordFromSite(Site $site, ?string $reason = null, ?string $excludedBy = null): ?self
    {
        if (! self::appliesTo($site)) {
            return null;
        }

        $providerSiteId = match ($site->hosting_provider) {
            Site::HOSTING_PROVIDER_SPINUPWP => $site->spinupwp_id !== null ? (string) $site->spinupwp_id : null,
            Site::HOSTING_PROVIDER_PRESSABLE => $site->pressable_site_id !== null ? (string) $site->pressable_site_id : null,
            default => null,
        };

        return self::query()->updateOrCreate(
            [
                'hosting_provider' => $site->hosting_provider,
                'domain' => strtolower($site->domain),
            ],
            [
                'provider_site_id' => $providerSiteId,
                'site_id' => $site->id,
                'reason' => $reason !== null && $reason !== '' ? $reason : null,
                'excluded_by' => $excludedBy,
            ],
        );
    }

    public static function clearForSite(Site $site): void
    {
        self::query()
            ->where(function ($query) use ($site) {
                $query->where('site_id', $site->id)
                    ->orWhere(function ($query) use ($site) {
                        $query->where('hosting_provider', $site->hosting_provider)
                            ->where('domain', strtolower($site->domain));
                    });
            })
            ->delete();
    }

    public static function compile(): SiteIngestExclusionSet
    {
        return SiteIngestExclusionSet::fromExclusions(self::query()->get());
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
