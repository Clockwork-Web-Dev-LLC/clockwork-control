<?php

use App\Models\ActionLog;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Ssh\SshClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\AiRemedy\Models\AiRemedyRun;
use Modules\AiRemedy\Services\OpenRouterClient;
use Modules\AiRemedy\Services\RunApprovalPolicy;
use Modules\AiRemedy\Services\ServerTelemetryCollector;
use Modules\Core\ModuleStateResolver;
use Modules\Core\Support\WebhookChatNotifier;
use Modules\Mattermost\MattermostNotifier;
use phpseclib3\Net\SSH2;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RefreshDatabase::class, RendersAuthenticatedPages::class);

const AIR_TIER1_A = 'sudo systemctl reload php8.3-fpm';
const AIR_TIER1_B = 'sudo nginx -t';
const AIR_TIER2 = 'sudo systemctl restart mysql';
const AIR_TIER3 = 'rm -rf /var/www';

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->mockIssueCounterZero();
    app(ModuleStateResolver::class)->flush();
    $this->server = Server::factory()->create(['name' => 'web-01', 'hostname' => '203.0.113.10']);
});

function airCopilotRun(Server $server, array $overrides = []): AiRemedyRun
{
    return AiRemedyRun::create(array_merge([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $server->id,
        'actor' => 'interactive',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'PHP-FPM pool saturated.',
        'root_cause' => 'Worker pool exhausted',
        'safety_tier' => AiRemedyRun::TIER_2_CAUTIOUS,
        'proposed_commands' => [AIR_TIER1_A, AIR_TIER1_B, AIR_TIER2],
        'started_at' => now(),
    ], $overrides));
}

/**
 * Fake SSH: record every command actually executed; optional per-command exit codes.
 *
 * @param  list<string>  $executed
 * @param  array<string, int>  $exitCodes
 */
function airFakeSsh(array &$executed, array $exitCodes = []): void
{
    $session = Mockery::mock(SSH2::class);
    $last = null;
    $session->shouldReceive('setTimeout');
    $session->shouldReceive('exec')->andReturnUsing(function ($cmd) use (&$executed, &$last) {
        $executed[] = $cmd;
        $last = $cmd;

        return "ok: {$cmd}";
    });
    $session->shouldReceive('getExitStatus')->andReturnUsing(function () use (&$last, $exitCodes) {
        return $exitCodes[$last] ?? 0;
    });
    $session->shouldReceive('disconnect');

    $ssh = Mockery::mock(SshClient::class);
    $ssh->shouldReceive('connect')->andReturn($session);
    app()->instance(SshClient::class, $ssh);

    $collector = Mockery::mock(ServerTelemetryCollector::class);
    $collector->shouldReceive('collect')->andReturn(['ok' => true, 'loadavg' => [0.5, 0.6, 0.7]]);
    app()->instance(ServerTelemetryCollector::class, $collector);
}

test('admin runs a chosen subset of a copilot fix and skipped commands are recorded', function () {
    $executed = [];
    airFakeSsh($executed);
    $run = airCopilotRun($this->server);

    $response = $this->actingAs($this->admin)->postJson(route('ai-remedy.execute', $run), [
        'selected' => [
            ['index' => 0, 'command' => AIR_TIER1_A],
            ['index' => 2, 'command' => AIR_TIER2],
        ],
    ]);

    $response->assertOk()->assertJsonPath('ok', true);
    expect($executed)->toBe([AIR_TIER1_A, AIR_TIER2]);

    $run->refresh();
    expect($run->status)->toBe(AiRemedyRun::STATUS_RESOLVED);
    expect($run->approved_commands)->toBe([AIR_TIER1_A, AIR_TIER2]);

    $log = ActionLog::where('action_type', 'ai_remediation')->sole();
    expect($log->details['approved_by_user_id'])->toBe($this->admin->id);
    expect(collect($log->details['decisions'])->pluck('decision', 'index')->all())
        ->toBe([0 => 'run', 1 => 'skipped', 2 => 'run']);
    expect($log->details['results'])->toHaveCount(2);
    expect($log->details['results'][0]['exit_status'])->toBe(0);
});

