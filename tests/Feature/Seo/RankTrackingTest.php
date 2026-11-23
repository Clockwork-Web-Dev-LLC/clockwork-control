<?php

use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\DataForSeo\Pricing;
use Modules\Seo\Models\ApiUsage;
use Modules\Seo\Models\ProviderTask;
use Modules\Seo\Models\RankCheck;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Models\TrackedKeyword;
use Modules\Seo\Ranks\RankSettings;
use Modules\Seo\Ranks\RankTracker;
use Modules\Seo\Ranks\SerpParser;
use Tests\Fixtures\DataForSeoFixtures;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['clockwork.seo.dataforseo_login' => 'api-login', 'clockwork.seo.dataforseo_password' => 'api-password-canary']);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true, 'competitors' => ['rival.com']]);
    app(RankSettings::class)->save('daily', 30);
});

function track(int $siteId, string $keyword, array $overrides = []): TrackedKeyword
{
    return TrackedKeyword::create($overrides + [
        'site_id' => $siteId, 'keyword' => $keyword, 'device' => 'desktop',
        'location_code' => 2840, 'language_code' => 'en', 'location_key' => '2840',
    ]);
}

describe('SerpParser', function () {
    it('finds the site, its competitors and the page features', function () {
        $parsed = SerpParser::parse(
            DataForSeoFixtures::serp('plumber', ['big.com', 'www.rival.com', 'blog.acme.com', 'other.com', 'acme.com'], ['acme.com']),
            'www.acme.com',
            ['rival.com', 'nowhere.com'],
        );

        expect($parsed['position'])->toBe(3)
            ->and($parsed['absolute_position'])->toBe(5)
            ->and($parsed['url'])->toBe('https://blog.acme.com/page-3')
            ->and($parsed['pages_ranking'])->toBe(2)
            ->and($parsed['depth'])->toBe(5)
            ->and($parsed['competitors'])->toBe(['rival.com' => 2, 'nowhere.com' => null])
            ->and($parsed['top_domains'][0])->toBe('big.com')
            ->and($parsed['has_ai_overview'])->toBeTrue()
            ->and($parsed['cited_in_ai_overview'])->toBeTrue()
            ->and($parsed['features'])->toContain('ai_overview', 'people_also_ask')->not->toContain('organic');
    });

    it('reports not found as null, never zero, and does not match lookalike domains', function () {
        $parsed = SerpParser::parse(DataForSeoFixtures::serp('plumber', ['notacme.com', 'acme.com.evil.net'], ['rival.com']), 'acme.com');

        expect($parsed['position'])->toBeNull()
            ->and($parsed['url'])->toBeNull()
            ->and($parsed['pages_ranking'])->toBe(0)
            ->and($parsed['has_ai_overview'])->toBeTrue()
            ->and($parsed['cited_in_ai_overview'])->toBeFalse();
    });
});

it('prices a check by pages of ten results', function () {
    expect(Pricing::serp(10))->toBe(0.0006)
        ->and(Pricing::serp(30))->toBe(0.0015)
        ->and(Pricing::serp(100))->toBe(0.00465);
});

it('queues due keywords, then stores the finished checks', function () {
    $plumber = track($this->site->id, 'plumber');
    $local = track($this->site->id, 'emergency plumber', ['device' => 'mobile', 'location_name' => 'Austin,Texas,United States', 'location_key' => 'austin,texas,united states']);
    track($this->site->id, 'paused keyword', ['is_active' => false]);

    Http::fake(['*/task_post' => Http::response(DataForSeoFixtures::created(['id-1', 'id-2'], 0.0015))]);
    $posted = app(RankTracker::class)->postDue();

    expect($posted)->toMatchArray(['due' => 2, 'posted' => 2, 'failed' => 0, 'refused' => false])
        ->and($posted['cost'])->toBe(0.003)
        ->and($plumber->fresh()->last_posted_on->toDateString())->toBe('2026-11-11')
        ->and(ProviderTask::query()->where('kind', 'serp_organic')->count())->toBe(2)
        ->and(ApiUsage::query()->sole()->feature)->toBe('rank_tracking');

    Http::assertSent(function (Request $request) {
        $tasks = $request->data();

        return str_ends_with($request->url(), '/v3/serp/google/organic/task_post')
            && $tasks[0]['keyword'] === 'plumber' && $tasks[0]['location_code'] === 2840 && $tasks[0]['depth'] === 30
            && $tasks[0]['priority'] === 1 && ! isset($tasks[0]['location_name'])
            && $tasks[1]['location_name'] === 'Austin,Texas,United States' && ! isset($tasks[1]['location_code']) && $tasks[1]['device'] === 'mobile';
    });

    // The same day again: nothing is due, nothing more is sent.
    expect(app(RankTracker::class)->postDue()['due'])->toBe(0);
    Http::assertSentCount(1);

    Http::fake([
        '*/task_get/advanced/id-1' => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task('id-1', [DataForSeoFixtures::serp('plumber', ['big.com', 'acme.com', 'rival.com'])])])),
        '*/task_get/advanced/id-2' => Http::response(DataForSeoFixtures::inQueue('id-2')),
    ]);
    $collected = app(RankTracker::class)->collect();

    expect($collected)->toBe(['collected' => 1, 'pending' => 1, 'failed' => 0]);

    $check = RankCheck::query()->sole();
    expect($check->tracked_keyword_id)->toBe($plumber->id)
        ->and($check->checked_on->toDateString())->toBe('2026-11-11')
        ->and($check->position)->toBe(2)
        ->and($check->competitors)->toBe(['rival.com' => 3])
        ->and($check->has_ai_overview)->toBeFalse()
        ->and($local->checks()->count())->toBe(0);
});

it('checks weekly keywords once a week and skips switched-off clients', function () {
    app(RankSettings::class)->save('weekly', 30);
    track($this->site->id, 'checked six days ago', ['last_posted_on' => '2026-11-05']);
    track($this->site->id, 'checked a week ago', ['last_posted_on' => '2026-11-04']);
    $off = Site::factory()->create(['domain' => 'off.com']);
    SiteProfile::create(['site_id' => $off->id, 'is_enabled' => false]);
    track($off->id, 'never');

    Http::fake(['*/task_post' => Http::response(DataForSeoFixtures::created(['id-1'], 0.0015))]);
    $posted = app(RankTracker::class)->postDue();

    expect($posted['due'])->toBe(1)->and($posted['posted'])->toBe(1);
    Http::assertSent(fn (Request $r) => count($r->data()) === 1 && $r->data()[0]['keyword'] === 'checked a week ago');
});

it('stops when the budget is used up and leaves the keyword due', function () {
    $keyword = track($this->site->id, 'plumber');
    app(BudgetGuard::class)->save(['caps' => ['rank_tracking' => 0]]);
    Http::fake();

    $posted = app(RankTracker::class)->postDue();

    expect($posted['refused'])->toBeTrue()
        ->and($posted['posted'])->toBe(0)
        ->and($posted['error'])->toContain('cap')
        ->and($keyword->fresh()->last_posted_on)->toBeNull();
    Http::assertNothingSent();
});

it('runs from the command and fails loudly without a DataForSEO login', function () {
    track($this->site->id, 'plumber');
    Http::fake(['*/task_post' => Http::response(DataForSeoFixtures::created(['id-1'], 0.0015))]);
    $this->artisan('clockwork:seo-track-ranks')->expectsOutputToContain('queued 1')->assertSuccessful();

    config(['clockwork.seo.dataforseo_login' => null, 'clockwork.seo.dataforseo_password' => null]);
    $this->artisan('clockwork:seo-track-ranks')->assertFailed();
});
