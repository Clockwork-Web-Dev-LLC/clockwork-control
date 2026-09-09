<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\User;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Core\ModuleRegistry;

/**
 * Renders /login and handles local password sign-in and /logout.
 * Delegates OAuth dances to registered AuthProvider modules (Google, GitHub, Microsoft).
 */
class LoginController extends Controller
{
    public function show(Request $request, ModuleRegistry $registry): View|RedirectResponse
    {
        if (Auth::check()) {
            $user = Auth::user();
            if ($user instanceof User && ! $user->isActive()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with(
                    'login_denial',
                    'Your account has been revoked. Please contact an administrator.'
                );
            }

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

    public function login(Request $request, ActionLogger $logger): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower(trim($credentials['email']));
        $user = User::where('email', $email)->first();

        if (! $user) {
            return back()
                ->withInput($request->only('email', 'remember'))
                ->with('login_denial', 'Invalid email or password.');
        }

        if (! $user->isActive()) {
            $logger->record(
                actionType: ActionLog::TYPE_LOGIN,
                summary: "Blocked password login for revoked operator {$email}.",
                ok: false,
                actor: $email,
            );

            return back()
                ->withInput($request->only('email', 'remember'))
                ->with('login_denial', 'Your account has been revoked. Please contact an administrator.');
        }

        if ($user->password === null || $user->password === '') {
            return back()
                ->withInput($request->only('email', 'remember'))
                ->with('login_denial', 'This account is configured for Single Sign-On. Please sign in with your configured OAuth provider, or ask an administrator to set a local password.');
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            $logger->record(
                actionType: ActionLog::TYPE_LOGIN,
                summary: "Failed password login attempt for {$email}.",
                ok: false,
                actor: $email,
            );

            return back()
                ->withInput($request->only('email', 'remember'))
                ->with('login_denial', 'Invalid email or password.');
        }

        Auth::login($user, $request->boolean('remember'));
        $user->forceFill(['last_login_at' => now()])->save();
        $request->session()->regenerate();

        $logger->record(
            actionType: ActionLog::TYPE_LOGIN,
            summary: "Operator {$email} signed in via password.",
            ok: true,
            actor: $email,
        );

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('login_status', 'You have been signed out.');
    }
}
