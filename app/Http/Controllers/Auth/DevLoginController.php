<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Local-only passwordless login for development. Never auto-creates users,
 * and never answers on a non-loopback host even if APP_ENV=local by mistake.
 */
class DevLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless(self::isLoopbackRequest($request), 404);

        $user = User::query()->whereNull('revoked_at')->first();
        abort_if($user === null, 404);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('settings.companion.index');
    }

    public static function isLoopbackRequest(Request $request): bool
    {
        // Direct loopback requests only — reject if forwarded by a reverse proxy
        if ($request->headers->has('x-forwarded-for') || $request->headers->has('x-forwarded-host')) {
            return false;
        }

        $host = strtolower($request->getHost());
        $remoteAddr = (string) ($request->server('REMOTE_ADDR') ?? $request->ip());

        $loopbackHosts = ['127.0.0.1', 'localhost', '::1'];
        $loopbackIps = ['127.0.0.1', '::1'];

        return in_array($host, $loopbackHosts, true)
            && in_array($remoteAddr, $loopbackIps, true);
    }
}
