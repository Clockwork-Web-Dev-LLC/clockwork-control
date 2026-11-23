<?php

use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\SearchNumbers;

beforeEach(function () {
    mockIssueCounterZero();
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    $this->profile = SiteProfile::query()->create(['site_id' => $this->site->id, 'gsc_site_url' => 'https://www.acme.com/', 'is_enabled' => true]);
});

it('renders the same numbers SearchNumbers returns, showing a suppressed comparison as "not comparable", never 0', function () {
    foreach ([['2026-09-15', 30, 600, 7.0], ['2025-09-15', 10, 100, 20.0]] as [$date, $clicks, $impressions, $position]) {
        DB::table('seo_gsc_daily')->insert(['site_id' => $this->site->id, 'date' => $date, 'segment' => 'all', 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => 0, 'position' => $position]);
        DB::table('seo_gsc_months')->insert(['site_id' => $this->site->id, 'month' => substr($date, 0, 8).'01', 'is_final' => true]);
    }

    $response = $this->actingAs($this->user)->get(route('seo.sites.show', [$this->site, 'month' => '2026-09']));

    $response->assertOk();
    $numbers = $response->viewData('numbers');
    expect($numbers)->toBe(app(SearchNumbers::class)->month($this->profile->fresh('site'), CarbonImmutable::parse('2026-09-01')))
        ->and($numbers['gsc']['position']['yoy_comparable'])->toBeFalse();

    $response->assertSee('not comparable')
        ->assertSeeInOrder(['Clicks', '30', '—', '—', '10', '+200%'], false);
    Http::assertNothingSent();
});

it('sends a site without a profile to set one up', function () {
    $other = Site::factory()->create();

    $this->actingAs($this->user)->get(route('seo.sites.show', $other))
        ->assertRedirect(route('seo.sites.profile', $other));
});
