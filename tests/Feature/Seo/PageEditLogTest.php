<?php

use App\Models\ActionLog;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Seo\Models\AiPrompt;
use Modules\Seo\Models\KeywordMapRow;
use Modules\Seo\Models\RankCheck;
use Modules\Seo\Models\RankedKeyword;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Models\TrackedKeyword;

/**
 * Changes made on the back-end pages are logged the way Claude's tools log
 * theirs: who, and the before and after values, on a row with no site.
 */
beforeEach(function () {
    mockIssueCounterZero();
    Http::preventStrayRequests();
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->admin = User::factory()->admin()->create();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    $this->profile = SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true, 'report_notes' => 'Old note']);
});

function seoLogs(string $type): Illuminate\Support\Collection
{
    return ActionLog::query()->where('action_type', $type)->orderBy('id')->get();
}

it('logs tracked keywords added, paused, resumed and deleted', function () {
    $this->actingAs($this->user)->post(route('seo.sites.rankings.store', $this->site), ['keywords' => "emergency plumber\nwater heater repair"]);

    $added = seoLogs('seo_tracked_keywords_updated')->sole();
    expect($added->actor)->toBe("user:{$this->user->id}")
        ->and($added->site_id)->toBeNull()
        ->and($added->target)->toBe('acme.com')
        ->and($added->details['via'])->toBe('web')
        ->and($added->details['changes']['tracked_keywords']['before'])->toBe(['active' => 0])
        ->and($added->details['changes']['tracked_keywords']['after'])->toMatchArray(['active' => 2, 'action' => 'add', 'keywords' => ['emergency plumber', 'water heater repair']]);

    // Nothing new: nothing logged.
    $this->actingAs($this->user)->post(route('seo.sites.rankings.store', $this->site), ['keywords' => 'emergency plumber']);
    expect(seoLogs('seo_tracked_keywords_updated'))->toHaveCount(1);

    $keyword = TrackedKeyword::query()->where('keyword', 'emergency plumber')->sole();
    RankCheck::create(['tracked_keyword_id' => $keyword->id, 'site_id' => $this->site->id, 'checked_on' => '2026-11-10', 'position' => 4, 'depth' => 30]);

    $this->actingAs($this->user)->patch(route('seo.sites.rankings.toggle', [$this->site, $keyword]));
    $this->actingAs($this->user)->patch(route('seo.sites.rankings.toggle', [$this->site, $keyword]));
    $this->actingAs($this->user)->delete(route('seo.sites.rankings.destroy', [$this->site, $keyword]));

    $logs = seoLogs('seo_tracked_keywords_updated');
    expect($logs)->toHaveCount(4)
        ->and($logs[1]->details['changes']['tracked_keywords']['after'])->toMatchArray(['is_active' => false, 'action' => 'pause', 'keyword' => 'emergency plumber', 'device' => 'desktop'])
        ->and($logs[2]->details['changes']['tracked_keywords']['after']['action'])->toBe('resume')
        ->and($logs[3]->details['changes']['tracked_keywords']['before'])->toMatchArray(['keyword' => 'emergency plumber', 'is_active' => true, 'stored_checks' => 1])
        ->and($logs[3]->details['changes']['tracked_keywords']['after'])->toBe(['action' => 'delete'])
        ->and($logs[3]->summary)->toContain('emergency plumber');
});

it('logs a competitor change once, and not a save that changes nothing', function () {
    $this->actingAs($this->user)->put(route('seo.sites.competitors', $this->site), ['competitors' => "rival.com\nother.com"]);
    $this->actingAs($this->user)->put(route('seo.sites.competitors', $this->site), ['competitors' => "rival.com\nother.com"]);

    $log = seoLogs('seo_profile_updated')->sole();
    expect($log->details['changes'])->toBe(['competitors' => ['before' => [], 'after' => ['rival.com', 'other.com']]])
        ->and($log->actor)->toBe("user:{$this->user->id}");
});

it('logs only the profile fields that changed', function () {
    $this->actingAs($this->user)->put(route('seo.sites.profile.update', $this->site), [
        'report_notes' => 'New note', 'is_enabled' => '1', 'compare_yoy' => '1',
    ])->assertSessionHasNoErrors();

    $log = seoLogs('seo_profile_updated')->sole();
    expect($log->details['changes'])->toEqual([
        'compare_yoy' => ['before' => false, 'after' => true],
        'report_notes' => ['before' => 'Old note', 'after' => 'New note'],
    ])->and($log->summary)->toContain('compare_yoy')->toContain('report_notes');

    // The same form again: nothing changed, nothing logged.
    $this->actingAs($this->user)->put(route('seo.sites.profile.update', $this->site), ['report_notes' => 'New note', 'is_enabled' => '1', 'compare_yoy' => '1']);
    expect(seoLogs('seo_profile_updated'))->toHaveCount(1);
});

