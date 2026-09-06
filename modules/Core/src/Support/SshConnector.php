<?php

namespace Modules\Core\Support;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

/**
 * Minimal, generic SSH connect+exec primitive — the same phpseclib
 * mechanics App\Services\Ssh\SshClient uses, but parameterized directly
 * (host/port/username/credentials) instead of tied to the App\Models\Server
 * model. SshClient can't be reused as-is for a hosting provider with real
 * per-site SSH but no Server row (WP Engine, Kinsta): it hard-requires a
 * Server, reads its ssh_password/ssh_private_key columns, and writes
 * last_ssh_ok_at back onto it — none of which applies when the "server"
 * is really just one WordPress install's own scoped SSH gateway.
 *
 * Each hosting-provider module resolves its own per-site host/port/
 * username/credentials (whatever shape that provider's API and SSH
 * gateway actually require) and calls this connector with the result —
 * this class has no opinion on where those values come from.
 */
class SshConnector
{
    /**
     * Open an authenticated SSH session. Tries a private key first (if
     * given), then a password — same precedence order as SshClient, minus
     * the "local default key file" fallback, which is a SpinupWP-fleet
     * concept (one shared operator key for every server) that doesn't
     * apply to a per-site managed-host SSH gateway.
     *
     * @throws RuntimeException on connect or auth failure
     */
    public function connect(
        string $host,
        int $port,
        string $username,
        ?string $privateKey = null,
        ?string $privateKeyPassphrase = null,
        ?string $password = null,
        int $connectTimeout = 10,
        int $execTimeout = 30,
        float $preflightTimeout = 2.0,
    ): SSH2 {
        if ($host === '' || $username === '') {
            throw new RuntimeException('SshConnector::connect() requires a non-empty host and username.');
        }

        // Same TCP pre-flight SshClient uses — phpseclib's own connect can
        // hang well past $connectTimeout against a black-holed route (host
        // deleted/firewalled), and a short fsockopen probe fails fast
        // instead.
        $preflight = @fsockopen($host, $port, $errno, $errstr, $preflightTimeout);
        if ($preflight === false) {
            throw new RuntimeException(
                "Cannot reach {$username}@{$host}:{$port} — TCP connect failed in {$preflightTimeout}s ({$errstr})."
            );
        }
        fclose($preflight);

        $ssh = new SSH2($host, $port, $connectTimeout);
        $ssh->setTimeout($execTimeout);

        $errors = [];

        if ($privateKey !== null && $privateKey !== '') {
            try {
                $key = $privateKeyPassphrase !== null && $privateKeyPassphrase !== ''
                    ? PublicKeyLoader::load($privateKey, $privateKeyPassphrase)
                    : PublicKeyLoader::load($privateKey);
                if ($ssh->login($username, $key)) {
                    return $ssh;
                }
                $errors[] = 'private key rejected by server';
            } catch (\Throwable $e) {
                $errors[] = 'private key load failed: '.$e->getMessage();
            }
        }

        if ($password !== null && $password !== '') {
            if ($ssh->login($username, $password)) {
                return $ssh;
            }
            $errors[] = 'password rejected by server';
        }

        if ($errors === []) {
            $errors[] = 'no credentials supplied (neither private key nor password)';
        }

        throw new RuntimeException(
            "SSH login failed for {$username}@{$host}:{$port}. Details: ".implode(' | ', $errors)
        );
    }

    /**
     * Run a single command and return stdout, mirroring SshClient::exec()'s
     * existing behavior exactly — output is returned as-is regardless of
     * exit code; callers that need the exit code append their own shell
     * sentinel (e.g. `; echo "SENTINEL:$?"`) and parse it out of the
     * returned string, same convention WpCoreChecksumVerifier already uses.
     */
    public function exec(SSH2 $ssh, string $command, ?int $timeoutSeconds = null): string
    {
        if ($timeoutSeconds !== null && $timeoutSeconds > 0) {
            $ssh->setTimeout($timeoutSeconds);
        }

        $output = $ssh->exec($command);

        return is_string($output) ? $output : '';
    }
}
