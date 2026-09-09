<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Ingest\IngestScheduleGate;
use App\Services\Process\BackgroundArtisan;
use App\Services\Process\BackgroundArtisanResult;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| IngestSettingsController
|--------------------------------------------------------------------------
|
| index()/update() just read/write plain settings keys via IngestScheduleGate
| + Settings — no external I/O to mock there. runNow() is the one action that
| touches the outside world: it launches clockwork:pull-llar-lockouts or
| clockwork:pull-wordfence-blocks via BackgroundArtisan (anything else is
| rejected first). Tests mock BackgroundArtisan so they never spawn a pull.
*/

function settingValue(string $key): mixed
{
    $row = AppSetting::query()->where('key', $key)->first();

    return $row?->value;
}

describe('IngestSettingsController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('requires authentication', function () {
        $this->get(route('settings.ingest.index'))
            ->assertRedirect(route('login'));
    });

    describe('index', function () {
        it('renders the schedule page with default config when nothing is saved yet', function () {
            $response = $this->actingAs(User::factory()->create())
                ->get(route('settings.ingest.index'));

            $response->assertOk()
                ->assertSee('Scheduling')
                ->assertSee('Limit Login Attempts Reloaded')
                ->assertSee('Wordfence')
                ->assertSee('America/New_York');
        });
    });

    describe('update', function () {
        it('saves the window, cadence, timezone, and per-source toggles, then redirects with a status flash', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->patch(route('settings.ingest.update'), [
                'always_on' => '1',
                'start_time' => '02:00',
                'end_time' => '08:30',
                'timezone' => 'America/Chicago',
                'frequency_minutes' => '30',
                'sources' => [
                    'llar' => ['enabled' => '1'],
                    // wordfence omitted entirely — mirrors an unchecked checkbox,
                    // controller must default it to false.
                ],
            ]);

            $response->assertRedirect(route('settings.ingest.index'));
            $response->assertSessionHas('status', 'Scheduling saved.');

            expect(settingValue(IngestScheduleGate::KEY_ALWAYS_ON))->toBeTrue();
            expect(settingValue(IngestScheduleGate::KEY_WINDOW_START))->toBe('02:00');
            expect(settingValue(IngestScheduleGate::KEY_WINDOW_END))->toBe('08:30');
            expect(settingValue(IngestScheduleGate::KEY_TIMEZONE))->toBe('America/Chicago');
            expect(settingValue(IngestScheduleGate::KEY_FREQUENCY_MIN))->toBe(30);
            expect(settingValue('ingest.llar.enabled'))->toBeTrue();
            expect(settingValue('ingest.wordfence.enabled'))->toBeFalse();
        });

        it('rejects an invalid payload (bad time format, bad timezone, out-of-range frequency) without persisting anything', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->patch(route('settings.ingest.update'), [
                'start_time' => 'not-a-time',
                'end_time' => '08:00',
                'timezone' => 'Not/A_Real_Zone',
                'frequency_minutes' => '0', // below min:5
            ]);

            $response->assertSessionHasErrors(['start_time', 'timezone', 'frequency_minutes']);

            expect(settingValue(IngestScheduleGate::KEY_WINDOW_START))->toBeNull();
            expect(AppSetting::query()->count())->toBe(0);
        });

        it('rejects a payload missing required fields entirely', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->patch(route('settings.ingest.update'), []);

            $response->assertSessionHasErrors(['start_time', 'end_time', 'timezone', 'frequency_minutes']);
        });
    });

    describe('runNow', function () {
        it('starts the LLAR pull in the background when source=llar', function () {
            $this->mock(BackgroundArtisan::class, function ($mock) {
                $mock->shouldReceive('start')
                    ->once()
                    ->withArgs(fn (string $key, array $cmds) => $key === 'ingest.llar'
                        && $cmds === ['clockwork:pull-llar-lockouts'])
                    ->andReturn(BackgroundArtisanResult::ok());
            });

            $response = $this->actingAs(User::factory()->create())
                ->post(route('settings.ingest.runNow'), ['source' => 'llar']);

            $response->assertRedirect();
            $response->assertSessionHas('status', 'Pull started for llar in the background. Refresh /review in a minute.');
            $response->assertSessionMissing('queue_error');
        });

        it('starts the Wordfence pull in the background when source=wordfence', function () {
            $this->mock(BackgroundArtisan::class, function ($mock) {
                $mock->shouldReceive('start')
                    ->once()
                    ->withArgs(fn (string $key, array $cmds) => $cmds === ['clockwork:pull-wordfence-blocks'])
                    ->andReturn(BackgroundArtisanResult::ok());
            });

            $response = $this->actingAs(User::factory()->create())
                ->post(route('settings.ingest.runNow'), ['source' => 'wordfence']);

            $response->assertRedirect();
            $response->assertSessionHas('status', 'Pull started for wordfence in the background. Refresh /review in a minute.');
        });

        it('defaults to source=llar when no source is given', function () {
            $this->mock(BackgroundArtisan::class, function ($mock) {
                $mock->shouldReceive('start')
                    ->once()
                    ->withArgs(fn (string $key, array $cmds) => $cmds === ['clockwork:pull-llar-lockouts'])
                    ->andReturn(BackgroundArtisanResult::ok());
            });

            $this->actingAs(User::factory()->create())
                ->post(route('settings.ingest.runNow'), [])
                ->assertSessionHas('status', 'Pull started for llar in the background. Refresh /review in a minute.');
        });

        it('never calls Artisan and flashes queue_error for an unknown source', function () {
            Artisan::shouldReceive('queue')->never();

            $response = $this->actingAs(User::factory()->create())
                ->post(route('settings.ingest.runNow'), ['source' => 'bogus-source']);

            $response->assertRedirect();
            $response->assertSessionHas('queue_error', "Unknown source 'bogus-source'.");
            $response->assertSessionMissing('status');
        });
    });
});
