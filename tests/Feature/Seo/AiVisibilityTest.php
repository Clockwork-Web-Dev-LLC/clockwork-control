<?php

use App\Models\ActionLog;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Seo\Ai\AiAnswerParser;
use Modules\Seo\Ai\AiPrompts;
use Modules\Seo\Ai\AiVisibilityReport;
use Modules\Seo\Ai\AiVisibilitySettings;
use Modules\Seo\Ai\AiVisibilityTracker;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\Mcp\Tools\SeoAiPromptsUpdate;
use Modules\Seo\Mcp\Tools\SeoAiVisibility;
use Modules\Seo\Models\AiCheck;
use Modules\Seo\Models\AiPrompt;
use Modules\Seo\Models\ApiUsage;
use Modules\Seo\Models\SiteProfile;
use Tests\Fixtures\DataForSeoFixtures;

function chatAnswer(string $markdown, array $sourceDomains = []): array
{
    return [
        'keyword' => 'q', 'location_code' => 2840, 'language_code' => 'en', 'model' => 'gpt', 'markdown' => $markdown,
        'sources' => array_map(fn (string $d) => ['type' => 'source', 'title' => 'T', 'domain' => $d, 'url' => "https://{$d}/page"], $sourceDomains),
        'items' => [['type' => 'chat_gpt_text', 'markdown' => $markdown]],
    ];
}

function aiModeAnswer(string $markdown, array $referenceUrls = []): array
{
    return ['keyword' => 'q', 'type' => 'ai_mode', 'item_types' => ['ai_overview'], 'items' => [[
        'type' => 'ai_overview', 'rank_group' => 1, 'markdown' => null,
        'items' => [['type' => 'ai_overview_element', 'markdown' => $markdown]],
        'references' => array_map(fn (string $u) => ['type' => 'ai_overview_reference', 'url' => $u, 'domain' => null], $referenceUrls),
    ]]];
}

function aiTool(User $user, string $tool, array $arguments): array
{
    $response = ClockworkServer::actingAs($user)->tool($tool, $arguments)->assertOk();

    return (fn () => $this->response->toArray()['result']['structuredContent'])->call($response);
}

function aiCheck(AiPrompt $prompt, string $engine, string $date, bool $mentioned, bool $cited, array $competitors = []): void
{
    AiCheck::create(['prompt_id' => $prompt->id, 'site_id' => $prompt->site_id, 'engine' => $engine, 'checked_on' => $date, 'mentioned' => $mentioned, 'cited' => $cited, 'answered' => true, 'excerpt' => 'An answer.', 'sources' => ['yelp.com'], 'competitors' => $competitors]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['clockwork.seo.dataforseo_login' => 'api-login', 'clockwork.seo.dataforseo_password' => 'api-password-canary']);
    // A Wednesday, so "this week" and "last week" are unambiguous.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'www.acmeplumbing.com']);
    SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true, 'brand_terms' => 'Acme Plumbing, Acme', 'competitors' => ['rivalrooter.com', 'pro.com']]);
});

describe('reading an answer', function () {
    it('tells mentioned from cited, for the client and its competitors', function () {
        $parsed = AiAnswerParser::parse(
            chatAnswer('For emergencies, **Acme Plumbing** and RivalRooter are often recommended. Pros say to check reviews.', ['yelp.com', 'www.rivalrooter.com', 'blog.acmeplumbing.com']),
            'www.acmeplumbing.com', ['Acme Plumbing'], ['rivalrooter.com', 'pro.com'],
        );

        expect($parsed)->toMatchArray(['answered' => true, 'mentioned' => true, 'cited' => true])
            ->and($parsed['sources'])->toBe(['yelp.com', 'rivalrooter.com', 'blog.acmeplumbing.com'])
            ->and($parsed['competitors']['rivalrooter.com'])->toBe(['mentioned' => true, 'cited' => true])
            // "pro" is too short and ordinary to count as naming pro.com.
            ->and($parsed['competitors']['pro.com'])->toBe(['mentioned' => false, 'cited' => false]);
    });

    it('matches whole words only and reads nested answers and bare URLs', function () {
        $none = AiAnswerParser::parse(chatAnswer('The pacmen of Acmeville recommend Bob.', ['notacmeplumbing.com']), 'acmeplumbing.com', ['Acme']);
        expect($none)->toMatchArray(['mentioned' => false, 'cited' => false]);

        $nested = AiAnswerParser::parse(aiModeAnswer('Try Acme for same-day service.', ['https://www.acmeplumbing.com/emergency', 'https://yelp.com/x']), 'acmeplumbing.com', ['Acme']);
        expect($nested)->toMatchArray(['answered' => true, 'mentioned' => true, 'cited' => true])
            ->and($nested['sources'])->toBe(['acmeplumbing.com', 'yelp.com']);

        $empty = AiAnswerParser::parse(['keyword' => 'q', 'items' => null], 'acmeplumbing.com', ['Acme']);
        expect($empty)->toMatchArray(['answered' => false, 'mentioned' => false, 'cited' => false, 'excerpt' => null]);
    });
});

