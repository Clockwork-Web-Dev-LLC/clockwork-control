<?php

namespace Tests\Fixtures;

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Modules\Seo\Models\SiteProfile;

/**
 * Stored SEO rows for tests that read them back (the MCP tools), written
 * straight to the tables the nightly sync fills.
 */
class SeoStoredData
{
    public static function profile(string $domain = 'acme.com', array $overrides = []): SiteProfile
    {
        $site = Site::factory()->create(['domain' => $domain]);

        return SiteProfile::query()->create($overrides + [
            'site_id' => $site->id,
            'gsc_site_url' => "https://www.{$domain}/",
            'ga4_property_id' => '123456',
            'brand_terms' => 'acme',
            'brand_regex' => '(?i)(acme)',
            'is_enabled' => true,
            'last_gsc_synced_at' => now(),
            'last_ga4_synced_at' => now(),
        ])->load('site');
    }

    /** Two days of Search Console totals in a month, plus the month marker. */
    public static function gscMonth(int $siteId, string $month, int $clicks, int $impressions, float $position, bool $final = true, ?int $nonbrandedClicks = null): void
    {
        foreach (['01', '15'] as $day) {
            DB::table('seo_gsc_daily')->insert([
                'site_id' => $siteId, 'date' => "{$month}-{$day}", 'segment' => 'all',
                'clicks' => intdiv($clicks, 2), 'impressions' => intdiv($impressions, 2), 'ctr' => 0, 'position' => $position,
            ]);
            if ($nonbrandedClicks !== null) {
                DB::table('seo_gsc_daily')->insert([
                    'site_id' => $siteId, 'date' => "{$month}-{$day}", 'segment' => 'nonbranded',
                    'clicks' => intdiv($nonbrandedClicks, 2), 'impressions' => intdiv($impressions, 4), 'ctr' => 0, 'position' => $position,
                ]);
            }
        }

        DB::table('seo_gsc_months')->insert(['site_id' => $siteId, 'month' => "{$month}-01", 'is_final' => $final]);
    }

    /**
     * @param  'page'|'query'|'page_query'  $dimension
     */
    public static function row(int $siteId, string $month, string $dimension, ?string $page, ?string $query, int $clicks, int $impressions, float $position = 5.0, ?bool $branded = null): void
    {
        DB::table('seo_gsc_month_rows')->insert([
            'site_id' => $siteId, 'month' => "{$month}-01", 'dimension' => $dimension,
            'page' => $page, 'query' => $query,
            'row_hash' => sha1($dimension.'|'.$page.'|'.$query),
            'is_branded' => $query === null ? null : ($branded ?? false),
            'clicks' => $clicks, 'impressions' => $impressions,
            'ctr' => $impressions > 0 ? $clicks / $impressions : 0, 'position' => $position,
        ]);
    }

    /**
     * @param  array<string, int>  $channels  channel name to sessions
     * @param  array<string, int>  $events
     */
    public static function ga4Month(int $siteId, string $month, array $channels = ['Organic Search' => 500, 'Direct' => 200, 'AI Assistant' => 7], array $events = ['generate_lead' => 4, 'form_submit' => 0, 'contact_link_click' => 2], bool $final = true): void
    {
        foreach ($channels as $name => $sessions) {
            DB::table('seo_ga4_months')->insert([
                'site_id' => $siteId, 'month' => "{$month}-01", 'channel_group' => $name,
                'sessions' => $sessions, 'engaged_sessions' => intdiv($sessions * 3, 5), 'key_events' => 0, 'is_final' => $final,
            ]);
        }
        foreach ($events as $name => $count) {
            DB::table('seo_ga4_key_event_months')->insert([
                'site_id' => $siteId, 'month' => "{$month}-01", 'event_name' => $name,
                'key_events' => $count, 'event_count' => $count, 'is_final' => $final,
            ]);
        }
    }
}
