<?php

namespace Tests\Feature;

use App\Models\ActionLog;
use App\Models\Server;
use App\Models\Tag;
use App\Services\Servers\AptUpdateProbe;
use App\Services\Servers\ServerUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers clockwork:process-server-updates' failure path — it must never
 * make a real SSH/apt-get call, so ServerUpdater and AptUpdateProbe are
 * always mocked here. Mattermost is disabled fleet-wide in .env.testing,
 * so the one test that asserts a webhook fires enables it explicitly via
 * config() — Http::fake() still blocks the request from ever leaving the
 * process either way.
 */
class ProcessServerUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private function makeQueuedServer(): Server
    {
        return Server::query()->create([
            'name' => 'test-verify-server',
            'hostname' => 'test-verify-server.example.com',
            'ssh_user' => 'root',
            'ip_address' => '10.0.0.1',
            'update_status' => Server::UPDATE_STATUS_QUEUED,
            'update_queued_at' => now(),
        ]);
    }

    public function test_failed_update_logs_and_alerts(): void
    {
        config(['clockwork.mattermost.enabled' => true, 'clockwork.mattermost.webhook_url' => 'https://mattermost.example.com/hooks/test']);
        Http::fake();
        $server = $this->makeQueuedServer();

        $this->mock(ServerUpdater::class, function ($mock) {
            $mock->shouldReceive('update')->once()->andReturn([
                'ok' => false,
                'output' => 'E: Unable to fetch some archives, maybe run apt-get update',
                'reboot_required_after' => false,
                'reboot_scheduled' => false,
                'nginx_state_after' => 'active',
            ]);
        });

        $this->artisan('clockwork:process-server-updates')->assertSuccessful();

        $server->refresh();
        $this->assertSame(Server::UPDATE_STATUS_FAILED, $server->update_status);

        $log = ActionLog::query()
            ->where('server_id', $server->id)
            ->where('action_type', ActionLog::TYPE_SERVER_UPDATE_FAILED)
            ->first();
        $this->assertNotNull($log);
        $this->assertFalse($log->ok);
        $this->assertStringContainsString('Unable to fetch', (string) $log->error);

        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'Server update failed'));
    }

    public function test_successful_update_does_not_alert_or_log_a_failure(): void
    {
        Http::fake();
        $server = $this->makeQueuedServer();

        $this->mock(ServerUpdater::class, function ($mock) {
            $mock->shouldReceive('update')->once()->andReturn([
                'ok' => true,
                'output' => 'ok',
                'reboot_required_after' => false,
                'reboot_scheduled' => false,
                'nginx_state_after' => 'active',
            ]);
        });

        // Success path also re-polls via AptUpdateProbe — mock it so this
        // test never touches anything SSH-shaped even on the happy path.
        $this->mock(AptUpdateProbe::class, function ($mock) {
            $mock->shouldReceive('probe')->andThrow(new \RuntimeException('not relevant to this test'));
        });

        $this->artisan('clockwork:process-server-updates')->assertSuccessful();

        $server->refresh();
        $this->assertSame(Server::UPDATE_STATUS_COMPLETED, $server->update_status);
        $this->assertSame(0, ActionLog::query()->where('server_id', $server->id)->count());
        Http::assertNothingSent();
    }

    public function test_limit_option_caps_how_many_queued_servers_are_processed_in_one_tick(): void
    {
        Http::fake();
        $first = $this->makeQueuedServer();
        // Ensure deterministic orderBy('update_queued_at') ordering.
        $second = Server::query()->create([
            'name' => 'test-verify-server-2',
            'hostname' => 'test-verify-server-2.example.com',
            'ssh_user' => 'root',
            'ip_address' => '10.0.0.2',
            'update_status' => Server::UPDATE_STATUS_QUEUED,
            'update_queued_at' => now()->addMinute(),
        ]);

        $this->mock(ServerUpdater::class, function ($mock) {
            $mock->shouldReceive('update')->once()->andReturn([
                'ok' => true,
                'output' => 'ok',
                'reboot_required_after' => false,
                'reboot_scheduled' => false,
                'nginx_state_after' => 'active',
            ]);
        });
        $this->mock(AptUpdateProbe::class, function ($mock) {
            $mock->shouldReceive('probe')->andThrow(new \RuntimeException('not relevant to this test'));
        });

        $this->artisan('clockwork:process-server-updates', ['--limit' => 1])->assertSuccessful();

        $this->assertSame(Server::UPDATE_STATUS_COMPLETED, $first->fresh()->update_status);
        $this->assertSame(Server::UPDATE_STATUS_QUEUED, $second->fresh()->update_status);
    }

    public function test_staging_tagged_server_is_still_drained_for_system_updates(): void
    {
        Http::fake();
        $server = $this->makeQueuedServer();
        $server->tags()->attach(Tag::factory()->create(['slug' => 'staging']));

        $this->mock(ServerUpdater::class, function ($mock) {
            $mock->shouldReceive('update')->once()->andReturn([
                'ok' => true,
                'output' => 'ok',
                'reboot_required_after' => false,
                'reboot_scheduled' => false,
                'nginx_state_after' => 'active',
            ]);
        });
        $this->mock(AptUpdateProbe::class, function ($mock) {
            $mock->shouldReceive('probe')->andThrow(new \RuntimeException('not relevant to this test'));
        });

        $this->artisan('clockwork:process-server-updates')->assertSuccessful();

        $this->assertSame(Server::UPDATE_STATUS_COMPLETED, $server->fresh()->update_status);
    }

    public function test_immediate_reboot_passes_always_reboot_to_updater(): void
    {
        Http::fake();
        $server = $this->makeQueuedServer();
        $server->update([
            'scheduled_reboot_at' => now()->subMinute(),
        ]);

        $this->mock(ServerUpdater::class, function ($mock) use ($server) {
            $mock->shouldReceive('update')
                ->once()
                ->withArgs(function ($srv, $rebootAt, $alwaysReboot) use ($server) {
                    return $srv->id === $server->id
                        && $rebootAt === null
                        && $alwaysReboot === true;
                })
                ->andReturn([
                    'ok' => true,
                    'output' => 'ok',
                    'reboot_required_after' => false,
                    'reboot_scheduled' => true,
                    'nginx_state_after' => 'active',
                ]);
        });

        $this->mock(AptUpdateProbe::class, function ($mock) {
            $mock->shouldReceive('probe')->andThrow(new \RuntimeException('not relevant to this test'));
        });

        $this->artisan('clockwork:process-server-updates')->assertSuccessful();

        $this->assertSame(Server::UPDATE_STATUS_COMPLETED, $server->fresh()->update_status);
    }
}
