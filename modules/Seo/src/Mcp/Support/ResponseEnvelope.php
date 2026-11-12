<?php

namespace Modules\Seo\Mcp\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Models\SiteProfile;

/**
 * The opening every SEO read tool shares: a one-line summary, then meta
 * that says which site and period, how fresh the data is, and what to be
 * careful about. Claude reads the summary first, so it leads with the
 * things that change how a number may be used.
 */
class ResponseEnvelope
{
    /**
     * @param  array<string, mixed>  $period
     * @param  list<array<string, mixed>>  $caveats
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function make(string $summary, SiteProfile $profile, array $period, ?string $dataState, ?string $finalAfter, array $caveats, array $data): array
    {
        return [
            'summary' => $summary,
            'meta' => [
                'site' => self::site($profile),
                'period' => $period,
                'data_state' => $dataState,
                'final_after' => $finalAfter,
                'freshness' => self::freshness($profile),
                'caveats' => array_values($caveats),
                // Everything comes from rows Control already stored. No
                // tool calls Google.
                'source' => 'stored',
            ],
        ] + $data;
    }

    /**
     * Only these fields of a site ever leave through a tool. Never
     * toArray(): a Site row also carries database and server details.
     *
     * @return array{id: int, domain: ?string, client: ?string}
     */
    public static function site(SiteProfile $profile): array
    {
        $site = $profile->site;

        return [
            'id' => $profile->site_id,
            'domain' => $site?->domain,
            'client' => $site?->client?->name,
        ];
    }

    /** @return array<string, mixed> */
    public static function freshness(SiteProfile $profile): array
    {
        $latest = DB::table('seo_gsc_daily')
            ->where('site_id', $profile->site_id)
            ->where('segment', 'all')
            ->max('date');

        return [
            'gsc_synced_at' => self::time($profile->last_gsc_synced_at),
            'gsc_latest_date' => $latest === null ? null : substr((string) $latest, 0, 10),
            'ga4_synced_at' => self::time($profile->last_ga4_synced_at),
            'last_sync_error' => self::clip($profile->last_sync_error, 200),
            'connection' => self::connectionStatus(),
        ];
    }

    public static function connectionStatus(): string
    {
        return GoogleConnection::current()?->status ?? 'not_connected';
    }

    public static function time(?CarbonInterface $time): ?string
    {
        return $time?->toIso8601String();
    }

    /** Cut long text and drop control characters before it reaches Claude. */
    public static function clip(?string $text, int $length): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