describe('prompts', function () {
    it('adds prompts once, rejects junk and stops at the limit', function () {
        $result = app(AiPrompts::class)->add($this->site, ['Who is the best emergency plumber in Austin?', 'who is the  best emergency plumber in austin?', 'hi', str_repeat('x', 600)], $this->user->id);

        expect($result['added'])->toBe(1)
            ->and(array_column($result['rejected'], 'reason'))->toBe(['too short to be a question', 'longer than 500 characters'])
            ->and(app(AiPrompts::class)->add($this->site, ['Who is the best emergency plumber in Austin?'])['existing'])->toBe(1);

        for ($i = 0; $i < 49; $i++) {
            AiPrompt::create(['site_id' => $this->site->id, 'prompt' => "filler prompt number {$i}", 'prompt_hash' => AiPrompt::hash("filler prompt number {$i}")]);
        }
        $full = app(AiPrompts::class)->add($this->site, ['One more question for the list?']);
        expect($full['added'])->toBe(0)->and($full['rejected'][0]['reason'])->toContain('already has 50');
    });

    it('estimates the monthly cost from engines and frequency', function () {
        $settings = app(AiVisibilitySettings::class);
        expect($settings->costPerRound())->toBe(0.0036)
            // 25 prompts weekly on three engines.
            ->and($settings->monthlyEstimate(25))->toBe(0.39);

        $settings->save('daily', ['chat_gpt', 'not_an_engine']);
        expect($settings->engines())->toBe(['chat_gpt'])->and($settings->monthlyEstimate(25))->toBe(0.91);

        $settings->save('off', ['chat_gpt']);
        expect($settings->monthlyEstimate(25))->toBe(0.0);
    });
});

