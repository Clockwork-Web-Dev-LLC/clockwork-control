<?php

use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Passport;
use Modules\Mcp\Http\Middleware\EnsureMcpUser;
use Modules\Mcp\Http\Middleware\McpGate;
use Modules\Mcp\Support\ClaudeConnector;
use Modules\Seo\Services\SearchNumbers;
use Tests\Concerns\EnablesClaudeAccess;
use Tests\Fixtures\SeoStoredData;

uses(EnablesClaudeAccess::class);

beforeEach(function () {
    $this->user = User::factory()->operator()->create(['name' => 'Lara Operator', 'email' => 'lara@example.com']);
});

it('has no endpoint at all until Claude access is turned on', function () {
    $this->postJson('/mcp', $this->toolCall('control_whoami'))->assertNotFound();
    $this->get('/.well-known/oauth-protected-resource/mcp')->assertNotFound();

    expect(Mcp::getWebServer('mcp'))->toBeNull();
});

describe('with Claude access on', function () {
    beforeEach(fn () => $this->enableClaudeAccess());

    it('answers a signed-in person\'s tool call as that person', function () {
        Passport::actingAs($this->user, ['mcp:use'], 'api');

        $this->postJson('/mcp', $this->toolCall('control_whoami'))
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.user.email', 'lara@example.com')
            ->assertJsonPath('result.structuredContent.user.role', 'operator');
    });

    it('accepts the newest protocol form with its headers', function () {
        Passport::actingAs($this->user, ['mcp:use'], 'api');

        $body = $this->toolCall('control_whoami');
        $body['params']['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];

        $this->postJson('/mcp', $body, [
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'control_whoami',
        ])->assertOk()->assertJsonPath('result.structuredContent.user.email', 'lara@example.com');
    });

    it('lists the tools over the network', function () {
        Passport::actingAs($this->user, ['mcp:use'], 'api');

        $names = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()
            ->json('result.tools.*.name');

        expect($names)->toHaveCount(12)->toContain('control_whoami', 'seo_month_numbers');
    });

    it('answers 401 with the sign-in pointer when there is no token, whatever the client accepts', function (array $headers) {
        $response = $this->post('/mcp', $this->toolCall('control_whoami'), $headers);

        // Never a redirect to the login page: Claude needs the 401.
        $response->assertUnauthorized();
        expect($response->headers->get('WWW-Authenticate'))
            ->toContain('Bearer')
            ->toContain('resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
    })->with([
        'json' => [['Accept' => 'application/json']],
        'anything' => [['Accept' => '*/*']],
        'event stream' => [['Accept' => 'text/event-stream']],
    ]);

    it('refuses a token that lacks the mcp scope', function () {
        Passport::actingAs($this->user, [], 'api');

        $this->postJson('/mcp', $this->toolCall('control_whoami'))->assertForbidden();
    });

    it('refuses a revoked person on every call, with JSON and not a crash', function () {
        Passport::actingAs($this->user, ['mcp:use'], 'api');
        $this->postJson('/mcp', $this->toolCall('control_whoami'))->assertOk();

        $this->user->forceFill(['revoked_at' => now()])->save();

        $this->postJson('/mcp', $this->toolCall('control_whoami'))
            ->assertUnauthorized()
            ->assertJsonStructure(['message']);
    });

    it('only allows POST', function () {
        $this->get('/mcp')->assertStatus(405);
        $this->delete('/mcp')->assertStatus(405);
    });

    it('turns away a browser origin that is not Claude, and lets Claude\'s through to the token check', function () {
        $this->postJson('/mcp', $this->toolCall('control_whoami'), ['Origin' => 'https://evil.test'])->assertForbidden();
        // Not even localhost, which laravel/mcp alone would allow.
        $this->postJson('/mcp', $this->toolCall('control_whoami'), ['Origin' => 'http://localhost:3000'])->assertForbidden();

        $this->postJson('/mcp', $this->toolCall('control_whoami'), ['Origin' => 'https://claude.ai'])->assertUnauthorized();
    });

    it('goes dark the moment the switch is turned off, even with routes still loaded', function () {
        Passport::actingAs($this->user, ['mcp:use'], 'api');
        app(Settings::class)->put(ClaudeConnector::SETTING_ENABLED, false);

        $this->postJson('/mcp', $this->toolCall('control_whoami'))->assertNotFound();
        $this->get('/mcp')->assertNotFound();
    });

    it('never shows an exception message, even with debug on', function () {
        config(['app.debug' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
        SeoStoredData::profile('acme.com');
        $this->mock(SearchNumbers::class)->shouldReceive('month')->andThrow(new RuntimeException('SQLSTATE secret-table-name password=hunter2'));
        Passport::actingAs($this->user, ['mcp:use'], 'api');

        $response = $this->postJson('/mcp', $this->toolCall('seo_month_numbers', ['site' => 'acme.com']));

        expect($response->getContent())
            ->not->toContain('hunter2')
            ->not->toContain('SQLSTATE')
            ->not->toContain('RuntimeException')
            ->not->toContain('vendor/');
    });

    it('limits each person to 60 calls a minute', function () {
        Passport::actingAs($this->user, ['mcp:use'], 'api');

        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $i, 'method' => 'ping'])->assertOk();
        }

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 61, 'method' => 'ping'])->assertStatus(429);
    });

    it('logs who called which tool, without the arguments or the answer', function () {
        Log::spy();
        Passport::actingAs($this->user, ['mcp:use'], 'api');

        $this->postJson('/mcp', $this->toolCall('seo_list_sites', ['site' => 'acme.com', 'search' => 'private-search-text']))->assertOk();

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'mcp.tool_call'
            && $context['user_id'] === $this->user->id
            && $context['tool'] === 'seo_list_sites'
            && $context['site'] === 'acme.com'
            && is_int($context['ms'])
            && ! str_contains(json_encode($context), 'private-search-text'))->once();
    });

    it('guards the endpoint with the gate, the token guard and the active-user check', function () {
        $middleware = Route::gatherRouteMiddleware(Mcp::getWebServer('mcp'));

        expect($middleware)->toContain(McpGate::class, EnsureMcpUser::class, 'throttle:mcp')
            ->and(collect($middleware)->contains(fn ($m) => is_string($m) && str_contains($m, 'auth:api') || str_contains((string) $m, 'Authenticate:api')))->toBeTrue()
            // A bearer-token route: no session, no CSRF.
            ->and($middleware)->not->toContain('web');
    });
});
