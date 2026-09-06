<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clockwork:clean-failed-provision {server}')]
#[Description('Print a recovery script that removes the broken sudoers and fail2ban configs from a server hit by the v0 provisioner bug. Run via SpinupWP custom-script (root) since the server\'s sudo is broken.')]
class CleanFailedProvision extends Command
{
    public function handle(): int
    {
        $idOrHost = (string) $this->argument('server');
        $server = is_numeric($idOrHost)
            ? Server::find((int) $idOrHost)
            : Server::where('hostname', $idOrHost)->orWhere('name', $idOrHost)->first();

        if (! $server) {
            $this->error("Server '{$idOrHost}' not found.");

            return self::FAILURE;
        }

        $this->info("Recovery script for {$server->name} ({$server->hostname}):");
        $this->line('');
        $this->line('  Run this via SpinupWP\'s "Run a Custom Script" feature (it executes as root,');
        $this->line('  bypassing the broken sudo on the server). Or paste it into the DigitalOcean');
        $this->line('  recovery console for that droplet.');
        $this->line('');
        $this->line('--- copy below ---');
        $this->line('');
        $this->line('rm -f /etc/sudoers.d/clockwork');
        $this->line('rm -f /etc/fail2ban/filter.d/clockwork.conf');
        $this->line('rm -f /etc/fail2ban/jail.d/clockwork.local');
        $this->line('systemctl restart fail2ban 2>/dev/null || true');
        $this->line('echo "Clockwork v0 provisioning artifacts removed."');
        $this->line('sudo -l -U '.$server->ssh_user.' >/dev/null && echo "sudo for '.$server->ssh_user.' is working again." || echo "ERROR: sudo still not working — investigate manually."');
        $this->line('');
        $this->line('--- copy above ---');
        $this->line('');
        $this->info('After running, in this app: clear the failed provision state and re-try.');

        if ($server->clockwork_jail_provisioned_at !== null || $server->last_provision_log !== null) {
            $server->clockwork_jail_provisioned_at = null;
            $server->last_provision_log = null;
            $server->save();
            $this->line("Cleared provisioning state on '{$server->name}' in our DB.");
        }

        return self::SUCCESS;
    }
}
