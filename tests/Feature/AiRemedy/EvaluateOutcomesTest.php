<?php

use App\Models\Server;
use App\Models\ServerMetric;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\AiRemedy\Models\AiRemedyRun;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-03 15:00:00');
    $this->server = Server::factory()->create(['name' => 'web-eval-01']);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('evaluate outcomes artisan command evaluates eligible runs and persists outcome', function () {
    // Run 1: watch_mode, 70 mins old, eligible
    $run1 = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'watch_mode',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Spike',
        'root_cause' => 'Worker spike',
        'started_at' => Carbon::now()->subMinutes(70),
    ]);

    // Metric 40 mins after: recovered
    ServerMetric::create([
        'server_id' => $this->server->id,
        'cpu_pct' => 25.0,
        'memory_pct' => 30.0,
        'recorded_at' => Carbon::now()->subMinutes(30),
    ]);

    // Run 2: too recent (30 mins old), should not be evaluated
    $run2 = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'watch_mode',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Recent spike',
        'root_cause' => 'Worker spike',
        'started_at' => Carbon::now()->subMinutes(30),
    ]);

    // Run 3: simulation run (should be excluded per spec)
    $run3 = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'simulation',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Simulated spike',
        'root_cause' => 'Simulated',
        'started_at' => Carbon::now()->subMinutes(90),
    ]);

    // Run 4: manual run (should be excluded per spec)
    $run4 = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'manual',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Manual spike',
        'root_cause' => 'Manual test',
        'started_at' => Carbon::now()->subMinutes(90),
    ]);

    $exitCode = Artisan::call('clockwork:ai-remedy-evaluate-outcomes');

    expect($exitCode)->toBe(0);

    $run1->refresh();
    $run2->refresh();
    $run3->refresh();
    $run4->refresh();

    expect($run1->outcome)->toBe(AiRemedyRun::OUTCOME_SELF_RESOLVED);
    expect($run1->outcome_evaluated_at)->not->toBeNull();

    expect($run2->outcome)->toBeNull();
    expect($run3->outcome)->toBeNull();
    expect($run4->outcome)->toBeNull();
});

test('expire unreviewed runs artisan command marks unreviewed Copilot incidents over 24h old as expired', function () {
    // 25 hours old interactive analyzed run -> should expire
    $oldInteractive = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'interactive',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Spike',
        'root_cause' => 'Worker spike',
        'started_at' => Carbon::now()->subHours(25),
    ]);

    // 2 hours old interactive analyzed run -> still active
    $recentInteractive = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'interactive',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Spike',
        'root_cause' => 'Worker spike',
        'started_at' => Carbon::now()->subHours(2),
    ]);

    // 25 hours old watch_mode run -> should NOT expire (watch_mode is passive, not in review queue)
    $oldWatch = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'watch_mode',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Spike',
        'root_cause' => 'Worker spike',
        'started_at' => Carbon::now()->subHours(25),
    ]);

    $exitCode = Artisan::call('clockwork:ai-remedy-expire-unreviewed');

    expect($exitCode)->toBe(0);

    $oldInteractive->refresh();
    $recentInteractive->refresh();
    $oldWatch->refresh();

    expect($oldInteractive->status)->toBe(AiRemedyRun::STATUS_EXPIRED);
    expect($recentInteractive->status)->toBe(AiRemedyRun::STATUS_ANALYZED);
    expect($oldWatch->status)->toBe(AiRemedyRun::STATUS_ANALYZED);
});
