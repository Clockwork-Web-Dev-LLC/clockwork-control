<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\User;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Allowlist management. A row in `users` IS the allowlist:
 *   - store: insert (or restore if previously revoked)
 *   - revoke: set revoked_at (preserves audit history)
 *   - restore: clear revoked_at
 *
 * Self-revoke is blocked at the controller — losing access to your own UI is
 * surprising and the artisan recovery path doesn't help if you can't reach
 * the host. The artisan command is still the always-works escape hatch if
 * someone else revokes you.
 */
class UsersSettingsController extends Controller
{
    public function index(): View
    {
        $users = User::query()
            ->orderBy('revoked_at')   // active first (NULLs sort first by default)
            ->orderBy('email')
            ->get();

        return view('settings.users', compact('users'));
    }

    public function store(Request $request, ActionLogger $logger): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $email = strtolower(trim($data['email']));
        $name = (string) ($data['name'] ?? $email);

        $user = User::where('email', $email)->first();

        if ($user) {
            $wasRevoked = $user->revoked_at !== null;
            $user->forceFill(['name' => $name, 'revoked_at' => null])->save();

            if ($wasRevoked) {
                $logger->record(
                    actionType: ActionLog::TYPE_USER_RESTORED,
                    summary: "Restored allowlist access for {$email}.",
                    ok: true,
                    actor: (string) (Auth::user()->email ?? 'manual'),
                );

                return redirect()->route('settings.users.index')
                    ->with('status', "Restored access for {$email}.");
            }

            return redirect()->route('settings.users.index')
                ->with('status', "{$email} is already on the allowlist.");
        }

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => null,
        ])->save();

        $logger->record(
            actionType: ActionLog::TYPE_USER_ADDED,
            summary: "Added {$email} to allowlist.",
            ok: true,
            actor: (string) (Auth::user()->email ?? 'manual'),
        );

        return redirect()->route('settings.users.index')
            ->with('status', "Added {$email}. They can now sign in with their Google account.");
    }

    public function revoke(User $user, ActionLogger $logger): RedirectResponse
    {
        if (Auth::id() === $user->id) {
            return redirect()->route('settings.users.index')
                ->with('error', "You can't revoke your own access. Use the artisan command if you really mean it.");
        }

        if ($user->revoked_at !== null) {
            return redirect()->route('settings.users.index')
                ->with('status', "{$user->email} is already revoked.");
        }

        $user->forceFill(['revoked_at' => now()])->save();

        $logger->record(
            actionType: ActionLog::TYPE_USER_REVOKED,
            summary: "Revoked allowlist access for {$user->email}.",
            ok: true,
            actor: (string) (Auth::user()->email ?? 'manual'),
        );

        return redirect()->route('settings.users.index')
            ->with('status', "Revoked {$user->email}. They'll be denied at next sign-in attempt.");
    }

    public function restore(User $user, ActionLogger $logger): RedirectResponse
    {
        if ($user->revoked_at === null) {
            return redirect()->route('settings.users.index')
                ->with('status', "{$user->email} is already active.");
        }

        $user->forceFill(['revoked_at' => null])->save();

        $logger->record(
            actionType: ActionLog::TYPE_USER_RESTORED,
            summary: "Restored allowlist access for {$user->email}.",
            ok: true,
            actor: (string) (Auth::user()->email ?? 'manual'),
        );

        return redirect()->route('settings.users.index')
            ->with('status', "Restored {$user->email}.");
    }
}
