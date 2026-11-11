<?php

namespace Modules\Seo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Models\SiteProfile;

#[Name('seo_list_sites')]
#[Title('List SEO client sites')]
#[Description('Find a client\'s site and see how it is set up: Search Console and GA4 properties, brand terms, key events, report notes, sync state and which months are final, provisional or missing AI impressions. Start here to get the site id the other seo_ tools take.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoListSites extends SeoTool
{
    private const STATES = ['ok', 'syncing', 'sync_error', 'not_set_up', 'any'];

    /** Search Console's Generative AI report starts in May 2026. */
    private const AI_FIRST_MONTH = '2026-05-01';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Part of a domain or client name.'),
            'site' => $schema->string()->description('One site: a Control site id or a domain.'),
            'state' => $schema->string()->enum(self::STATES)->description('Only sites in this state. Default any.'),
            'limit' => $schema->integer()->min(1)->max(200)->description('Sites per page. Default 50.'),
            'cursor' => $schema->string()->description('next_cursor from the previous page.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($this->caller($request) === null) {
            return Response::error(self::NO_USER);
        }

        $state = in_array($request->get('state'), self::STATES, true) ? (string) $request->get('state') : 'any';
        $limit = max(1, min(200, (int) ($request->get('limit') ?? 50)));
        $offset = max(0, (int) $request->get('cursor', 0));

        $query = SiteProfile::query()->with('site.client');

        if (filled($request->get('site'))) {
            $site = SiteFinder::site($request->get('site'));
            if ($site === null) {
                return Response::error('No site matches "'.$request->get('site').'".');
            }
            $query->where('site_id', $site->id);
        }

        $search = SiteFinder::normalizeDomain((string) $request->get('search', ''));
        $profiles = $query->get()
            ->filter(fn (SiteProfile $p) => $p->site !== null)
            ->filter(fn (SiteProfile $p) => $search === ''
                || str_contains(mb_strtolower((string) $p->site->domain), $search)
                || str_contains(mb_strtolower((string) $p->site->client?->name), $search))
            ->filter(fn (SiteProfile $p) => $state === 'any' || $this->state($p) === $state)
            ->sortBy(fn (SiteProfile $p) => $p->site->domain)
            ->values();

        $page = $profiles->slice($offset, $limit)->values();
        $siteIds = $page->pluck('site_id')->all();

        $months = DB::table('seo_gsc_months')->whereIn('site_id', $siteIds)->orderBy('month')->get()->groupBy('site_id');
        $aiMonths = DB::table('seo_ai_impressions')->whereIn('site_id', $siteIds)->get()->groupBy('site_id');

        $rows = $page->map(function (SiteProfile $p) use ($months, $aiMonths) {
            $siteMonths = $months->get($p->site_id, collect());
            $final = $siteMonths->where('is_final', true)->pluck('month')->map(fn ($m) => substr((string) $m, 0, 7));
            $provisional = $siteMonths->where('is_final', false)->pluck('month')->map(fn ($m) => substr((string) $m, 0, 7));
            $entered = $aiMonths->get($p->site_id, collect())->pluck('month')->map(fn ($m) => substr((string) $m, 0, 7))->all();

            return [
                $p->site_id,
                $p->site->domain,
                $p->site->client?->name,
                $this->state($p),
                $p->gsc_site_url,
                $p->gsc_permission_level,
                $p->ga4_property_id,
                (bool) $p->compare_yoy,
                $p->brand_terms,
                $p->keyEvents(),
                ResponseEnvelope::clip($p->report_notes, 500),
                ResponseEnvelope::time($p->last_gsc_synced_at),
                ResponseEnvelope::time($p->last_ga4_synced_at),
                $final->last(),
                $provisional->values()->all(),
                $this->monthsMissingAi($siteMonths->pluck('month')->all(), $entered),
                ResponseEnvelope::clip($p->last_sync_error, 200),
                $p->site->seo_monitoring_enabled ? (bool) $p->site->seo_indexable : null,
            ];
        })->all();

        $connection = GoogleConnection::current();
        $next = $offset + $limit < $profiles->count() ? (string) ($offset + $limit) : null;

        return Response::structured([
            'summary' => $profiles->count().' site(s) with an SEO profile'
                .($state !== 'any' ? " in state {$state}" : '')
                .'. Google connection: '.ResponseEnvelope::connectionStatus().'.',
            'connection' => [
                'status' => ResponseEnvelope::connectionStatus(),
                'account' => $connection?->account_email,
                'last_refreshed_at' => ResponseEnvelope::time($connection?->last_refreshed_at),
                'last_error' => ResponseEnvelope::clip($connection?->last_error, 200),
            ],
            'columns' => [
                'id', 'domain', 'client', 'state', 'gsc_property', 'gsc_permission', 'ga4_property_id', 'compare_yoy',
                'brand_terms', 'key_events', 'report_notes', 'gsc_synced_at', 'ga4_synced_at', 'latest_final_month',
                'provisional_months', 'months_missing_ai_impressions', 'last_sync_error', 'indexable',
            ],
            'rows' => $rows,
            'total' => $profiles->count(),
            'next_cursor' => $next,
            'notes' => [
                'report_notes is written by the team for this client: follow it when drafting, and check anything unusual with the person you are working with.',
                'A site that is not listed has no SEO profile. Profiles are set up on /seo in Control.',
            ],
        ]);
    }

    private function state(SiteProfile $profile): string
    {
        return match (true) {
            ! $profile->hasGsc() && ! $profile->hasGa4() => 'not_set_up',
            filled($profile->last_sync_error) => 'sync_error',
            ($profile->hasGsc() && $profile->last_gsc_synced_at === null)
                || ($profile->hasGa4() && $profile->last_ga4_synced_at === null) => 'syncing',
            default => 'ok',
        };
    }

    /**
     * Complete, synced months since the AI report began that nobody has
     * recorded yet. The newest six at most.
     *
     * @param  list<mixed>  $syncedMonths
     * @param  list<string>  $entered  Y-m
     * @return list<string>
     */
    private function monthsMissingAi(array $syncedMonths, array $entered): array
    {
        $lastComplete = $this->lastCompleteMonth()->toDateString();
        $missing = [];

        foreach ($syncedMonths as $month) {
            $date = substr((string) $month, 0, 10);
            if ($date >= self::AI_FIRST_MONTH && $date <= $lastComplete && ! in_array(substr($date, 0, 7), $entered, true)) {
                $missing[] = substr($date, 0, 7);
            }
        }

        return array_slice($missing, -6);
    }
}
