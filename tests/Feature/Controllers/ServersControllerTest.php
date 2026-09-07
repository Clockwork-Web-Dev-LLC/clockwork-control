<?php

use App\Models\BlockedIp;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\User;
use App\Services\CloudProvider\CloudProviderRegistry;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Contracts\CloudProvider;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| ServersController
|--------------------------------------------------------------------------
|
| store() and refreshFromSpinupWp() both funnel through the protected
| runSpinupWpImport() helper, which shells out to two artisan commands via
| the Artisan facade: `clockwork:import-spinupwp` then (only if that
| succeeds) `clockwork:poll-servers`. Both commands themselves reach real
| external clients (SpinupWpClient, DigitalOceanClient, HetznerClient,
| the cloud-provider metrics APIs) — rather than let those run for real (or
| mock 3-4 client classes just to steer them), we mock the Artisan facade
| directly, the same pattern already used in IngestSettingsControllerTest.
| This is the correct boundary: it proves the CONTROLLER wires exit
| code + output into the right flash message, without re-proving the
| import/poll commands' own internal logic.
|
| recheckHealth() is the one action injected with real service classes
| directly (CloudProviderRegistry, CpuStatusClassifier) rather than via
| Artisan — CloudProviderRegistry is mocked per-test to return a stub
| CloudProvider, since that's the actual external-I/O boundary
| (CloudProvider::metrics()).
*/

