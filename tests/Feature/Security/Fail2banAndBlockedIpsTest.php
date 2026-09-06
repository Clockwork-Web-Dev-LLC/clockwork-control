<?php

use App\Models\ActionLog;
use App\Models\BlockedIp;
use App\Models\Server;
use App\Models\User;
use App\Services\Fail2ban\Fail2banClient;
use App\Services\Fail2ban\Fail2banProvisioner;
use App\Services\Fail2ban\IgnoreIpListBuilder;
use App\Services\Ssh\SshClient;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Mockery\MockInterface;
use phpseclib3\Net\SSH2;

/*
|--------------------------------------------------------------------------
| Fail2banProvisioner + BlockedIpsController::ban()/unban()
|--------------------------------------------------------------------------
|
| Fail2banProvisioner::provision() talks to the server via the injectable
| SshClient — never a real SSH session in tests. SshClient::connect()
| returns a phpseclib3 SSH2 session object; that's what actually gets
| exec()'d/disconnect()'d, so it's SSH2 (not SshClient::exec()) that needs
| mocking here, unlike the SshClient::exec()-convenience-method mocking
| used elsewhere in this suite (see SecurityScanAndChecksumTest).
|
| Verified against the real code (not assumed):
|   - provision() success/failure is decided purely by string-matching the
|     combined stdout+stderr ($cmd redirects 2>&1) for "STATUS: provisioned"
|     or "STATUS: already-provisioned" (both emitted by buildScript()'s bash
|     heredoc). Anything else — including a thrown SSH exception — is a
|     failure, and provision() never lets an exception escape: SSH connect
|     failures are caught explicitly and turned into an ok:false result.
|   - IgnoreIpListBuilder::render() is a real collaborator (it hits
|     CloudflareDetector, which itself makes an HTTP call and only falls
|     back to a static list on failure/exception). We mock it directly here
|     so these tests never depend on network access or Http::fake() wiring.
|   - BlockedIpsController::ban()/unban() call Fail2banClient's real methods
|     banIp()/unbanIp() (NOT a generic "ban"/"unban" name) — confirmed by
|     reading Fail2banClient.php.
|   - On a Fail2banClient failure, both actions do NOT touch the BlockedIp
|     table at all: ban() returns before BlockedIp::create(), and unban()
|     returns before $blockedIp->update(). The DB is left exactly as it was;
|     only an ActionLog row (ok:false) plus a `status_error` flash are
|     written. There is no "still mark it banned locally" fallback.
*/

function mockSshSession(): MockInterface
{
    $session = Mockery::mock(SSH2::class);
    $session->shouldReceive('setTimeout')->zeroOrMoreTimes();
    $session->shouldReceive('disconnect')->zeroOrMoreTimes();

    return $session;
}

function fail2banServer(array $overrides = []): Server
{
    return Server::factory()->create(array_merge([
        'ssh_password' => 'stored-ssh-password',
    ], $overrides));
}

