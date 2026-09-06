<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Models\Site;
use App\Services\Ssh\SshClient;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Coverage for clockwork:diagnose-companion-install
|--------------------------------------------------------------------------
|
| Read-only triage command. SshClient::exec() is mocked at its outer
| boundary (the command's own runAsSiteUser() sentinel-wrapping logic runs
| for real against the mocked return strings, so those fixtures must
| already look like real remote output — sentinel + exit code included).
| ClockworkCompanionClient's /health probe is faked at the HTTP boundary,
| same convention as tests/Feature/Companion/CompanionHmacAuthTest.php.
*/

/**
 * Builds the raw string SshClient::exec() would return for a probe that
 * exits with $exitCode and prints $output on stdout — mirroring
 * DiagnoseCompanionInstall::runAsSiteUser()'s sentinel-echo wrapper
 * (`... ; echo "__CLOCKWORK_DIAG_EXIT__:$?"`).
 */
function diagProbeOutput(string $output, int $exitCode = 0): string
{
    return ($output === '' ? '' : $output."\n").'__CLOCKWORK_DIAG_EXIT__:'.$exitCode;
}

describe('clockwork:diagnose-companion-install — site resolution', function () {
    it('fails when no site matches the given id-or-domain', function () {
        $this->artisan('clockwork:diagnose-companion-install', ['site' => 'no-such-site.example'])
            ->assertFailed();
    });

    it('fails with a clear message when prerequisites are missing (no site_user)', function () {
        $server = Server::factory()->create(['ssh_password' => 'secret']);
        $site = Site::factory()->create(['server_id' => $server->id, 'site_user' => null]);

        $this->artisan('clockwork:diagnose-companion-install', ['site' => $site->domain])
            ->assertFailed()
            ->expectsOutputToContain('Missing prerequisites');
    });
});

describe('clockwork:diagnose-companion-install — healthy site', function () {
    it('walks all probes, finds matching secrets, and prints the healthy verdict', function () {
        $server = Server::factory()->create(['ssh_password' => 'sudo-pw']);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'site_user' => 'siteuser',
            'domain' => 'healthy-site.example',
            'wp_path' => '/sites/healthy-site.example/files',
            'table_prefix' => 'wp_',
            'companion_secret' => 'sharedsecretvalue1234',
        ]);

        $this->mock(SshClient::class, function ($mock) use ($site) {
            $mock->shouldReceive('exec')
                ->withArgs(fn (Server $s, string $cmd) => $s->is($site->server) && str_contains($cmd, 'option get siteurl'))
                ->andReturn(diagProbeOutput('https://healthy-site.example', 0));

            $mock->shouldReceive('exec')
                ->withArgs(fn (Server $s, string $cmd) => str_contains($cmd, 'which wp'))
                ->andReturn(diagProbeOutput("/usr/local/bin/wp\nWP-CLI 2.9.0", 0));

            $mock->shouldReceive('exec')
                ->withArgs(fn (Server $s, string $cmd) => str_contains($cmd, 'table_prefix'))
                ->andReturn(diagProbeOutput("\$table_prefix = 'wp_';", 0));

            $mock->shouldReceive('exec')
                ->withArgs(fn (Server $s, string $cmd) => str_contains($cmd, 'db query'))
                ->andReturn(diagProbeOutput('sharedsecretvalue1234', 0));
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/health" => Http::response(
                ['version' => '1.30.4', 'capabilities' => ['resource-sampler']],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:diagnose-companion-install', ['site' => $site->domain])
            ->assertSuccessful()
            ->expectsOutputToContain('HTTP 200')
            ->expectsOutputToContain('Secrets match, wp-cli works, /health is 200. Companion is healthy.');
    });
});

describe('clockwork:diagnose-companion-install — broken site (Cluster 1: wp-cli cannot boot)', function () {
    it('surfaces the wp-cli-boot-failure verdict when probe A exits non-zero', function () {
        $server = Server::factory()->create(['ssh_password' => 'sudo-pw']);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'site_user' => 'siteuser',
            'domain' => 'broken-site.example',
            'wp_path' => '/sites/broken-site.example/files',
            'table_prefix' => 'wp_',
            'companion_secret' => 'somesecret',
        ]);

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')
                ->withArgs(fn (Server $s, string $cmd) => str_contains($cmd, 'option get siteurl'))
                ->andReturn(diagProbeOutput('Error: wp-cli not found', 1));

            $mock->shouldReceive('exec')
                ->withArgs(fn (Server $s, string $cmd) => str_contains($cmd, 'which wp'))
                ->andReturn(diagProbeOutput('(no wp at /usr/local/bin/wp)', 0));

            $mock->shouldReceive('exec')
                ->withArgs(fn (Server $s, string $cmd) => str_contains($cmd, 'table_prefix'))
                ->andReturn(diagProbeOutput('', 1));

            $mock->shouldReceive('exec')
                ->withArgs(fn (Server $s, string $cmd) => str_contains($cmd, 'db query'))
                ->andReturn(diagProbeOutput('', 1));
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/health" => Http::response(
                ['message' => 'invalid_signature'],
                401,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:diagnose-companion-install', ['site' => $site->domain])
            ->assertSuccessful()
            ->expectsOutputToContain('[Cluster 1] wp-cli cannot boot at the assumed path.')
            ->expectsOutputToContain('clockwork:extract-wp-configs --site=broken-site.example');
    });
});

describe('clockwork:diagnose-companion-install — broken site (table_prefix mismatch)', function () {
    it('detects a table_prefix mismatch between the Site model and wp-config.php', function () {
        $server = Server::factory()->create(['ssh_password' => 'sudo-pw']);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'site_user' => 'siteuser',
            'domain' => 'prefix-mismatch.example',
            'wp_path' => '/sites/prefix-mismatch.example/files',
            'table_prefix' => 'wp_',
            'companion_secret' => 'somesecret',
        ]);

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')
                ->withArgs(fn (Server $s, string $cmd) => str_contains($cmd, 'option get siteurl'))
                ->andReturn(diagProbeOutput('https://prefix-mismatch.example', 0));

            $mock->shouldReceive('exec')
                ->withArgs(fn (Server $s, string $cmd) => str_contains($cmd, 'which wp'))
                ->andReturn(diagProbeOutput("/usr/local/bin/wp\nWP-CLI 2.9.0", 0));

            $mock->shouldReceive('exec')
                ->withArgs(fn (Server $s, string $cmd) => str_contains($cmd, 'table_prefix'))
                ->andReturn(diagProbeOutput("\$table_prefix = 'wpxyz_';", 0));

            $mock->shouldReceive('exec')
                ->withArgs(fn (Server $s, string $cmd) => str_contains($cmd, 'db query'))
                ->andReturn(diagProbeOutput('', 1));
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/health" => Http::response([], 401, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:diagnose-companion-install', ['site' => $site->domain])
            ->assertSuccessful()
            ->expectsOutputToContain('PREFIX MISMATCH')
            ->expectsOutputToContain('[Cluster 1, prefix variant] table_prefix mismatch.');
    });
});
