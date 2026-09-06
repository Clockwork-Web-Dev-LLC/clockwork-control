<?php

namespace Tests\Feature\Console;

use App\Models\ActionLog;
use App\Models\Server;
use App\Models\Site;
use App\Services\Ssh\SshClient;

/*
|--------------------------------------------------------------------------
| Phase 6 console coverage for clockwork:scan-wp7-truncation
|--------------------------------------------------------------------------
|
| SshClient::exec() is mocked wholesale — this command shells out (find,
| grep, and a multi-step `wp core download` recovery script), so it must
| never touch a real SSH connection. The mock dispatches a canned response
| per command shape (detect / read-version / repair-script) so both the
| detect-only and --repair flows can be exercised end to end.
*/

function w7Server(): Server
{
    return Server::factory()->create(['ssh_password' => 'super-secret']);
}

function w7MockSsh(string $findOutput = "0\n", ?string $versionOutput = null, ?string $repairOutput = null): void
{
    test()->mock(SshClient::class, function ($mock) use ($findOutput, $versionOutput, $repairOutput) {
        $mock->shouldReceive('exec')->andReturnUsing(function ($server, $cmd, $timeout = null) use ($findOutput, $versionOutput, $repairOutput) {
            if (str_contains($cmd, 'wc -l')) {
                return $findOutput;
            }
            if (str_contains($cmd, 'wp_version')) {
                return $versionOutput ?? "\$wp_version = '7.0.1';\n";
            }
            if (str_starts_with($cmd, 'CW_SUDO_PW=')) {
                return $repairOutput ?? "BACKUP=/sites/x/wp-includes/php-ai-client.bak.20260828\nVERIFY_OK\n";
            }

            return '';
        });
    });
}

describe('clockwork:scan-wp7-truncation — detect only', function () {
    it('reports a clean site as unaffected and exits SUCCESS', function () {
        $site = Site::factory()->withCompanionInstalled()->create(['server_id' => w7Server()->id]);
        w7MockSsh(findOutput: "0\n");

        $this->artisan('clockwork:scan-wp7-truncation')->assertSuccessful();

        expect(ActionLog::query()->count())->toBe(0);
    });

    it('flags an affected site and exits FAILURE without --repair', function () {
        $site = Site::factory()->withCompanionInstalled()->create(['server_id' => w7Server()->id]);
        w7MockSsh(findOutput: "3\n");

        $this->artisan('clockwork:scan-wp7-truncation')->assertFailed();

        // Detect-only mode never writes a repair log.
        expect(ActionLog::query()->where('action_type', ActionLog::TYPE_WP_CORE_REPAIRED)->count())->toBe(0);
    });

    it('skips sites whose server has no ssh_password on file', function () {
        $server = Server::factory()->create(['ssh_password' => null]);
        Site::factory()->withCompanionInstalled()->create(['server_id' => $server->id]);

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldNotReceive('exec');
        });

        $this->artisan('clockwork:scan-wp7-truncation')->assertSuccessful();
    });

    it('does not crash and stays SUCCESS when the SSH probe itself throws', function () {
        Site::factory()->withCompanionInstalled()->create(['server_id' => w7Server()->id]);

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')->once()->andThrow(new \RuntimeException('connection refused'));
        });

        $this->artisan('clockwork:scan-wp7-truncation')->assertSuccessful();
    });

    it('excludes sites on ignored servers and non-Companion sites', function () {
        $ignoredServer = Server::factory()->ignored()->create(['ssh_password' => 'x']);
        Site::factory()->withCompanionInstalled()->create(['server_id' => $ignoredServer->id]);
        Site::factory()->create(['companion_installed' => false, 'server_id' => w7Server()->id]);

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldNotReceive('exec');
        });

        $this->artisan('clockwork:scan-wp7-truncation')->assertSuccessful();
    });

    it('--site limits the scan to a single site by ID or domain', function () {
        $target = Site::factory()->withCompanionInstalled()->create(['domain' => 'target7.example.test', 'server_id' => w7Server()->id]);
        Site::factory()->withCompanionInstalled()->create(['domain' => 'other7.example.test', 'server_id' => w7Server()->id]);
        w7MockSsh(findOutput: "0\n");

        $this->artisan('clockwork:scan-wp7-truncation', ['--site' => $target->domain])->assertSuccessful();
    });
});

describe('clockwork:scan-wp7-truncation --repair', function () {
    it('repairs an affected site and logs a successful ActionLog row', function () {
        $server = w7Server();
        $site = Site::factory()->withCompanionInstalled()->create(['server_id' => $server->id, 'domain' => 'repair-me.example.test']);
        w7MockSsh(findOutput: "2\n");

        $this->artisan('clockwork:scan-wp7-truncation', ['--repair' => true])->assertSuccessful();

        $log = ActionLog::query()->where('action_type', ActionLog::TYPE_WP_CORE_REPAIRED)->where('site_id', $site->id)->first();
        expect($log)->not->toBeNull()
            ->and($log->ok)->toBeTrue()
            ->and($log->details['wp_version'] ?? null)->toBe('7.0.1');
    });

    it('logs a failed ActionLog row when the repair script never reports VERIFY_OK', function () {
        $server = w7Server();
        $site = Site::factory()->withCompanionInstalled()->create(['server_id' => $server->id]);
        w7MockSsh(findOutput: "1\n", repairOutput: "some unrelated output, no verify marker\n");

        // Documented gap: the command's exit-code formula is
        // `($affected > 0 && !$repair) ? FAILURE : SUCCESS` — with --repair
        // passed, this is unconditionally SUCCESS even when every repair
        // attempt failed. Asserted here as current behavior, not endorsed.
        $this->artisan('clockwork:scan-wp7-truncation', ['--repair' => true])->assertSuccessful();

        $log = ActionLog::query()->where('action_type', ActionLog::TYPE_WP_CORE_REPAIRED)->where('site_id', $site->id)->first();
        expect($log)->not->toBeNull()
            ->and($log->ok)->toBeFalse()
            ->and($log->error)->toContain('verify failed');
    });
});