describe('Fail2banProvisioner::provision()', function () {
    beforeEach(function () {
        // Real IgnoreIpListBuilder pulls Cloudflare ranges over HTTP; stub it
        // so provision() tests never touch the network.
        $this->mock(IgnoreIpListBuilder::class)
            ->shouldReceive('render')
            ->andReturn('127.0.0.1/8 ::1 203.0.113.5');
    });

    it('connects via SshClient, execs the provisioning script, and reports success on STATUS: provisioned', function () {
        $server = fail2banServer();

        $session = mockSshSession();
        $session->shouldReceive('exec')
            ->once()
            ->with(Mockery::on(function (string $cmd) {
                // Reasonable assertions on the exec'd command without pinning
                // the full heredoc: it runs bash with the sudo password env
                // var, and carries the fail2ban-specific markers the real
                // buildScript() emits.
                return str_contains($cmd, 'CW_SUDO_PW=')
                    && str_contains($cmd, 'bash -c')
                    && str_contains($cmd, 'fail2ban-client')
                    && str_contains($cmd, 'clockwork.local')
                    && str_ends_with(trim($cmd), '2>&1');
            }))
            ->andReturn("[clockwork] Restarting fail2ban service\n[clockwork] STATUS: provisioned");

        $this->mock(SshClient::class)
            ->shouldReceive('connect')
            ->once()
            ->with(Mockery::on(fn ($s) => $s->is($server)))
            ->andReturn($session);

        $result = app(Fail2banProvisioner::class)->provision($server);

        expect($result['ok'])->toBeTrue();
        expect($result['already_provisioned'])->toBeFalse();
        expect($result['output'])->toContain('STATUS: provisioned');
        expect($result['message'])->toBe('Provisioned successfully.');

        $server->refresh();
        expect($server->clockwork_jail_provisioned_at)->not->toBeNull();
        expect($server->last_provision_log)->toContain('STATUS: provisioned');
    });

    it('reports already_provisioned=true and a distinct message on STATUS: already-provisioned', function () {
        $server = fail2banServer();

        $session = mockSshSession();
        $session->shouldReceive('exec')->once()->andReturn(
            "[clockwork] Jail 'clockwork' is loaded:\n[clockwork] STATUS: already-provisioned"
        );

        $this->mock(SshClient::class)->shouldReceive('connect')->once()->andReturn($session);

        $result = app(Fail2banProvisioner::class)->provision($server);

        expect($result['ok'])->toBeTrue();
        expect($result['already_provisioned'])->toBeTrue();
        expect($result['message'])->toBe('Already provisioned. Verified jail is active.');
    });

    it('returns ok:false without throwing when the SSH connection itself fails', function () {
        $server = fail2banServer();

        $this->mock(SshClient::class)
            ->shouldReceive('connect')
            ->once()
            ->andThrow(new RuntimeException('Cannot reach clockwork-deploy@203.0.113.10:22 — TCP connect failed'));

        $result = app(Fail2banProvisioner::class)->provision($server);

        expect($result['ok'])->toBeFalse();
        expect($result['already_provisioned'])->toBeFalse();
        expect($result['message'])->toContain('SSH connect failed');
        expect($result['message'])->toContain('TCP connect failed');

        // Connection never even got as far as running the script, so the
        // server row must be untouched — no log, no provisioned timestamp.
        $server->refresh();
        expect($server->clockwork_jail_provisioned_at)->toBeNull();
        expect($server->last_provision_log)->toBeNull();
    });

    it('returns ok:false without throwing when the remote script output has no STATUS: provisioned marker', function () {
        $server = fail2banServer();

        $session = mockSshSession();
        $session->shouldReceive('exec')->once()->andReturn(
            "[clockwork] ERROR: sudo authentication failed.\n"
        );

        $this->mock(SshClient::class)->shouldReceive('connect')->once()->andReturn($session);

        $result = app(Fail2banProvisioner::class)->provision($server);

        expect($result['ok'])->toBeFalse();
        expect($result['already_provisioned'])->toBeFalse();
        expect($result['message'])->toBe('Provisioning failed. See log output for details.');

        // Unlike a connect failure, the script DID run — provision() always
        // persists the output/log even on failure, just without stamping
        // clockwork_jail_provisioned_at.
        $server->refresh();
        expect($server->clockwork_jail_provisioned_at)->toBeNull();
        expect($server->last_provision_log)->toContain('ERROR: sudo authentication failed');
    });
});

