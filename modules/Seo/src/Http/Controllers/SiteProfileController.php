<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\Seo\Jobs\RefreshGooglePropertiesJob;
use Modules\Seo\Jobs\SyncSiteGoogleDataJob;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Models\AiImpressionEntry;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Models\GoogleProperty;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\BrandRegex;
use Modules\Seo\Services\SiteProfileService;

/**
 * /seo/sites/{site}/profile, for any active user. Holds the Search Console
 * and GA4 properties, the keyword market and the report notes. It never calls
 * Google: the property list comes from seo_google_properties, which the
 * nightly sync and the queued "Refresh list" button fill.
 */
class SiteProfileController extends Controller
{
    public function show(Site $site): View
    {
        $profile = SiteProfile::query()->firstOrNew(['site_id' => $site->id]);
        $connection = GoogleConnection::current();

        $properties = GoogleProperty::query()
            ->where('connection_id', $connection->id ?? 0)
            ->where('kind', GoogleProperty::KIND_GSC)
            ->orderBy('property')
            ->get();
        $latestSeen = $properties->max('last_seen_at');

        $suggested = $this->suggestedProperties($site->domain);

        return view('seo::sites.profile', [
            'site' => $site,
            'profile' => $profile,
            'connection' => $connection,
            'properties' => $properties->map(fn (GoogleProperty $p) => [
                'property' => $p->property,
                'permission' => $p->permission_level,
                'unseen' => $latestSeen !== null && $p->last_seen_at !== null && $p->last_seen_at->lt($latestSeen->copy()->subHour()),
                'suggested' => in_array($p->property, $suggested, true),
            ]),
            'suggestedProperty' => $profile->gsc_site_url ?? $properties->pluck('property')->first(fn ($p) => in_array($p, $suggested, true) && ! str_starts_with($p, 'sc-domain:')),
            'brandPreview' => $this->brandPreview($site, $profile),
            'aiEntries' => AiImpressionEntry::query()->where('site_id', $site->id)->orderByDesc('month')->limit(6)->get(),
            'lastMonth' => now()->subMonthNoOverflow()->format('Y-m'),
        ]);
    }

    public function update(Request $request, Site $site, SiteProfileService $service): RedirectResponse
    {
        $validated = $request->validate([
            'gsc_site_url' => ['nullable', 'string', 'max:255'],
            'ga4_property_id' => ['nullable', 'string', 'max:64', 'regex:/^(properties\/)?\d+$/'],
            'ga4_property_name' => ['nullable', 'string', 'max:255'],
            'ga4_custom_primary_channel' => ['nullable', 'boolean'],
            'brand_terms' => ['nullable', 'string', 'max:2048'],
            'key_events' => ['nullable', 'string', 'max:1000'],
            'compare_yoy' => ['nullable', 'boolean'],
            'report_notes' => ['nullable', 'string', 'max:5000'],
            'is_enabled' => ['nullable', 'boolean'],
            'confirm_purge' => ['nullable', 'boolean'],
            'market_location_code' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'market_language_code' => ['nullable', 'string', 'regex:/^[a-z]{2}(-[A-Za-z]{2,4})?$/'],
            'market_city' => ['nullable', 'string', 'max:191'],
        ], [
            'market_language_code.regex' => 'Use a language code such as en or es.',
            'ga4_property_id.regex' => 'The GA4 property ID is the number from GA4 Admin → Property details, e.g. 123456789.',
        ]);

        $profile = SiteProfile::query()->firstOrNew(['site_id' => $site->id]);

        $attributes = [
            'gsc_site_url' => $this->blank($validated['gsc_site_url'] ?? null),
            'gsc_permission_level' => null,
            'ga4_property_id' => $this->blank(preg_replace('/^properties\//', '', (string) ($validated['ga4_property_id'] ?? ''))),
            'ga4_property_name' => $this->blank($validated['ga4_property_name'] ?? null),
            'ga4_custom_primary_channel' => $request->boolean('ga4_custom_primary_channel'),
            'brand_terms' => $validated['brand_terms'] ?? null,
            'key_events' => $this->keyEvents($validated['key_events'] ?? null),
            'compare_yoy' => $request->boolean('compare_yoy'),
            'report_notes' => $this->blank($validated['report_notes'] ?? null),
            'is_enabled' => $request->boolean('is_enabled'),
        ];

        // The keyword market. A form that doesn't send it leaves it alone.
        if ($request->has('market_location_code')) {
            $attributes['market_location_code'] = (int) ($validated['market_location_code'] ?? Market::DEFAULT_LOCATION_CODE);
            $attributes['market_language_code'] = $validated['market_language_code'] ?? Market::DEFAULT_LANGUAGE_CODE;
            $attributes['market_city'] = $this->blank($validated['market_city'] ?? null);
        }

        if ($attributes['gsc_site_url'] !== null) {
            $attributes['gsc_permission_level'] = GoogleProperty::query()
                ->where('kind', GoogleProperty::KIND_GSC)
                ->where('property', $attributes['gsc_site_url'])
                ->value('permission_level');
        }

        $needsConfirm = $profile->exists && (
            $attributes['gsc_site_url'] !== $profile->gsc_site_url
            || $attributes['ga4_property_id'] !== $profile->ga4_property_id
            || $attributes['ga4_custom_primary_channel'] !== $profile->ga4_custom_primary_channel
            || BrandRegex::parseTerms($attributes['brand_terms']) !== BrandRegex::parseTerms($profile->brand_terms)
        );

        if ($needsConfirm && ! $request->boolean('confirm_purge')) {
            return back()->withInput()->withErrors([
                'confirm_purge' => 'This change deletes or re-pulls stored history for this site. Tick the confirmation box and save again.',
            ]);
        }

        try {
            $result = $service->save($profile, $attributes, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['brand_terms' => $e->getMessage()]);
        }

        $message = match (true) {
            $result['created'] => 'Profile saved. Pulling 16 months of history in the background.',
            $result['gsc_purged'] || $result['ga4_purged'] => 'Profile saved. Old history for the changed property was deleted and 16 months are being re-pulled.',
            $result['brand'] !== null => 'Profile saved. Branded queries were re-marked, and non-branded totals from '.$result['brand']['repull_from'].' are being re-pulled.',
            default => 'Profile saved.',
        };

        return redirect()->route('seo.sites.profile', $site)->with('status', $message);
    }

