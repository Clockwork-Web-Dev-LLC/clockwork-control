<?php

use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\SshClient;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| ServerCredentialsController
|--------------------------------------------------------------------------
|
| ssh_password / ssh_private_key are `encrypted` casts on Server (see
| app/Models/Server.php). Every "persists" assertion below reads the raw DB
| column via DB::table('servers') (bypassing the cast) to confirm the
| stored value is NOT the plaintext, in addition to asserting the decrypted
| value read back through the model is correct.
|
| SshClient is a real network/SSH client (phpseclib3 under the hood) — every
| action that calls it (test(), and the verification step inside
| bulkUpdate()/update()/feedApply()) mocks it here.
*/

beforeEach(function () {
    $this->mockIssueCounterZero();
});

it('requires authentication', function () {
    $this->get(route('servers.credentials.bulk'))->assertRedirect(route('login'));
});

describe('bulk (GET /servers/credentials)', function () {
    it('renders the bulk credentials page listing non-ignored servers', function () {
        Server::factory()->create(['name' => 'web-test1.example.com', 'is_ignored' => false]);
        Server::factory()->create(['name' => 'ignored-box.example.com', 'is_ignored' => true]);

        $response = $this->actingAs(User::factory()->create())->get(route('servers.credentials.bulk'));

        $response->assertOk();
        $response->assertSee('web-test1.example.com');
        $response->assertDontSee('ignored-box.example.com');
    });
});

describe('bulkUpdate (POST /servers/credentials)', function () {
    it('sets the password on each server, defaults the SSH user, verifies over SSH, and persists the password encrypted', function () {
        $server = Server::factory()->create(['ssh_user' => '', 'ssh_password' => null]);

        $this->mock(SshClient::class)
            ->shouldReceive('test')
            ->once()
            ->withArgs(fn (Server $s) => $s->is($server))
            ->andReturn(['ok' => true, 'message' => 'Connected.']);

        $response = $this->actingAs(User::factory()->create())->post(route('servers.credentials.bulkUpdate'), [
            'passwords' => [
                $server->id => 'super-secret-pw-1',
            ],
        ]);

        $response->assertRedirect(route('servers.credentials.bulk'));
        $response->assertSessionHas('status', 'Updated SSH credentials on 1 server(s). Verified 1.');

        $fresh = $server->fresh();
        expect($fresh->ssh_password)->toBe('super-secret-pw-1');
        expect($fresh->ssh_user)->toBe((string) config('clockwork.ssh.default_user'));

        $rawPassword = DB::table('servers')->where('id', $server->id)->value('ssh_password');
        expect($rawPassword)->not->toBe('super-secret-pw-1');
        expect($rawPassword)->not->toBeNull();
    });

    it('reports a failed count in the flash message when SSH verification fails, but still saves the password', function () {
        $server = Server::factory()->create(['ssh_user' => 'clockwork-deploy']);

        $this->mock(SshClient::class)->shouldReceive('test')->once()->andReturn([
            'ok' => false,
            'message' => 'Connection refused.',
        ]);

        $response = $this->actingAs(User::factory()->create())->post(route('servers.credentials.bulkUpdate'), [
            'passwords' => [$server->id => 'another-pw'],
        ]);

        $response->assertSessionHas('status', 'Updated SSH credentials on 1 server(s). Verified 0, 1 failed verification.');
        expect($server->fresh()->ssh_password)->toBe('another-pw');
    });

    it('skips servers with an empty or missing password entry and never touches SshClient for them', function () {
        $server = Server::factory()->create(['ssh_password' => null]);

        $this->mock(SshClient::class)->shouldNotReceive('test');

        $response = $this->actingAs(User::factory()->create())->post(route('servers.credentials.bulkUpdate'), [
            'passwords' => [$server->id => ''],
        ]);

        $response->assertSessionHas('status', 'Updated SSH credentials on 0 server(s). Verified 0.');
        expect($server->fresh()->ssh_password)->toBeNull();
    });
});

