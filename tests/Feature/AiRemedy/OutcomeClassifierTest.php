<?php

use App\Models\ActionLog;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\SiteUptimeEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AiRemedy\Models\AiRemedyRun;
use Modules\AiRemedy\Services\OutcomeClassifier;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-03 12:00:00');
    $this->server = Server::factory()->create(['name' => 'prod-web-01']);
    $this->site = Site::factory()->create(['server_id' => $this->server->id, 'domain' => 'example.com']);
    $this->classifier = app(OutcomeClassifier::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('server spike outcome: self_resolved when metrics drop under threshold with no human action', function () {
    $run = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'watch_mode',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Spike',
        'root_cause' => 'Traffic burst',
        'started_at' => Carbon::now()->subMinutes(65),
    ]);

    // Metric 15 mins after: 85%
    ServerMetric::create([
        'server_id' => $this->server->id,
        'cpu_pct' => 85.0,
        'memory_pct' => 60.0,
        'recorded_at' => Carbon::now()->subMinutes(50),
    ]);

    // Metric 40 mins after: 35% (recovered)
    ServerMetric::create([
        'server_id' => $this->server->id,
        'cpu_pct' => 35.0,
        'memory_pct' => 40.0,
        'recorded_at' => Carbon::now()->subMinutes(20),
    ]);

    $result = $this->classifier->evaluate($run);

    expect($result['outcome'])->toBe(AiRemedyRun::OUTCOME_SELF_RESOLVED);
});

test('server spike outcome: human_resolved when metrics drop but operator action logged', function () {
    $operator = User::factory()->create();

    $run = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'interactive',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Spike',
        'root_cause' => 'Traffic burst',
        'started_at' => Carbon::now()->subMinutes(65),
    ]);

    // Action logged 20 minutes after run started
    ActionLog::create([
        'server_id' => $this->server->id,
        'action_type' => 'restart_services',
        'summary' => 'Restarted services',
        'actor' => $operator->name,
        'ran_at' => Carbon::now()->subMinutes(45),
        'created_at' => Carbon::now()->subMinutes(45),
    ]);

    // Metric 40 mins after: recovered
    ServerMetric::create([
        'server_id' => $this->server->id,
        'cpu_pct' => 20.0,
        'memory_pct' => 30.0,
        'recorded_at' => Carbon::now()->subMinutes(20),
    ]);

    $result = $this->classifier->evaluate($run);

    expect($result['outcome'])->toBe(AiRemedyRun::OUTCOME_HUMAN_RESOLVED);
});

test('server spike outcome: persisted when metrics remain above threshold at 60 minutes', function () {
    $run = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'watch_mode',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Spike',
        'root_cause' => 'Runaway process',
        'started_at' => Carbon::now()->subMinutes(70),
    ]);

    // Metric near 60 min mark: still high (85%)
    ServerMetric::create([
        'server_id' => $this->server->id,
        'cpu_pct' => 85.0,
        'memory_pct' => 70.0,
        'recorded_at' => Carbon::now()->subMinutes(12),
    ]);

    $result = $this->classifier->evaluate($run);

    expect($result['outcome'])->toBe(AiRemedyRun::OUTCOME_PERSISTED);
});

test('server spike outcome: escalated when server reaches critical red threshold', function () {
    $run = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'watch_mode',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Spike',
        'root_cause' => 'Runaway process',
        'started_at' => Carbon::now()->subMinutes(70),
    ]);

    // Metric hits red threshold (98% CPU)
    ServerMetric::create([
        'server_id' => $this->server->id,
        'cpu_pct' => 98.0,
        'memory_pct' => 96.0,
        'recorded_at' => Carbon::now()->subMinutes(30),
    ]);

    $result = $this->classifier->evaluate($run);

    expect($result['outcome'])->toBe(AiRemedyRun::OUTCOME_ESCALATED);
});

test('server spike outcome: unknown when no server metrics exist', function () {
    $run = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'server_id' => $this->server->id,
        'actor' => 'watch_mode',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Spike',
        'root_cause' => 'Unknown',
        'started_at' => Carbon::now()->subMinutes(70),
    ]);

    $result = $this->classifier->evaluate($run);

    expect($result['outcome'])->toBe(AiRemedyRun::OUTCOME_UNKNOWN);
});

test('site downtime outcome: self_resolved when UP event occurs with no human action', function () {
    $run = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SITE_DOWNTIME,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'site_id' => $this->site->id,
        'server_id' => $this->server->id,
        'actor' => 'interactive',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Site down',
        'root_cause' => 'PHP 500 error',
        'started_at' => Carbon::now()->subMinutes(75),
    ]);

    // Site recovered 25 mins later
    SiteUptimeEvent::create([
        'site_id' => $this->site->id,
        'event_type' => 'up',
        'event_at' => Carbon::now()->subMinutes(50),
    ]);

    $result = $this->classifier->evaluate($run);

    expect($result['outcome'])->toBe(AiRemedyRun::OUTCOME_SELF_RESOLVED);
});

test('site downtime outcome: human_resolved when UP event occurs and human action logged', function () {
    $operator = User::factory()->create();

    $run = AiRemedyRun::create([
        'trigger_type' => AiRemedyRun::TRIGGER_SITE_DOWNTIME,
        'status' => AiRemedyRun::STATUS_ANALYZED,
        'site_id' => $this->site->id,
        'server_id' => $this->server->id,
        'actor' => 'interactive',
        'model_used' => 'anthropic/claude-sonnet-4.5',
        'diagnosis_summary' => 'Site down',
        'root_cause' => 'PHP 500 error',
        'started_at' => Carbon::now()->subMinutes(75),
    ]);

    ActionLog::create([
        'site_id' => $this->site->id,
        'action_type' => 'deploy_fix',
        'summary' => 'Deploy fix',
        'actor' => $operator->name,
        'ran_at' => Carbon::now()->subMinutes(60),
        'created_at' => Carbon::now()->subMinutes(60),
    ]);

    SiteUptimeEvent::create([
        'site_id' => $this->site->id,
        'event_type' => 'up',
        'event_at' => Carbon::now()->subMinutes(50),
    ]);

    $result = $this->classifier->evaluate($run);

    expect($result['outcome'])->toBe(AiRemedyRun::OUTCOME_HUMAN_RESOLVED);
});
