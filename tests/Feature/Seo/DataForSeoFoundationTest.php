<?php

use App\Models\Site;
use App\Models\User;
use App\Support\IssueCounter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\DataForSeo\TaskRunner;
use Modules\Seo\Models\ApiUsage;
use Modules\Seo\Models\ProviderTask;
use Tests\Fixtures\DataForSeoFixtures;

function spend(string $feature, float $cost, ?int $userId = null, ?string $at = null): void
{
    DB::table('seo_api_usage')->insert([
        'provider' => 'dataforseo', 'feature' => $feature, 'endpoint' => '/v3/test', 'user_id' => $userId,
        'tasks' => 1, 'cost_usd' => $cost, 'status' => 'ok', 'created_at' => $at ?? now(),
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['clockwork.seo.dataforseo_login' => 'api-login', 'clockwork.seo.dataforseo_password' => 'api-password-canary']);
    $this->client = app(DataForSeoClient::class);
});

describe('DataForSeoClient', function () {
    it('sends tasks as a JSON array with basic auth and writes the real cost to the ledger', function () {
        Http::fake([DataForSeoFixtures::API => Http::response(DataForSeoFixtures::envelope([
            DataForSeoFixtures::task('t1', [['keyword' => 'plumber']], 0.0132),
        ]))]);
        $site = Site::factory()->create();
        $user = User::factory()->operator()->create();

        $result = $this->client->post('/v3/dataforseo_labs/google/keyword_overview/live', [['keywords' => ['plumber']]], Feature::KEYWORDS, 0.02, $site->id, $user);

        expect($result->ok)->toBeTrue()
            ->and($result->cost)->toBe(0.0132)
            ->and($result->firstResult())->toBe([['keyword' => 'plumber']]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.dataforseo.com/v3/dataforseo_labs/google/keyword_overview/live'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('api-login:api-password-canary'))
            && $request->data() === [['keywords' => ['plumber']]]);

        $row = ApiUsage::query()->sole();
        // The cost DataForSEO reported, not the caller's estimate.
        expect($row->cost_usd)->toBe(0.0132)
            ->and($row->feature)->toBe('keywords')
            ->and($row->site_id)->toBe($site->id)
            ->and($row->user_id)->toBe($user->id)
            ->and($row->status)->toBe('ok')
            ->and(json_encode($row->toArray()))->not->toContain('canary');
    });

    it('spends nothing when a cap would be passed, and says why', function () {
        Http::fake();
        spend(Feature::KEYWORDS, 19.99);

        $result = $this->client->post('/v3/x', [['a' => 1]], Feature::KEYWORDS, 0.05);

        expect($result->ok)->toBeFalse()
            ->and($result->refused)->toBeTrue()
            ->and($result->error)->toContain('$19.99 of its $20.00 cap');
        Http::assertNothingSent();
        expect(ApiUsage::query()->where('status', 'refused')->count())->toBe(1);
    });

    it('turns DataForSEO\'s refusals into plain errors and never retries them', function (int $http, array $body, string $expected) {
        Http::fake([DataForSeoFixtures::API => Http::response($body, $http)]);

        $result = $this->client->post('/v3/x', [['a' => 1]], Feature::KEYWORDS, 0.01);

        expect($result->ok)->toBeFalse()->and($result->error)->toContain($expected);
        Http::assertSentCount(1);
        expect(ApiUsage::query()->sole()->status)->toBe('error');
    })->with([
        'bad login' => [401, DataForSeoFixtures::error(40100, 'You are not authorized.'), 'rejected the API login'],
        'no funds' => [402, DataForSeoFixtures::error(40200, 'Payment Required.'), 'out of funds'],
        'too many' => [429, DataForSeoFixtures::error(40202, 'Rate limit.'), 'too many requests'],
        'other' => [200, DataForSeoFixtures::error(40501, 'Invalid Field: keyword.'), 'Invalid Field: keyword'],
    ]);

    it('does nothing without credentials', function () {
        config(['clockwork.seo.dataforseo_login' => null]);
        Http::fake();

        expect(app(DataForSeoClient::class)->post('/v3/x', [['a' => 1]], Feature::KEYWORDS, 0.01)->error)->toContain('not set up');
        Http::assertNothingSent();
    });

    it('refuses more than 100 tasks in one call', function () {
        Http::fake();

        expect($this->client->post('/v3/x', array_fill(0, 101, ['a' => 1]), Feature::KEYWORDS, 0.01)->ok)->toBeFalse();
        Http::assertNothingSent();
    });

    it('uses the free sandbox when told to, and skips the caps there', function () {
        config(['clockwork.seo.dataforseo_sandbox' => true]);
        spend(Feature::KEYWORDS, 500.0);
        Http::fake([DataForSeoFixtures::SANDBOX => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task('t1', [])]))]);

        expect($this->client->post('/v3/x', [['a' => 1]], Feature::KEYWORDS, 5.0)->ok)->toBeTrue();
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://sandbox.dataforseo.com/v3/x'));
    });

    it('reads the account balance', function () {
        Http::fake([DataForSeoFixtures::API => Http::response(DataForSeoFixtures::userData(48.25))]);

        expect($this->client->account())->toBe(['ok' => true, 'login' => 'agency@example.com', 'balance' => 48.25, 'error' => null]);
        // A free read leaves no ledger row.
        expect(ApiUsage::query()->count())->toBe(0);
    });
});

