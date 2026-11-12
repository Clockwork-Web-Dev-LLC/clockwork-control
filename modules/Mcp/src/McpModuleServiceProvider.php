<?php

namespace Modules\Mcp;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Passport;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;
use Modules\Mcp\Console\McpSetup;
use Modules\Mcp\Http\Controllers\AlwaysConsentAuthorizationController;
use Modules\Mcp\Http\Middleware\EnsureMcpUser;
use Modules\Mcp\Http\Middleware\LogMcpToolCall;
use Modules\Mcp\Http\Middleware\McpGate;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Mcp\Support\ClaudeConnector;

/**
 * Claude access: one MCP server that other modules add tools to through
 * ModuleServiceProvider::mcpTools(). It lives in its own module, not in
 * Core, so it can be turned off.
 *
 * Two ways in:
 *  - Stdio ("php artisan mcp:start clockwork"), for Claude Code on the
 *    Control host. No login, so it runs as the user named in
 *    CLOCKWORK_MCP_LOCAL_USER and is not registered without one.
 *  - The network endpoint, POST /mcp, for claude.ai and Cowork, with
 *    per-user sign-in through Passport. It is off until an admin turns it
 *    on at /settings/claude-access; until then the app has no /mcp, /oauth
 *    or /.well-known/oauth route at all.
 */
class McpModuleServiceProvider extends ModuleServiceProvider
{
    public const LOCAL_HANDLE = 'clockwork';

    /** Tool calls allowed per person per minute. */
    public const CALLS_PER_MINUTE = 60;

    public function register(): void
    {
        parent::register();

        // Passport registers every route it has as soon as it is installed.
        // Control registers only the four it needs, and only while Claude
        // access is on (registerNetworkRoutes), so Passport's own are off
        // for good. This can't depend on the setting: settings live in the
        // database, which isn't reachable yet while providers register.
        Passport::ignoreRoutes();
        Passport::$deviceCodeGrantEnabled = false;

        // Passport hands the session guard only to its own two controllers.
        $this->app->when(AlwaysConsentAuthorizationController::class)
            ->needs(StatefulGuard::class)
            ->give(fn () => Auth::guard(config('passport.guard')));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'claude-access');
        $this->commands([McpSetup::class]);

        // An hour for the token Claude sends with each call, 90 days before
        // a person has to sign in again.
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(90));
        Passport::authorizationView('claude-access::authorize');

        // laravel/mcp checks Origin too, against its own config key.
        config(['mcp.allowed_origins' => config('clockwork.mcp.allowed_origins', [])]);

        // Registered even while the endpoint is off: a route that names a
        // limiter nobody defined fails in a confusing way.
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(self::CALLS_PER_MINUTE)
            ->by('mcp:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        if (! $this->enabled()) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if (filled(config('clockwork.mcp.local_user'))) {
            Mcp::local(self::LOCAL_HANDLE, ClockworkServer::class);
        }

        if ($this->app->make(ClaudeConnector::class)->enabled()) {
            $this->registerNetworkRoutes();
        }
    }

    /**
     * The network endpoint and the sign-in routes behind it. Public so
     * tests can register them: under test the settings table doesn't exist
     * yet when providers boot.
     */
    public function registerNetworkRoutes(): void
    {
        // Sign-in: only these four of Passport's routes, under its own
        // names, which laravel/mcp's discovery document looks up.
        Route::prefix(config('passport.path', 'oauth'))->name('passport.')->group(function () {
            Route::post('/token', [AccessTokenController::class, 'issueToken'])
                ->middleware('throttle:60,1')
                ->name('token');

            // In the web group, so the login page, the user allowlist and
            // the revoked-user check all apply before anyone can consent.
            Route::get('/authorize', [AlwaysConsentAuthorizationController::class, 'authorize'])
                ->middleware('web')
                ->name('authorizations.authorize');

            Route::middleware(['web', 'auth:web', 'active'])->group(function () {
                Route::post('/authorize', [ApproveAuthorizationController::class, 'approve'])->name('authorizations.approve');
                Route::delete('/authorize', [DenyAuthorizationController::class, 'deny'])->name('authorizations.deny');
            });
        });

        // The documents Claude reads to find the sign-in routes. No
        // registration route: the one client is created by an admin.
        Route::middleware('throttle:60,1')->group(fn () => Mcp::oauthRoutes(registration: false));

        // Not in the web group: it is called by a server with a bearer
        // token, with no session and no CSRF token.
        Route::middleware(McpGate::class)->group(function () {
            Mcp::web('/mcp', ClockworkServer::class)->middleware([
                'auth:api',
                CheckToken::using(Registrar::OAUTH_SCOPE),
                EnsureMcpUser::class,
                'throttle:mcp',
                LogMcpToolCall::class,
            ]);
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'mcp',
            name: 'Claude access',
            description: 'Lets Claude read Control through an MCP server. Other modules add the tools; SEO is the first.',
            status: ModuleManifest::STATUS_LOOKING_FOR_TESTERS,
            statusNote: 'New. The network endpoint is off until an admin turns it on at Settings → Claude access, and it also needs a public hostname pointed at Control.',
        );
    }

    public function navItems(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return [
            new NavItem(
                label: 'Claude access',
                icon: 'fa-solid fa-plug',
                route: 'settings.claude-access.index',
            ),
        ];
    }
}
