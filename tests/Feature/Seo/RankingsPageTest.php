<?php

use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Seo\Models\RankCheck;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Models\TrackedKeyword;
use Modules\Seo\Ranks\RankReport;
use Modules\Seo\Ranks\RankSettings;
use Modules\Seo\Ranks\TrackedKeywords;

beforeEach(function () {
    mockIssueCounterZero();
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    $this->profile = SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true, 'market_location_code' => 2124, 'market_language_code' => 'en']);
});

function rankCheck(TrackedKeyword $keyword, string $date, ?int $position, array $extra = []): RankCheck
{
    return RankCheck::create($extra + [
        'tracked_keyword_id' => $keyword->id, 'site_id' => $keyword->site_id, 'checked_on' => $date,
        'position' => $position, 'depth' => 30, 'url' => $position ? 'https://acme.com/services' : null,
    ]);
}

it('adds keywords in the profile market, once per device, and refuses costly or duplicate ones', function () {
    $this->actingAs($this->user)->post(route('seo.sites.rankings.store', $this->site), [
        'keywords' => "Emergency  Plumber\nemergency plumber\nsite:acme.com plumber\n\nwater heater repair",
        'devices' => ['desktop', 'mobile'],
    ])->assertRedirect(route('seo.sites.rankings', $this->site))
        ->assertSessionHas('status', fn (string $m) => str_contains($m, '4 keywords added'))
        ->assertSessionHas('warning', fn (string $m) => str_contains($m, 'site:acme.com plumber'));

    $rows = TrackedKeyword::query()->orderBy('keyword')->orderBy('device')->get();
    expect($rows->pluck('keyword')->unique()->values()->all())->toBe(['emergency plumber', 'water heater repair'])
        ->and($rows->pluck('device')->unique()->sort()->values()->all())->toBe(['desktop', 'mobile'])
        ->and($rows->first()->location_code)->toBe(2124)
        ->and($rows->first()->location_key)->toBe('2124')
        ->and($rows->first()->added_by)->toBe($this->user->id);

    // Again: nothing new. With a city: tracked separately.
    $this->actingAs($this->user)->post(route('seo.sites.rankings.store', $this->site), ['keywords' => 'emergency plumber'])
        ->assertSessionHas('status', fn (string $m) => str_contains($m, '0 keywords added') && str_contains($m, '1 already tracked'));
    $this->actingAs($this->user)->post(route('seo.sites.rankings.store', $this->site), ['keywords' => 'emergency plumber', 'city' => 'Toronto,Ontario,Canada']);

    expect(TrackedKeyword::count())->toBe(5)
        ->and(TrackedKeyword::query()->whereNotNull('location_name')->sole()->location_key)->toBe('toronto,ontario,canada');
});

it('needs a profile before keywords can be tracked', function () {
    $bare = Site::factory()->create(['domain' => 'bare.com']);

    $this->actingAs($this->user)->post(route('seo.sites.rankings.store', $bare), ['keywords' => 'plumber'])
        ->assertRedirect(route('seo.sites.profile', $bare));
    expect(TrackedKeyword::count())->toBe(0);
});

it('reports position, change, entering and dropping out without inventing numbers', function () {
    $tracked = app(TrackedKeywords::class);
    $tracked->add($this->site, ['up', 'down', 'entered', 'dropped', 'unchecked', 'never found']);
    $k = TrackedKeyword::query()->get()->keyBy('keyword');

    rankCheck($k['up'], '2026-11-04', 8);
    rankCheck($k['up'], '2026-11-11', 3);
    rankCheck($k['down'], '2026-11-04', 5);
    rankCheck($k['down'], '2026-11-11', 12);
    rankCheck($k['entered'], '2026-11-04', null);
    rankCheck($k['entered'], '2026-11-11', 20);
    rankCheck($k['dropped'], '2026-11-04', 9);
    rankCheck($k['dropped'], '2026-11-11', null);
    rankCheck($k['never found'], '2026-11-11', null);

    $report = app(RankReport::class)->forSite($this->site->id);
    $rows = collect($report['rows'])->keyBy('keyword');

    expect($rows['up'])->toMatchArray(['position' => 3, 'previous_position' => 8, 'change' => 5, 'movement' => null, 'best_position' => 3])
        ->and($rows['down']['change'])->toBe(-7)
        ->and($rows['entered'])->toMatchArray(['position' => 20, 'change' => null, 'movement' => 'entered'])
        ->and($rows['dropped'])->toMatchArray(['position' => null, 'change' => null, 'movement' => 'dropped_out'])
        ->and($rows['unchecked'])->toMatchArray(['position' => null, 'checked_on' => null, 'change' => null])
        ->and($report['summary'])->toMatchArray([
            'tracked' => 6, 'active' => 6, 'checked' => 5, 'top3' => 1, 'top10' => 1, 'top30' => 3,
            'not_found' => 2, 'improved' => 2, 'declined' => 2, 'last_checked' => '2026-11-11',
        ]);
});