describe('BudgetGuard', function () {
    beforeEach(fn () => $this->guard = app(BudgetGuard::class));

    it('caps each feature on its own', function () {
        spend(Feature::RESEARCH, 19.50);

        expect($this->guard->refusal(Feature::RESEARCH, 1.00))->toContain('Ranking counts and competitor research')
            // Research being full does not stop rank tracking.
            ->and($this->guard->refusal(Feature::RANK_TRACKING, 1.00))->toBeNull();
    });

    it('caps everything together', function () {
        $this->guard->save(['caps' => [Feature::RANK_TRACKING => 500, Feature::SITE_AUDIT => 500], 'total_cap' => 100]);
        spend(Feature::SITE_AUDIT, 99.50);

        expect($this->guard->refusal(Feature::RANK_TRACKING, 1.00))->toContain('$99.50 of the $100.00 monthly cap');
    });

    it('holds a non-admin to a personal allowance, and not an admin', function () {
        $operator = User::factory()->operator()->create();
        $admin = User::factory()->admin()->create();
        spend(Feature::KEYWORDS, 9.80, $operator->id);
        spend(Feature::KEYWORDS, 9.80, $admin->id);

        expect($this->guard->refusal(Feature::RESEARCH, 0.50, $operator))->toContain('your $10.00 monthly allowance')
            ->and($this->guard->refusal(Feature::RESEARCH, 0.50, $admin))->toBeNull()
            // A scheduled job has no person and no allowance.
            ->and($this->guard->refusal(Feature::RESEARCH, 0.50))->toBeNull();
    });

    it('starts again each calendar month', function () {
        spend(Feature::KEYWORDS, 19.99, null, now()->subMonthNoOverflow()->endOfMonth());

        expect($this->guard->refusal(Feature::KEYWORDS, 5.00))->toBeNull()
            ->and($this->guard->spentThisMonth(Feature::KEYWORDS))->toBe(0.0);
    });

    it('saves caps and reports spend against them', function () {
        $this->guard->save(['caps' => [Feature::RANK_TRACKING => '120', 'made_up' => 5], 'total_cap' => '250', 'person_allowance' => '15']);
        spend(Feature::RANK_TRACKING, 12.3456);

        $tracking = collect($this->guard->summary())->firstWhere('feature', Feature::RANK_TRACKING);
        expect($tracking)->toMatchArray(['spent' => 12.3456, 'cap' => 120.0])
            ->and($this->guard->totalCap())->toBe(250.0)
            ->and($this->guard->personAllowance())->toBe(15.0)
            ->and($this->guard->refusal('made_up', 1))->toContain('Unknown spending category');
    });
});