describe('tracking', function () {
    it('queues each due prompt on each engine, once per interval', function () {
        app(AiPrompts::class)->add($this->site, ['Who is the best emergency plumber in Austin?', 'How much does drain cleaning cost?']);
        AiPrompt::query()->where('prompt', 'like', 'How much%')->update(['is_active' => false]);
        Http::fake(['*/task_post' => fn () => Http::response(DataForSeoFixtures::created([uniqid('t')], 0.0012))]);

        $posted = app(AiVisibilityTracker::class)->postDue();

        expect($posted)->toMatchArray(['due' => 3, 'posted' => 3, 'failed' => 0])
            ->and(ApiUsage::query()->pluck('feature')->unique()->all())->toBe(['ai_visibility'])
            ->and(array_keys(AiPrompt::query()->where('is_active', true)->sole()->last_posted))->toEqualCanonicalizing(['chat_gpt', 'gemini', 'google_ai_mode']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/ai_optimization/chat_gpt/llm_scraper/task_post')
            && $r->data()[0]['keyword'] === 'Who is the best emergency plumber in Austin?' && $r->data()[0]['location_code'] === 2840 && $r->data()[0]['priority'] === 1);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/serp/google/ai_mode/task_post'));

        // The same day, and six days on: nothing due. A week on: due again.
        expect(app(AiVisibilityTracker::class)->postDue()['due'])->toBe(0);
        $this->travel(6)->days();
        expect(app(AiVisibilityTracker::class)->postDue()['due'])->toBe(0);
        $this->travel(1)->days();
        expect(app(AiVisibilityTracker::class)->postDue()['due'])->toBe(3);
    });

    it('stores an answer when it comes back', function () {
        app(AiPrompts::class)->add($this->site, ['Who is the best emergency plumber in Austin?']);
        app(AiVisibilitySettings::class)->save('weekly', ['chat_gpt']);
        Http::fake(['*/chat_gpt/llm_scraper/task_post' => Http::response(DataForSeoFixtures::created(['ai-1'], 0.0012))]);
        app(AiVisibilityTracker::class)->postDue();

        Http::fake(['*/chat_gpt/llm_scraper/task_get/advanced/ai-1' => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task('ai-1', [
            chatAnswer('Acme Plumbing is a solid choice in Austin.', ['yelp.com', 'rivalrooter.com']),
        ])]))]);

        expect(app(AiVisibilityTracker::class)->collect())->toBe(['collected' => 1, 'pending' => 0, 'failed' => 0]);

        $check = AiCheck::query()->sole();
        expect($check)->toMatchArray(['engine' => 'chat_gpt', 'mentioned' => true, 'cited' => false, 'answered' => true])
            ->and($check->checked_on->toDateString())->toBe('2026-11-11')
            ->and($check->sources)->toBe(['yelp.com', 'rivalrooter.com'])
            ->and($check->competitors['rivalrooter.com'])->toBe(['mentioned' => false, 'cited' => true]);
    });

    it('stops at the spending cap, does nothing when switched off, and skips disabled clients', function () {
        app(AiPrompts::class)->add($this->site, ['Who is the best emergency plumber in Austin?']);
        Http::fake();

        app(BudgetGuard::class)->save(['caps' => ['ai_visibility' => 0]]);
        $refused = app(AiVisibilityTracker::class)->postDue();
        expect($refused['refused'])->toBeTrue()->and(AiPrompt::query()->sole()->last_posted)->toBeNull();

        app(BudgetGuard::class)->save(['caps' => ['ai_visibility' => 20]]);
        app(AiVisibilitySettings::class)->save('off', ['chat_gpt']);
        expect(app(AiVisibilityTracker::class)->postDue()['due'])->toBe(0);

        app(AiVisibilitySettings::class)->save('weekly', ['chat_gpt']);
        SiteProfile::query()->update(['is_enabled' => false]);
        expect(app(AiVisibilityTracker::class)->postDue()['due'])->toBe(0);
        Http::assertNothingSent();

        config(['clockwork.seo.dataforseo_login' => null, 'clockwork.seo.dataforseo_password' => null]);
        $this->artisan('clockwork:seo-track-ai')->assertFailed();
    });
});

