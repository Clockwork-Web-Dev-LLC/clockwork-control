<?php

use App\Http\Middleware\EnforceInstallerGate;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Auth\OAuthLoginHandler;
use App\Services\Sites\SiteMySqlClient;
use App\Services\Ssh\SshClient;
use App\Support\EnvCredentialManager;
use App\Support\SsrfGuard;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

describe('Security Hardening Audit Regression Suite', function () {
    it('neutralizes ${VAR} dotenv interpolation and $0 regex backreferences in EnvCredentialManager', function () {
        $tempEnv = (string) tempnam(sys_get_temp_dir(), 'cw_sec_env_');
        file_put_contents($tempEnv, "APP_KEY=base64:supersecretkey\nCUSTOM_SECRET=old\n");

        try {
            $manager = new EnvCredentialManager($tempEnv);
            $payload = 'p@ss${APP_KEY}$0\\1"quote';
            $manager->writeEnv('CUSTOM_SECRET', $payload);

            $rawFile = (string) file_get_contents($tempEnv);
            $parsed = Dotenv::parse($rawFile);
            expect($rawFile)->toContain('\${APP_KEY}')
                ->and($parsed['CUSTOM_SECRET'])->toBe($payload);
        } finally {
            @unlink($tempEnv);
        }
    });

    it('requires a valid reopen_token when the installer is reopened via CLI', function () {
        $reopenPath = storage_path('installer_reopened');
        $token = bin2hex(random_bytes(24));
        file_put_contents($reopenPath, $token);
        EnforceInstallerGate::fake(false);

        try {
            $this->get('/install')->assertForbidden();
            $this->get('/install?reopen_token=wrong-token')->assertForbidden();
            $this->get("/install?reopen_token={$token}")->assertOk();
        } finally {
            EnforceInstallerGate::fake(null);
            @unlink($reopenPath);
        }
    });

    it('rejects OAuth login when provider account ID mismatches linked user identity', function () {
        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'client@example.com',
            'google_id' => 'google-sub-original-123',
        ]);

        $request = Request::create('/auth/google/callback', 'GET');
        $request->setLaravelSession(app('session.store'));

        $response = app(OAuthLoginHandler::class)->handle(
            request: $request,
            providerName: 'Google',
            email: 'client@example.com',
            providerId: 'google-sub-attacker-999',
            providerIdColumn: 'google_id',
            name: 'Jane Doe',
        );

        expect($response->getTargetUrl())->toBe(route('login'))
            ->and(auth()->check())->toBeFalse()
            ->and($user->fresh()->google_id)->toBe('google-sub-original-123');
    });

    it('enforces admin role on privileged settings, credential, module, and backup-relay routes', function () {
        $operator = User::factory()->operator()->create([
            'email' => 'operator@example.com',
        ]);
        $server = Server::factory()->create([
            'name' => 'web-01.example.test',
            'hostname' => '203.0.113.25',
        ]);

        $this->actingAs($operator)->patch(route('settings.integrations.update'), [
            'integration' => 'cloudflare',
            'credentials' => ['api_token' => 'tok-test'],
        ])->assertForbidden();

        $this->actingAs($operator)->post(route('servers.store'), [
            'name' => 'unauth.example.test',
            'hostname' => '203.0.113.26',
        ])->assertForbidden();

        $this->actingAs($operator)->get(route('servers.credentials.edit', $server))->assertForbidden();
        $this->actingAs($operator)->patch(route('settings.backup-relay.update'))->assertForbidden();
    });

    it('blocks bracketed IPv6 loopback, CGNAT 100.64.0.0/10, and NAT64 64:ff9b::/96 in SsrfGuard', function () {
        SsrfGuard::fake();

        expect(fn () => SsrfGuard::assertPublic('http://[::1]/admin'))
            ->toThrow(RuntimeException::class)
            ->and(fn () => SsrfGuard::assertPublic('https://100.64.0.1/latest/meta-data'))
            ->toThrow(RuntimeException::class)
            ->and(fn () => SsrfGuard::assertPublic('https://[64:ff9b::a9fe:a9fe]/metadata'))
            ->toThrow(RuntimeException::class)
            ->and(fn () => SsrfGuard::assertPublic('file:///etc/passwd'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects MySQL credentials containing INI newlines or leading-dash database names in SiteMySqlClient', function () {
        $ssh = $this->mock(SshClient::class);
        $ssh->shouldNotReceive('exec');

        $server = Server::factory()->create([
            'name' => 'db-01.example.test',
            'hostname' => '203.0.113.50',
        ]);

        $siteWithNewline = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'newline.example.com',
            'db_name' => 'wp_example',
            'db_user' => "wp_user\nlocal-infile=1",
            'db_password' => 'secret',
            'db_host' => '127.0.0.1',
        ]);

        $siteWithDashDb = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'dash.example.com',
            'db_name' => '--execute=evil',
            'db_user' => 'wp_user',
            'db_password' => 'secret',
            'db_host' => '127.0.0.1',
        ]);

        $client = new SiteMySqlClient($ssh);

        expect(fn () => $client->query($siteWithNewline, 'SELECT 1'))
            ->toThrow(RuntimeException::class)
            ->and(fn () => $client->query($siteWithDashDb, 'SELECT 1'))
            ->toThrow(RuntimeException::class);
    });

    it('prevents open redirects via untrusted return_to on ServersController::toggleIgnore', function () {
        $admin = User::factory()->admin()->create();
        $server = Server::factory()->create([
            'name' => 'edge-01.example.test',
            'hostname' => '203.0.113.77',
            'is_ignored' => false,
        ]);

        $response = $this->actingAs($admin)->post(route('servers.toggleIgnore', $server), [
            'reason' => 'maintenance',
            'return_to' => 'https://attacker.example.org/phish',
        ]);

        $response->assertRedirect(route('servers.show', $server));

        $protoRelative = $this->actingAs($admin)->post(route('servers.toggleIgnore', $server), [
            'return_to' => '//attacker.example.org/phish',
        ]);

        $protoRelative->assertRedirect(route('servers.show', $server));
    });

    it('blocks cross-domain or non-HTTPS SSO redirect URLs in SitesController::ssoLaunch', function () {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->custom()->create([
            'domain' => 'portal.example.com',
            'companion_installed' => true,
            'companion_secret' => str_repeat('a', 64),
            'companion_snapshot' => [
                'admins' => [
                    'admins' => [
                        ['login' => 'jane_admin'],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://portal.example.com/wp-json/clockwork/v1/sso' => Http::response([
                'ok' => true,
                'login_url' => 'https://attacker.example.net/capture-token?token=xyz',
                'expires_at' => now()->addMinute()->timestamp,
            ], 200),
        ]);

        $response = $this->actingAs($admin)
            ->from(route('sites.show', $site))
            ->post(route('sites.companion.sso', $site), ['as' => 'jane_admin']);

        $response->assertRedirect(route('sites.show', $site));
        $response->assertSessionHas('status_error');
    });
});
