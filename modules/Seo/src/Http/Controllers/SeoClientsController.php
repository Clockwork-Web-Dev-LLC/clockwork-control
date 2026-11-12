<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\Core\ModuleStateResolver;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Models\GoogleProperty;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\BrandRegex;
use Modules\Seo\Services\SiteProfileService;
use Modules\Seo\Setup\SetupChecklist;
use Modules\Seo\Support\GscDates;

/**
 * SEO clients: the sites that have an SEO profile. One page to see them
 * all, and one form to add a new one from nothing but a domain.
 *
 * Adding a client does not need the site to be on a server Control manages
 * or to run WordPress: a site that isn't in Control yet is created as a
 * plain monitored site.
 */
class SeoClientsController extends Controller
{
    /** Common DataForSEO country codes, for the market picker. Any other code can be typed. */
    public const COUNTRIES = [
        2840 => 'United States',
        2124 => 'Canada',
        2826 => 'United Kingdom',
        2036 => 'Australia',
        2554 => 'New Zealand',
        2372 => 'Ireland',
    ];

    public function index(SetupChecklist $checklist): View
    {
        $profiles = SiteProfile::query()
            ->with('site.client')
            ->get()
            ->filter(fn (SiteProfile $p) => $p->site !== null)
            ->sortBy(fn (SiteProfile $p) => $p->site->domain)
            ->values();

        $month = GscDates::today()->startOfMonth()->subMonthNoOverflow();
        $previous = $month->subMonthNoOverflow();

        // Clicks for the last complete month and the one before, for every
        // client in two grouped queries. Read from stored rows only.
        $clicks = fn ($from, $to) => DB::table('seo_gsc_daily')
            ->whereIn('site_id', $profiles->pluck('site_id'))
            ->where('segment', 'all')
            ->whereBetween('date', [$from->toDateString(), $to->endOfMonth()->toDateString()])
            ->groupBy('site_id')
            ->selectRaw('site_id, SUM(clicks) AS clicks')
            ->pluck('clicks', 'site_id');

        return view('seo::clients.index', [
            'profiles' => $profiles,
            'month' => $month,
            'clicks' => $clicks($month, $month),
            'previousClicks' => $clicks($previous, $previous),
            'steps' => $checklist->steps(),
            'setupComplete' => $checklist->complete(),
            'connection' => GoogleConnection::current(),
        ]);
    }

    public function create(ModuleStateResolver $modules): View
    {
        $connection = GoogleConnection::current();

        return view('seo::clients.create', [
            'connection' => $connection,
            'properties' => $connection !== null
                ? GoogleProperty::query()
                    ->where('connection_id', $connection->id)
                    ->where('kind', GoogleProperty::KIND_GSC)
                    ->orderBy('property')
                    ->get()
                : collect(),
            // Sites already in Control that aren't SEO clients yet.
            'existingSites' => Site::query()
                ->where('is_inactive', false)
                ->whereNotIn('id', SiteProfile::query()->select('site_id'))
                ->orderBy('domain')
                ->get(['id', 'domain']),
            'clients' => $this->clientsEnabled($modules)
                ? DB::table('clients')->orderBy('name')->get(['id', 'name'])
                : collect(),
            'countries' => self::COUNTRIES,
        ]);
    }

