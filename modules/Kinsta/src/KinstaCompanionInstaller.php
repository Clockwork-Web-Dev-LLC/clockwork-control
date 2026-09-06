<?php

namespace Modules\Kinsta;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Companion\CompanionTarballBuilder;
use Modules\Core\Contracts\CompanionInstaller as CompanionInstallerContract;
use Modules\Core\Support\SshConnector;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Deploys the Companion mu-plugin over Kinsta's real per-environment SSH
 * access. Mirrors WPEngineCompanionInstaller's structure and remote-side
 * shell script exactly (tarball extract, then a wp-cli db-query secret
 * upsert + cache flush, then a /health probe) — the only real differences
 * are how the SSH connection is obtained and authenticated; see
 * KinstaSshCommandRunner's docblock for both.
 *
 * Docroot assumption: Kinsta's SSH login is documented (MyKinsta dashboard's
 * own SSH/SFTP connection panel) to land in the environment's container
 * home directory, with the WordPress docroot at `./public` relative to it —
 * unlike WP Engine, there's no install-name-shaped subdirectory to guess at.
 * Still treat this as needing live-account confirmation before first
 * production use, same as every other structural assumption in this module
 * (see KinstaServiceProvider's manifest description, and especially
 * KinstaClient::sshConnectionInfo()'s docblock, which is this module's
 * single biggest unverified assumption).
 */
class KinstaCompanionInstaller implements CompanionInstallerContract
{
    private const RESULT_INSTALLED = 'installed';

    private const RESULT_UPDATED = 'updated';

    private const RESULT_ALREADY_CURRENT = 'already-current';

    private const RESULT_FAILED = 'failed';

    private const RESULT_SKIPPED_NOT_WP = 'skipped-not-wp';

    /** Relative to the SSH landing directory — see class docblock. */
    private const WP_PATH = 'public';

    public function __construct(
        private readonly SshConnector $connector,
        private readonly CompanionTarballBuilder $tarballBuilder,
        private readonly KinstaClient $client,
        private readonly ?string $configuredPassword,
    ) {}

    /**
     * @return array{result: string, message: string, output?: string, version?: string}
     */
    public function installOrUpdate(Site $site, bool $rotateSecret = false): array
    {
        if (! $site->is_wordpress) {
            return ['result' => self::RESULT_SKIPPED_NOT_WP, 'message' => 'Not a WordPress site.'];
        }

        $environmentId = (string) $site->kinsta_environment_id;
        if ($environmentId === '') {
            return ['result' => self::RESULT_FAILED, 'message' => 'Site has no kinsta_environment_id.'];
        }

        try {
            $tarball = $this->tarballBuilder->acquire();
        } catch (Throwable $e) {
            return ['result' => self::RESULT_FAILED, 'message' => 'Could not acquire plugin source: '.$e->getMessage()];
        }

        try {
            $info = $this->client->sshConnectionInfo($environmentId);
            $password = $this->resolvePassword($environmentId);

            $ssh = $this->connector->connect(
                host: $info['host'],
                port: $info['port'],
                username: $info['username'],
                password: $password,
                execTimeout: 60,
            );
        } catch (Throwable $e) {
            return ['result' => self::RESULT_FAILED, 'message' => 'SSH connection failed: '.$e->getMessage()];
        }

        $secret = $rotateSecret || ! is_string($site->companion_secret) || $site->companion_secret === ''
            ? bin2hex(random_bytes(32))
            : $site->companion_secret;

        // See class docblock — needs live-account confirmation.
        $wpPath = self::WP_PATH;

        $extract = $this->extractOnRemote($ssh, $wpPath, $tarball);
        if ($extract['exit'] !== 0) {
            $ssh->disconnect();

            return [
                'result' => self::RESULT_FAILED,
                'message' => 'Remote extract failed (exit '.$extract['exit'].').',
                'output' => $extract['output'],
            ];
        }

        $pushSecret = $this->pushSecret($ssh, $wpPath, $secret);
        $ssh->disconnect();
        if ($pushSecret['exit'] !== 0) {
            return [
                'result' => self::RESULT_FAILED,
                'message' => 'wp-cli secret push failed (exit '.$pushSecret['exit'].').',
                'output' => $pushSecret['output'],
            ];
        }

        $previouslyInstalled = (bool) $site->companion_installed;
        $site->companion_secret = $secret;
        $site->save();

        try {
            $health = (new ClockworkCompanionClient($site->fresh()))->health();
        } catch (Throwable $e) {
            return [
                'result' => self::RESULT_FAILED,
                'message' => 'Plugin extracted + secret stored, but /health probe failed: '.$e->getMessage(),
            ];
        }

        $version = (string) ($health['version'] ?? '');
        $capabilities = (array) ($health['capabilities'] ?? []);
        $previousVersion = $site->companion_version;

        $site->forceFill([
            'companion_installed' => true,
            'companion_version' => $version,
            'companion_capabilities' => $capabilities,
            'companion_last_seen_at' => now(),
        ])->save();

        if (! $previouslyInstalled) {
            return ['result' => self::RESULT_INSTALLED, 'message' => "Companion {$version} installed on {$site->domain}.", 'version' => $version];
        }
        if ($previousVersion !== $version) {
            return ['result' => self::RESULT_UPDATED, 'message' => "Companion upgraded {$previousVersion} → {$version} on {$site->domain}.", 'version' => $version];
        }

        return ['result' => self::RESULT_ALREADY_CURRENT, 'message' => "Companion {$version} already current on {$site->domain}; secret refreshed.", 'version' => $version];
    }

