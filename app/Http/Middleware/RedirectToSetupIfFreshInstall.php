<?php

namespace App\Http\Middleware;

use App\Models\Server;
use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a fresh install to /setup instead of an empty dashboard. Applied
 * only to the dashboard route (not the whole authenticated group) — this
 * app has no self-provisioning (a `users` row IS the allowlist, see
 * GoogleAuthController), so reaching any authenticated route at all already
 * means someone ran migrations and seeded a user row; there's no true
 * pre-auth "first run" to gate.
 *
 * "Fresh" is deliberately narrow: zero servers AND zero sites. Not "zero
 * credentials configured" — an operator might legitimately want to browse
 * around with everything still unconfigured, and a credential-based check
 * would fight them on every page load. An empty fleet is the one condition
 * with no plausible false positive on a real, in-use instance.
 */
class RedirectToSetupIfFreshInstall
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Server::count() === 0 && Site::count() === 0) {
            return redirect()->route('setup.index');
        }

        return $next($request);
    }
}
