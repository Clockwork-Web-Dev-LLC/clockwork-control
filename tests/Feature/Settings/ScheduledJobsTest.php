<?php

use App\Models\ActionLog;
use App\Models\ScheduledJobRun;
use App\Models\User;
use App\Services\Process\BackgroundArtisan;
use App\Services\Process\BackgroundArtisanResult;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

test('guests are redirected to login from the scheduled jobs page', function () {
    $this->get(route('settings.scheduled-jobs.index'))->assertRedirect(route('login'));
});

test('authenticated users see the live schedule with a heartbeat entry', function () {
    $this->mockIssueCounterZero();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.scheduled-jobs.index'))
        ->assertOk()
        ->assertSee('Scheduled Jobs')
        ->assertSee('clockwork:scheduler-heartbeat');
});

test('a recorded run shows its status on the page', function () {
    $this->mockIssueCounterZero();
    ScheduledJobRun::create([
        'command' => 'clockwork:scheduler-heartbeat',
        'status' => ScheduledJobRun::STATUS_SUCCESS,
        'duration_ms' => 42,
        'exit_code' => 0,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('settings.scheduled-jobs.index'))
        ->assertOk()
        ->assertSee('OK');
});

describe('run', function () {
    it('dispatches a known scheduled command via BackgroundArtisan', function () {
        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(fn (string $key, array $cmds) => $cmds === ['clockwork:scheduler-heartbeat'])
                ->andReturn(BackgroundArtisanResult::ok());
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('settings.scheduled-jobs.run'), ['command' => 'clockwork:scheduler-heartbeat']);

        $response->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('action_logs', [
            'action_type' => ActionLog::TYPE_SCHEDULED_JOB_RUN_NOW,
            'target' => 'clockwork:scheduler-heartbeat',
        ]);
    });

    it('rejects a command that is not in the live schedule, without dispatching anything', function () {
        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldNotReceive('start');
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('settings.scheduled-jobs.run'), ['command' => 'rm -rf /; clockwork:whatever']);

        $response->assertRedirect()->assertSessionHas('queue_error');

        $this->assertDatabaseMissing('action_logs', [
            'action_type' => ActionLog::TYPE_SCHEDULED_JOB_RUN_NOW,
        ]);
    });
});