describe('report and tools', function () {
    beforeEach(function () {
        Http::fake();
        app(AiPrompts::class)->add($this->site, ['Best emergency plumber in Austin?', 'How much does drain cleaning cost?', 'Who fixes water heaters near me?']);
        [$a, $b, $c] = AiPrompt::query()->orderBy('id')->get()->all();
        $this->a = $a;
        // Last week, then this week.
        aiCheck($a, 'chat_gpt', '2026-11-04', false, false);
        aiCheck($a, 'chat_gpt', '2026-11-11', true, true, ['rivalrooter.com' => ['mentioned' => true, 'cited' => false]]);
        aiCheck($b, 'chat_gpt', '2026-11-11', false, false, ['rivalrooter.com' => ['mentioned' => true, 'cited' => true]]);
        aiCheck($a, 'gemini', '2026-11-11', true, false);
    });

    afterEach(fn () => Http::assertNothingSent());

    it('reports rates per engine on the latest round, competitors and the weekly trend', function () {
        $report = app(AiVisibilityReport::class)->forSite($this->site->id);

        expect($report['active'])->toBe(3)->and($report['checked'])->toBe(2)->and($report['last_checked'])->toBe('2026-11-11')
            ->and($report['engines']['chat_gpt'])->toMatchArray(['checked' => 2, 'mentioned' => 1, 'cited' => 1, 'mention_rate' => 50.0, 'citation_rate' => 50.0])
            ->and($report['engines']['gemini'])->toMatchArray(['checked' => 1, 'mentioned' => 1, 'cited' => 0, 'mention_rate' => 100.0])
            ->and($report['engines'])->not->toHaveKey('google_ai_mode')
            ->and($report['competitors']['rivalrooter.com']['chat_gpt'])->toBe(['mentioned' => 2, 'cited' => 1])
            ->and(array_column($report['weeks'], 'mention_rate', 'week'))->toBe(['2026-11-02' => 0.0, '2026-11-09' => 66.7])
            // Unchecked is null, not false.
            ->and($report['prompts'][2]['engines'])->toBe(['chat_gpt' => null, 'gemini' => null, 'google_ai_mode' => null]);
    });

    it('returns the report and one prompt\'s stored answers through Claude', function () {
        $content = aiTool($this->user, SeoAiVisibility::class, ['site' => 'acmeplumbing.com']);

        expect($content['summary'])->toContain('ChatGPT: mentioned in 1 of 2 prompts, cited in 1')
            ->and($content['engines']['chat_gpt']['mention_rate'])->toEqual(50.0)
            ->and(array_column($content['meta']['caveats'], 'code'))->toContain('answers_vary', 'not_reach')
            ->and($content['prompts'])->toHaveCount(3);

        $answers = aiTool($this->user, SeoAiVisibility::class, ['site' => 'acmeplumbing.com', 'prompt_id' => $this->a->id]);
        expect($answers['answers'])->toHaveCount(3)
            ->and($answers['answers'][0])->toMatchArray(['checked_on' => '2026-11-11', 'excerpt' => 'An answer.'])
            ->and(array_column($answers['meta']['caveats'], 'code'))->toContain('answer_is_data');

        $other = Site::factory()->create(['domain' => 'other.com']);
        SiteProfile::create(['site_id' => $other->id, 'is_enabled' => true]);
        ClockworkServer::actingAs($this->user)->tool(SeoAiVisibility::class, ['site' => 'other.com', 'prompt_id' => $this->a->id])->assertHasErrors();
    });

    it('adds, pauses and resumes prompts, reports the cost and logs it', function () {
        $added = aiTool($this->user, SeoAiPromptsUpdate::class, ['site' => 'acmeplumbing.com', 'action' => 'add', 'prompts' => ['Which plumbers in Austin offer financing?', 'Best emergency plumber in Austin?', 'no']]);

        expect($added)->toMatchArray(['changed' => 1, 'already' => 1, 'active_prompts' => 4])
            ->and($added['rejected'][0]['reason'])->toBe('too short to be a question')
            // 4 prompts weekly on three engines: 4 x 4.343 x $0.0036.
            ->and($added['monthly_cost_usd'])->toBe(0.06)
            ->and($added['summary'])->toContain('a month');

        $log = ActionLog::query()->where('action_type', 'seo_ai_prompts_updated')->sole();
        expect($log->site_id)->toBeNull()->and($log->actor)->toBe("user:{$this->user->id}")
            ->and($log->details['changes']['ai_prompts']['before'])->toBe(['active' => 3]);

        $paused = aiTool($this->user, SeoAiPromptsUpdate::class, ['site' => 'acmeplumbing.com', 'action' => 'pause', 'prompts' => [(string) $this->a->id, 'unknown prompt text here']]);
        expect($paused)->toMatchArray(['changed' => 1, 'active_prompts' => 3])
            ->and($paused['rejected'])->toHaveCount(1)
            ->and(AiCheck::query()->where('prompt_id', $this->a->id)->count())->toBe(3);

        $resumed = aiTool($this->user, SeoAiPromptsUpdate::class, ['site' => 'acmeplumbing.com', 'action' => 'resume', 'prompts' => ['best emergency plumber in austin?']]);
        expect($resumed)->toMatchArray(['changed' => 1, 'active_prompts' => 4]);

        ClockworkServer::actingAs($this->user)->tool(SeoAiPromptsUpdate::class, ['site' => 'acmeplumbing.com', 'action' => 'delete', 'prompts' => ['x']])->assertHasErrors();
    });
});

