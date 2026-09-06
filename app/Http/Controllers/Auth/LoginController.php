<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\ModuleRegistry;

/**
 * Renders /login and handles /logout. Delegates OAuth dances to
 * registered AuthProvider modules (Google, GitHub, Microsoft).
 */
class LoginController extends Controller
{
    public function show(Request $request, ModuleRegistry $registry): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->intended(route('dashboard'));
        }

        return view('auth.login', [
            'denial' => $request->session()->get('login_denial'),
            // Only show a provider once its credentials are actually set —
            // an enabled-but-unconfigured module would otherwise render a
            // button that 500s (or worse, silently no-ops) when clicked.
            'providers' => array_values(array_filter(
                $registry->authProviders(),
                fn ($provider) => $provider->isConfigured(),
            )),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('login_status', 'You have been signed out.');
    }
}