it('logs a new client', function () {
    $this->actingAs($this->user)->post(route('seo.clients.store'), ['domain' => 'example.org', 'brand_terms' => 'New Client'])
        ->assertSessionHasNoErrors();

    $log = seoLogs('seo_profile_updated')->sole();
    expect($log->target)->toBe('example.org')
        ->and($log->summary)->toContain('Added example.org as an SEO client')
        ->and($log->details['changes']['brand_terms'])->toBe(['before' => null, 'after' => 'New Client'])
        ->and($log->details['changes']['is_enabled'])->toBe(['before' => null, 'after' => true]);
});

it('logs keyword map rows added and edited, with only the edited fields', function () {
    $this->actingAs($this->user)->post(route('seo.sites.keyword-map.version', $this->site), ['name' => 'November'])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('seo.sites.keyword-map.rows.store', $this->site), [
        'page_name' => 'Drain cleaning', 'url' => '/drain-cleaning', 'primary_keyword' => 'drain cleaning', 'ship_status' => 'not_started',
    ])->assertSessionHasNoErrors();

    $row = KeywordMapRow::query()->sole();
    $form = [
        'page_name' => 'Drain cleaning', 'url' => '/drain-cleaning', 'primary_keyword' => 'drain cleaning', 'ship_status' => 'not_started',
        'proposed_title' => 'Drain Cleaning in Austin', 'expected_updated_at' => $row->updated_at->toIso8601String(),
    ];
    $this->actingAs($this->user)->put(route('seo.sites.keyword-map.rows.update', [$this->site, $row]), $form)->assertSessionHasNoErrors();
    // Saved again with nothing different: no row.
    $this->actingAs($this->user)->put(route('seo.sites.keyword-map.rows.update', [$this->site, $row]), ['expected_updated_at' => $row->fresh()->updated_at->toIso8601String()] + $form)->assertSessionHasNoErrors();

    $logs = seoLogs('seo_keyword_map_updated');
    expect($logs)->toHaveCount(3)
        ->and($logs[0]->details['changes']['keyword_map']['after'])->toMatchArray(['action' => 'new_version', 'name' => 'November'])
        ->and($logs[1]->details['changes']['rows']['before'])->toBe([$row->id => null])
        ->and($logs[1]->details['changes']['rows']['after'][$row->id])->toMatchArray(['page_name' => 'Drain cleaning', 'primary_keyword' => 'drain cleaning'])
        ->and($logs[2]->details['changes']['rows'])->toBe([
            'before' => [$row->id => ['proposed_title' => null]],
            'after' => [$row->id => ['proposed_title' => 'Drain Cleaning in Austin']],
        ])
        ->and($logs[2]->details['via'])->toBe('web');
});

it('logs AI prompts added, paused and deleted', function () {
    $this->actingAs($this->user)->post(route('seo.sites.ai-visibility.store', $this->site), ['prompts' => 'Who is the best plumber in Austin?']);
    $prompt = AiPrompt::query()->sole();
    $this->actingAs($this->user)->patch(route('seo.sites.ai-visibility.toggle', [$this->site, $prompt]));
    $this->actingAs($this->user)->delete(route('seo.sites.ai-visibility.destroy', [$this->site, $prompt]));

    $logs = seoLogs('seo_ai_prompts_updated');
    expect($logs)->toHaveCount(3)
        ->and($logs[0]->details['changes']['ai_prompts']['after'])->toMatchArray(['active' => 1, 'action' => 'add'])
        ->and($logs[1]->details['changes']['ai_prompts']['after'])->toMatchArray(['is_active' => false, 'action' => 'pause'])
        ->and($logs[2]->details['changes']['ai_prompts']['before'])->toMatchArray(['prompt' => $prompt->prompt, 'is_active' => false, 'stored_answers' => 0]);
});

it('logs typed AI impressions with the value they replaced', function () {
    $this->actingAs($this->user)->post(route('seo.sites.ai-impressions.store', $this->site), ['month' => '2026-10', 'status' => 'entered', 'ai_impressions' => 150]);
    $this->actingAs($this->user)->post(route('seo.sites.ai-impressions.store', $this->site), ['month' => '2026-10', 'status' => 'entered', 'ai_impressions' => 180]);

    $logs = seoLogs('seo_ai_impressions_recorded');
    expect($logs)->toHaveCount(2)
        ->and($logs[0]->details['changes']['ai_impressions']['before'])->toBeNull()
        ->and($logs[1]->details['changes']['ai_impressions']['before']['ai_impressions'])->toBe(150)
        ->and($logs[1]->details['changes']['ai_impressions']['after'])->toMatchArray(['ai_impressions' => 180, 'month' => '2026-10']);
});

