<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Process\BackgroundArtisan;
use App\Services\Process\BackgroundArtisanResult;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| SecurityScansSettingsController
|--------------------------------------------------------------------------
|
| Verified against the real controller (not assumed):
|   - index()/update() only touch the Settings facade (app_settings table)
|     — no external I/O, no mocking needed.
|   - runNow() launches the matching artisan command via BackgroundArtisan
|     (detached nohup). Tests mock that helper so they never spawn a real
|     Sucuri/SSH/blacklist scan.
|   - runNow() with an unknown `source` never reaches BackgroundArtisan —
|     it returns back() with a `queue_error` flash first.
|   - The three real SOURCES keys or commands, confirmed by reading the
|     controller's SOURCES const: sitecheck -> clockwork:scan-sitecheck,
|     checksums -> clockwork:verify-wp-core-checksums,
|     blacklist -> clockwork:check-blacklists.
*/

describe('SecurityScansSettingsController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('redirects guests to login', function () {
        $this->get(route('settings.security-scans.index'))->assertRedirect(route('login'));
        $this->patch(route('settings.security-scans.update'), [])->assertRedirect(route('login'));
        $this->post(route('settings.security-scans.runNow'), [])->assertRedirect(route('login'));
    });

    describe('index', function () {
        it('renders the page listing all three scan sources', function () {
            $response = $this->actingAs(User::factory()->create())
                ->get(route('settings.security-scans.index'));

            $response->assertOk()
                ->assertSee('Sucuri SiteCheck')
                ->assertSee('Core file integrity (wp-cli)')
                ->assertSee('Domain blacklists');
        });

        it('defaults every source to enabled when no setting row exists', function () {
            $response = $this->actingAs(User::factory()->create())
                ->get(route('settings.security-scans.index'));

            $response->assertOk();
            // No app_settings rows written yet — controller default is true.
            $this->assertDatabaseCount('app_settings', 0);
        });

        it('reflects a persisted disabled state and last-run timestamp', function () {
            AppSetting::query()->create(['key' => 'security_scans.checksums_enabled', 'value' => false]);
            AppSetting::query()->create(['key' => 'security_scans.checksums_last_run_at', 'value' => '2026-08-01T02:30:00Z']);

            $response = $this->actingAs(User::factory()->create())
                ->get(route('settings.security-scans.index'));

            $response->assertOk();
            $response->assertViewHas('sources', function ($sources) {
                return $sources['checksums']['enabled'] === false
                    && $sources['checksums']['last_run_at'] !== null;
            });
        });
    });

    describe('update', function () {
        it('persists enabled/disabled per source', function () {
            $response = $this->actingAs(User::factory()->create())
                ->patch(route('settings.security-scans.update'), [
                    'sources' => [
                        'sitecheck' => ['enabled' => '1'],
                        // checksums omitted entirely -> treated as false.
                        'blacklist' => ['enabled' => '1'],
                    ],
                ]);

            $response->assertRedirect(route('settings.security-scans.index'))
                ->assertSessionHas('status', 'Security scan settings saved.');

            $this->assertDatabaseHas('app_settings', ['key' => 'security_scans.sitecheck_enabled', 'value' => json_encode(true)]);
            $this->assertDatabaseHas('app_settings', ['key' => 'security_scans.checksums_enabled', 'value' => json_encode(false)]);
            $this->assertDatabaseHas('app_settings', ['key' => 'security_scans.blacklist_enabled', 'value' => json_encode(true)]);
        });

        it('disables all sources when the sources array is entirely omitted', function () {
            $this->actingAs(User::factory()->create())
                ->patch(route('settings.security-scans.update'), []);

            $this->assertDatabaseHas('app_settings', ['key' => 'security_scans.sitecheck_enabled', 'value' => json_encode(false)]);
            $this->assertDatabaseHas('app_settings', ['key' => 'security_scans.checksums_enabled', 'value' => json_encode(false)]);
            $this->assertDatabaseHas('app_settings', ['key' => 'security_scans.blacklist_enabled', 'value' => json_encode(false)]);
        });
    });

    describe('runNow', function () {
        it('starts the sitecheck command in the background', function () {
            $this->mock(BackgroundArtisan::class, function ($mock) {
                $mock->shouldReceive('start')
                    ->once()
                    ->withArgs(fn (string $key, array $cmds) => $key === 'security_scans.sitecheck'
                        && $cmds === ['clockwork:scan-sitecheck'])
                    ->andReturn(BackgroundArtisanResult::ok());
            });

            $response = $this->actingAs(User::factory()->create())
                ->post(route('settings.security-scans.runNow'), ['source' => 'sitecheck']);

            $response->assertRedirect()
                ->assertSessionHas('status', 'sitecheck scan started in the background — refresh this page in a few minutes.');
        });

        it('starts the checksums command in the background', function () {
            $this->mock(BackgroundArtisan::class, function ($mock) {
                $mock->shouldReceive('start')
                    ->once()
                    ->withArgs(fn (string $key, array $cmds) => $cmds === ['clockwork:verify-wp-core-checksums'])
                    ->andReturn(BackgroundArtisanResult::ok());
            });

            $this->actingAs(User::factory()->create())
                ->post(route('settings.security-scans.runNow'), ['source' => 'checksums'])
                ->assertSessionHas('status', function ($status) {
                    return str_contains($status, 'checksums scan started in the background');
                });
        });

        it('starts the blacklist command in the background', function () {
            $this->mock(BackgroundArtisan::class, function ($mock) {
                $mock->shouldReceive('start')
                    ->once()
                    ->withArgs(fn (string $key, array $cmds) => $cmds === ['clockwork:check-blacklists'])
                    ->andReturn(BackgroundArtisanResult::ok());
            });

            $this->actingAs(User::factory()->create())
                ->post(route('settings.security-scans.runNow'), ['source' => 'blacklist'])
                ->assertSessionHas('status', function ($status) {
                    return str_contains($status, 'blacklist scan started in the background');
                });
        });

        it('rejects an unknown source without queuing anything', function () {
            Queue::fake();

            $response = $this->actingAs(User::factory()->create())
                ->post(route('settings.security-scans.runNow'), ['source' => 'not-a-real-source']);

            $response->assertRedirect()
                ->assertSessionHas('queue_error', "Unknown source 'not-a-real-source'.");

            Queue::assertNotPushed(QueuedCommand::class);
        });

        it('rejects a missing source without queuing anything', function () {
            Queue::fake();

            $response = $this->actingAs(User::factory()->create())
                ->post(route('settings.security-scans.runNow'), []);

            $response->assertRedirect()
                ->assertSessionHas('queue_error', "Unknown source ''.");

            Queue::assertNotPushed(QueuedCommand::class);
        });
    });
});
