<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\UserProvisioner;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SetPassword extends Command
{
    protected $signature = 'clockwork:set-password
                            {email : Email address of the user}
                            {--password= : The new password (if not provided, you will be prompted securely)}';

    protected $description = 'Set or reset an operator password on the Clockwork allowlist.';

    public function handle(UserProvisioner $provisioner): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User '{$email}' was not found on the allowlist.");
            $this->line('Run php artisan clockwork:add-user to create an account first.');

            return self::FAILURE;
        }

        $password = $this->option('password');

        if ($password === null) {
            $password = (string) $this->secret('Enter new password (min 8 characters): ');
            $confirm = (string) $this->secret('Confirm new password: ');

            if ($password !== $confirm) {
                $this->error('Passwords do not match.');

                return self::FAILURE;
            }
        }

        try {
            $provisioner->setPassword($user, $password, actor: 'cli');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Password for {$email} updated successfully.");
        $this->line('They can now sign in at /login with their email and password.');

        return self::SUCCESS;
    }
}
