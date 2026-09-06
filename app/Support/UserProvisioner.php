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
    public function addOrRestore(string $email, ?string $name = null, string $actor = 'cli'): array
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("'{$email}' doesn't look like a valid email address.");
        }

        $name = (string) ($name ?: $email);
        $existing = User::where('email', $email)->first();

        if ($existing) {
            $wasRevoked = $existing->revoked_at !== null;
            $existing->forceFill([
                'name' => $name,
                'revoked_at' => null,
            ])->save();

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
            'password' => null,
        ])->save();

        $this->logger->record(
            actionType: ActionLog::TYPE_USER_ADDED,
            summary: "Added {$email} to allowlist via {$actor}.",
            ok: true,
            actor: $actor,
        );

        return ['user' => $user, 'status' => 'created'];
    }
}