describe('edit (GET /servers/{server}/edit)', function () {
    it('renders the credentials edit form for the server', function () {
        $server = Server::factory()->create(['name' => 'web-test2.example.com']);

        $response = $this->actingAs(User::factory()->create())->get(route('servers.credentials.edit', $server));

        $response->assertOk();
        $response->assertSee($server->hostname);
    });
});

describe('update (PATCH /servers/{server}/credentials)', function () {
    it('updates user/port/password, verifies over SSH, and persists the password encrypted', function () {
        $server = Server::factory()->create([
            'ssh_user' => 'clockwork-deploy',
            'ssh_port' => 22,
            'ssh_password' => 'old-password',
        ]);

        $this->mock(SshClient::class)
            ->shouldReceive('test')
            ->once()
            ->withArgs(fn (Server $s) => $s->is($server))
            ->andReturn(['ok' => true, 'message' => 'Connected.']);

        $response = $this->actingAs(User::factory()->create())->patch(route('servers.credentials.update', $server), [
            'ssh_user' => 'deploy',
            'ssh_port' => 2222,
            'ssh_password' => 'new-strong-password',
        ]);

        $response->assertRedirect(route('servers.show', $server));
        $response->assertSessionHas('status', 'Credentials updated. SSH verified.');

        $fresh = $server->fresh();
        expect($fresh->ssh_user)->toBe('deploy');
        expect($fresh->ssh_port)->toBe(2222);
        expect($fresh->ssh_password)->toBe('new-strong-password');

        $rawPassword = DB::table('servers')->where('id', $server->id)->value('ssh_password');
        expect($rawPassword)->not->toBe('new-strong-password');
    });

    it('clears the stored password when clear_password is set, without calling SSH', function () {
        $server = Server::factory()->create([
            'ssh_user' => 'clockwork-deploy',
            'ssh_port' => 22,
            'ssh_password' => 'old-password',
        ]);

        $this->mock(SshClient::class)->shouldNotReceive('test');

        $response = $this->actingAs(User::factory()->create())->patch(route('servers.credentials.update', $server), [
            'ssh_user' => 'clockwork-deploy',
            'ssh_port' => 22,
            'clear_password' => true,
        ]);

        $response->assertSessionHas('status', 'Credentials updated.');
        expect($server->fresh()->ssh_password)->toBeNull();
    });

    it('rejects the update when ssh_user is missing', function () {
        $server = Server::factory()->create(['ssh_user' => 'clockwork-deploy', 'ssh_port' => 22]);

        $this->mock(SshClient::class)->shouldNotReceive('test');

        $response = $this->actingAs(User::factory()->create())->patch(route('servers.credentials.update', $server), [
            'ssh_port' => 22,
        ]);

        $response->assertSessionHasErrors('ssh_user');
        expect($server->fresh()->ssh_user)->toBe('clockwork-deploy');
    });

    it('rejects the update when ssh_port is out of range', function () {
        $server = Server::factory()->create(['ssh_user' => 'clockwork-deploy', 'ssh_port' => 22]);

        $this->mock(SshClient::class)->shouldNotReceive('test');

        $response = $this->actingAs(User::factory()->create())->patch(route('servers.credentials.update', $server), [
            'ssh_user' => 'clockwork-deploy',
            'ssh_port' => 70000,
        ]);

        $response->assertSessionHasErrors('ssh_port');
        expect($server->fresh()->ssh_port)->toBe(22);
    });
});

describe('test (POST /servers/{server}/test)', function () {
    it('returns the SshClient::test() result as JSON', function () {
        $server = Server::factory()->create();

        $this->mock(SshClient::class)
            ->shouldReceive('test')
            ->once()
            ->withArgs(fn (Server $s) => $s->is($server))
            ->andReturn(['ok' => true, 'message' => 'Connected as clockwork-deploy on web-test1.', 'whoami' => 'clockwork-deploy']);

        $response = $this->actingAs(User::factory()->create())->post(route('servers.test', $server));

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'message' => 'Connected as clockwork-deploy on web-test1.',
            'whoami' => 'clockwork-deploy',
        ]);
    });
});

describe('feed (GET /servers/credentials/feed)', function () {
    it('renders the empty feed-paste form', function () {
        $response = $this->actingAs(User::factory()->create())->get(route('servers.credentials.feed'));

        $response->assertOk();
        $response->assertSee('Paste credentials feed');
    });
});

