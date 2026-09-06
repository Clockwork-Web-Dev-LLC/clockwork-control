<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\ThreatLog;
use App\Services\Logs\NginxLogParser;
use App\Services\Ssh\SshClient;

/*
|--------------------------------------------------------------------------
| Call-site coverage for IngestRotatedLogs
|--------------------------------------------------------------------------
|
| This command shells out to SSH (`test -f`, `zcat | grep`) directly via
| SshClient::exec() and parses the result via NginxLogParser::parse() — both
| are mocked here so this suite never attempts a real SSH connection.
*/

function irlMonitoredSite(array $siteOverrides = []): Site
{
    $server = Server::factory()->create(['last_ssh_ok_at' => now(), 'is_ignored' => false]);

    return Site::factory()->spinupwp()->create(array_merge(
        ['server_id' => $server->id],
        $siteOverrides,
    ));
}

describe('IngestRotatedLogs', function () {
    it('fails fast with no valid --dates', function () {
        $this->artisan('clockwork:ingest-rotated-logs')
            ->expectsOutputToContain('No valid dates')
            ->assertFailed();
    });

    it('fails fast when --dates only contains unparseable values', function () {
        $this->artisan('clockwork:ingest-rotated-logs', ['--dates' => 'not-a-date'])
            ->expectsOutputToContain('No valid dates')
            ->assertFailed();
    });

    it('inserts parsed rows into threat_logs for a rotated file that exists', function () {
        $site = irlMonitoredSite(['domain' => 'rotated.example.com']);

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')
                ->once()
                ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'test -f') && str_contains($cmd, '-20260718.gz'))
                ->andReturn('exists');

            $mock->shouldReceive('exec')
                ->once()
                ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'zcat') && str_contains($cmd, 'grep'))
                ->andReturn("raw log bytes for 17/Jul/2026\n");
        });

        $this->mock(NginxLogParser::class, function ($mock) use ($site) {
            $mock->shouldReceive('parse')
                ->once()
                ->withArgs(fn ($contents, Site $s) => $s->is($site))
                ->andReturn([
                    [
                        'site_id' => $site->id,
                        'source' => ThreatLog::SOURCE_NGINX,
                        'event_at' => now(),
                        'ip' => '1.2.3.4',
                        'user_agent' => 'ua',
                        'request_path' => '/wp-login.php',
                        'request_method' => 'POST',
                        'status_code' => 403,
                        'raw' => json_encode([]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);
        });

        $this->artisan('clockwork:ingest-rotated-logs', ['--dates' => '2026-07-17'])
            ->expectsOutputToContain('Total inserted: 1. Failures: 0.')
            ->assertSuccessful();

        expect(ThreatLog::query()->where('site_id', $site->id)->count())->toBe(1);
    });

    it('skips a site/date when the rotated file does not exist on the server', function () {
        irlMonitoredSite();

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')
                ->once()
                ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'test -f'))
                ->andReturn('missing');
        });

        $this->mock(NginxLogParser::class, function ($mock) {
            $mock->shouldNotReceive('parse');
        });

        $this->artisan('clockwork:ingest-rotated-logs', ['--dates' => '2026-07-17'])
            ->expectsOutputToContain('Total inserted: 0. Failures: 0.')
            ->assertSuccessful();

        expect(ThreatLog::query()->count())->toBe(0);
    });

    it('counts an SSH failure without aborting the whole run', function () {
        irlMonitoredSite();

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')
                ->once()
                ->andThrow(new RuntimeException('Cannot reach server'));
        });

        $this->artisan('clockwork:ingest-rotated-logs', ['--dates' => '2026-07-17'])
            ->expectsOutputToContain('Total inserted: 0. Failures: 1.')
            ->assertSuccessful();
    });

    it('filters to a single site via --site', function () {
        $target = irlMonitoredSite(['domain' => 'target.example.com']);
        irlMonitoredSite(); // decoy

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')
                ->once()
                ->andReturn('missing');
        });

        $this->artisan('clockwork:ingest-rotated-logs', [
            '--dates' => '2026-07-17',
            '--site' => 'target.example.com',
        ])->assertSuccessful();
    });

    it('parses multiple comma-separated dates for the same site', function () {
        irlMonitoredSite();

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')
                ->twice() // one `test -f` per date, both report missing
                ->andReturn('missing');
        });

        $this->artisan('clockwork:ingest-rotated-logs', ['--dates' => '2026-07-17,2026-07-18'])
            ->assertSuccessful();
    });
});
