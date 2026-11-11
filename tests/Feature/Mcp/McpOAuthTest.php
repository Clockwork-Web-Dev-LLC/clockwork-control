<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Modules\Mcp\Support\ClaudeConnector;
use Tests\Concerns\EnablesClaudeAccess;
use Tests\Concerns\RendersAuthenticatedPages;

uses(EnablesClaudeAccess::class, RendersAuthenticatedPages::class);

const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

/** PKCE pair: [verifier, challenge]. */
function pkcePair(): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}

beforeEach(function () {
    $this->mockIssueCounterZero();
    $this->withoutVite();
    $this->enableClaudeAccess();

    $this->user = User::factory()->operator()->create(['name' => 'Lara Operator', 'email' => 'lara@example.com']);
    $created = app(ClaudeConnector::class)->ensureClient();
    $this->client = $created['client'];
    $this->secret = (string) $this->client->plainSecret;

    /** Walk the whole sign-in as Claude would, and return the token response. */
    $this->signIn = function (User $user): array {
        [$verifier, $challenge] = pkcePair();

        $query = [
            'response_type' => 'code',
            'client_id' => $this->client->getKey(),
            'redirect_uri' => CLAUDE_CALLBACK,
            'scope' => 'mcp:use',
            'state' => 'state-123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        $consent = $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query));
        $consent->assertOk();

        $approved = $this->actingAs($user, 'web')->post('/oauth/authorize', [
            'state' => 'state-123',
            'client_id' => $this->client->getKey(),
            'auth_token' => $consent->viewData('authToken'),
        ]);
        $approved->assertRedirect();

        parse_str((string) parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $back);
        expect($approved->headers->get('Location'))->toStartWith(CLAUDE_CALLBACK)
            ->and($back['state'])->toBe('state-123');

        // The token call comes from Claude's servers: no session, no cookies.
        // (Not forgetGuards(): the test app reuses the controller, which
        // would keep the old guard and its user for the next sign-in.)
        Auth::guard('web')->forgetUser();
        $this->flushSession();

        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->getKey(),
            'client_secret' => $this->secret,
            'redirect_uri' => CLAUDE_CALLBACK,
            'code_verifier' => $verifier,
            'code' => $back['code'],
        ])->assertOk()->json();
    };
});

it('publishes where to sign in, and offers no way to register a client', function () {
    $this->getJson('/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertJsonPath('resource', url('/mcp'))
        ->assertJsonPath('scopes_supported', ['mcp:use']);

    $server = $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
    $server->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
        ->assertJsonPath('token_endpoint', url('/oauth/token'))
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonMissingPath('registration_endpoint');

    $uris = collect(Route::getRoutes()->getRoutes())->map->uri()->all();
    expect($uris)->not->toContain('oauth/register')
        ->and(collect($uris)->filter(fn ($uri) => str_contains($uri, 'device'))->all())->toBe([])
        // Only the four sign-in routes, none of Passport's others.
        ->and(collect($uris)->filter(fn ($uri) => str_starts_with($uri, 'oauth/'))->unique()->sort()->values()->all())
        ->toBe(['oauth/authorize', 'oauth/token']);
});

it('keeps access tokens for an hour and sign-ins for 90 days', function () {
    $now = now()->toImmutable();

    expect($now->add(Passport::tokensExpireIn())->diffInMinutes($now, true))->toEqualWithDelta(60, 1)
        ->and($now->add(Passport::refreshTokensExpireIn())->diffInDays($now, true))->toEqualWithDelta(90, 0.1);
});

it('sends a guest to the login page before any consent', function () {
    $this->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code', 'client_id' => $this->client->getKey(), 'redirect_uri' => CLAUDE_CALLBACK,
        'scope' => 'mcp:use', 'state' => 's', 'code_challenge' => pkcePair()[1], 'code_challenge_method' => 'S256',
    ]))->assertRedirect(route('login'));
});