it('logs spending caps and schedule settings an admin changes, with no site or domain', function () {
    $caps = array_fill_keys(array_keys(Modules\Seo\DataForSeo\Feature::ALL), 5);
    $budget = app(Modules\Seo\DataForSeo\BudgetGuard::class);
    $budget->save(['caps' => $caps, 'total_cap' => 100, 'person_allowance' => 10]);

    $this->actingAs($this->admin)->put(route('seo.dataforseo.budget'), ['caps' => ['research' => 12] + $caps, 'total_cap' => 150, 'person_allowance' => 10])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('seo.rank-tracking.update'), ['frequency' => 'daily', 'depth' => 100])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('seo.site-audit.update'), ['frequency' => 'weekly', 'max_pages' => 1000, 'javascript' => '1'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('seo.ai-visibility.update'), ['frequency' => 'daily', 'engines' => ['chat_gpt']])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('seo.settings.update'), ['final_after_days' => 7])->assertSessionHasNoErrors();
    // Unchanged: no row.
    $this->actingAs($this->admin)->put(route('seo.rank-tracking.update'), ['frequency' => 'daily', 'depth' => 100]);

    $logs = seoLogs('seo_settings_updated');
    expect($logs)->toHaveCount(5)
        ->and($logs->pluck('actor')->unique()->all())->toBe(["user:{$this->admin->id}"])
        ->and($logs->pluck('target')->unique()->all())->toBe([null])
        ->and($logs[0]->details['changes'])->toBe([
            'total_cap' => ['before' => 100.0, 'after' => 150.0],
            'cap_research' => ['before' => 5.0, 'after' => 12.0],
        ])
        ->and($logs[1]->details['changes']['rank_tracking']['after'])->toBe(['frequency' => 'daily', 'depth' => 100])
        ->and($logs[2]->details['changes']['site_audit']['after'])->toBe(['frequency' => 'weekly', 'max_pages' => 1000, 'javascript' => true])
        ->and($logs[3]->details['changes']['ai_visibility']['after'])->toBe(['frequency' => 'daily', 'engines' => ['chat_gpt']])
        ->and($logs[4]->details['changes'])->toHaveKey('final_after_days');
});

describe('keyword gap page', function () {
    beforeEach(function () {
        $this->profile->forceFill(['competitors' => ['rival.com', 'nolist.com']])->save();
        $ranked = fn (string $domain, string $keyword, int $position, ?int $volume) => RankedKeyword::create(['domain' => $domain, 'market_key' => '2840-en', 'provider' => 'dataforseo', 'keyword' => $keyword, 'position' => $position, 'volume' => $volume, 'url' => "https://{$domain}/page", 'fetched_at' => now()]);
        $ranked('rival.com', 'emergency plumber', 4, 2000);
        $ranked('rival.com', 'drain cleaning', 2, 900);
        $ranked('rival.com', 'water heater repair', 3, 1500);
        $ranked('acme.com', 'water heater repair', 9, 1500);
        $ranked('acme.com', 'acme plumbing', 1, 300);
    });

    it('lists any group in full from stored lists, with every competitor column', function () {
        Http::fake();

        $this->actingAs($this->user)->get(route('seo.sites.research.gap', $this->site))
            ->assertOk()
            ->assertSee('data-gap-keyword="emergency plumber"', false)
            ->assertSee('data-gap-keyword="drain cleaning"', false)
            ->assertDontSee('data-gap-keyword="water heater repair"', false)
            ->assertSee('Left out, no list bought: nolist.com');

        $this->actingAs($this->user)->get(route('seo.sites.research.gap', [$this->site, 'group' => 'weak']))
            ->assertOk()
            ->assertSee('data-gap-keyword="water heater repair"', false)
            ->assertDontSee('data-gap-keyword="emergency plumber"', false);

        $this->actingAs($this->user)->get(route('seo.sites.research.gap', [$this->site, 'group' => 'unique']))
            ->assertOk()->assertSee('data-gap-keyword="acme plumbing"', false);

        Http::assertNothingSent();
    });

    it('filters by text and volume, and refuses an unknown group', function () {
        $this->actingAs($this->user)->get(route('seo.sites.research.gap', [$this->site, 'group' => 'missing', 'min_volume' => 1000]))
            ->assertSee('data-gap-keyword="emergency plumber"', false)
            ->assertDontSee('data-gap-keyword="drain cleaning"', false);

        $this->actingAs($this->user)->get(route('seo.sites.research.gap', [$this->site, 'contains' => 'Drain']))
            ->assertSee('data-gap-keyword="drain cleaning"', false)
            ->assertDontSee('data-gap-keyword="emergency plumber"', false);

        $this->actingAs($this->user)->get(route('seo.sites.research.gap', [$this->site, 'group' => 'nonsense']))->assertSessionHasErrors('group');
    });

    it('links every group from the Research page and says so when no competitor is named', function () {
        $html = $this->actingAs($this->user)->get(route('seo.sites.research', $this->site))->assertOk()->getContent();
        foreach (['missing', 'untapped', 'weak', 'strong', 'shared', 'unique'] as $group) {
            expect($html)->toContain('data-gap-group="'.$group.'"');
        }

        $this->profile->forceFill(['competitors' => null])->save();
        $this->actingAs($this->user)->get(route('seo.sites.research.gap', $this->site))->assertOk()->assertSee('Name competitors');
    });
});