test('edited commands are recorded with the original and re-checked by the safety guard', function () {
    $executed = [];
    airFakeSsh($executed);
    $run = airCopilotRun($this->server);

    $this->actingAs($this->admin)->postJson(route('ai-remedy.execute', $run), [
        'selected' => [['index' => 0, 'command' => 'sudo systemctl reload php8.2-fpm']],
    ])->assertOk();

    $decision = collect(ActionLog::sole()->details['decisions'])->firstWhere('index', 0);
    expect($decision['decision'])->toBe('edited');
    expect($decision['original_command'])->toBe(AIR_TIER1_A);

    // An edit into something prohibited is refused before anything runs.
    $executed = [];
    $run2 = airCopilotRun($this->server);
    $this->actingAs($this->admin)->postJson(route('ai-remedy.execute', $run2), [
        'selected' => [['index' => 0, 'command' => AIR_TIER3]],
    ])->assertStatus(422);
    expect($executed)->toBe([]);
    expect($run2->fresh()->status)->toBe(AiRemedyRun::STATUS_ANALYZED);
});

test('a run can only be executed once', function () {
    $executed = [];
    airFakeSsh($executed);
    $run = airCopilotRun($this->server);
    $payload = ['selected' => [['index' => 0, 'command' => AIR_TIER1_A]]];

    $this->actingAs($this->admin)->postJson(route('ai-remedy.execute', $run), $payload)->assertOk();
    $this->actingAs($this->admin)->postJson(route('ai-remedy.execute', $run), $payload)
        ->assertStatus(409)
        ->assertJsonPath('ok', false);

    expect($executed)->toBe([AIR_TIER1_A]);
});

test('shadow and simulation runs cannot be executed even with a hand-crafted request', function (string $actor) {
    $executed = [];
    airFakeSsh($executed);
    $run = airCopilotRun($this->server, ['actor' => $actor]);

    $this->actingAs($this->admin)->postJson(route('ai-remedy.execute', $run), [
        'selected' => [['index' => 0, 'command' => AIR_TIER1_A]],
    ])->assertStatus(409);

    expect($executed)->toBe([]);
    expect($run->fresh()->status)->toBe(AiRemedyRun::STATUS_ANALYZED);
})->with(['watch_mode', 'simulation']);

test('diagnoses older than the age limit are refused', function () {
    $executed = [];
    airFakeSsh($executed);
    $run = airCopilotRun($this->server, ['started_at' => now()->subMinutes(RunApprovalPolicy::MAX_AGE_MINUTES + 1)]);

    $this->actingAs($this->admin)->postJson(route('ai-remedy.execute', $run), [
        'selected' => [['index' => 0, 'command' => AIR_TIER1_A]],
    ])->assertStatus(409)->assertJsonFragment(['ok' => false]);

    expect($executed)->toBe([]);
});

test('blank, empty, and out-of-range selections are rejected', function (array $selected) {
    $executed = [];
    airFakeSsh($executed);
    $run = airCopilotRun($this->server);

    $this->actingAs($this->admin)->postJson(route('ai-remedy.execute', $run), ['selected' => $selected])
        ->assertStatus(422);

    expect($executed)->toBe([]);
    expect($run->fresh()->status)->toBe(AiRemedyRun::STATUS_ANALYZED);
})->with([
    'nothing selected' => [[]],
    'blank command' => [[['index' => 0, 'command' => '   ']]],
    'index out of range' => [[['index' => 9, 'command' => AIR_TIER1_A]]],
    'duplicate index' => [[['index' => 0, 'command' => AIR_TIER1_A], ['index' => 0, 'command' => AIR_TIER1_A]]],
]);

test('another fix already running on the same server blocks execution', function () {
    $executed = [];
    airFakeSsh($executed);
    $run = airCopilotRun($this->server);

    $lock = Cache::lock("ai-remedy:execute:server:{$this->server->id}", 60);
    expect($lock->get())->toBeTrue();

    $this->actingAs($this->admin)->postJson(route('ai-remedy.execute', $run), [
        'selected' => [['index' => 0, 'command' => AIR_TIER1_A]],
    ])->assertStatus(409);

    $lock->release();
    expect($executed)->toBe([]);
    expect($run->fresh()->status)->toBe(AiRemedyRun::STATUS_ANALYZED);
});

test('a failing command stops the run and later selected commands are recorded as not run', function () {
    $executed = [];
    airFakeSsh($executed, [AIR_TIER1_A => 1]);
    $run = airCopilotRun($this->server);

    $response = $this->actingAs($this->admin)->postJson(route('ai-remedy.execute', $run), [
        'selected' => [['index' => 0, 'command' => AIR_TIER1_A], ['index' => 1, 'command' => AIR_TIER1_B]],
    ]);

    $response->assertStatus(422);
    expect($executed)->toBe([AIR_TIER1_A]);
    expect($response->json('results.1.not_run'))->toBeTrue();
    expect($run->fresh()->status)->toBe(AiRemedyRun::STATUS_FAILED);
});