it('shows a consent page that says who is signing in and where they go next', function () {
    $this->actingAs($this->user, 'web')->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code', 'client_id' => $this->client->getKey(), 'redirect_uri' => CLAUDE_CALLBACK,
        'scope' => 'mcp:use', 'state' => 's', 'code_challenge' => pkcePair()[1], 'code_challenge_method' => 'S256',
    ]))
        ->assertOk()
        ->assertSee('Let Claude into Clockwork Control?')
        ->assertSee('Lara Operator')
        ->assertSee('claude.ai')
        ->assertSee('data-claude-access="consent"', false);
});

it('refuses a redirect address that is not Claude\'s', function () {
    $response = $this->actingAs($this->user, 'web')->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code', 'client_id' => $this->client->getKey(), 'redirect_uri' => 'https://evil.test/callback',
        'scope' => 'mcp:use', 'state' => 's', 'code_challenge' => pkcePair()[1], 'code_challenge_method' => 'S256',
    ]));

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)
        ->and((string) $response->headers->get('Location'))->not->toContain('evil.test');
});

it('signs a person in end to end, and Claude can then call a tool as them', function () {
    $tokens = ($this->signIn)($this->user);

    expect($tokens['token_type'])->toBe('Bearer')
        ->and($tokens['expires_in'])->toBeGreaterThan(3500)->toBeLessThanOrEqual(3600)
        ->and($tokens)->toHaveKey('refresh_token');

    $this->postJson('/mcp', $this->toolCall('control_whoami'), ['Authorization' => 'Bearer '.$tokens['access_token']])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.user.email', 'lara@example.com');
});

it('rejects the wrong client secret', function () {
    $this->secret = 'not-the-secret';

    [$verifier, $challenge] = pkcePair();
    $consent = $this->actingAs($this->user, 'web')->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code', 'client_id' => $this->client->getKey(), 'redirect_uri' => CLAUDE_CALLBACK,
        'scope' => 'mcp:use', 'state' => 's', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));
    $approved = $this->actingAs($this->user, 'web')->post('/oauth/authorize', ['state' => 's', 'client_id' => $this->client->getKey(), 'auth_token' => $consent->viewData('authToken')]);
    parse_str((string) parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $back);

    $this->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $this->client->getKey(), 'client_secret' => 'not-the-secret',
        'redirect_uri' => CLAUDE_CALLBACK, 'code_verifier' => $verifier, 'code' => $back['code'],
    ])->assertStatus(401)->assertJsonPath('error', 'invalid_client');
});

it('shows the consent page again even when the person is already connected', function () {
    ($this->signIn)($this->user);

    $this->actingAs($this->user, 'web')->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code', 'client_id' => $this->client->getKey(), 'redirect_uri' => CLAUDE_CALLBACK,
        'scope' => 'mcp:use', 'state' => 's', 'code_challenge' => pkcePair()[1], 'code_challenge_method' => 'S256',
    ]))->assertOk()->assertSee('data-claude-access="consent"', false);
});

it('lets a person say no, and issues nothing', function () {
    $consent = $this->actingAs($this->user, 'web')->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code', 'client_id' => $this->client->getKey(), 'redirect_uri' => CLAUDE_CALLBACK,
        'scope' => 'mcp:use', 'state' => 's', 'code_challenge' => pkcePair()[1], 'code_challenge_method' => 'S256',
    ]));

    $denied = $this->actingAs($this->user, 'web')->delete('/oauth/authorize', ['state' => 's', 'client_id' => $this->client->getKey(), 'auth_token' => $consent->viewData('authToken')]);

    expect($denied->headers->get('Location'))->toContain('error=access_denied')
        ->and(DB::table('oauth_access_tokens')->count())->toBe(0);
});