    public function store(Request $request, SiteProfileService $profiles, ModuleStateResolver $modules): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer'],
            'gsc_site_url' => ['nullable', 'string', 'max:255'],
            'ga4_property_id' => ['nullable', 'string', 'max:64', 'regex:/^(properties\/)?\d+$/'],
            'brand_terms' => ['nullable', 'string', 'max:2048'],
            'compare_yoy' => ['nullable', 'boolean'],
            'market_location_code' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'market_language_code' => ['nullable', 'string', 'regex:/^[a-z]{2}(-[A-Za-z]{2,4})?$/'],
            'market_city' => ['nullable', 'string', 'max:191'],
        ], [
            'ga4_property_id.regex' => 'The GA4 property ID is the number from GA4 Admin → Property details, e.g. 123456789.',
            'market_language_code.regex' => 'Use a language code such as en or es.',
        ]);

        $domain = self::normalizeDomain($validated['domain']);
        if ($domain === null) {
            return back()->withInput()->withErrors(['domain' => 'Enter a domain such as example.com.']);
        }

        try {
            BrandRegex::compile($validated['brand_terms'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['brand_terms' => $e->getMessage()]);
        }

        $site = Site::query()->whereIn('domain', [$domain, 'www.'.$domain])->orderBy('is_inactive')->first();

        if ($site !== null && SiteProfile::query()->where('site_id', $site->id)->exists()) {
            return redirect()->route('seo.sites.profile', $site)
                ->with('warning', "{$site->domain} is already an SEO client. This is its profile.");
        }

        $created = false;
        if ($site === null) {
            // Not on a managed server and not necessarily WordPress: a
            // plain site that Control watches from the outside.
            $site = Site::create([
                'domain' => $domain,
                'hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM,
                'server_id' => null,
                'is_wordpress' => false,
                'uptime_monitoring_enabled' => true,
            ]);
            $created = true;
        }

        if (! empty($validated['client_id']) && $this->clientsEnabled($modules)
            && DB::table('clients')->where('id', $validated['client_id'])->exists()) {
            $site->forceFill(['client_id' => (int) $validated['client_id']])->save();
        }

        $gscSiteUrl = $this->blank($validated['gsc_site_url'] ?? null) ?? $this->matchingProperty($domain);
        $ga4 = $this->blank(preg_replace('/^properties\//', '', (string) ($validated['ga4_property_id'] ?? '')));

        $profiles->save(new SiteProfile(['site_id' => $site->id]), [
            'gsc_site_url' => $gscSiteUrl,
            'gsc_permission_level' => $gscSiteUrl !== null
                ? GoogleProperty::query()->where('kind', GoogleProperty::KIND_GSC)->where('property', $gscSiteUrl)->value('permission_level')
                : null,
            'ga4_property_id' => $ga4,
            'brand_terms' => $validated['brand_terms'] ?? null,
            'compare_yoy' => $request->boolean('compare_yoy'),
            'is_enabled' => true,
            'market_location_code' => (int) ($validated['market_location_code'] ?? Market::DEFAULT_LOCATION_CODE),
            'market_language_code' => $validated['market_language_code'] ?? Market::DEFAULT_LANGUAGE_CODE,
            'market_city' => $this->blank($validated['market_city'] ?? null),
        ], $request->user()?->id);

        $message = "{$site->domain} added as an SEO client.";
        if ($created) {
            $message .= ' It was not in Control yet, so it was added as a monitored site.';
        }
        $message .= match (true) {
            $gscSiteUrl === null && $ga4 === null => ' No Search Console or GA4 property is set yet, so nothing will sync until one is chosen on its profile.',
            GoogleConnection::current() === null => ' Connect a Google account on SEO → Settings and the first sync will run.',
            default => ' The first sync is queued and pulls 16 months; give it a few minutes.',
        };

        return redirect()->route('seo.clients.index')->with('status', $message);
    }

    /** "https://www.Example.com/page" to "example.com"; null when it isn't a domain. */
    public static function normalizeDomain(string $input): ?string
    {
        $domain = mb_strtolower(trim($input));
        $domain = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];
        $domain = preg_replace('/^www\./', '', $domain) ?? $domain;

        return preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1 ? $domain : null;
    }

    /**
     * The Search Console property the connected account can see for this
     * domain, preferring the URL-prefix forms most reports already use.
     */
    private function matchingProperty(string $domain): ?string
    {
        $candidates = ["https://www.{$domain}/", "https://{$domain}/", "sc-domain:{$domain}", "http://www.{$domain}/", "http://{$domain}/"];

        $seen = GoogleProperty::query()
            ->where('kind', GoogleProperty::KIND_GSC)
            ->whereIn('property', $candidates)
            ->pluck('property')
            ->all();

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $seen, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function clientsEnabled(ModuleStateResolver $modules): bool
    {
        return $modules->isEnabled('client-management') && Schema::hasTable('clients');
    }

    private function blank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
