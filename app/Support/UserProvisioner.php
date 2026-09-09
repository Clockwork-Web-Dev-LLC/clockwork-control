<?php

namespace App\Support;

use App\Models\ActionLog;
use App\Models\User;
use App\Services\ActionLog\ActionLogger;
use InvalidArgumentException;

class UserProvisioner
{
    public function __construct(
        protected ActionLogger $logger,
    ) {}

    /**
     * Add or restore an operator on the Clockwork allowlist.
     *
     * @return array{user: User, status: 'created'|'restored'|'updated'}
     */
    public function addOrRestore(
        string $email,
        ?string $name = null,
        string $actor = 'cli',
        ?string $password = null,
        string $role = User::ROLE_ADMIN,
    ): array {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("'{$email}' doesn't look like a valid email address.");
        }

        $role = $role === User::ROLE_OPERATOR ? User::ROLE_OPERATOR : User::ROLE_ADMIN;

        $name = (string) ($name ?: $email);
        $existing = User::where('email', $email)->first();

        if ($existing) {
            $wasRevoked = $existing->revoked_at !== null;
            $updates = [
                'name' => $name,
                'revoked_at' => null,
                'role' => $role,
            ];
            if ($password !== null && $password !== '') {
                $updates['password'] = $password;
            }
            $existing->forceFill($updates)->save();

            if ($password !== null && $password !== '') {
                $existing->invalidateSessions();
            }

            if ($wasRevoked) {
                $this->logger->record(
                    actionType: ActionLog::TYPE_USER_RESTORED,
                    summary: "Restored allowlist access for {$email} via {$actor}.",
                    ok: true,
                    actor: $actor,
                );

                return ['user' => $existing, 'status' => 'restored'];
            }

            return ['user' => $existing, 'status' => 'updated'];
        }

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => ($password !== null && $password !== '') ? $password : null,
            'role' => $role,
        ])->save();

        $this->logger->record(
            actionType: ActionLog::TYPE_USER_ADDED,
            summary: "Added {$email} to allowlist via {$actor}.".($password ? ' (local password set)' : ''),
            ok: true,
            actor: $actor,
        );

        return ['user' => $user, 'status' => 'created'];
    }

    /**
     * Set or update an operator's local password.
     */
    public function setPassword(User $user, string $password, string $actor = 'cli'): void
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }

        $user->forceFill(['password' => $password])->save();
        $user->invalidateSessions();

        $this->logger->record(
            actionType: ActionLog::TYPE_USER_PASSWORD_CHANGED,
            summary: "Updated password for {$user->email} via {$actor}.",
            ok: true,
            actor: $actor,
        );
    }
}
