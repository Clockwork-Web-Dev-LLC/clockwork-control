<?php

use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleStateResolver;
use Modules\Mcp\Support\ClaudeConnector;
use Tests\Concerns\EnablesClaudeAccess;
use Tests\Concerns\RendersAuthenticatedPages;

uses(EnablesClaudeAccess::class, RendersAuthenticatedPages::class);

/** Give a person a live Claude connection, as a finished sign-in leaves it. */
function connectClaude(User $user, string $clientId): void
{
    $tokenId = bin2hex(random_bytes(20));
    DB::table('oauth_access_tokens')->insert([
        'id' => $tokenId, 'user_id' => $user->id, 'client_id' => $clientId, 'scopes' => '["mcp:use"]',
        'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addHour(),
    ]);
    DB::table('oauth_refresh_tokens')->insert([
        'id' => bin2hex(random_bytes(20)), 'access_token_id' => $tokenId, 'revoked' => false, 'expires_at' => now()->addDays(90),
    ]);
}

beforeEach(function () {
    $this->mockIssueCounterZero();
    $this->withoutVite();

    // Keys go to a scratch folder, never to the real storage/.
    $this->keyDir = sys_get_temp_dir().'/cw-passport-'.bin2hex(random_bytes(6));
    mkdir($this->keyDir);
    Passport::loadKeysFrom($this->keyDir);

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->operator = User::factory()->operator()->create(['name' => 'Lara Operator']);
    $this->connector = app(ClaudeConnector::class);
    $this->url = route('settings.claude-access.index');
});

afterEach(function () {
    array_map('unlink', glob($this->keyDir.'/*') ?: []);
    @rmdir($this->keyDir);
    $this->tearDownClaudeAccess();
});

it('is linked from the menu and the Settings hub, and gone when the module is off', function () {
    $this->actingAs($this->operator)->get(route('settings.index'))->assertOk()->assertSee($this->url, false);
    $this->actingAs($this->operator)->get($this->url)->assertOk()->assertSee('Claude access');

    InstalledModule::updateOrCreate(['module_id' => 'mcp'], ['name' => 'Claude access', 'enabled' => false, 'source' => 'bundled', 'status' => 'active']);
    app(ModuleStateResolver::class)->flush();

    $this->actingAs($this->operator)->get(route('settings.diagnostics.index'))->assertOk()->assertDontSee('settings/claude-access', false);
});

it('redirects guests to login', function () {
    $this->get($this->url)->assertRedirect(route('login'));
});

it('starts off, not set up, and says what is missing', function () {
    $this->actingAs($this->admin)->get($this->url)
        ->assertOk()
        ->assertSee('data-claude-access-state="off"', false)
        ->assertSee('Signing keys not created yet')
        ->assertSee('Run setup')
        ->assertSee(url('/mcp'))
        ->assertSee('seo_month_numbers')
        ->assertDontSee('data-claude-access="client-id"', false);
});

it('shows an operator the page without the admin controls', function () {
    $this->actingAs($this->operator)->get($this->url)
        ->assertOk()
        ->assertSee('Only an administrator can change this.')
        ->assertDontSee('Run setup')
        ->assertDontSee(route('settings.claude-access.setup'), false);

    $this->actingAs($this->operator)->patch(route('settings.claude-access.update'), ['enabled' => 1])->assertForbidden();
    $this->actingAs($this->operator)->post(route('settings.claude-access.setup'))->assertForbidden();
    $this->actingAs($this->operator)->post(route('settings.claude-access.rotate-secret'))->assertForbidden();
    $this->actingAs($this->operator)->delete(route('settings.claude-access.revoke-all'))->assertForbidden();

    expect($this->connector->enabled())->toBeFalse();
});

it('will not turn on before setup has been run', function () {
    $this->actingAs($this->admin)->patch(route('settings.claude-access.update'), ['enabled' => 1])
        ->assertSessionHas('warning');

    expect($this->connector->enabled())->toBeFalse();
});

it('runs setup from the page and shows the secret exactly once', function () {
    $response = $this->actingAs($this->admin)->post(route('settings.claude-access.setup'));
    $response->assertRedirect()->assertSessionHas('claude_access_secret');
    $secret = session('claude_access_secret');

    $client = $this->connector->client();
    expect($this->connector->ready())->toBeTrue()
        ->and(strlen($secret))->toBeGreaterThanOrEqual(40)
        ->and(Hash::check($secret, DB::table('oauth_clients')->value('secret')))->toBeTrue()
        ->and(ActionLog::query()->where('action_type', 'claude_access_setup')->count())->toBe(1);

    // The page right after setup shows it...
    $this->actingAs($this->admin)->get($this->url)
        ->assertSee($secret)
        ->assertSee($client->getKey())
        ->assertSee('Signing keys are in place');

    // ...and never again.
    $this->actingAs($this->admin)->get($this->url)->assertDontSee($secret)->assertSee($client->getKey());

    // The operator never sees the client ID either.
    $this->actingAs($this->operator)->get($this->url)->assertDontSee($client->getKey());

    // A second run changes nothing and hands out no secret.
    $this->actingAs($this->admin)->post(route('settings.claude-access.setup'))->assertSessionMissing('claude_access_secret');
    expect(DB::table('oauth_clients')->count())->toBe(1);
});

