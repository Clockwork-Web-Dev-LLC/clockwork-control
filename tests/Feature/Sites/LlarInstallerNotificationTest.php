<?php

namespace Tests\Feature\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Services\Sites\LlarInstaller;
use App\Services\Ssh\SshClient;

/*
|--------------------------------------------------------------------------
| LlarInstaller::process() notification call-site coverage
|--------------------------------------------------------------------------
|
| Phase 4 of the notification call-site build-out. This file is only about
| the two $this->mattermost->llarInstalled($site) call sites in
| LlarInstaller::process() (source lines ~107 and ~164) — confirming each
| fires under its real triggering condition and does NOT fire when it
| shouldn't. It is NOT about ChatNotifierDispatcher's own is_inactive gating
| (see tests/Feature/Chat/ChatNotifierGatingTest.php for that) and it is NOT
| a full re-test of LlarInstaller's wp-cli command sequencing — just enough
| of each path to reach (or deliberately not reach) the notify call.
|
| SshClient::exec() is mocked directly; every mocked return string carries
| the same "__CLOCKWORK_WP_EXIT__:$exit" sentinel suffix that
| LlarInstaller::run()'s private parser regexes for and strips — omitting it
| would make every call look like exit=-1 regardless of the intended exit
| code.
*/

/**
 * Build a raw wp-cli output string exactly as SshClient::exec() would
 * return it for a `wp ...; echo "__CLOCKWORK_WP_EXIT__:$?"` invocation.
 */
function wpOutput(string $output, int $exit): string
{
    return $output."\n__CLOCKWORK_WP_EXIT__:{$exit}";
}

/**
 * A site with everything LlarInstaller::process() requires to get past its
 * early guards and actually reach SSH: is_wordpress, a linked server, a
 * site_user, and a server ssh_password.
 */
function llarReadySite(array $siteOverrides = []): Site
{
    return Site::factory()
        ->spinupwp()
        ->create(array_merge([
            'is_wordpress' => true,
            'site_user' => 'clockwork-site',
            'wp_path' => '/sites/example.com/files',
            'server_id' => Server::factory()->create([
                'ssh_password' => 'super-secret-sudo-pw',
            ]),
        ], $siteOverrides));
}

describe('LlarInstaller::process() — fresh install path (line ~164)', function () {
    it('fires llarInstalled once after a clean install + activate + email suppression', function () {
        $site = llarReadySite();

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')->andReturn(
                wpOutput('', 1),                                  // plugin is-installed -> not installed
                wpOutput('Success: installed and activated.', 0), // plugin install --activate
                wpOutput('Success: updated.', 0),                 // lockout_notify cleared
                wpOutput('Success: updated.', 0),                 // admin_notify_email blank
                wpOutput('Success: updated.', 0),                 // notify_after maxed
                wpOutput('Success: updated.', 0),                 // onboarding popup hidden
                wpOutput('Success: updated.', 0),                 // review notice hidden
            );
        });

        $this->mock(ChatNotifier::class, function ($mock) use ($site) {
            $mock->shouldReceive('llarInstalled')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturn(true);
        });

        $result = app(LlarInstaller::class)->process($site);

        expect($result['result'])->toBe(LlarInstaller::RESULT_INSTALLED);
    });

    it('does NOT fire llarInstalled when a required email-suppression step fails after a fresh install', function () {
        $site = llarReadySite();

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')->andReturn(
                wpOutput('', 1),                                  // plugin is-installed -> not installed
                wpOutput('Success: installed and activated.', 0), // plugin install --activate
                wpOutput('Error: could not set option.', 1),      // lockout_notify cleared -> FAILS
            );
        });

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('llarInstalled')->never();
        });

        $result = app(LlarInstaller::class)->process($site);

        expect($result['result'])->toBe(LlarInstaller::RESULT_FAILED)
            ->and($result['message'])->toContain('Aborting before notifying Mattermost');
    });
});

describe('LlarInstaller::process() — already-on-disk-but-inactive path (line ~107)', function () {
    it('fires llarInstalled once after activating an already-present-but-inactive plugin', function () {
        $site = llarReadySite();

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')->andReturn(
                wpOutput('', 0),                    // plugin is-installed -> installed
                wpOutput('', 1),                    // plugin is-active -> inactive
                wpOutput('Plugin activated.', 0),   // plugin activate
                wpOutput('Success: updated.', 0),   // lockout_notify cleared
                wpOutput('Success: updated.', 0),   // admin_notify_email blank
                wpOutput('Success: updated.', 0),   // notify_after maxed
                wpOutput('Success: updated.', 0),   // onboarding popup hidden
                wpOutput('Success: updated.', 0),   // review notice hidden
            );
        });

        $this->mock(ChatNotifier::class, function ($mock) use ($site) {
            $mock->shouldReceive('llarInstalled')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturn(true);
        });

        $result = app(LlarInstaller::class)->process($site);

        expect($result['result'])->toBe(LlarInstaller::RESULT_INSTALLED)
            ->and($result['message'])->toContain('installed but inactive');
    });
});

describe('LlarInstaller::process() — already installed AND active', function () {
    it('never touches the plugin and never fires llarInstalled', function () {
        $site = llarReadySite(['llar_enabled' => false]);

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')->andReturn(
                wpOutput('', 0), // plugin is-installed -> installed
                wpOutput('', 0), // plugin is-active -> active
            );
        });

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('llarInstalled')->never();
        });

        $result = app(LlarInstaller::class)->process($site);

        expect($result['result'])->toBe(LlarInstaller::RESULT_ALREADY_PRESENT)
            ->and($result['message'])->toContain('left untouched');
    });
});

describe('LlarInstaller::process() — guard clauses short-circuit before SSH', function () {
    it('never calls SSH or llarInstalled when the server has no ssh_password stored', function () {
        $site = Site::factory()->spinupwp()->create([
            'is_wordpress' => true,
            'site_user' => 'clockwork-site',
            'server_id' => Server::factory()->create([
                'ssh_password' => null,
            ]),
        ]);

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')->never();
        });

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('llarInstalled')->never();
        });

        $result = app(LlarInstaller::class)->process($site);

        expect($result['result'])->toBe(LlarInstaller::RESULT_FAILED)
            ->and($result['message'])->toContain('sudo password');
    });

    it('never calls SSH or llarInstalled when the site has no site_user', function () {
        $site = llarReadySite(['site_user' => null]);

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')->never();
        });

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('llarInstalled')->never();
        });

        $result = app(LlarInstaller::class)->process($site);

        expect($result['result'])->toBe(LlarInstaller::RESULT_FAILED)
            ->and($result['message'])->toContain('site_user');
    });
});