describe('feedParse (POST /servers/credentials/feed)', function () {
    it('parses a feed block and matches it to an existing server by hostname', function () {
        $server = Server::factory()->create([
            'name' => 'srv50.example.com',
            'hostname' => '198.51.100.50',
            'ssh_user' => 'deploy',
        ]);

        $feed = <<<'FEED'
        srv50.example.com
        198.51.100.50
        ssh deploy@198.51.100.50
        pasted-password-value
        FEED;

        $response = $this->actingAs(User::factory()->create())->post(route('servers.credentials.feedParse'), [
            'feed' => $feed,
        ]);

        $response->assertOk();
        $response->assertSee('srv50.example.com');
        $response->assertSee('Matched');

        // feedParse() only parses + previews — it must not touch the DB.
        expect($server->fresh()->ssh_password)->toBeNull();
    });

    it('fails validation when the feed field is missing', function () {
        $this->actingAs(User::factory()->create())->post(route('servers.credentials.feedParse'), [])
            ->assertSessionHasErrors('feed');
    });
});

describe('feedApply (POST /servers/credentials/feed/apply)', function () {
    it('applies a matched entry to an existing server, verifies over SSH, and persists the password encrypted', function () {
        $server = Server::factory()->create([
            'ssh_user' => 'clockwork-deploy',
            'ssh_port' => 22,
            'ssh_password' => null,
        ]);

        $this->mock(SshClient::class)
            ->shouldReceive('test')
            ->once()
            ->withArgs(fn (Server $s) => $s->is($server))
            ->andReturn(['ok' => true, 'message' => 'Connected.']);

        $response = $this->actingAs(User::factory()->create())->post(route('servers.credentials.feedApply'), [
            'entries' => [
                [
                    'apply' => '1',
                    'server_id' => $server->id,
                    'password' => 'feed-applied-password',
                    'user' => 'clockwork-deploy',
                    'port' => 22,
                ],
            ],
        ]);

        $response->assertRedirect(route('servers.credentials.bulk'));
        $response->assertSessionHas('status', 'Updated credentials on 1 server(s). Verified 1.');

        $fresh = $server->fresh();
        expect($fresh->ssh_password)->toBe('feed-applied-password');

        $rawPassword = DB::table('servers')->where('id', $server->id)->value('ssh_password');
        expect($rawPassword)->not->toBe('feed-applied-password');
    });

    it('creates a new server for an unmatched entry and verifies it over SSH', function () {
        $this->mock(SshClient::class)->shouldReceive('test')->once()->andReturn(['ok' => true, 'message' => 'Connected.']);

        $response = $this->actingAs(User::factory()->create())->post(route('servers.credentials.feedApply'), [
            'entries' => [
                [
                    'apply' => '1',
                    'name' => 'web-test9.example.com',
                    'ip' => '203.0.113.99',
                    'user' => 'clockwork-deploy',
                    'port' => 22,
                    'password' => 'brand-new-password',
                ],
            ],
        ]);

        $response->assertSessionHas('status', 'Created 1 new server(s). Verified 1.');

        $created = Server::query()->where('hostname', '203.0.113.99')->firstOrFail();
        expect($created->name)->toBe('web-test9.example.com');
        expect($created->ssh_password)->toBe('brand-new-password');
        expect($created->status)->toBe(Server::STATUS_UNKNOWN);
    });

    it('skips an unmatched entry missing required create fields, never creating a server or calling SSH', function () {
        $this->mock(SshClient::class)->shouldNotReceive('test');

        $response = $this->actingAs(User::factory()->create())->post(route('servers.credentials.feedApply'), [
            'entries' => [
                [
                    'apply' => '1',
                    'name' => '',
                    'ip' => '203.0.113.100',
                    'user' => 'clockwork-deploy',
                ],
            ],
        ]);

        $response->assertSessionHas('status', 'No changes. Verified 0. Skipped 1.');
        expect(Server::query()->where('hostname', '203.0.113.100')->exists())->toBeFalse();
    });
});
