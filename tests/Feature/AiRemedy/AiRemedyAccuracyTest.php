<?php

use App\Models\Server;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AiRemedy\Models\AiRemedyRun;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RefreshDatabase::class, RendersAuthenticatedPages::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-03 16:00:00');
    $this->admin = User::factory()->admin()->create();
    $this->mockIssueCounterZero();
    $this->server = Server::factory()->create(['name' => 'accuracy-srv-01']);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('operator can submit verdict on an AiRemedy run', function () {
    $run = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'watch_mode',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'High memory usage from Redis',
        'root_cause' => 'Redis maxmemory exceeded',
        'started_at' => Carbon::now()->subMinutes(10),
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson(route('ai-remedy.verdict', $run), [
            'verdict' => AiRemedyRun::VERDICT_CORRECT,
            'note' => 'Spot on diagnosis, verified Redis log.',
        ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'verdict' => 'correct',
        ]);

    $run->refresh();
    expect($run->verdict)->toBe(AiRemedyRun::VERDICT_CORRECT);
    expect($run->verdict_note)->toBe('Spot on diagnosis, verified Redis log.');
    expect($run->verdict_by_user_id)->toBe($this->admin->id);
    expect($run->verdict_at)->not->toBeNull();

    $this->assertDatabaseHas('action_logs', [
        'server_id' => $this->server->id,
        'action_type' => 'ai_remedy_verdict',
    ]);
});

test('verdict endpoint validates allowed values', function () {
    $run = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'watch_mode',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Spike',
        'root_cause' => 'Spike',
        'started_at' => Carbon::now()->subMinutes(10),
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson(route('ai-remedy.verdict', $run), [
            'verdict' => 'invalid_verdict',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['verdict']);
});

test('accuracy report page renders aggregate statistics and calculations', function () {
    // Seed 4 runs with various verdicts and outcomes:
    // Run 1: Correct verdict, self_resolved, Tier 1 safe
    AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_RESOLVED,
        'server_id' => $this->server->id,
        'actor' => 'watch_mode',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Redis leak',
        'root_cause' => 'Redis memory',
        'safety_tier' => AiRemedyRun::TIER_1_SAFE,
        'proposed_commands' => ['redis-cli memory purge'],
        'verdict' => AiRemedyRun::VERDICT_CORRECT,
        'verdict_by_user_id' => $this->admin->id,
        'verdict_at' => Carbon::now()->subHours(2),
        'outcome' => AiRemedyRun::OUTCOME_SELF_RESOLVED,
        'outcome_evaluated_at' => Carbon::now()->subHours(2),
        'total_cost_usd' => 0.0045,
        'started_at' => Carbon::now()->subHours(3),
    ]);

    // Run 2: Wrong verdict, persisted
    AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'interactive',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Bad guess',
        'root_cause' => 'Bad guess',
        'safety_tier' => AiRemedyRun::TIER_1_SAFE,
        'proposed_commands' => ['service restart'],
        'verdict' => AiRemedyRun::VERDICT_WRONG,
        'verdict_by_user_id' => $this->admin->id,
        'verdict_at' => Carbon::now()->subHours(1),
        'outcome' => AiRemedyRun::OUTCOME_PERSISTED,
        'outcome_evaluated_at' => Carbon::now()->subHours(1),
        'total_cost_usd' => 0.0035,
        'started_at' => Carbon::now()->subHours(2),
    ]);

    // Run 3: Allowed maintenance detected
    AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ALLOWED_MAINTENANCE,
        'server_id' => $this->server->id,
        'actor' => 'watch_mode',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Backup run',
        'root_cause' => 'Daily backup',
        'is_maintenance' => true,
        'maintenance_type' => 'backup',
        'outcome' => AiRemedyRun::OUTCOME_SELF_RESOLVED,
        'outcome_evaluated_at' => Carbon::now()->subMinutes(30),
        'total_cost_usd' => 0.0020,
        'started_at' => Carbon::now()->subHours(1),
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('ai-remedy.accuracy'));

    $response->assertOk()
        ->assertSee('AiRemedy Accuracy & Outcomes')
        ->assertSee('Diagnostic Accuracy')
        ->assertSee('Avoided Premature Actions')
        ->assertSee('Maintenance Precision');
});
