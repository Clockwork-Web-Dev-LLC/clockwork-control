<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Transport\StdioTransport;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleRegistry;
use Modules\Core\ModuleStateResolver;
use Modules\Mcp\McpModuleServiceProvider;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Mcp\Tools\ControlWhoAmI;
use Modules\Seo\Mcp\Tools\SeoAiImpressionsRecord;
use Modules\Seo\Mcp\Tools\SeoDailySeries;
use Modules\Seo\Mcp\Tools\SeoListSites;
use Modules\Seo\Mcp\Tools\SeoMonthNumbers;
use Modules\Seo\Mcp\Tools\SeoSearchRows;
use Modules\Seo\Mcp\Tools\SeoSiteProfileUpdate;

const SEO_TOOLS = [
    SeoListSites::class,
    SeoMonthNumbers::class,
    SeoDailySeries::class,
    SeoSearchRows::class,
    SeoAiImpressionsRecord::class,
    SeoSiteProfileUpdate::class,
];

/** tools/list as Claude receives it, as plain arrays. */
function listedTools(User $user): array
{
    $list = ClockworkServer::actingAs($user)->tools();

    return (fn () => $this->items)->call($list);
}

beforeEach(function () {
    $this->user = User::factory()->operator()->create(['name' => 'Lara Operator', 'email' => 'lara@example.com']);
});

it('offers its own tool and the tools enabled modules contribute', function () {
    ClockworkServer::actingAs($this->user)->tools()
        ->assertRegistered([ControlWhoAmI::class, ...SEO_TOOLS]);

    expect(app(ModuleRegistry::class)->mcpTools())->toBe(SEO_TOOLS);
});

it('drops a module\'s tools when the module is turned off', function () {
    InstalledModule::updateOrCreate(
        ['module_id' => 'seo'],
        ['name' => 'SEO', 'enabled' => false, 'source' => 'bundled', 'status' => 'active']
    );
    app(ModuleStateResolver::class)->flush();

    ClockworkServer::actingAs($this->user)->tools()
        ->assertRegistered(ControlWhoAmI::class)
        ->assertNotRegistered(SEO_TOOLS);

    expect(app(ModuleRegistry::class)->mcpTools())->toBe([]);
});

it('names every tool control_ or seo_ and gives each a title, a description and hints', function () {
    $tools = listedTools($this->user);

    expect($tools)->toHaveCount(7);

    foreach ($tools as $tool) {
        expect($tool['name'])->toMatch('/^(control|seo)_[a-z_]+$/')
            ->and(strlen($tool['name']))->toBeLessThanOrEqual(40)
            ->and($tool['title'])->not->toBeEmpty()
            ->and(strlen($tool['description']))->toBeGreaterThan(40)
            // An unmarked write is treated as destructive, so every tool says.
            ->and($tool['annotations'])->toHaveKey('readOnlyHint');

        if ($tool['annotations']['readOnlyHint'] === false) {
            expect($tool['annotations'])->toHaveKey('destructiveHint');
        }
    }

    expect(collect($tools)->where('annotations.readOnlyHint', false)->pluck('name')->sort()->values()->all())
        ->toBe(['seo_ai_impressions_record', 'seo_site_profile_update']);
});

it('returns every tool on the first page', function () {
    // laravel/mcp pages tools/list at 15 by default.
    expect((new ReflectionClass(ClockworkServer::class))->getProperty('defaultPaginationLength')->getDefaultValue())->toBe(50);
});

it('tells the caller who they are and what they can use', function () {
    ClockworkServer::actingAs($this->user)->tool(ControlWhoAmI::class)
        ->assertOk()
        ->assertStructuredContentPath('user', ['name' => 'Lara Operator', 'email' => 'lara@example.com', 'role' => 'operator'])
        ->assertStructuredContentPath('tools.0', 'control_whoami')
        ->assertStructuredContentPath('tools.1', 'seo_ai_impressions_record');
});

it('hides the SEO tools from a revoked user and refuses them', function () {
    $revoked = User::factory()->create(['revoked_at' => now()]);

    ClockworkServer::actingAs($revoked)->tools()->assertNotRegistered(SEO_TOOLS);
    ClockworkServer::actingAs($revoked)->tool(ControlWhoAmI::class)->assertHasErrors();

    foreach (SEO_TOOLS as $tool) {
        $response = app($tool)->handle(new Request(['site' => '1']), ...array_map(
            fn (ReflectionParameter $p) => app($p->getType()->getName()),
            array_slice((new ReflectionMethod($tool, 'handle'))->getParameters(), 1),
        ));

        expect($response->isError())->toBeTrue();
    }
});

it('turns debug off so an exception never reaches Claude', function () {
    config(['app.debug' => true]);

    ClockworkServer::actingAs($this->user)->tools();

    expect(config('app.debug'))->toBeFalse();
});

describe('stdio', function () {
    it('is not registered without CLOCKWORK_MCP_LOCAL_USER', function () {
        expect(Mcp::getLocalServer(McpModuleServiceProvider::LOCAL_HANDLE))->toBeNull();
    });

    it('is registered once a local user is named', function () {
        config(['clockwork.mcp.local_user' => 'lara@example.com']);
        app()->getProvider(McpModuleServiceProvider::class)->boot();

        expect(Mcp::getLocalServer(McpModuleServiceProvider::LOCAL_HANDLE))->not->toBeNull();
    });

    it('acts as the named user', function () {
        config(['clockwork.mcp.local_user' => 'lara@example.com']);

        (new ClockworkServer(new StdioTransport))->start();

        expect(auth()->user()?->is($this->user))->toBeTrue();
    });

    it('refuses to start for an unknown or revoked user', function (string $email) {
        User::factory()->create(['email' => 'gone@example.com', 'revoked_at' => now()]);
        config(['clockwork.mcp.local_user' => $email]);

        expect(fn () => (new ClockworkServer(new StdioTransport))->start())->toThrow(RuntimeException::class);
        expect(auth()->user())->toBeNull();
    })->with(['', 'nobody@example.com', 'gone@example.com']);
});

it('exposes no network endpoint or sign-in routes yet', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map->uri();

    expect($uris->filter(fn (string $uri) => $uri === 'mcp' || str_starts_with($uri, 'oauth/') || str_starts_with($uri, '.well-known/oauth'))->values()->all())
        ->toBe([]);
});