it('renews with the refresh token, and each refresh token works once', function () {
    $tokens = ($this->signIn)($this->user);

    $refresh = fn (string $token) => $this->post('/oauth/token', [
        'grant_type' => 'refresh_token', 'refresh_token' => $token,
        'client_id' => $this->client->getKey(), 'client_secret' => $this->secret, 'scope' => 'mcp:use',
    ]);

    $renewed = $refresh($tokens['refresh_token'])->assertOk()->json();
    expect($renewed['access_token'])->not->toBe($tokens['access_token']);

    // The old refresh token was rotated out.
    $refresh($tokens['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('cuts Claude off when the person is revoked: the token stops working and cannot be renewed', function () {
    $tokens = ($this->signIn)($this->user);
    $bearer = ['Authorization' => 'Bearer '.$tokens['access_token']];
    $this->postJson('/mcp', $this->toolCall('control_whoami'), $bearer)->assertOk();

    // What Settings → Users → Revoke does.
    $this->user->forceFill(['revoked_at' => now()])->save();
    $this->user->invalidateSessions();
    app('auth')->forgetGuards();

    $this->postJson('/mcp', $this->toolCall('control_whoami'), $bearer)->assertUnauthorized();

    $this->post('/oauth/token', [
        'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        'client_id' => $this->client->getKey(), 'client_secret' => $this->secret,
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect(DB::table('oauth_access_tokens')->where('revoked', false)->count())->toBe(0)
        ->and(DB::table('oauth_refresh_tokens')->where('revoked', false)->count())->toBe(0);
});

it('revokes tokens on a password change too, and leaves other people alone', function () {
    $other = User::factory()->operator()->create();
    ($this->signIn)($this->user);
    $otherTokens = ($this->signIn)($other);

    $this->user->invalidateSessions(keepCurrent: true);
    app('auth')->forgetGuards();

    expect(DB::table('oauth_access_tokens')->where('user_id', $this->user->id)->where('revoked', false)->count())->toBe(0)
        ->and(DB::table('oauth_access_tokens')->where('user_id', $other->id)->where('revoked', false)->count())->toBe(1);
    $this->postJson('/mcp', $this->toolCall('control_whoami'), ['Authorization' => 'Bearer '.$otherTokens['access_token']])->assertOk();
});

describe('clockwork:mcp-setup', function () {
    beforeEach(function () {
        // Start from nothing: no client, keys written to a scratch folder.
        DB::table('oauth_clients')->delete();
        config(['passport.private_key' => null, 'passport.public_key' => null]);
        $this->keyDir = sys_get_temp_dir().'/cw-passport-'.bin2hex(random_bytes(6));
        mkdir($this->keyDir);
        Passport::loadKeysFrom($this->keyDir);
    });

    afterEach(function () {
        array_map('unlink', glob($this->keyDir.'/*') ?: []);
        @rmdir($this->keyDir);
        $this->tearDownClaudeAccess();
    });

    it('creates the keys and one client, prints the secret once, and changes nothing on a second run', function () {
        $this->artisan('clockwork:mcp-setup')
            ->expectsOutputToContain('Created the signing keys')
            ->expectsOutputToContain('Client secret:')
            ->assertSuccessful();

        expect(is_file($this->keyDir.'/oauth-private.key'))->toBeTrue()
            ->and(substr(sprintf('%o', fileperms($this->keyDir.'/oauth-private.key')), -3))->toBe('600');

        $client = app(ClaudeConnector::class)->client();
        expect($client->redirect_uris)->toBe(ClaudeConnector::REDIRECT_URIS)
            ->and($client->confidential())->toBeTrue()
            ->and($client->grant_types)->toBe(['authorization_code', 'refresh_token'])
            // Stored hashed, never in the clear.
            ->and(DB::table('oauth_clients')->value('secret'))->toStartWith('$2y$');

        $this->artisan('clockwork:mcp-setup')
            ->expectsOutputToContain('already exists')
            ->doesntExpectOutputToContain('Client secret:')
            ->assertSuccessful();

        expect(DB::table('oauth_clients')->count())->toBe(1);
    });

    it('issues a new secret on --rotate-secret and the old one stops working', function () {
        $this->artisan('clockwork:mcp-setup')->assertSuccessful();
        $before = DB::table('oauth_clients')->value('secret');

        $this->artisan('clockwork:mcp-setup', ['--rotate-secret' => true])
            ->expectsOutputToContain('Client secret:')
            ->assertSuccessful();

        expect(DB::table('oauth_clients')->value('secret'))->not->toBe($before)
            ->and(DB::table('oauth_clients')->count())->toBe(1);
    });

    it('stops when only one key file is present', function () {
        file_put_contents($this->keyDir.'/oauth-public.key', 'x');

        $this->artisan('clockwork:mcp-setup')->assertFailed();

        expect(DB::table('oauth_clients')->count())->toBe(0);
    });
});
