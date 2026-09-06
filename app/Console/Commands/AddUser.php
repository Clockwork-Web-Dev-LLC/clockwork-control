<?php

namespace App\Console\Commands;

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
                            {email : Email address (must match the Google account they will sign in with)}
                            {--name= : Display name (Google will overwrite this on first login)}';

    protected $description = 'Add or restore a user on the Clockwork allowlist.';

    public function handle(UserProvisioner $provisioner): int
    {
        $email = (string) $this->argument('email');
        $name = $this->option('name');

        try {
            $result = $provisioner->addOrRestore($email, $name, actor: 'cli');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $email = $result['user']->email;

        match ($result['status']) {
            'restored' => $this->info("Restored {$email} (was revoked)."),
            'updated' => $this->info("{$email} already on the allowlist; name updated."),
            'created' => (function () use ($email) {
                $this->info("Added {$email} to the allowlist.");
                $this->line('They can now sign in at /login with the Google account whose primary email matches.');
            })(),
        };

        return self::SUCCESS;
    }
}
