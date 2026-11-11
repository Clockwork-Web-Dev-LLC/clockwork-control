<?php

namespace Tests\Concerns;

use App\Support\Settings;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Modules\Mcp\McpModuleServiceProvider;
use Modules\Mcp\Support\ClaudeConnector;

/**
 * Turns the Claude access network endpoint on for a test.
 *
 * Under test the settings table doesn't exist yet when providers boot, so
 * the routes are registered here, the way the provider does it on a real
 * request once the switch is on. Passport also needs a signing key pair
 * before it will build its guard; one is generated per test process.
 */
trait EnablesClaudeAccess
{
    private static ?array $claudeAccessKeys = null;

    protected function useClaudeAccessKeys(): void
    {
        if (self::$claudeAccessKeys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$claudeAccessKeys = [$private, openssl_pkey_get_details($key)['key']];
        }

        config([
            'passport.private_key' => self::$claudeAccessKeys[0],
            'passport.public_key' => self::$claudeAccessKeys[1],
        ]);
    }

    protected function enableClaudeAccess(): void
    {
        $this->useClaudeAccessKeys();

        app(Settings::class)->put(ClaudeConnector::SETTING_ENABLED, true);
        app()->getProvider(McpModuleServiceProvider::class)->registerNetworkRoutes();

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    /** A tools/call body in the older protocol form, which needs no extra headers. */
    protected function toolCall(string $tool, array $arguments = [], int $id = 1): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => (object) $arguments],
        ];
    }

    protected function tearDownClaudeAccess(): void
    {
        Passport::$keyPath = null;
    }
}