    public function refreshProperties(Site $site): RedirectResponse
    {
        if (GoogleConnection::current() === null) {
            return back()->with('error', 'Google isn\'t connected yet. An admin can connect it on SEO → Settings.');
        }

        RefreshGooglePropertiesJob::dispatch();

        return back()->with('status', 'Refreshing the Search Console property list. Reload in a minute.');
    }

    public function sync(Site $site): RedirectResponse
    {
        $profile = SiteProfile::query()->where('site_id', $site->id)->first();
        if ($profile === null || ! $profile->is_enabled) {
            return back()->with('error', 'Set up and enable this site\'s SEO profile first.');
        }

        SyncSiteGoogleDataJob::dispatch($site->id);

        return back()->with('status', 'Sync queued. Numbers update in a few minutes.');
    }

    /** @return list<string> */
    private function suggestedProperties(string $domain): array
    {
        $bare = preg_replace('/^www\./i', '', strtolower($domain)) ?? $domain;

        return ["https://www.{$bare}/", "https://{$bare}/", "sc-domain:{$bare}"];
    }

    /**
     * Last stored month's top queries and which ones the current terms exclude.
     *
     * @return array{month: ?string, queries: list<array{query: string, impressions: int, branded: bool}>}
     */
    private function brandPreview(Site $site, SiteProfile $profile): array
    {
        $month = DB::table('seo_gsc_month_rows')->where('site_id', $site->id)->where('dimension', 'query')->max('month');
        if ($month === null) {
            return ['month' => null, 'queries' => []];
        }

        $rows = DB::table('seo_gsc_month_rows')
            ->where('site_id', $site->id)->where('dimension', 'query')->where('month', $month)
            ->orderByDesc('impressions')->limit(25)->get(['query', 'impressions']);

        return [
            'month' => substr((string) $month, 0, 7),
            'queries' => $rows->map(fn ($r) => [
                'query' => (string) $r->query,
                'impressions' => (int) $r->impressions,
                'branded' => BrandRegex::matches((string) $r->query, $profile->brand_terms),
            ])->values()->all(),
        ];
    }

    /** @return ?list<string> */
    private function keyEvents(?string $value): ?array
    {
        $events = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $value)))));

        return $events === [] ? null : $events;
    }

    private function blank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
