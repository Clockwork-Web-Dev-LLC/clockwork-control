<?php

namespace Modules\Kinsta;

use App\Models\Site;
use Modules\Core\Contracts\SiteCommandRunner;
use Modules\Core\Support\SshConnector;
use RuntimeException;

/**
 * SiteCommandRunner over Kinsta's real per-environment SSH access. Mirrors
 * WPEngineSshCommandRunner's structure exactly, adapted for two real
 * differences between the providers:
 *
 *   1. WP Engine's SSH host/port/username are a fixed, guessable pattern
 *      ({install}@{install}.ssh.wpengine.net:22). Kinsta's are not — they're
 *      resolved per environment via KinstaClient::sshConnectionInfo(), which
 *      is itself a best-effort guess at Kinsta's API shape (see that
 *      method's docblock). Any failure there surfaces as a clean
 *      RuntimeException from this class, same as a missing install name.
 *   2. WP Engine authenticates with a single account-wide SSH keypair.
 *      Kinsta's confirmed SSH-management endpoints (ssh/set-status,
 *      ssh/generate-password, ssh/set-allowed-ips) point at password auth,
 *      not key auth, so this runner uses SshConnector's
 *      password path instead of its private-key path. Password source is
 *      configurable (see resolvePassword()): a fixed CLOCKWORK_KINSTA_SSH_
 *      PASSWORD from config is used if set (simplest — matches an operator
 *      having enabled SSH and set a stable password once via the MyKinsta
 *      dashboard); otherwise a fresh one-time password is requested from
 *      Kinsta's API on every connection.
 *
 * Mirrors SshCommandRunner's existing behavior exactly (returns output
 * as-is regardless of exit code) — see SiteCommandRunner's docblock for why
 * that convention matters to callers like WpCoreChecksumVerifier.
 */
class KinstaSshCommandRunner implements SiteCommandRunner
{
    public function __construct(
        private readonly SshConnector $connector,
        private readonly KinstaClient $client,
        private readonly ?string $configuredPassword,
    ) {}

    public function run(Site $site, string $command, ?int $timeoutSeconds = null): string
    {
        $environmentId = (string) $site->kinsta_environment_id;
        if ($environmentId === '') {
            throw new RuntimeException("Site #{$site->id} ({$site->domain}) has no kinsta_environment_id — cannot run commands over SSH.");
        }

        $info = $this->client->sshConnectionInfo($environmentId);
        $password = $this->resolvePassword($environmentId);

        $ssh = $this->connector->connect(
            host: $info['host'],
            port: $info['port'],
            username: $info['username'],
            password: $password,
            execTimeout: $timeoutSeconds ?? 30,
        );

        $output = $this->connector->exec($ssh, $command, $timeoutSeconds);
        $ssh->disconnect();

        return $output;
    }

    /**
     * See class docblock. A pre-configured password is used verbatim (an
     * operator is assumed to have already called setSshStatus(true) via the
     * MyKinsta dashboard or a setup script); otherwise this generates a
     * fresh one-time password for every single command invocation, which is
     * correct but chatty — a caller running many commands back-to-back
     * against the same environment should prefer configuring
     * CLOCKWORK_KINSTA_SSH_PASSWORD instead.
     */
    private function resolvePassword(string $environmentId): string
    {
        if ($this->configuredPassword !== null && $this->configuredPassword !== '') {
            return $this->configuredPassword;
        }

        $this->client->setSshStatus($environmentId, true);

        return $this->client->generateSshPassword($environmentId);
    }
}