it('shows the rankings page with competitors, and words instead of zeros', function () {
    $this->profile->forceFill(['competitors' => ['rival.com']])->save();
    app(TrackedKeywords::class)->add($this->site, ['plumber', 'drain cleaning', 'new keyword']);
    $k = TrackedKeyword::query()->get()->keyBy('keyword');
    rankCheck($k['plumber'], '2026-11-11', 4, ['competitors' => ['rival.com' => 2], 'has_ai_overview' => true, 'cited_in_ai_overview' => true, 'pages_ranking' => 2]);
    rankCheck($k['drain cleaning'], '2026-11-11', null);

    $html = $this->actingAs($this->user)->get(route('seo.sites.rankings', $this->site))
        ->assertOk()
        ->assertSee('rival.com')
        ->assertSee('Not in top 30')
        ->assertSee('Cited')
        ->assertSee('2 pages')
        ->assertSee('/services')
        ->assertSee('DataForSEO API login')
        ->getContent();

    $row = substr($html, strpos($html, 'data-keyword-row="'.$k['new keyword']->id.'"'));
    $cell = substr($row, strpos($row, 'data-position'), 200);
    expect($cell)->toContain('—')->not->toContain('>0<');
});

it('pauses, resumes and deletes a keyword, and not through another site', function () {
    app(TrackedKeywords::class)->add($this->site, ['plumber']);
    $keyword = TrackedKeyword::query()->sole();
    rankCheck($keyword, '2026-11-11', 4);
    $other = Site::factory()->create(['domain' => 'other.com']);

    $this->actingAs($this->user)->patch(route('seo.sites.rankings.toggle', [$other, $keyword]))->assertNotFound();
    $this->actingAs($this->user)->delete(route('seo.sites.rankings.destroy', [$other, $keyword]))->assertNotFound();

    $this->actingAs($this->user)->patch(route('seo.sites.rankings.toggle', [$this->site, $keyword]))->assertRedirect();
    expect($keyword->fresh()->is_active)->toBeFalse()->and(RankCheck::count())->toBe(1);

    $this->actingAs($this->user)->delete(route('seo.sites.rankings.destroy', [$this->site, $keyword]))->assertRedirect();
    expect(TrackedKeyword::count())->toBe(0)->and(RankCheck::count())->toBe(0);
});

it('saves competitor domains cleaned up, without the client itself', function () {
    $this->actingAs($this->user)->put(route('seo.sites.competitors', $this->site), [
        'competitors' => "https://www.Rival.com/pricing\nacme.com, other.co.uk\nnot a domain\nrival.com",
    ])->assertRedirect(route('seo.sites.rankings', $this->site));

    expect($this->profile->fresh()->competitors)->toBe(['rival.com', 'other.co.uk']);

    $this->actingAs($this->user)->put(route('seo.sites.competitors', $this->site), ['competitors' => ''])->assertRedirect();
    expect($this->profile->fresh()->competitors)->toBeNull();
});

it('lets only an admin change how often and how deep ranks are checked', function () {
    $this->actingAs($this->user)->put(route('seo.rank-tracking.update'), ['frequency' => 'daily', 'depth' => 100])->assertForbidden();

    $admin = User::factory()->create();
    $this->actingAs($admin)->put(route('seo.rank-tracking.update'), ['frequency' => 'hourly', 'depth' => 100])->assertSessionHasErrors('frequency');
    $this->actingAs($admin)->put(route('seo.rank-tracking.update'), ['frequency' => 'daily', 'depth' => 100])
        ->assertRedirect(route('seo.settings'))->assertSessionHas('status');

    $settings = app(RankSettings::class);
    expect($settings->frequency())->toBe('daily')->and($settings->depth())->toBe(100)
        // 1,500 keywords daily at the top 100: the plan's upper figure.
        ->and($settings->monthlyEstimate(1500))->toBe(212.04);

    $this->actingAs($admin)->get(route('seo.settings'))->assertOk()->assertSee('Save rank tracking');
});
