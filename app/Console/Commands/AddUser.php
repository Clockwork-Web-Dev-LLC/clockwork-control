<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\UserProvisioner;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Bootstrap + recovery path for the auth allowlist.
 *
 * Idempotent: if the email already exists, restore (clear `revoked_at`) and
 * update the name; do NOT error. This is the always-works escape hatch when
 * you've revoked yourself by mistake from the UI.
 */
class AddUser extends Command
{
    protected $signature = 'clockwork:add-user
                            {email : Email address of the user}
                            {--name= : Display name}
                            {--password= : Optional local password (min 8 characters)}
                            {--role=admin : Role: admin or operator}';

    protected $description = 'Add or restore a user on the Clockwork allowlist.';

    public function handle(UserProvisioner $provisioner): int
    {
        $email = (string) $this->argument('email');
        $name = $this->option('name');
        $password = $this->option('password');
        $role = (string) $this->option('role');

        if ($password !== null && strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        if (! in_array($role, [User::ROLE_ADMIN, User::ROLE_OPERATOR], true)) {
            $this->error('Role must be admin or operator.');

            return self::FAILURE;
        }

        try {
            $result = $provisioner->addOrRestore($email, $name, actor: 'cli', password: $password, role: $role);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $email = $result['user']->email;

        match ($result['status']) {
            'restored' => $this->info("Restored {$email} (was revoked)."),
            'updated' => $this->info("{$email} already on the allowlist; name updated."),
            'created' => (function () use ($email, $password) {
                $this->info("Added {$email} to the allowlist.");
                if ($password) {
                    $this->line('They can now sign in at /login with their email and password.');
                } else {
                    $this->line('They can now sign in at /login with their configured Single Sign-On provider.');
                }
            })(),
        };

        return self::SUCCESS;
    }
}