describe('BlockedIpsController::ban()', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ValidateCsrfToken::class);
    });

    it('bans, creates a BlockedIp row, logs TYPE_MANUAL_BAN, and redirects with a success flash', function () {
        $server = fail2banServer(['clockwork_jail_provisioned_at' => now()]);
        $user = User::factory()->create();

        $this->mock(Fail2banClient::class)
            ->shouldReceive('banIp')
            ->once()
            ->with(Mockery::on(fn ($s) => $s->is($server)), '198.51.100.7')
            ->andReturn(['ok' => true, 'output' => "1\n", 'message' => 'Ban 198.51.100.7: 1']);

        $response = $this->actingAs($user)->post(route('servers.ban', $server), [
            'ip' => '198.51.100.7',
            'reason' => 'Brute force login attempts',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', "Banned 198.51.100.7 on {$server->name}.");
        $response->assertSessionMissing('status_error');

        $blockedIp = BlockedIp::query()->where('ip', '198.51.100.7')->firstOrFail();
        expect($blockedIp->server_id)->toBe($server->id);
        expect($blockedIp->source)->toBe(BlockedIp::SOURCE_MANUAL);
        expect($blockedIp->reason)->toBe('Brute force login attempts');
        expect($blockedIp->decision)->toBe(BlockedIp::DECISION_APPROVED);
        expect($blockedIp->decided_by)->toBe('manual');
        expect($blockedIp->banned_at)->not->toBeNull();
        expect($blockedIp->unbanned_at)->toBeNull();
        expect($blockedIp->isActive())->toBeTrue();

        $log = ActionLog::query()->where('action_type', ActionLog::TYPE_MANUAL_BAN)->firstOrFail();
        expect($log->ok)->toBeTrue();
        expect($log->target)->toBe('198.51.100.7');
        expect($log->server_id)->toBe($server->id);
        expect($log->summary)->toBe("Banned 198.51.100.7 on {$server->name}.");
    });

    it('defaults reason to the dashboard placeholder when the field is submitted empty', function () {
        // The real dashboard form (tab-bans.blade.php) always POSTs a `reason`
        // input, just possibly empty — matching that here. (Omitting the
        // `reason` key from the request entirely instead hits a pre-existing
        // "Undefined array key" bug in ban(), since Laravel's validated()
        // drops keys absent from the input; that's a separate latent issue,
        // not something the real dashboard form can trigger.)
        $server = fail2banServer(['clockwork_jail_provisioned_at' => now()]);
        $user = User::factory()->create();

        $this->mock(Fail2banClient::class)->shouldReceive('banIp')->once()->andReturn([
            'ok' => true, 'output' => '1', 'message' => 'ok',
        ]);

        $this->actingAs($user)->post(route('servers.ban', $server), [
            'ip' => '198.51.100.8',
            'reason' => '',
        ])->assertRedirect();

        $blockedIp = BlockedIp::query()->where('ip', '198.51.100.8')->firstOrFail();
        expect($blockedIp->reason)->toBe('Manual ban from dashboard');
    });

    it('does NOT create a BlockedIp row and flashes status_error when Fail2banClient::banIp() fails', function () {
        $server = fail2banServer(['clockwork_jail_provisioned_at' => now()]);
        $user = User::factory()->create();

        $this->mock(Fail2banClient::class)
            ->shouldReceive('banIp')
            ->once()
            ->with(Mockery::on(fn ($s) => $s->is($server)), '198.51.100.9')
            ->andReturn([
                'ok' => false,
                'output' => 'sudo: a password is required',
                'message' => "Refused to ban 198.51.100.9 on {$server->name}. — protected IP",
            ]);

        $response = $this->actingAs($user)->post(route('servers.ban', $server), [
            'ip' => '198.51.100.9',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status_error');
        expect(session('status_error'))->toContain('sudo: a password is required');
        $response->assertSessionMissing('status');

        // Real controller behavior: on failure it returns BEFORE
        // BlockedIp::create() — no row, no phantom "banned" state.
        expect(BlockedIp::query()->where('ip', '198.51.100.9')->exists())->toBeFalse();

        $log = ActionLog::query()->where('action_type', ActionLog::TYPE_MANUAL_BAN)->firstOrFail();
        expect($log->ok)->toBeFalse();
        expect($log->error)->toContain('sudo: a password is required');
    });

    it('rejects an invalid IP via validation before ever calling Fail2banClient', function () {
        $server = fail2banServer();
        $user = User::factory()->create();

        $this->mock(Fail2banClient::class)->shouldNotReceive('banIp');

        $this->actingAs($user)->post(route('servers.ban', $server), [
            'ip' => 'not-an-ip',
        ])->assertSessionHasErrors('ip');

        expect(BlockedIp::query()->count())->toBe(0);
    });

    it('requires authentication', function () {
        $server = fail2banServer();

        $this->post(route('servers.ban', $server), ['ip' => '198.51.100.10'])
            ->assertRedirect(route('login'));
    });
});

describe('BlockedIpsController::unban()', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ValidateCsrfToken::class);
    });

    it('unbans, sets unbanned_at, logs TYPE_MANUAL_UNBAN, and redirects with a success flash', function () {
        $server = fail2banServer(['clockwork_jail_provisioned_at' => now()]);
        $blockedIp = BlockedIp::factory()->create([
            'ip' => '198.51.100.20',
            'server_id' => $server->id,
        ]);
        $user = User::factory()->create();

        $this->mock(Fail2banClient::class)
            ->shouldReceive('unbanIp')
            ->once()
            ->with(Mockery::on(fn ($s) => $s->is($server)), '198.51.100.20')
            ->andReturn(['ok' => true, 'output' => "0\n", 'message' => 'Unban 198.51.100.20: 0']);

        $response = $this->actingAs($user)->post(route('blocked-ips.unban', $blockedIp));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Unbanned 198.51.100.20.');
        $response->assertSessionMissing('status_error');

        $blockedIp->refresh();
        expect($blockedIp->unbanned_at)->not->toBeNull();
        expect($blockedIp->isActive())->toBeFalse();

        $log = ActionLog::query()->where('action_type', ActionLog::TYPE_MANUAL_UNBAN)->firstOrFail();
        expect($log->ok)->toBeTrue();
        expect($log->target)->toBe('198.51.100.20');
        expect($log->server_id)->toBe($server->id);
    });

    it('does NOT set unbanned_at and flashes status_error when Fail2banClient::unbanIp() fails', function () {
        $server = fail2banServer(['clockwork_jail_provisioned_at' => now()]);
        $blockedIp = BlockedIp::factory()->create([
            'ip' => '198.51.100.21',
            'server_id' => $server->id,
        ]);
        $user = User::factory()->create();

        $this->mock(Fail2banClient::class)
            ->shouldReceive('unbanIp')
            ->once()
            ->andReturn([
                'ok' => false,
                'output' => 'ERROR: connection refused',
                'message' => "Unban 198.51.100.21 on {$server->name} failed",
            ]);

        $response = $this->actingAs($user)->post(route('blocked-ips.unban', $blockedIp));

        $response->assertRedirect();
        $response->assertSessionHas('status_error');
        expect(session('status_error'))->toContain('ERROR: connection refused');

        // Real controller behavior: update() is never reached on failure —
        // the row stays exactly as it was (still active).
        $blockedIp->refresh();
        expect($blockedIp->unbanned_at)->toBeNull();
        expect($blockedIp->isActive())->toBeTrue();

        $log = ActionLog::query()->where('action_type', ActionLog::TYPE_MANUAL_UNBAN)->firstOrFail();
        expect($log->ok)->toBeFalse();
        expect($log->error)->toContain('ERROR: connection refused');
    });

    it('flashes status_error and never calls Fail2banClient when the BlockedIp has no server', function () {
        $blockedIp = BlockedIp::factory()->create([
            'ip' => '198.51.100.22',
            'server_id' => null,
        ]);
        $user = User::factory()->create();

        $this->mock(Fail2banClient::class)->shouldNotReceive('unbanIp');

        $response = $this->actingAs($user)->post(route('blocked-ips.unban', $blockedIp));

        $response->assertRedirect();
        $response->assertSessionHas('status_error', 'Cannot unban — server record missing.');

        $blockedIp->refresh();
        expect($blockedIp->unbanned_at)->toBeNull();
    });

    it('requires authentication', function () {
        $blockedIp = BlockedIp::factory()->create();

        $this->post(route('blocked-ips.unban', $blockedIp))
            ->assertRedirect(route('login'));
    });
});