describe('TaskRunner', function () {
    beforeEach(function () {
        $this->runner = app(TaskRunner::class);
        $this->site = Site::factory()->create();
    });

    it('posts in batches of 100 and remembers each task with its context', function () {
        $batch = 0;
        Http::fake([DataForSeoFixtures::API => function (Request $request) use (&$batch) {
            $batch++;

            return Http::response(DataForSeoFixtures::created(array_map(fn ($i) => "b{$batch}-{$i}", array_keys($request->data()))));
        }]);
        $items = array_map(fn ($i) => ['task' => ['keyword' => "kw {$i}"], 'context' => ['keyword_id' => $i]], range(1, 150));

        $summary = $this->runner->post(Feature::RANK_TRACKING, 'serp_organic', '/v3/serp/google/organic/task_post', $items, 0.0006, $this->site->id);

        expect($summary)->toMatchArray(['posted' => 150, 'failed' => 0, 'refused' => false])
            ->and(round($summary['cost'], 4))->toBe(0.09);
        Http::assertSentCount(2);

        // The 101st keyword is the first task of the second batch.
        $row = ProviderTask::query()->where('task_id', 'b2-0')->sole();
        expect($row->context)->toBe(['keyword_id' => 101])
            ->and($row->kind)->toBe('serp_organic')
            ->and($row->status)->toBe('pending')
            ->and(ProviderTask::query()->count())->toBe(150);
    });

    it('stops at a refusal and reports it once', function () {
        Http::fake();
        spend(Feature::RANK_TRACKING, 80.0);
        $items = array_map(fn ($i) => ['task' => ['keyword' => "kw {$i}"]], range(1, 150));

        $summary = $this->runner->post(Feature::RANK_TRACKING, 'serp_organic', '/v3/x', $items, 0.0006);

        expect($summary['refused'])->toBeTrue()->and($summary['posted'])->toBe(0)->and($summary['error'])->toContain('cap');
        Http::assertNothingSent();
        expect(ApiUsage::query()->where('status', 'refused')->count())->toBe(1);
    });

    it('collects finished tasks, leaves queued ones, and fails broken ones', function () {
        foreach (['done', 'queued', 'broken'] as $id) {
            ProviderTask::query()->create(['provider' => 'dataforseo', 'feature' => Feature::RANK_TRACKING, 'kind' => 'serp_organic', 'task_id' => $id, 'context' => ['keyword_id' => $id], 'posted_at' => now()]);
        }
        Http::fake([
            '*/task_get/advanced/done' => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task('done', [['items' => [['rank_absolute' => 3]]]])])),
            '*/task_get/advanced/queued' => Http::response(DataForSeoFixtures::inQueue('queued')),
            '*/task_get/advanced/broken' => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task('broken', null, 0, 40501, 'Invalid Field.')])),
        ]);
        $handled = [];

        $summary = $this->runner->collect('serp_organic', '/v3/serp/google/organic/task_get/advanced/{id}', function (array $task, ProviderTask $row) use (&$handled) {
            $handled[$row->context['keyword_id']] = $task['result'][0]['items'][0]['rank_absolute'];
        });

        expect($summary)->toBe(['collected' => 1, 'pending' => 1, 'failed' => 1])
            ->and($handled)->toBe(['done' => 3])
            ->and(ProviderTask::query()->where('task_id', 'done')->value('status'))->toBe('collected')
            ->and(ProviderTask::query()->where('task_id', 'queued')->value('status'))->toBe('pending')
            ->and(ProviderTask::query()->where('task_id', 'broken')->first()->error)->toContain('Invalid Field');

        // Collected tasks are not fetched again.
        Http::fake(['*/task_get/advanced/queued' => Http::response(DataForSeoFixtures::inQueue('queued'))]);
        expect($this->runner->collect('serp_organic', '/v3/serp/google/organic/task_get/advanced/{id}', fn () => null))->toBe(['collected' => 0, 'pending' => 1, 'failed' => 0])
            ->and($this->runner->pendingCount('serp_organic'))->toBe(1);
    });

    it('keeps a task pending when DataForSEO is unreachable, and writes it off after two days', function () {
        ProviderTask::query()->create(['provider' => 'dataforseo', 'feature' => Feature::RANK_TRACKING, 'kind' => 'serp_organic', 'task_id' => 'fresh', 'posted_at' => now()]);
        ProviderTask::query()->create(['provider' => 'dataforseo', 'feature' => Feature::RANK_TRACKING, 'kind' => 'serp_organic', 'task_id' => 'stale', 'posted_at' => now()->subHours(49)]);
        Http::fake([DataForSeoFixtures::API => Http::response('upstream down', 503)]);

        $summary = $this->runner->collect('serp_organic', '/v3/serp/google/organic/task_get/advanced/{id}', fn () => null);

        expect($summary)->toBe(['collected' => 0, 'pending' => 1, 'failed' => 1])
            ->and(ProviderTask::query()->where('task_id', 'stale')->first()->error)->toContain('written off');
    });

    it('fails a task whose result could not be stored, without stopping the rest', function () {
        foreach (['a', 'b'] as $id) {
            ProviderTask::query()->create(['provider' => 'dataforseo', 'feature' => Feature::RANK_TRACKING, 'kind' => 'serp_organic', 'task_id' => $id, 'posted_at' => now()]);
        }
        Http::fake([DataForSeoFixtures::API => fn (Request $request) => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task(basename($request->url()), [['x' => 1]])]))]);

        $summary = $this->runner->collect('serp_organic', '/v3/serp/google/organic/task_get/advanced/{id}', function (array $task, ProviderTask $row) {
            if ($row->task_id === 'a') {
                throw new RuntimeException('bad row');
            }
        });

        expect($summary)->toBe(['collected' => 1, 'pending' => 0, 'failed' => 1]);
    });
});

