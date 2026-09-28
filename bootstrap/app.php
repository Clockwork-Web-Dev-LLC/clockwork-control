<?php

use App\Http\Middleware\EnforceInstallerGate;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            require __DIR__.'/../routes/install.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // cloudflared (Cloudflare Tunnel) connects to the app over plain HTTP
        // on loopback only (com.clockwork.serve is bound to 127.0.0.1) — so
        // localhost is the sole path in and trusting it is safe. Needed so
        // route()/url() report https:// and the real client host once
        // traffic arrives via the tunnel instead of localhost:8000 directly.
        $middleware->trustProxies(at: ['127.0.0.1', '::1'], headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->encryptCookies(except: [
            'cw_theme',
        ]);

        $middleware->web(append: [
            EnforceInstallerGate::class,
            EnsureUserIsActive::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('install') || $request->is('install/*')) {
                if ($e instanceof ValidationException) {
                    return null;
                }

                if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 404) {
                    return null;
                }

                // Installer routes are reachable by definition by someone who hasn't proven any
                // identity yet — never leak $e->getMessage() (DB/SQL error strings, file paths,
                // etc.) to that visitor regardless of APP_DEBUG. Log the real detail server-side.
                Log::error('installer.exception', [
                    'message' => $e->getMessage(),
                    'path' => $request->path(),
                    'exception' => get_class($e),
                ]);

                $genericMessage = 'Something went wrong during installation. Check the application '
                    .'logs for details, then try again.';

                if ($request->expectsJson()) {
                    return response()->json([
                        'ok' => false,
                        'message' => $genericMessage,
                    ], 500);
                }

                return response()->view('install.error', [
                    'message' => $genericMessage,
                ], 500);
            }

            return null;
        });
    })->create();