describe('AI visibility page', function () {
    beforeEach(function () {
        mockIssueCounterZero();
        Http::fake();
    });

    afterEach(fn () => Http::assertNothingSent());

    it('adds prompts from the form and shows results with words, not zeros', function () {
        $this->actingAs($this->user)->get(route('seo.sites.ai-visibility', $this->site))
            ->assertOk()->assertSee('No prompts yet')->assertSee('data-site-tabs', false)->assertDontSee('data-no-brand-terms', false);

        $this->actingAs($this->user)->post(route('seo.sites.ai-visibility.store', $this->site), ['prompts' => "Best emergency plumber in Austin?\n\nhi\nHow much does drain cleaning cost?"])
            ->assertRedirect(route('seo.sites.ai-visibility', $this->site))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, '2 prompts added'))
            ->assertSessionHas('warning', fn (string $m) => str_contains($m, 'too short'));

        [$a, $b] = AiPrompt::query()->orderBy('id')->get()->all();
        aiCheck($a, 'chat_gpt', '2026-11-11', true, true, ['rivalrooter.com' => ['mentioned' => true, 'cited' => false]]);
        aiCheck($a, 'gemini', '2026-11-11', false, false);

        $html = $this->actingAs($this->user)->get(route('seo.sites.ai-visibility', $this->site))
            ->assertOk()
            ->assertSee('Mentioned + cited')->assertSee('Not there')
            ->assertSee('1 <span', false)->assertSee('of 1 prompts mentioned')
            ->assertSee('rivalrooter.com')
            ->assertSee('one check is a sample')
            ->getContent();

        // The prompt with no answers shows a dash in every engine column.
        $row = substr($html, strpos($html, 'data-prompt-row="'.$b->id.'"'));
        $row = substr($row, 0, strpos($row, '</tr>'));
        expect(substr_count($row, '—'))->toBe(3);

        $this->actingAs($this->user)->get(route('seo.sites.ai-visibility', [$this->site, 'prompt' => $a->id]))
            ->assertOk()->assertSee('An answer.')->assertSee('Sources: yelp.com');
    });

    it('warns when the client has no brand terms', function () {
        SiteProfile::query()->update(['brand_terms' => null]);
        $this->actingAs($this->user)->get(route('seo.sites.ai-visibility', $this->site))->assertOk()->assertSee('No brand terms are set');
    });

    it('pauses, resumes and deletes a prompt, and not through another site', function () {
        app(AiPrompts::class)->add($this->site, ['Best emergency plumber in Austin?']);
        $prompt = AiPrompt::query()->sole();
        aiCheck($prompt, 'chat_gpt', '2026-11-11', true, false);
        $other = Site::factory()->create(['domain' => 'other.com']);

        $this->actingAs($this->user)->patch(route('seo.sites.ai-visibility.toggle', [$other, $prompt]))->assertNotFound();
        $this->actingAs($this->user)->delete(route('seo.sites.ai-visibility.destroy', [$other, $prompt]))->assertNotFound();

        $this->actingAs($this->user)->patch(route('seo.sites.ai-visibility.toggle', [$this->site, $prompt]))->assertRedirect();
        expect($prompt->fresh()->is_active)->toBeFalse()->and(AiCheck::count())->toBe(1);

        $this->actingAs($this->user)->delete(route('seo.sites.ai-visibility.destroy', [$this->site, $prompt]))->assertRedirect();
        expect(AiPrompt::count())->toBe(0)->and(AiCheck::count())->toBe(0);
    });

    it('lets only an admin choose the engines and frequency', function () {
        $this->actingAs($this->user)->put(route('seo.ai-visibility.update'), ['frequency' => 'daily', 'engines' => ['chat_gpt']])->assertForbidden();

        $admin = User::factory()->create();
        $this->actingAs($admin)->put(route('seo.ai-visibility.update'), ['frequency' => 'hourly', 'engines' => ['bing']])->assertSessionHasErrors(['frequency', 'engines.0']);
        $this->actingAs($admin)->put(route('seo.ai-visibility.update'), ['frequency' => 'daily', 'engines' => ['chat_gpt', 'gemini']])
            ->assertRedirect(route('seo.settings'))->assertSessionHas('status', fn (string $m) => str_contains($m, 'daily on ChatGPT, Gemini'));

        $settings = app(AiVisibilitySettings::class);
        expect($settings->frequency())->toBe('daily')->and($settings->engines())->toBe(['chat_gpt', 'gemini']);
        $this->actingAs($admin)->get(route('seo.settings'))->assertOk()->assertSee('Save AI visibility');
    });
});
