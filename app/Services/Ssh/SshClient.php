<?php

namespace App\Services\Ssh;

use App\Models\Server;
use Illuminate\Support\Carbon;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

class SshClient
{
    /**
     * All nullable and ??=-defaulted from config(), same pattern as every
     * other API client — a bare `new SshClient()` behaves exactly as before
     * (still .env-only); a container-resolved instance can be given
     * DB-backed values via IntegrationServiceProvider.
     */
    public function __construct(
        protected ?int $connectTimeout = null,
        protected ?int $execTimeout = null,
        protected ?float $preflightTimeout = null,
        protected ?string $defaultKeyPath = null,
        protected ?string $defaultKeyPassphrase = null,
    ) {
        $this->connectTimeout ??= (int) config('clockwork.ssh.connect_timeout', 10);
        $this->execTimeout ??= (int) config('clockwork.ssh.exec_timeout', 30);
        $this->preflightTimeout ??= (float) config('clockwork.ssh.preflight_timeout', 2);
        $this->defaultKeyPath ??= (string) config('clockwork.ssh.default_key_path');
        $this->defaultKeyPassphrase ??= (string) config('clockwork.ssh.default_key_passphrase');
    }

    /**
     * Open an authenticated SSH session.
     *
     * Auth precedence:
     *   1. Per-server stored private key (if any).
     *   2. Local default key file (config: clockwork.ssh.default_key_path) — typical SpinupWP setup.
     *   3. Per-server stored password (rare — most SpinupWP boxes have PasswordAuthentication off).
     *
     * Throws on connect or auth failure with a message that lists which methods were tried.
     */
    public function connect(Server $server): SSH2
    {
        $methodsTried = [];
        $errors = [];

        if ($server->hostname === '' || $server->ssh_user === '') {
            throw new RuntimeException("Server #{$server->id} is missing hostname or ssh_user.");
        }

        $connectTimeout = $this->connectTimeout;
        $execTimeout = $this->execTimeout;
        $port = $server->ssh_port ?: 22;

        // TCP pre-flight: when a server has been deleted at the cloud
        // provider (or firewalled into oblivion), phpseclib's connect can
        // still hang far past its $connectTimeout because the OS keeps
        // retrying the SYN against a black-holed route. PHP's
        // max_execution_time then kills the whole request before the
        // try/catch upstream can recover. A short fsockopen probe fails
        // fast in that case (~2s) and lets the upstream catch run.
        $preflightTimeout = $this->preflightTimeout;
        $preflight = @fsockopen($server->hostname, $port, $errno, $errstr, $preflightTimeout);
        if ($preflight === false) {
            throw new RuntimeException(
                "Cannot reach {$server->ssh_user}@{$server->hostname}:{$port} — TCP connect failed in {$preflightTimeout}s ({$errstr}). Server may be deleted, firewalled, or down."
            );
        }
        fclose($preflight);

        $ssh = new SSH2($server->hostname, $port, $connectTimeout);
        $ssh->setTimeout($execTimeout);

        // Try per-server key.
        if ($server->ssh_private_key !== null && $server->ssh_private_key !== '') {
            $methodsTried[] = 'per-server key';
            try {
                $key = PublicKeyLoader::load($server->ssh_private_key);
                if ($ssh->login($server->ssh_user, $key)) {
                    $this->markOk($server);

                    return $ssh;
                }
                $errors[] = 'per-server key rejected';
            } catch (\Throwable $e) {
                $errors[] = 'per-server key load failed: '.$e->getMessage();
            }
        }

        // Try default local key.
        $defaultKey = $this->loadDefaultKey($errors);
        if ($defaultKey !== null) {
            $methodsTried[] = 'default key ('.$this->defaultKeyPath.')';
            if ($ssh->login($server->ssh_user, $defaultKey)) {
                $this->markOk($server);

                return $ssh;
            }
            $errors[] = 'default key rejected by server';
        }

        // Try password.
        if ($server->ssh_password !== null && $server->ssh_password !== '') {
            $methodsTried[] = 'password';
            if ($ssh->login($server->ssh_user, $server->ssh_password)) {
                $this->markOk($server);

                return $ssh;
            }
            $errors[] = 'password rejected (server may have PasswordAuthentication disabled)';
        }

        $tried = $methodsTried === [] ? 'no auth methods configured' : implode(' → ', $methodsTried);
        $detail = $errors === [] ? '' : ' Details: '.implode(' | ', $errors);

        throw new RuntimeException(
            "SSH login failed for {$server->ssh_user}@{$server->hostname}:{$server->ssh_port}. "
            ."Tried: {$tried}.{$detail}"
        );
    }

    /**
     * Run a single command and return stdout. Optional $timeoutSeconds extends
     * the per-read timeout for slow commands (e.g. wp core verify-checksums
     * has to SHA256 every WordPress core file and routinely exceeds the
     * 30-second default on small VPSes).
     */
    public function exec(Server $server, string $command, ?int $timeoutSeconds = null): string
    {
        $ssh = $this->connect($server);

        if ($timeoutSeconds !== null && $timeoutSeconds > 0) {
            $ssh->setTimeout($timeoutSeconds);
        }

        $output = $ssh->exec($command);

        $ssh->disconnect();

        return is_string($output) ? $output : '';
    }

    /**
     * Lightweight connection test. Returns the username the server reports back
     * along with which auth method actually succeeded.
     *
     * @return array{ok: bool, message: string, whoami?: string, hostname?: string, auth?: string}
     */
    public function test(Server $server): array
    {
        $defaultKeyPath = $this->defaultKeyPath;
        $hasAnyAuth = $server->ssh_password
            || $server->ssh_private_key
            || ($defaultKeyPath !== '' && is_readable($defaultKeyPath));

        if (! $hasAnyAuth) {
            return [
                'ok' => false,
                'message' => 'No SSH credentials configured (no per-server key/password and no default key path).',
            ];
        }

        try {
            $ssh = $this->connect($server);
            $whoami = trim((string) $ssh->exec('whoami'));
            $hostname = trim((string) $ssh->exec('hostname'));
            $ssh->disconnect();

            $expected = $server->ssh_user;
            if ($whoami !== $expected) {
                return [
                    'ok' => false,
                    'message' => "Connected, but whoami returned '{$whoami}' (expected '{$expected}').",
                    'whoami' => $whoami,
                    'hostname' => $hostname,
                ];
            }

            return [
                'ok' => true,
                'message' => "Connected as {$whoami} on {$hostname}.",
                'whoami' => $whoami,
                'hostname' => $hostname,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function markOk(Server $server): void
    {
        // Cheap timestamp-only update — no events, no log spam.
        Server::query()
            ->whereKey($server->id)
            ->update(['last_ssh_ok_at' => Carbon::now()]);
    }

    protected function loadDefaultKey(array &$errors): mixed
    {
        $path = $this->defaultKeyPath;

        if ($path === '') {
            return null;
        }

        if (! is_readable($path)) {
            $errors[] = "default key path not readable: {$path}";

            return null;
        }

        try {
            $contents = file_get_contents($path);
            if ($contents === false) {
                $errors[] = "could not read default key: {$path}";

                return null;
            }

            $passphrase = $this->defaultKeyPassphrase;

            return $passphrase !== ''
                ? PublicKeyLoader::load($contents, $passphrase)
                : PublicKeyLoader::load($contents);
        } catch (\Throwable $e) {
            $errors[] = "default key load failed: {$e->getMessage()}";

            return null;
        }
    }
}