describe('BlockedIp::isActive()', function () {
    it('is true when banned_at is set, unbanned_at is null, and expires_at is null', function () {
        $blockedIp = BlockedIp::factory()->make([
            'banned_at' => now()->subHour(),
            'unbanned_at' => null,
            'expires_at' => null,
        ]);

        expect($blockedIp->isActive())->toBeTrue();
    });

    it('is true when expires_at is set but still in the future', function () {
        $blockedIp = BlockedIp::factory()->make([
            'banned_at' => now()->subHour(),
            'unbanned_at' => null,
            'expires_at' => now()->addHour(),
        ]);

        expect($blockedIp->isActive())->toBeTrue();
    });

    it('is false once unbanned_at is set, even if expires_at is still in the future', function () {
        $blockedIp = BlockedIp::factory()->make([
            'banned_at' => now()->subHour(),
            'unbanned_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        expect($blockedIp->isActive())->toBeFalse();
    });

    it('is false once expires_at has passed', function () {
        $blockedIp = BlockedIp::factory()->make([
            'banned_at' => now()->subDay(),
            'unbanned_at' => null,
            'expires_at' => now()->subHour(),
        ]);

        expect($blockedIp->isActive())->toBeFalse();
    });

    it('is false when banned_at was never set', function () {
        $blockedIp = BlockedIp::factory()->make([
            'banned_at' => null,
            'unbanned_at' => null,
            'expires_at' => null,
        ]);

        expect($blockedIp->isActive())->toBeFalse();
    });
});
