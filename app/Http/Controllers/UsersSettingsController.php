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
 * Admin-only allowlist management. A row in `users` IS the allowlist:
 *   - store: insert (or restore if previously revoked); new UI users default to operator
 *   - revoke: set revoked_at and invalidate sessions
 *   - restore: clear revoked_at
 *   - updatePassword: set password and invalidate other sessions
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
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['nullable', 'in:'.User::ROLE_ADMIN.','.User::ROLE_OPERATOR],
        ]);

        $email = strtolower(trim($data['email']));
        $name = (string) ($data['name'] ?? $email);
        $role = $data['role'] ?? User::ROLE_OPERATOR;

        $user = User::where('email', $email)->first();

        if ($user) {
            if ($user->isActive() && $user->isAdmin() && $role === User::ROLE_OPERATOR) {
                if (Auth::id() === $user->id) {
                    return redirect()->route('settings.users.index')
                        ->with('error', "You can't demote your own account.");
                }

                if (User::query()->active()->where('role', User::ROLE_ADMIN)->count() <= 1) {
                    return redirect()->route('settings.users.index')
                        ->with('error', "You can't demote the last administrator.");
                }
            }

            $wasRevoked = $user->revoked_at !== null;
            $updates = ['name' => $name, 'revoked_at' => null, 'role' => $role];
            if (! empty($data['password'])) {
                $updates['password'] = $data['password'];
            }
            $user->forceFill($updates)->save();

            if (! empty($data['password'])) {
                $user->invalidateSessions();
            }

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
            'password' => ! empty($data['password']) ? $data['password'] : null,
            'role' => $role,
        ])->save();

        $logger->record(
            actionType: ActionLog::TYPE_USER_ADDED,
            summary: "Added {$email} to allowlist.".(! empty($data['password']) ? ' (local password set)' : ''),
            ok: true,
            actor: (string) (Auth::user()->email ?? 'manual'),
        );

        return redirect()->route('settings.users.index')
            ->with('status', "Added {$email}. They can now sign in".(! empty($data['password']) ? ' with their password.' : ' with their Google account.'));
    }

    public function updatePassword(Request $request, User $user, ActionLogger $logger): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->forceFill(['password' => $data['password']])->save();
        $user->invalidateSessions(keepCurrent: Auth::id() === $user->id);

        $logger->record(
            actionType: ActionLog::TYPE_USER_PASSWORD_CHANGED,
            summary: "Updated password for {$user->email}.",
            ok: true,
            actor: (string) (Auth::user()->email ?? 'manual'),
        );

        return redirect()->route('settings.users.index')
            ->with('status', "Updated password for {$user->email}.");
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

        if ($user->isAdmin() && User::query()->active()->where('role', User::ROLE_ADMIN)->count() === 1) {
            return redirect()->route('settings.users.index')
                ->with('error', "You can't revoke the last administrator.");
        }

        $user->forceFill(['revoked_at' => now()])->save();
        $user->invalidateSessions();

        $logger->record(
            actionType: ActionLog::TYPE_USER_REVOKED,
            summary: "Revoked allowlist access for {$user->email}.",
            ok: true,
            actor: (string) (Auth::user()->email ?? 'manual'),
        );

        return redirect()->route('settings.users.index')
            ->with('status', "Revoked {$user->email}. Their sessions have been ended.");
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
