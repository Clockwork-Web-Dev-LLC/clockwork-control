<?php

use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Seo\Http\Controllers\SeoClientsController;
use Modules\Seo\Jobs\SyncSiteGoogleDataJob;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Models\GoogleProperty;
use Modules\Seo\Models\SiteProfile;

beforeEach(function () {
    mockIssueCounterZero();
    Http::preventStrayRequests();
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
});

function seoClientsConnection(): GoogleConnection
{
    return GoogleConnection::create([
        'google_email' => 'seo@agency.example',
        'refresh_token' => 'rt',
        'scopes' => [],
        'status' => GoogleConnection::STATUS_ACTIVE,
    ]);
}

it('requires sign-in', function () {
    $this->get(route('seo.clients.index'))->assertRedirect();
    $this->post(route('seo.clients.store'), ['domain' => 'acme.com'])->assertRedirect();
    expect(SiteProfile::count())->toBe(0);
});

it('shows the getting started checklist on a fresh install', function () {
    $this->actingAs($this->user)->get(route('seo.clients.index'))
        ->assertOk()
        ->assertSee('Getting started')
        ->assertSee('Add your Google OAuth client')
        ->assertSee('Add your DataForSEO API login')
        ->assertSee('Add your first client')
        ->assertSee('Ask an admin')
        ->assertDontSee('clockworkwd');
});

it('adds a client from nothing but a domain, creating a plain monitored site', function () {
    $this->actingAs($this->user)
        ->post(route('seo.clients.store'), ['domain' => 'https://www.Acme.com/contact'])
        ->assertRedirect(route('seo.clients.index'))
        ->assertSessionHas('status');

    $site = Site::query()->where('domain', 'acme.com')->sole();
    expect($site->hosting_provider)->toBe(Site::HOSTING_PROVIDER_CUSTOM)
        ->and($site->server_id)->toBeNull()
        ->and((bool) $site->is_wordpress)->toBeFalse();

    $profile = SiteProfile::query()->where('site_id', $site->id)->sole();
    expect($profile->gsc_site_url)->toBeNull()
        ->and($profile->market_location_code)->toBe(2840)
        ->and($profile->market_language_code)->toBe('en')
        ->and($profile->state())->toBe('not_set_up');
});

it('matches the Search Console property the connected account can see and queues the first sync', function () {
    $connection = seoClientsConnection();
    foreach (['sc-domain:acme.com', 'https://www.acme.com/'] as $property) {
        GoogleProperty::create(['connection_id' => $connection->id, 'kind' => GoogleProperty::KIND_GSC, 'property' => $property, 'permission_level' => 'siteOwner', 'last_seen_at' => now()]);
    }

    $this->actingAs($this->user)->post(route('seo.clients.store'), [
        'domain' => 'acme.com',
        'ga4_property_id' => 'properties/123456',
        'brand_terms' => 'acme',
        'market_location_code' => 2124,
        'market_language_code' => 'en',
        'market_city' => 'Toronto',
    ])->assertRedirect(route('seo.clients.index'));

    $profile = SiteProfile::query()->sole();
    expect($profile->gsc_site_url)->toBe('https://www.acme.com/')
        ->and($profile->gsc_permission_level)->toBe('siteOwner')
        ->and($profile->ga4_property_id)->toBe('123456')
        ->and($profile->market_location_code)->toBe(2124)
        ->and($profile->market_city)->toBe('Toronto');

    Queue::assertPushed(SyncSiteGoogleDataJob::class);
});

it('reuses a site already in Control and never makes a second profile', function () {
    $site = Site::factory()->create(['domain' => 'acme.com']);

    $this->actingAs($this->user)->post(route('seo.clients.store'), ['domain' => 'www.acme.com'])
        ->assertRedirect(route('seo.clients.index'));
    expect(Site::count())->toBe(1)
        ->and(SiteProfile::query()->where('site_id', $site->id)->count())->toBe(1);

    $this->actingAs($this->user)->post(route('seo.clients.store'), ['domain' => 'acme.com'])
        ->assertRedirect(route('seo.sites.profile', $site))
        ->assertSessionHas('warning');
    expect(SiteProfile::count())->toBe(1);
});

it('rejects things that are not domains', function (string $input) {
    $this->actingAs($this->user)->post(route('seo.clients.store'), ['domain' => $input])
        ->assertSessionHasErrors('domain');
    expect(Site::count())->toBe(0);
})->with(['localhost', 'not a domain', '127.0.0.1', '<script>alert(1)</script>', 'acme..com']);

it('normalizes domains', function () {
    expect(SeoClientsController::normalizeDomain(' HTTPS://WWW.Acme.co.uk:443/x?y=1 '))->toBe('acme.co.uk')
        ->and(SeoClientsController::normalizeDomain('shop.acme.com'))->toBe('shop.acme.com')
        ->and(SeoClientsController::normalizeDomain('acme'))->toBeNull();
});

it('lists clients with last month clicks and leaves unknown months blank', function () {
    $acme = Site::factory()->create(['domain' => 'acme.com']);
    $bolt = Site::factory()->create(['domain' => 'bolt.com']);
    foreach ([$acme, $bolt] as $site) {
        SiteProfile::create(['site_id' => $site->id, 'gsc_site_url' => "https://www.{$site->domain}/", 'is_enabled' => true, 'last_gsc_synced_at' => now()]);
    }
    DB::table('seo_gsc_daily')->insert([
        ['site_id' => $acme->id, 'date' => '2026-10-15', 'segment' => 'all', 'clicks' => 1500, 'impressions' => 9000],
        ['site_id' => $acme->id, 'date' => '2026-09-15', 'segment' => 'all', 'clicks' => 1000, 'impressions' => 9000],
    ]);

    $html = $this->actingAs($this->user)->get(route('seo.clients.index'))->assertOk()
        ->assertSee('acme.com')->assertSee('bolt.com')
        ->assertSee('1,500')->assertSee('+50%')
        ->assertSee('October 2026')
        ->getContent();

    // bolt.com has no stored rows: a dash, not a zero.
    $row = substr($html, strpos($html, 'data-client-row="'.$bolt->id.'"'));
    $row = substr($row, 0, strpos($row, '</tr>'));
    expect($row)->toContain('—')->not->toContain('>0<');
});

it('shows the add form, and saves the keyword market from the profile page', function () {
    $this->actingAs($this->user)->get(route('seo.clients.create'))
        ->assertOk()->assertSee('Automatic: match the domain')->assertSee('Keyword market');

    $site = Site::factory()->create(['domain' => 'acme.com']);
    SiteProfile::create(['site_id' => $site->id, 'is_enabled' => true]);

    $this->actingAs($this->user)->put(route('seo.sites.profile.update', $site), [
        'is_enabled' => '1',
        'market_location_code' => 2826,
        'market_language_code' => 'en',
        'market_city' => 'Leeds',
    ])->assertSessionHasNoErrors();

    $profile = SiteProfile::query()->sole();
    expect($profile->market_location_code)->toBe(2826)->and($profile->market_city)->toBe('Leeds');
});