describe('DataForSEO card on /seo/settings', function () {
    beforeEach(function () {
        $this->partialMock(IssueCounter::class, fn ($mock) => $mock->shouldReceive('total')->andReturn(0));
        $this->withoutVite();
        $this->admin = User::factory()->admin()->create();
    });

    it('shows spend and caps from the ledger without calling DataForSEO', function () {
        Http::fake();
        spend(Feature::RANK_TRACKING, 70.25);
        spend(Feature::KEYWORDS, 1.5);

        $this->actingAs($this->admin)->get(route('seo.settings'))
            ->assertOk()
            ->assertSee('data-dataforseo-state="configured"', false)
            ->assertSee('Position tracking')
            ->assertSee('$70.25')
            ->assertSee('$71.75')
            ->assertDontSee('api-password-canary');

        Http::assertNothingSent();
    });

    it('says how to set it up when there is no login', function () {
        config(['clockwork.seo.dataforseo_login' => null]);

        $this->actingAs($this->admin)->get(route('seo.settings'))
            ->assertOk()
            ->assertSee('data-dataforseo-state="missing"', false)
            ->assertSee('Not set up');
    });

    it('saves the caps, and warns when they add up to more than the total', function () {
        $caps = array_fill_keys(array_keys(Feature::ALL), 10);
        $caps[Feature::RANK_TRACKING] = 150;

        $this->actingAs($this->admin)->put(route('seo.dataforseo.budget'), ['caps' => $caps, 'total_cap' => 120, 'person_allowance' => 5])
            ->assertRedirect(route('seo.settings'))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'more than the $120.00 total'));

        $guard = app(BudgetGuard::class);
        expect($guard->featureCap(Feature::RANK_TRACKING))->toBe(150.0)
            ->and($guard->totalCap())->toBe(120.0)
            ->and($guard->personAllowance())->toBe(5.0);
    });

    it('rejects a missing or negative cap', function () {
        $this->actingAs($this->admin)->put(route('seo.dataforseo.budget'), ['caps' => [Feature::KEYWORDS => -1], 'total_cap' => 100, 'person_allowance' => 5])
            ->assertSessionHasErrors(['caps.'.Feature::KEYWORDS, 'caps.'.Feature::RANK_TRACKING]);
    });

    it('tests the connection and reports the balance', function () {
        Http::fake([DataForSeoFixtures::API => Http::response(DataForSeoFixtures::userData(48.25))]);

        $this->actingAs($this->admin)->post(route('seo.dataforseo.test'))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'agency@example.com') && str_contains($message, '$48.25'));
    });

    it('reports a failed test in plain words', function () {
        Http::fake([DataForSeoFixtures::API => Http::response(DataForSeoFixtures::error(40100, 'You are not authorized.'), 401)]);

        $this->actingAs($this->admin)->post(route('seo.dataforseo.test'))
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, 'rejected the API login'));
    });

    it('keeps operators out', function () {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)->put(route('seo.dataforseo.budget'), [])->assertForbidden();
        $this->actingAs($operator)->post(route('seo.dataforseo.test'))->assertForbidden();
    });
});
