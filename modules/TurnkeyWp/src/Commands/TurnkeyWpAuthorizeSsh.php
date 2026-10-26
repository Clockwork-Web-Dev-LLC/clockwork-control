<?php

namespace Modules\TurnkeyWp\Commands;

use App\Models\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\TurnkeyWp\TurnkeyWpClient;
use Modules\TurnkeyWp\TurnkeyWpReadOnlyException;

/**
 * Gives Clockwork Control SSH access to TurnkeyWP servers by adding our
 * public key to each server's `turnkey` sudo user through the TurnkeyWP API
 * (POST /servers/{id}/ssh-keys). TurnkeyWP deploys the key in a queued task.
 *
 * Only servers whose ssh_user is `turnkey` are touched: a server adopted from
 * SpinupWP keeps the deploy user it already had.
 */
#[Signature('clockwork:turnkeywp-authorize-ssh
    {--server=* : Clockwork server id(s) to authorize; default is every TurnkeyWP server with ssh_user turnkey}
    {--public-key-file= : Public key to install; default is CLOCKWORK_SSH_KEY_PATH with .pub appended}
    {--name=Clockwork Control : Key name shown in TurnkeyWP}
    {--force : Skip the confirmation prompt}')]
#[Description('Add Clockwork Control\'s SSH public key to TurnkeyWP servers (turnkey user) through the TurnkeyWP API.')]
class TurnkeyWpAuthorizeSsh extends Command
{
    public function handle(TurnkeyWpClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('TURNKEYWP_TOKEN is not configured in .env or Settings.');

            return self::FAILURE;
        }

        if ($client->isViewOnly()) {
            $this->error('TurnkeyWP is in View-Only mode; adding SSH keys is a write. Turn View-Only off to run this.');

            return self::FAILURE;
        }

        $publicKey = $this->readPublicKey();
        if ($publicKey === null) {
            return self::FAILURE;
        }

        $query = Server::query()->whereNotNull('turnkeywp_server_id');
        $ids = array_filter((array) $this->option('server'));
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $query->where('ssh_user', 'turnkey');
        }
        $servers = $query->orderBy('name')->get();

        if ($servers->isEmpty()) {
            $this->warn('No matching TurnkeyWP servers. Run clockwork:import-turnkeywp first.');

            return self::SUCCESS;
        }

        $this->table(['Clockwork ID', 'TurnkeyWP ID', 'Name', 'Host', 'SSH user'], $servers->map(fn (Server $s) => [
            $s->id, $s->turnkeywp_server_id, $s->name, $s->hostname, $s->ssh_user,
        ])->all());

        if (! $this->option('force') && ! $this->confirm('Add this public key to the turnkey user on these '.$servers->count().' server(s)?')) {
            return self::FAILURE;
        }

        $keyBody = $this->keyBody($publicKey);
        $failed = 0;

        foreach ($servers as $server) {
            try {
                $existing = collect($client->serverSshKeys($server->turnkeywp_server_id))
                    ->first(fn (array $k) => isset($k['server_id']) && (string) $k['server_id'] === (string) $server->turnkeywp_server_id
                        && $this->keyBody((string) ($k['public_key'] ?? '')) === $keyBody);

                if ($existing) {
                    $this->line("  {$server->name}: key already registered, skipping.");

                    continue;
                }

                $response = $client->addServerSshKey($server->turnkeywp_server_id, (string) $this->option('name'), $publicKey);
                $this->info("  {$server->name}: queued (TurnkeyWP event ".($response['event_id'] ?? '?').').');
            } catch (TurnkeyWpReadOnlyException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  {$server->name}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->line('TurnkeyWP deploys keys in the background. Once its events finish, check access with clockwork:poll-servers or an SSH test.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function readPublicKey(): ?string
    {
        $path = (string) ($this->option('public-key-file') ?: '');
        if ($path === '') {
            $privatePath = (string) config('clockwork.ssh.default_key_path');
            if ($privatePath === '') {
                $this->error('Pass --public-key-file, or set CLOCKWORK_SSH_KEY_PATH so <path>.pub can be used.');

                return null;
            }
            $path = $privatePath.'.pub';
        }

        if (! is_readable($path)) {
            $this->error("Public key file not readable: {$path}");

            return null;
        }

        $key = trim((string) file_get_contents($path));
        if (! preg_match('/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-[a-z0-9]+) [A-Za-z0-9+\/]+={0,3}( [^\r\n]*)?$/', $key)) {
            $this->error("{$path} does not look like a single-line OpenSSH public key.");

            return null;
        }

        return $key;
    }

    /** Type and base64 body, ignoring the comment. */
    private function keyBody(string $key): string
    {
        $parts = preg_split('/\s+/', trim($key)) ?: [];

        return implode(' ', array_slice($parts, 0, 2));
    }
}