    /**
     * See KinstaSshCommandRunner::resolvePassword()'s docblock — identical
     * policy, duplicated rather than shared because the two classes have no
     * common base and this is the only line they'd share.
     */
    private function resolvePassword(string $environmentId): string
    {
        if ($this->configuredPassword !== null && $this->configuredPassword !== '') {
            return $this->configuredPassword;
        }

        $this->client->setSshStatus($environmentId, true);

        return $this->client->generateSshPassword($environmentId);
    }

    /**
     * @return array{output: string, exit: int}
     */
    private function extractOnRemote(SSH2 $ssh, string $wpPath, string $base64Tarball): array
    {
        $remoteB64Path = '/tmp/clockwork-companion-stage-'.bin2hex(random_bytes(8)).'.b64';
        $upload = $this->uploadBase64ToRemote($ssh, $remoteB64Path, $base64Tarball);
        if ($upload['exit'] !== 0) {
            return $upload;
        }

        $escapedB64Path = escapeshellarg($remoteB64Path);

        // No sudo, no site_user drop needed — the SSH session already IS
        // the right user for this one environment.
        $script = <<<BASH
set -euo pipefail
MU="{$wpPath}/wp-content/mu-plugins"
mkdir -p "\$MU"
cd "\$MU"
rm -rf .clockwork-stage
mkdir .clockwork-stage
base64 -d < {$escapedB64Path} | tar -xzf - -C .clockwork-stage
rm -f clockwork-companion.php
rm -rf clockwork-companion
mv .clockwork-stage/clockwork-companion.php .
mv .clockwork-stage/clockwork-companion .
rm -rf .clockwork-stage
rm -f {$escapedB64Path}
echo "OK: companion staged at \$MU"
BASH;

        $sentinel = '__CLOCKWORK_KINSTA_EXIT__';
        $raw = $this->connector->exec($ssh, $script.'; echo "'.$sentinel.':$?"', 60);

        return $this->parseSentinelOutput($raw, $sentinel);
    }