test('operators cannot execute fixes', function () {
    $executed = [];
    airFakeSsh($executed);
    $run = airCopilotRun($this->server);

    $this->actingAs(User::factory()->operator()->create())
        ->postJson(route('ai-remedy.execute', $run), ['selected' => [['index' => 0, 'command' => AIR_TIER1_A]]])
        ->assertForbidden();

    expect($executed)->toBe([]);
});

test('legacy commands payload still works and maps by position', function () {
    $executed = [];
    airFakeSsh($executed);
    $run = airCopilotRun($this->server, ['proposed_commands' => [AIR_TIER1_A]]);

    $this->actingAs($this->admin)->postJson(route('ai-remedy.execute', $run), ['commands' => [AIR_TIER1_A]])
        ->assertOk();

    expect($executed)->toBe([AIR_TIER1_A]);
});

test('review payload classifies each command and gates the run button', function () {
    $run = airCopilotRun($this->server, ['proposed_commands' => [AIR_TIER1_A, AIR_TIER2, AIR_TIER3]]);
    $policy = app(RunApprovalPolicy::class);

    $review = $policy->review($run, $this->admin);
    expect($review['executable'])->toBeTrue();
    expect($review['can_execute'])->toBeTrue();
    expect(array_column($review['commands'], 'tier'))->toBe(['tier_1_safe', 'tier_2_cautious', 'tier_3_prohibited']);
    expect(array_column($review['commands'], 'allowed'))->toBe([true, true, false]);

    expect($policy->review($run, User::factory()->operator()->create())['can_execute'])->toBeFalse();

    $watch = airCopilotRun($this->server, ['actor' => 'watch_mode']);
    expect($policy->review($watch, $this->admin)['executable'])->toBeFalse();
});

test('incident log and run page render the approval panel for copilot runs', function () {
    $run = airCopilotRun($this->server);

    $this->actingAs($this->admin)->get(route('ai-remedy.show', $run))
        ->assertOk()
        ->assertSee('Review &amp; run', false)
        ->assertSee('aiRemedyReview(', false);

    $this->actingAs($this->admin)->get(route('ai-remedy.index'))
        ->assertOk()
        ->assertSee('aiRemedyReview(', false);
});

test('manual diagnose stores the guard tier, not the LLM-claimed tier', function () {
    $this->mock(ServerTelemetryCollector::class, function ($mock) {
        $mock->shouldReceive('collect')->andReturn(['ok' => true, 'loadavg' => [9, 8, 7], 'cores' => 2]);
    });
    $this->mock(OpenRouterClient::class, function ($mock) {
        $mock->shouldReceive('getModel')->andReturn('anthropic/claude-sonnet-4.5');
        $mock->shouldReceive('diagnoseServerSpike')->andReturn([
            'ok' => true, 'summary' => 'DB wedged', 'root_cause' => 'mysql', 'is_fixable' => true,
            'safety_tier' => 'tier_1_safe', // LLM claims safe…
            'commands' => [AIR_TIER2],          // …but the guard says Tier 2
            'explanation' => '', 'unfixable_briefing' => null,
            'prompt_tokens' => 1, 'completion_tokens' => 1, 'cost_usd' => 0.0,
        ]);
    });

    $this->actingAs($this->admin)->postJson(route('ai-remedy.server.diagnose', $this->server))
        ->assertOk()
        ->assertJsonPath('review.commands.0.tier', 'tier_2_cautious');

    expect(AiRemedyRun::sole()->safety_tier)->toBe('tier_2_cautious');
});

test('triage alert links to the run and survives site runs without a server', function () {
    config(['clockwork.mattermost.enabled' => true, 'clockwork.mattermost.webhook_url' => 'https://chat.example.test/hooks/x']);
    Http::fake();

    $site = Site::factory()->create(['domain' => 'acme-example.org']);
    $run = airCopilotRun($this->server, ['server_id' => null, 'site_id' => $site->id, 'trigger_type' => AiRemedyRun::TRIGGER_SITE_DOWNTIME]);

    $notifier = app(MattermostNotifier::class);
    expect($notifier)->toBeInstanceOf(WebhookChatNotifier::class);
    $notifier->aiRemedyTriaged($run->fresh());

    Http::assertSent(function ($request) use ($run) {
        $attachment = $request['attachments'][0] ?? [];

        return str_contains($attachment['title'] ?? '', 'acme-example.org')
            && ($attachment['title_link'] ?? null) === route('ai-remedy.show', $run)
            && str_contains($attachment['text'] ?? '', route('ai-remedy.show', $run).'#review');
    });
});
