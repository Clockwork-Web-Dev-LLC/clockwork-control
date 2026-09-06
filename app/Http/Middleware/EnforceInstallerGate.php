<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnforceInstallerGate
{
    /**
     * Test isolation fake for installation state.
     */
    public static ?bool $fakeInstalled = null;

    /**
     * When true during unit tests, allows testing real file-based and auto-heal gate behavior.
     */
    public static bool $ignoreUnitTestBypass = false;

    /**
     * Override installation state during tests.
     */
    public static function fake(?bool $installed = true): void
    {
        static::$fakeInstalled = $installed;
    }

    public static ?string $customSentinelPath = null;

    /**
     * Get the sentinel path that marks the installation as complete.
     */
    public static function sentinelPath(): string
    {
        if (static::$customSentinelPath !== null) {
            return static::$customSentinelPath;
        }

        if (app()->runningUnitTests()) {
            $token = getenv('TEST_TOKEN') ?: '1';

            return storage_path("installed_test_{$token}");
        }

        return storage_path('installed');
    }

    /**
     * Determine whether Clockwork Control has completed initial installation.
     */
    public static function isInstalled(): bool
    {
        if (static::$fakeInstalled !== null) {
            return static::$fakeInstalled;
        }

        if (app()->runningUnitTests() && ! static::$ignoreUnitTestBypass) {
            return true;
        }

        return file_exists(static::sentinelPath());
    }

    /**
     * Check if the database contains existing active user accounts.
     */
    public static function hasExistingDatabase(): bool
    {
        try {
            if (empty(config('app.key'))) {
                return false;
            }

            return Schema::hasTable('users')
                && User::query()->whereNull('revoked_at')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Write the sentinel file to mark the installation as complete.
     *
     * @param  array<string, mixed>  $data
     */
    public static function writeSentinel(array $data = []): void
    {
        $payload = array_merge([
            'installed_at' => now()->toIso8601String(),
            'version' => config('clockwork.version', '1.1.0'),
        ], $data);

        file_put_contents(
            static::sentinelPath(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isInstalled = static::isInstalled();

        if ($isInstalled) {
            // Hard 404 on any installer route once installed — eliminates replay or bookmark probing.
            if ($request->is('install') || $request->is('install/*')) {
                abort(404);
            }

            return $next($request);
        }

        // Pre-installation gate: allow installer wizard, health check, and static assets.
        if (
            $request->is('install') ||
            $request->is('install/*') ||
            $request->is('up') ||
            $request->is('build/*') ||
            $request->is('favicon.ico')
        ) {
            return $next($request);
        }

        // Auto-heal / unlock for existing installations accessing standard app routes:
        // If an operator visits an app route (e.g. /login, /, /sites) and the database
        // already has active users, automatically write the sentinel and let them in
        // so they are never locked out of their existing system.
        if (
            static::$fakeInstalled === null &&
            static::hasExistingDatabase() &&
            ! file_exists(storage_path('installer_reopened'))
        ) {
            static::writeSentinel([
                'auto_healed' => true,
            ]);

            return $next($request);
        }

        return redirect()->route('install.welcome');
    }
}
