<?php

use App\Models\ActionLog;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Seo\Mcp\Tools\SeoRankHistory;
use Modules\Seo\Mcp\Tools\SeoRankings;
use Modules\Seo\Mcp\Tools\SeoTrackedKeywordsUpdate;
use Modules\Seo\Models\RankCheck;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Models\TrackedKeyword;
use Modules\Seo\Ranks\TrackedKeywords;

function rankTool(User $user, string $tool, array $arguments): array
{
    $response = ClockworkServer::actingAs($user)->tool($tool, $arguments)->assertOk();

    return (fn () => $this->response->toArray()['result']['structuredContent'])->call($response);
}

function storedCheck(TrackedKeyword $keyword, string $date, ?int $position, array $extra = []): void
{
    RankCheck::create($extra + [
        'tracked_keyword_id' => $keyword->id, 'site_id' => $keyword->site_id, 'checked_on' => $date,
        'position' => $position, 'depth' => 30, 'url' => $position ? 'https://acme.com/services' : null,
    ]);
}

beforeEach(function () {
    // No rank tool may call out: they read stored checks or change a list.
    Http::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    $this->profile = SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true, 'competitors' => ['rival.com']]);
});

afterEach(fn () => Http::assertNothingSent());

describe('seo_rankings', function () {
    it('returns positions with nulls kept apart from numbers, best first', function () {
        app(TrackedKeywords::class)->add($this->site, ['plumber', 'drain cleaning', 'water heater', 'unchecked one']);
        $k = TrackedKeyword::query()->get()->keyBy('keyword');
        storedCheck($k['plumber'], '2026-11-04', 9);
        storedCheck($k['plumber'], '2026-11-11', 2, ['competitors' => ['rival.com' => 5], 'has_ai_overview' => true, 'cited_in_ai_overview' => true]);
        storedCheck($k['drain cleaning'], '2026-11-11', null);
        storedCheck($k['water heater'], '2026-11-04', 6);
        storedCheck($k['water heater'], '2026-11-11', 14);

        $content = rankTool($this->user, SeoRankings::class, ['site' => 'acme.com']);
        $columns = array_flip($content['columns']);
        $rows = collect($content['rows'])->keyBy(fn (array $r) => $r[$columns['keyword']]);

        expect($content['summary'])->toContain('3 of 4 tracked keywords checked')->toContain('1 in the top 3')->toContain('not in the top 30')
            ->and($content['totals'])->toMatchArray(['active' => 4, 'checked' => 3, 'top3' => 1, 'top10' => 1, 'not_found' => 1, 'improved' => 1, 'declined' => 1])
            ->and(array_column($content['rows'], $columns['keyword']))->toBe(['plumber', 'water heater', 'drain cleaning', 'unchecked one'])
            ->and($rows['plumber'][$columns['position']])->toBe(2)
            ->and($rows['plumber'][$columns['change']])->toBe(7)
            ->and($rows['plumber'][$columns['competitor:rival.com']])->toBe(5)
            ->and($rows['plumber'][$columns['cited_in_ai_overview']])->toBeTrue()
            ->and($rows['drain cleaning'][$columns['position']])->toBeNull()
            ->and($rows['drain cleaning'][$columns['checked_on']])->toBe('2026-11-11')
            ->and($rows['unchecked one'][$columns['checked_on']])->toBeNull()
            ->and(array_column($content['meta']['caveats'], 'code'))->toContain('not_found_is_not_zero', 'one_check_one_place')
            ->and($content['meta']['source'])->toBe('stored');

        $declined = rankTool($this->user, SeoRankings::class, ['site' => 'acme.com', 'only' => 'declined']);
        expect(array_column($declined['rows'], $columns['keyword']))->toBe(['water heater'])
            ->and($declined['rows_matching'])->toBe(1);

        $notFound = rankTool($this->user, SeoRankings::class, ['site' => 'acme.com', 'only' => 'not_found']);
        expect(array_column($notFound['rows'], $columns['keyword']))->toBe(['drain cleaning']);
    });

    it('says so when nothing is tracked, and names an unknown site', function () {
        $content = rankTool($this->user, SeoRankings::class, ['site' => (string) $this->site->id]);
        expect(array_column($content['meta']['caveats'], 'code'))->toContain('nothing_tracked')
            ->and($content['rows'])->toBe([]);

        ClockworkServer::actingAs($this->user)->tool(SeoRankings::class, ['site' => 'nope.com'])->assertHasErrors();
    });
});