it('turns on and off, and records who did it', function () {
    $this->actingAs($this->admin)->post(route('settings.claude-access.setup'));
    connectClaude($this->operator, $this->connector->client()->getKey());

    $this->actingAs($this->admin)->patch(route('settings.claude-access.update'), ['enabled' => 1])->assertSessionHas('status');
    expect($this->connector->enabled())->toBeTrue();
    $this->actingAs($this->admin)->get($this->url)->assertSee('data-claude-access-state="on"', false);

    $this->actingAs($this->admin)->patch(route('settings.claude-access.update'), ['enabled' => 0]);
    expect($this->connector->enabled())->toBeFalse()
        // A pause keeps people's connections; disconnecting is a separate step.
        ->and(DB::table('oauth_access_tokens')->where('revoked', false)->count())->toBe(1);

    $logs = ActionLog::query()->whereIn('action_type', ['claude_access_enabled', 'claude_access_disabled'])->orderBy('id')->get();
    expect($logs->pluck('action_type')->all())->toBe(['claude_access_enabled', 'claude_access_disabled'])
        ->and($logs[0]->actor)->toBe($this->admin->email);
});

it('issues a new secret that replaces the old one', function () {
    $this->actingAs($this->admin)->post(route('settings.claude-access.setup'));
    $old = session('claude_access_secret');

    $this->actingAs($this->admin)->post(route('settings.claude-access.rotate-secret'))->assertSessionHas('claude_access_secret');
    $new = session('claude_access_secret');
    $stored = DB::table('oauth_clients')->value('secret');

    expect($new)->not->toBe($old)
        ->and(Hash::check($new, $stored))->toBeTrue()
        ->and(Hash::check($old, $stored))->toBeFalse()
        ->and(DB::table('oauth_clients')->count())->toBe(1);
});

describe('who is connected', function () {
    beforeEach(function () {
        $this->actingAs($this->admin)->post(route('settings.claude-access.setup'));
        $this->clientId = $this->connector->client()->getKey();
        connectClaude($this->operator, $this->clientId);
        connectClaude($this->operator, $this->clientId);   // a renewed token: still one row
        connectClaude($this->admin, $this->clientId);
    });

    it('shows an admin everyone, one row per person', function () {
        $response = $this->actingAs($this->admin)->get($this->url)->assertOk();

        $response->assertSee('Lara Operator')->assertSee('Ada Admin')->assertSee('Disconnect everyone');
        expect(substr_count($response->getContent(), 'data-claude-access-user='))->toBe(2);
    });

    it('shows an operator only their own connection', function () {
        $this->actingAs($this->operator)->get($this->url)
            ->assertOk()
            ->assertSee('Your connection')
            ->assertSee('data-claude-access-user="'.$this->operator->id.'"', false)
            ->assertDontSee('data-claude-access-user="'.$this->admin->id.'"', false)
            ->assertDontSee('Disconnect everyone');
    });

    it('lets a person disconnect themselves, and nobody else', function () {
        $this->actingAs($this->operator)
            ->delete(route('settings.claude-access.revoke', ['user' => $this->admin->id, 'client' => $this->clientId]))
            ->assertForbidden();
        expect(DB::table('oauth_access_tokens')->where('user_id', $this->admin->id)->where('revoked', false)->count())->toBe(1);

        $this->actingAs($this->operator)
            ->delete(route('settings.claude-access.revoke', ['user' => $this->operator->id, 'client' => $this->clientId]))
            ->assertSessionHas('status');

        expect(DB::table('oauth_access_tokens')->where('user_id', $this->operator->id)->where('revoked', false)->count())->toBe(0)
            ->and(DB::table('oauth_refresh_tokens')->where('revoked', false)->count())->toBe(1)
            ->and(DB::table('oauth_access_tokens')->where('user_id', $this->admin->id)->where('revoked', false)->count())->toBe(1);

        $this->actingAs($this->operator)->get($this->url)->assertSee('You have not connected Claude.');
    });

    it('lets an admin disconnect one person or everyone', function () {
        $this->actingAs($this->admin)
            ->delete(route('settings.claude-access.revoke', ['user' => $this->operator->id, 'client' => $this->clientId]));
        expect(DB::table('oauth_access_tokens')->where('user_id', $this->operator->id)->where('revoked', false)->count())->toBe(0)
            ->and(ActionLog::query()->where('action_type', 'claude_access_revoked')->sole()->target)->toBe($this->operator->email);

        $this->actingAs($this->admin)->delete(route('settings.claude-access.revoke-all'));
        expect(DB::table('oauth_access_tokens')->where('revoked', false)->count())->toBe(0)
            ->and(DB::table('oauth_refresh_tokens')->where('revoked', false)->count())->toBe(0);

        $this->actingAs($this->admin)->get($this->url)->assertSee('Nobody is connected.');
    });

    it('drops a person from the list when they are removed on Settings → Users', function () {
        $this->operator->invalidateSessions();

        $response = $this->actingAs($this->admin)->get($this->url);
        expect(substr_count($response->getContent(), 'data-claude-access-user='))->toBe(1);
    });
});
