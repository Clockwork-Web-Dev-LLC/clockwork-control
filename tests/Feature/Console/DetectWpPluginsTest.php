<?php

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\WpPluginDetector;
use App\Services\Ssh\SshClient;

/*
|--------------------------------------------------------------------------
| clockwork:detect-wp-plugins
|--------------------------------------------------------------------------
|
| WpPluginDetector::detect() is exercised for real here (not mocked away)
| because it's the thing that actually mutates llar_enabled/wordfence_enabled
| — only its SSH boundary (SshClient::exec) is faked, matching every other
| wp-cli-probe command in this suite (BlockUserAgentTest, ScanWp7TruncationTest,
| etc.). Site-selection-only cases mock WpPluginDetector directly since the
| detection outcome is irrelevant to which sites get selected.
*/

function wpPluginSite(array $siteOverrides = [], array $serverOverrides = []): Site
{
    $server = Server::factory()->create(array_merge(['ssh_password' => 'sudo-pass'], $serverOverrides));

    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'is_wordpress' => true,
        'site_user' => 'siteuser',
        'domain' => 'wpplugins.example.com',
    ], $siteOverrides));
}

describe('clockwork:detect-wp-plugins — real detection through a mocked SSH boundary', function () {
    it('marks both llar and wordfence enabled when wp-cli reports both active, and stamps wp_plugins_detected_at', function () {
        $site = wpPluginSite();

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn(
            "limit-login-attempts-reloaded\nwordfence\n__CLOCKWORK_WP_EXIT__:0"
        );

        $this->artisan('clockwork:detect-wp-plugins')
            ->expectsOutputToContain('detected=1, changes=1, skipped=0, failed=0')
            ->assertSuccessful();

        $site->refresh();
        expect($site->llar_enabled)->toBeTrue()
            ->and($site->wordfence_enabled)->toBeTrue()
            ->and($site->wp_plugins_detected_at)->not->toBeNull();
    });

    it('marks both disabled when wp-cli reports neither plugin active', function () {
        $site = wpPluginSite();

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn(
            '__CLOCKWORK_WP_EXIT__:0'
        );

        $this->artisan('clockwork:detect-wp-plugins')
            ->expectsOutputToContain('detected=1, changes=0, skipped=0, failed=0')
            ->assertSuccessful();

        $site->refresh();
        expect($site->llar_enabled)->toBeFalse()
            ->and($site->wordfence_enabled)->toBeFalse();
    });

    it('counts a nonzero wp-cli exit as failed and exits FAILURE, without writing plugin state', function () {
        $site = wpPluginSite();

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn(
            "some error\n__CLOCKWORK_WP_EXIT__:1"
        );

        $this->artisan('clockwork:detect-wp-plugins')
            ->expectsOutputToContain('detected=0, changes=0, skipped=0, failed=1')
            ->assertFailed();

        expect($site->refresh()->wp_plugins_detected_at)->toBeNull();
    });
});

describe('clockwork:detect-wp-plugins — site selection', function () {
    it('defaults to WordPress sites on monitored (non-ignored) servers', function () {
        $eligible = wpPluginSite(['domain' => 'eligible.example.com']);
        $nonWp = wpPluginSite(['is_wordpress' => false, 'domain' => 'nonwp.example.com']);
        $ignoredServer = Server::factory()->ignored()->create();
        $onIgnored = Site::factory()->create([
            'server_id' => $ignoredServer->id,
            'is_wordpress' => true,
            'domain' => 'ignored-server.example.com',
        ]);

        $this->mock(WpPluginDetector::class, function ($mock) use ($eligible) {
            $mock->shouldReceive('detect')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($eligible))
                ->andReturn(['result' => WpPluginDetector::RESULT_DETECTED, 'message' => 'ok', 'llar' => false, 'wordfence' => false]);
        });

        $this->artisan('clockwork:detect-wp-plugins')->assertSuccessful();
    });

    it('--site limits to a single site by domain, ignoring the default WP/monitored scope query for other sites', function () {
        $target = wpPluginSite(['domain' => 'target.example.com']);
        wpPluginSite(['domain' => 'other.example.com']);

        $this->mock(WpPluginDetector::class, function ($mock) use ($target) {
            $mock->shouldReceive('detect')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($target))
                ->andReturn(['result' => WpPluginDetector::RESULT_DETECTED, 'message' => 'ok', 'llar' => false, 'wordfence' => false]);
        });

        $this->artisan('clockwork:detect-wp-plugins', ['--site' => 'target.example.com'])->assertSuccessful();
    });

    it('warns and succeeds when no sites match', function () {
        $this->mock(WpPluginDetector::class, fn ($mock) => $mock->shouldNotReceive('detect'));

        $this->artisan('clockwork:detect-wp-plugins')
            ->expectsOutputToContain('No sites match.')
            ->assertSuccessful();
    });
});