describe('seo_rank_history', function () {
    it('returns each stored check in date order and names keywords that are not tracked', function () {
        app(TrackedKeywords::class)->add($this->site, ['plumber'], ['desktop', 'mobile']);
        $desktop = TrackedKeyword::query()->where('device', 'desktop')->sole();
        storedCheck($desktop, '2026-08-01', 3);
        storedCheck($desktop, '2026-10-28', 12, ['competitors' => ['rival.com' => 4]]);
        storedCheck($desktop, '2026-11-04', null);
        storedCheck($desktop, '2026-11-11', 7);

        $content = rankTool($this->user, SeoRankHistory::class, ['site' => 'acme.com', 'keywords' => ['Plumber', 'not tracked kw'], 'device' => 'desktop']);

        expect($content['keywords'])->toHaveCount(1)
            ->and($content['keywords'][0])->toMatchArray(['keyword' => 'plumber', 'device' => 'desktop', 'checks' => 3, 'best_position' => 7, 'worst_position' => 12])
            ->and(array_column($content['keywords'][0]['rows'], 0))->toBe(['2026-10-28', '2026-11-04', '2026-11-11'])
            ->and($content['keywords'][0]['rows'][0])->toBe(['2026-10-28', 12, 'https://acme.com/services', false, false, 4])
            ->and($content['keywords'][0]['rows'][1][1])->toBeNull()
            ->and($content['not_tracked'])->toBe(['not tracked kw'])
            ->and($content['columns'])->toContain('competitor:rival.com');

        // By id, with a wider range.
        $all = rankTool($this->user, SeoRankHistory::class, ['site' => 'acme.com', 'keywords' => [(string) $desktop->id], 'start' => '2026-07-01']);
        expect($all['keywords'][0]['checks'])->toBe(4);
    });

    it('never returns another site\'s keyword by id', function () {
        $other = Site::factory()->create(['domain' => 'other.com']);
        SiteProfile::create(['site_id' => $other->id, 'is_enabled' => true]);
        app(TrackedKeywords::class)->add($other, ['secret keyword']);
        $foreign = TrackedKeyword::query()->where('site_id', $other->id)->sole();
        storedCheck($foreign, '2026-11-11', 1);

        $content = rankTool($this->user, SeoRankHistory::class, ['site' => 'acme.com', 'keywords' => [(string) $foreign->id]]);
        expect($content['keywords'])->toBe([])
            ->and(json_encode($content))->not->toContain('secret keyword');
    });
});

describe('seo_tracked_keywords_update', function () {
    it('adds keywords, reports the monthly cost and logs who did it', function () {
        $content = rankTool($this->user, SeoTrackedKeywordsUpdate::class, [
            'site' => 'acme.com', 'action' => 'add',
            'keywords' => ['Emergency Plumber', 'drain cleaning', 'site:acme.com x'], 'devices' => ['desktop', 'mobile'],
        ]);

        expect($content)->toMatchArray(['action' => 'add', 'changed' => 4, 'already' => 0, 'active_keyword_rows' => 4])
            ->and($content['rejected'][0]['keyword'])->toBe('site:acme.com x')
            // Weekly at the top 30: 4 rows x 30.4/7 checks x $0.0015.
            ->and($content['monthly_cost_usd'])->toBe(0.03)
            ->and($content['summary'])->toContain('Now tracking 4 keyword rows')->toContain('a month')
            ->and(TrackedKeyword::query()->where('added_by', $this->user->id)->count())->toBe(4);

        $log = ActionLog::query()->where('action_type', 'seo_tracked_keywords_updated')->sole();
        expect($log->site_id)->toBeNull()
            ->and($log->actor)->toBe("user:{$this->user->id}")
            ->and($log->target)->toBe('acme.com')
            ->and($log->details['changes']['tracked_keywords']['before'])->toBe(['active' => 0]);
    });

    it('pauses and resumes without touching history, and cannot delete', function () {
        app(TrackedKeywords::class)->add($this->site, ['plumber'], ['desktop', 'mobile']);
        storedCheck(TrackedKeyword::query()->where('device', 'desktop')->sole(), '2026-11-11', 4);

        $paused = rankTool($this->user, SeoTrackedKeywordsUpdate::class, ['site' => 'acme.com', 'action' => 'pause', 'keywords' => ['plumber', 'unknown'], 'devices' => ['mobile']]);
        expect($paused)->toMatchArray(['changed' => 1, 'active_keyword_rows' => 1])
            ->and($paused['rejected'][0])->toBe(['keyword' => 'unknown', 'reason' => 'not tracked for this site'])
            ->and($paused['monthly_cost_change_usd'])->toBeLessThanOrEqual(0.0)
            ->and(RankCheck::count())->toBe(1);

        $resumed = rankTool($this->user, SeoTrackedKeywordsUpdate::class, ['site' => 'acme.com', 'action' => 'resume', 'keywords' => ['plumber']]);
        expect($resumed)->toMatchArray(['changed' => 1, 'already' => 1, 'active_keyword_rows' => 2]);

        ClockworkServer::actingAs($this->user)->tool(SeoTrackedKeywordsUpdate::class, ['site' => 'acme.com', 'action' => 'delete', 'keywords' => ['plumber']])->assertHasErrors();
        expect(TrackedKeyword::count())->toBe(2);
    });
});
