<?php

namespace Modules\Seo\Services;

use Illuminate\Support\Facades\DB;
use Modules\Seo\Jobs\SyncSiteGoogleDataJob;
use Modules\Seo\Models\Annotation;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Support\GscDates;

/**
 * The rules for changing a site's SEO profile, shared by the profile page and
 * (later) Claude's profile tool.
 *
 * - A new GSC property purges the site's GSC rows and re-pulls 16 months:
 *   otherwise final months from the old property would stay, and
 *   month-over-month and YoY would compare two different properties. The same
 *   goes for a new GA4 property or a change to the custom channel flag.
 * - New brand terms re-mark stored month rows with one UPDATE and re-pull the
 *   non-branded daily totals Search Console still holds. Older stored days
 *   can't be re-pulled, so they keep the old terms and an annotation at the
 *   boundary puts a caveat on comparisons that straddle it.
 */
class SiteProfileService
{
    /**
     * @param  array<string, mixed>  $attributes  validated profile fields
     * @return array{created: bool, gsc_purged: bool, ga4_purged: bool, brand: ?array{repull_from: string, kept_older_days: int}}
     */
    public function save(SiteProfile $profile, array $attributes, ?int $userId = null): array
    {
        $created = ! $profile->exists;
        $gscChanged = ! $created && array_key_exists('gsc_site_url', $attributes) && $attributes['gsc_site_url'] !== $profile->gsc_site_url;
        $ga4Changed = ! $created && (
            (array_key_exists('ga4_property_id', $attributes) && $attributes['ga4_property_id'] !== $profile->ga4_property_id)
            || (array_key_exists('ga4_custom_primary_channel', $attributes) && (bool) $attributes['ga4_custom_primary_channel'] !== $profile->ga4_custom_primary_channel)
        );
        $brandChanged = array_key_exists('brand_terms', $attributes)
            && BrandRegex::parseTerms($attributes['brand_terms']) !== BrandRegex::parseTerms($profile->brand_terms);

        if (array_key_exists('brand_terms', $attributes)) {
            $attributes['brand_terms'] = implode(', ', BrandRegex::parseTerms($attributes['brand_terms'])) ?: null;
            $attributes['brand_regex'] = BrandRegex::compile($attributes['brand_terms']);
        }

        $brand = null;

        DB::transaction(function () use ($profile, $attributes, $created, $gscChanged, $ga4Changed, $brandChanged, $userId, &$brand) {
            $profile->fill($attributes);

            if ($created || $gscChanged || $ga4Changed) {
                $profile->forceFill(['history_starts_on' => GscDates::windowStart()->toDateString()]);
            }

            $profile->save();

            if ($gscChanged) {
                $this->purgeGsc($profile);
            }
            if ($ga4Changed) {
                $this->purgeGa4($profile);
            }
            if ($brandChanged && ! $created && ! $gscChanged) {
                $brand = $this->applyBrandTerms($profile, $userId);
            }
        });

        if ($created || $gscChanged || $ga4Changed) {
            SyncSiteGoogleDataJob::dispatch($profile->site_id, backfill: true);
        } elseif ($brand !== null) {
            SyncSiteGoogleDataJob::dispatch($profile->site_id, repullNonbrandedFrom: $brand['repull_from']);
        }

        return ['created' => $created, 'gsc_purged' => $gscChanged, 'ga4_purged' => $ga4Changed, 'brand' => $brand];
    }

    /**
     * What a brand-terms change would do, without doing it: the re-pull start
     * and how many stored non-branded days are too old to re-pull.
     *
     * @return array{repull_from: string, kept_older_days: int}
     */
    public function brandChangeImpact(SiteProfile $profile): array
    {
        $from = GscDates::windowStart();

        return [
            'repull_from' => $from->toDateString(),
            'kept_older_days' => DB::table('seo_gsc_daily')
                ->where('site_id', $profile->site_id)
                ->where('segment', 'nonbranded')
                ->where('date', '<', $from->toDateString())
                ->count(),
        ];
    }

    /** @return array{repull_from: string, kept_older_days: int} */
    private function applyBrandTerms(SiteProfile $profile, ?int $userId): array
    {
        $this->remarkBranded($profile);

        $impact = $this->brandChangeImpact($profile);

        if ($impact['kept_older_days'] > 0) {
            Annotation::query()->create([
                'site_id' => $profile->site_id,
                'starts_on' => $impact['repull_from'],
                'ends_on' => $impact['repull_from'],
                'kind' => Annotation::KIND_METHODOLOGY,
                'label' => 'Brand terms changed; non-branded totals before '.$impact['repull_from'].' use the old terms',
                'suppresses' => ['nonbranded'],
                'created_by_user_id' => $userId,
            ]);
        }

        return $impact;
    }

    /** One UPDATE per segment: a case-insensitive LIKE per escaped term matches what the regex matches. */
    public function remarkBranded(SiteProfile $profile): void
    {
        $base = DB::table('seo_gsc_month_rows')
            ->where('site_id', $profile->site_id)
            ->whereIn('dimension', ['query', 'page_query']);

        $terms = BrandRegex::parseTerms($profile->brand_terms);

        if ($terms === []) {
            (clone $base)->update(['is_branded' => false]);

            return;
        }

        $matchesAny = function ($q) use ($terms) {
            foreach ($terms as $term) {
                // '!' as the escape character: MySQL and SQLite read a
                // backslash in a string literal differently.
                $q->orWhereRaw("LOWER(query) LIKE ? ESCAPE '!'", ['%'.self::escapeLike(mb_strtolower($term)).'%']);
            }
        };

        (clone $base)->update(['is_branded' => false]);
        (clone $base)->where($matchesAny)->update(['is_branded' => true]);
    }

    public function purgeGsc(SiteProfile $profile): void
    {
        foreach (['seo_gsc_daily', 'seo_gsc_month_rows', 'seo_gsc_months'] as $table) {
            DB::table($table)->where('site_id', $profile->site_id)->delete();
        }
    }

    public function purgeGa4(SiteProfile $profile): void
    {
        foreach (['seo_ga4_daily', 'seo_ga4_months', 'seo_ga4_key_event_months'] as $table) {
            DB::table($table)->where('site_id', $profile->site_id)->delete();
        }
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