describe('ServersController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('requires authentication', function () {
        $server = Server::factory()->create();

        $this->post(route('servers.toggleIgnore', $server))
            ->assertRedirect(route('login'));
    });

    describe('create', function () {
        it('renders the add-server page for an authenticated user', function () {
            $response = $this->actingAs(User::factory()->create())
                ->get(route('servers.create'));

            $response->assertOk()->assertSee('Add server');
        });
    });

    describe('store', function () {
        it('creates a server with default ssh user/port, refreshes from SpinupWP, and redirects to servers.show', function () {
            Artisan::shouldReceive('call')->once()->with('clockwork:import-spinupwp')->andReturn(0);
            Artisan::shouldReceive('call')->once()->with('clockwork:poll-servers')->andReturn(0);
            Artisan::shouldReceive('output')->twice()->andReturn(
                'Servers: {"created":0,"updated":0,"unchanged":0,"spinupwp_id_nulled":0}',
                'Done. green=0 yellow=0 red=0 unknown=0 errors=0 deleted=0',
            );

            $response = $this->actingAs(User::factory()->create())->post(route('servers.store'), [
                'name' => 'new-server.example.com',
                'hostname' => '203.0.113.10',
                // The real /servers/new form always submits these two fields
                // (pre-filled with the config defaults) — send them blank
                // here to exercise store()'s `?:` fallback-to-config-default
                // path the same way a user clearing the field would.
                'ssh_user' => '',
                'ssh_port' => '',
            ]);

            $server = Server::where('name', 'new-server.example.com')->firstOrFail();

            $response->assertRedirect(route('servers.show', $server));
            $response->assertSessionHas('status', function ($msg) {
                return str_contains($msg, "Server 'new-server.example.com' created.")
                    && str_contains($msg, 'Refreshed from SpinupWP');
            });

            expect($server->hostname)->toBe('203.0.113.10');
            expect($server->ssh_user)->toBe(config('clockwork.ssh.default_user'));
            expect($server->ssh_port)->toBe((int) config('clockwork.ssh.default_port'));
            expect($server->status)->toBe(Server::STATUS_UNKNOWN);
            expect($server->is_ignored)->toBeFalse();
        });

        it('honors explicit ssh_user/ssh_port/is_ignored overrides', function () {
            Artisan::shouldReceive('call')->once()->with('clockwork:import-spinupwp')->andReturn(1);
            Artisan::shouldReceive('output')->once()->andReturn('CLOCKWORK_SPINUPWP_TOKEN is not set in .env.');

            $this->actingAs(User::factory()->create())->post(route('servers.store'), [
                'name' => 'custom-server.example.com',
                'hostname' => '203.0.113.11',
                'ssh_user' => 'deployer',
                'ssh_port' => 2222,
                'is_ignored' => true,
                'ignore_reason' => 'staging box',
            ]);

            $server = Server::where('name', 'custom-server.example.com')->firstOrFail();

            expect($server->ssh_user)->toBe('deployer');
            expect($server->ssh_port)->toBe(2222);
            expect($server->is_ignored)->toBeTrue();
            expect($server->ignore_reason)->toBe('staging box');
        });

        it('rejects a payload missing the required name/hostname fields, and creates no server', function () {
            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.store'), []);

            $response->assertSessionHasErrors(['name', 'hostname']);
            expect(Server::count())->toBe(0);
        });
    });

    describe('refreshFromSpinupWp', function () {
        it('flashes a success status when the import + poll succeed', function () {
            Artisan::shouldReceive('call')->once()->with('clockwork:import-spinupwp')->andReturn(0);
            Artisan::shouldReceive('call')->once()->with('clockwork:poll-servers')->andReturn(0);
            Artisan::shouldReceive('output')->twice()->andReturn(
                'Servers: {"created":2,"updated":1,"unchanged":0,"spinupwp_id_nulled":0}'."\n".'Sites: {"created":5}',
                'Done. green=3 yellow=0 red=0 unknown=0 errors=0 deleted=0',
            );

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.refreshFromSpinupWp'));

            $response->assertSessionHas('status', function ($msg) {
                return str_starts_with($msg, 'Refreshed from SpinupWP.')
                    && str_contains($msg, 'Servers: {"created":2');
            });
            $response->assertSessionMissing('status_error');
        });

        it('flashes a status_error when the import fails, and never calls poll-servers', function () {
            Artisan::shouldReceive('call')->once()->with('clockwork:import-spinupwp')->andReturn(1);
            Artisan::shouldReceive('call')->with('clockwork:poll-servers')->never();
            Artisan::shouldReceive('output')->once()->andReturn('CLOCKWORK_SPINUPWP_TOKEN is not set in .env.');

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.refreshFromSpinupWp'));

            $response->assertSessionHas('status_error', 'SpinupWP refresh failed. no summary');
            $response->assertSessionMissing('status');
        });
    });

    describe('refreshFromGridPane', function () {
        it('flashes a success status when the import + poll succeed', function () {
            Artisan::shouldReceive('call')->once()->with('clockwork:import-gridpane')->andReturn(0);
            Artisan::shouldReceive('call')->once()->with('clockwork:poll-servers')->andReturn(0);
            Artisan::shouldReceive('output')->twice()->andReturn(
                'Servers: {"created":1,"updated":0,"unchanged":0}'."\n".'Sites: {"created":3}',
                'Done. green=1 yellow=0 red=0 unknown=0 errors=0 deleted=0',
            );

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.refreshFromGridPane'));

            $response->assertSessionHas('status', function ($msg) {
                return str_starts_with($msg, 'Refreshed from GridPane.')
                    && str_contains($msg, 'Servers: {"created":1');
            });
            $response->assertSessionMissing('status_error');
        });

        it('flashes a status_error when the import fails, and never calls poll-servers', function () {
            Artisan::shouldReceive('call')->once()->with('clockwork:import-gridpane')->andReturn(1);
            Artisan::shouldReceive('call')->with('clockwork:poll-servers')->never();
            Artisan::shouldReceive('output')->once()->andReturn('GRIDPANE_API_KEY is not configured in .env or Settings.');

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.refreshFromGridPane'));

            $response->assertSessionHas('status_error', 'GridPane refresh failed. no summary');
            $response->assertSessionMissing('status');
        });
    });

    describe('toggleIgnore', function () {
        it('turns ignore ON, sets the reason, and redirects to servers.show by default', function () {
            $server = Server::factory()->create(['is_ignored' => false]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.toggleIgnore', $server), ['reason' => 'decommissioning']);

            $response->assertRedirect(route('servers.show', $server));
            $response->assertSessionHas('status', "{$server->name} is now ignored.");

            $server->refresh();
            expect($server->is_ignored)->toBeTrue();
            expect($server->ignore_reason)->toBe('decommissioning');
        });

        it('turns ignore OFF and clears the reason when already ignored', function () {
            $server = Server::factory()->ignored('old reason')->create();

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.toggleIgnore', $server));

            $response->assertSessionHas('status', "{$server->name} is no longer ignored.");

            $server->refresh();
            expect($server->is_ignored)->toBeFalse();
            expect($server->ignore_reason)->toBeNull();
        });

        it('redirects to the given return_to url instead of servers.show', function () {
            $server = Server::factory()->create(['is_ignored' => false]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.toggleIgnore', $server), ['return_to' => '/dashboard']);

            $response->assertRedirect('/dashboard');
        });

        it('404s for a nonexistent server id', function () {
            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.toggleIgnore', ['server' => 999999]));

            $response->assertNotFound();
        });
    });

    describe('toggleAutoBanLlar', function () {
        it('flips auto_ban_llar off -> on with the "will be banned automatically" message', function () {
            $server = Server::factory()->create(['auto_ban_llar' => false]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.toggleAutoBanLlar', $server));

            $response->assertRedirect();
            $response->assertSessionHas('status', "Auto-ban from LLAR enabled on {$server->name}. New lockouts will be banned automatically.");
            expect($server->fresh()->auto_ban_llar)->toBeTrue();
        });

        it('flips auto_ban_llar on -> off with the "review queue" message', function () {
            $server = Server::factory()->create(['auto_ban_llar' => true]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.toggleAutoBanLlar', $server));

            $response->assertSessionHas('status', "Auto-ban from LLAR disabled on {$server->name}. New lockouts will go to the review queue.");
            expect($server->fresh()->auto_ban_llar)->toBeFalse();
        });

        it('404s for a nonexistent server id', function () {
            $this->actingAs(User::factory()->create())
                ->post(route('servers.toggleAutoBanLlar', ['server' => 999999]))
                ->assertNotFound();
        });
    });

    describe('toggleAutoBanWordfence', function () {
        it('flips auto_ban_wordfence off -> on with the "will be banned automatically" message', function () {
            $server = Server::factory()->create(['auto_ban_wordfence' => false]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.toggleAutoBanWordfence', $server));

            $response->assertSessionHas('status', "Auto-ban from Wordfence enabled on {$server->name}. New blocks will be banned automatically.");
            expect($server->fresh()->auto_ban_wordfence)->toBeTrue();
        });

        it('flips auto_ban_wordfence on -> off with the "review queue" message', function () {
            $server = Server::factory()->create(['auto_ban_wordfence' => true]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.toggleAutoBanWordfence', $server));

            $response->assertSessionHas('status', "Auto-ban from Wordfence disabled on {$server->name}. New blocks will go to the review queue.");
            expect($server->fresh()->auto_ban_wordfence)->toBeFalse();
        });

        it('404s for a nonexistent server id', function () {
            $this->actingAs(User::factory()->create())
                ->post(route('servers.toggleAutoBanWordfence', ['server' => 999999]))
                ->assertNotFound();
        });
    });

    describe('destroy', function () {
        it('deletes the server and cascades sites + metrics when confirm_name matches, redirecting to dashboard', function () {
            $server = Server::factory()->create(['name' => 'doomed.example.com']);
            $site = Site::factory()->spinupwp()->create(['server_id' => $server->id]);
            $metric = ServerMetric::factory()->create(['server_id' => $server->id]);
            $ban = BlockedIp::factory()->create(['server_id' => $server->id]);

            $response = $this->actingAs(User::factory()->create())->delete(
                route('servers.destroy', $server),
                ['confirm_name' => 'doomed.example.com'],
            );

            $response->assertRedirect(route('dashboard'));
            $response->assertSessionHas('status', function ($msg) {
                return str_contains($msg, 'Removed doomed.example.com from Clockwork.')
                    && str_contains($msg, '1 sites, 1 metrics, 1 ban records.');
            });

            expect(Server::find($server->id))->toBeNull();
            expect(Site::withoutGlobalScopes()->find($site->id))->toBeNull();
            expect(ServerMetric::find($metric->id))->toBeNull();
            // blocked_ips.server_id is nullOnDelete, not cascadeOnDelete — the
            // ban record itself survives with its server link cleared.
            expect($ban->fresh()->server_id)->toBeNull();
        });

        it('refuses to delete and flashes a mismatch message when confirm_name does not match', function () {
            $server = Server::factory()->create(['name' => 'keep-me.example.com']);

            $response = $this->actingAs(User::factory()->create())->delete(
                route('servers.destroy', $server),
                ['confirm_name' => 'wrong-name'],
            );

            $response->assertRedirect(route('servers.show', $server));
            $response->assertSessionHas('status', "Confirmation name didn't match — server NOT deleted.");
            expect(Server::find($server->id))->not->toBeNull();
        });

        it('rejects a request missing confirm_name entirely', function () {
            $server = Server::factory()->create();

            $response = $this->actingAs(User::factory()->create())
                ->delete(route('servers.destroy', $server), []);

            $response->assertSessionHasErrors('confirm_name');
            expect(Server::find($server->id))->not->toBeNull();
        });
    });

    describe('recheckHealth', function () {
        it('returns 422 without touching any provider when the server has no provider_id', function () {
            $server = Server::factory()->create(['provider_id' => null]);

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.recheck-health', $server));

            $response->assertStatus(422)
                ->assertJson(['ok' => false, 'error' => 'Server has no provider ID — cannot poll.']);
        });

        it('classifies green status from cpu below the yellow threshold and updates last_polled_at', function () {
            $server = Server::factory()->digitalOcean()->create([
                'provider_id' => '555111',
                'status' => Server::STATUS_UNKNOWN,
                'last_polled_at' => null,
            ]);

            $providerMock = Mockery::mock(CloudProvider::class);
            $providerMock->shouldReceive('metrics')->once()->andReturn([
                'cpu_pct' => 42.0, 'memory_pct' => null, 'disk_pct' => null, 'load_1' => null,
            ]);
            $this->mock(CloudProviderRegistry::class, function ($mock) use ($server, $providerMock) {
                $mock->shouldReceive('resolve')->once()->with($server->provider)->andReturn($providerMock);
            });

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.recheck-health', $server));

            $response->assertOk()->assertJson([
                'ok' => true,
                'status' => Server::STATUS_GREEN,
                'cpu_pct' => 42.0,
                'last_polled' => 'just now',
            ]);

            $server->refresh();
            expect($server->status)->toBe(Server::STATUS_GREEN);
            expect($server->last_polled_at)->not->toBeNull();
        });

        it('classifies red status at/above the red threshold and stamps last_alert_at on the transition into red', function () {
            $server = Server::factory()->digitalOcean()->create([
                'provider_id' => '555111',
                'status' => Server::STATUS_GREEN,
                'last_alert_at' => null,
            ]);

            $providerMock = Mockery::mock(CloudProvider::class);
            $providerMock->shouldReceive('metrics')->once()->andReturn([
                'cpu_pct' => 95.0, 'memory_pct' => null, 'disk_pct' => null, 'load_1' => null,
            ]);
            $this->mock(CloudProviderRegistry::class, function ($mock) use ($server, $providerMock) {
                $mock->shouldReceive('resolve')->once()->with($server->provider)->andReturn($providerMock);
            });

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.recheck-health', $server));

            $response->assertOk()->assertJsonPath('status', Server::STATUS_RED);

            $server->refresh();
            expect($server->status)->toBe(Server::STATUS_RED);
            expect($server->last_alert_at)->not->toBeNull();
        });

        it('returns 500 with the exception message when the provider metrics call throws', function () {
            $server = Server::factory()->digitalOcean()->create(['provider_id' => '555111']);

            $providerMock = Mockery::mock(CloudProvider::class);
            $providerMock->shouldReceive('metrics')->once()->andThrow(new RuntimeException('DO API timed out'));
            $this->mock(CloudProviderRegistry::class, function ($mock) use ($server, $providerMock) {
                $mock->shouldReceive('resolve')->once()->with($server->provider)->andReturn($providerMock);
            });

            $response = $this->actingAs(User::factory()->create())
                ->post(route('servers.recheck-health', $server));

            $response->assertStatus(500)
                ->assertJson(['ok' => false, 'error' => 'DO API timed out']);
        });

        it('404s for a nonexistent server id', function () {
            $this->actingAs(User::factory()->create())
                ->post(route('servers.recheck-health', ['server' => 999999]))
                ->assertNotFound();
        });
    });
});
