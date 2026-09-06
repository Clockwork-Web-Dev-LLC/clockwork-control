<?php

namespace Modules\WPEngine;

use App\Models\Site;
use Modules\Core\Contracts\SiteCommandRunner;
use Modules\Core\Support\SshConnector;
use RuntimeException;

/**
 * SiteCommandRunner over WP Engine's real per-install SSH gateway
 * (`{install}@{install}.ssh.wpengine.net`, key-based auth only — no
 * password auth, no server concept at all). Auth is a single account-wide
 * SSH keypair registered once via the API (POST /ssh_keys), not a
 * per-install credential — every install on the account is reachable with
 * the same key, scoped by which install name you connect as.
 *
 * Mirrors SshCommandRunner's existing behavior exactly (returns output as
 * -is regardless of exit code) — see SiteCommandRunner's docblock for why
 * that convention matters to callers like WpCoreChecksumVerifier.
 */
class WPEngineSshCommandRunner implements SiteCommandRunner
{
    public function __construct(
        private readonly SshConnector $connector,
        private readonly ?string $privateKey,
        private readonly ?string $privateKeyPassphrase,
    ) {}

    public function run(Site $site, string $command, ?int $timeoutSeconds = null): string
    {
        $install = (string) $site->wpengine_install_name;
        if ($install === '') {
            throw new RuntimeException("Site #{$site->id} ({$site->domain}) has no wpengine_install_name — cannot run commands over SSH.");
        }

        if ($this->privateKey === null || $this->privateKey === '') {
            throw new RuntimeException('No WP Engine SSH private key configured (CLOCKWORK_WPENGINE_SSH_PRIVATE_KEY).');
        }

        $ssh = $this->connector->connect(
            host: "{$install}.ssh.wpengine.net",
            port: 22,
            username: $install,
            privateKey: $this->privateKey,
            privateKeyPassphrase: $this->privateKeyPassphrase,
            execTimeout: $timeoutSeconds ?? 30,
        );

        $output = $this->connector->exec($ssh, $command, $timeoutSeconds);
        $ssh->disconnect();

        return $output;
    }
}