    /**
     * @return array{output: string, exit: int}
     */
    private function uploadBase64ToRemote(SSH2 $ssh, string $remotePath, string $base64): array
    {
        $chunkSize = 50_000;
        $chunks = str_split($base64, $chunkSize);
        $escPath = escapeshellarg($remotePath);

        foreach ($chunks as $i => $chunk) {
            $redirect = $i === 0 ? '>' : '>>';
            $cmd = 'printf %s '.escapeshellarg($chunk).' '.$redirect.' '.$escPath;
            $out = trim($this->connector->exec($ssh, $cmd, 30));
            if ($out !== '') {
                return ['exit' => 1, 'output' => "uploadBase64ToRemote chunk {$i} of ".count($chunks)." failed: {$out}"];
            }
        }

        return ['exit' => 0, 'output' => 'uploaded '.count($chunks).' chunk(s)'];
    }

    /**
     * Same upsert-via-db-query + cache-flush approach as the SpinupWP and
     * WP Engine installers (see App\Services\Companion\CompanionInstaller::
     * pushSecret's docblock for the full reasoning) — the object-cache trap
     * it guards against isn't provider-specific, any WordPress install with
     * a persistent object cache can hit it.
     *
     * @return array{output: string, exit: int}
     */
    private function pushSecret(SSH2 $ssh, string $wpPath, string $secret): array
    {
        $sentinel = '__CLOCKWORK_KINSTA_EXIT__';

        $readScript = sprintf('wp --path=%s option get clockwork_companion_secret 2>/dev/null', escapeshellarg($wpPath));
        $read = $this->parseSentinelOutput(
            $this->connector->exec($ssh, $readScript.'; echo "'.$sentinel.':$?"', 30),
            $sentinel,
        );
        if ($read['exit'] === 0 && trim($read['output']) === $secret) {
            return ['output' => 'OK: secret already matches; no write needed', 'exit' => 0];
        }

        $prefixScript = sprintf('wp --path=%s config get table_prefix 2>/dev/null', escapeshellarg($wpPath));
        $prefixRead = $this->parseSentinelOutput(
            $this->connector->exec($ssh, $prefixScript.'; echo "'.$sentinel.':$?"', 30),
            $sentinel,
        );
        if ($prefixRead['exit'] !== 0 || trim($prefixRead['output']) === '') {
            return ['output' => "Could not read table_prefix from wp-config (exit {$prefixRead['exit']}): ".$prefixRead['output'], 'exit' => 1];
        }
        $tablePrefix = trim($prefixRead['output']);
        if (! preg_match('/^[a-z0-9_]+$/i', $tablePrefix)) {
            return ['output' => "table_prefix '{$tablePrefix}' contains invalid characters; refusing to interpolate", 'exit' => 1];
        }
        $optionsTable = $tablePrefix.'options';

        $sqlEsc = str_replace("'", "''", $secret);
        $sql = "INSERT INTO `{$optionsTable}` (option_name, option_value, autoload) "
            ."VALUES ('clockwork_companion_secret', '{$sqlEsc}', 'no') "
            ."ON DUPLICATE KEY UPDATE option_value = '{$sqlEsc}', autoload = 'no'";

        $script = sprintf(
            'wp --path=%s db query %s && wp --path=%s cache flush > /dev/null 2>&1 && echo OK: secret upserted',
            escapeshellarg($wpPath),
            escapeshellarg($sql),
            escapeshellarg($wpPath),
        );

        return $this->parseSentinelOutput(
            $this->connector->exec($ssh, $script.'; echo "'.$sentinel.':$?"', 30),
            $sentinel,
        );
    }

    /**
     * @return array{output: string, exit: int}
     */
    private function parseSentinelOutput(string $raw, string $sentinel): array
    {
        $exit = -1;
        if (preg_match('/'.$sentinel.':(\d+)/', $raw, $m)) {
            $exit = (int) $m[1];
            $raw = (string) preg_replace('/\s*'.$sentinel.':\d+\s*$/', '', $raw);
        }

        return ['output' => trim($raw), 'exit' => $exit];
    }
}
