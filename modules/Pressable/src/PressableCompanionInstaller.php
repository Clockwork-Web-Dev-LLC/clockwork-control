<?php

namespace Modules\Pressable;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Companion\CompanionBrandingManager;
use App\Services\Companion\CompanionInstaller;
use App\Services\Companion\CompanionTarballBuilder;
use Modules\Core\Contracts\CompanionInstaller as CompanionInstallerContract;
use Throwable;

/**
 * Deploys the Clockwork Companion mu-plugin onto a Pressable-hosted site.
 *
 * Same end state as the SSH-based CompanionInstaller (mu-plugins/ loader +
 * dir, HMAC secret in wp_options, verified via /health), different
 * transport: Pressable exposes no SSH — only an async fire-and-forget
 * command API whose results land in the site activity log (see
 * PressableCommandRunner for the mechanics and constraints).
 *
 * Transport-driven design differences vs the SSH installer:
 *
 * **Chunked, order-independent upload.** The ~130KB base64 tarball can't go
 * up in one command, and Pressable gives no ordering guarantee between
 * queued commands — so instead of append-chunks (order-sensitive), each
 * chunk writes its own zero-padded part file (part000, part001, …) and a
 * glob `cat` reassembles them. Correctness is proven by comparing the
 * reassembled file's sha256 against the locally-computed hash, retried
 * while the queue drains — corrupt or incomplete assembly can never
 * silently proceed to extraction.
 *
 * **Bootstrap-then-rotate secret.** Every command Pressable runs is written
 * verbatim to the site activity log, visible in their control panel for
 * ~30 days — so the secret-push command inevitably exposes whatever secret
 * it carries. We therefore NEVER push the site's long-lived secret through
 * this channel: a throwaway bootstrap secret goes through the logged
 * command, the /health probe verifies the install, then rotateSecret()
 * replaces it over authenticated HTTPS (nothing logged). The logged
 * bootstrap value is dead within seconds of appearing. If rotation fails
 * (older Companion without the endpoint, pinned secret), the install still
 * succeeds but the result message says the logged secret is still live so
 * the operator can follow up.
 *
 * **Fixed docroot.** Pressable is uniform: every site's WordPress root is
 * /srv/htdocs (confirmed via live command output). No per-site wp_path.
 *
 * Staging lives under mu-plugins/.cw-stage-<nonce>/ rather than /tmp:
 * observed evidence says the docroot filesystem persists across queued
 * commands (files written by one command were read by later ones); /tmp
 * has no such evidence. Staged content is only the base64 of the plugin
 * source — nothing secret — and the extract step removes the whole dir.
 */
class PressableCompanionInstaller implements CompanionInstallerContract
{
    private const DOCROOT = '/srv/htdocs';

    // Pressable hard-rejects (HTTP 400) any command over 50,000 bytes
    // SERIALIZED — that includes the `printf %s '...' > <path>` wrapper
    // around the raw chunk, not just the chunk itself (confirmed live
    // 2026-08-28: a 50,000-char chunk produced a 50,088-byte command and
    // was rejected). 45,000 leaves comfortable headroom for the wrapper
    // + escaping regardless of the stage path's exact length.
    private const CHUNK_SIZE = 45_000;

    /** Reassembly retries: parts drain through Pressable's queue at their own pace. */
    private const ASSEMBLE_ATTEMPTS = 6;

    public function __construct(
        private readonly PressableCommandRunner $runner,
        private readonly CompanionTarballBuilder $tarballBuilder,
        private readonly PressableClient $client,
    ) {}

    /**
     * $rotateSecret is accepted for interface compatibility but ignored —
     * see Modules\Core\Contracts\CompanionInstaller's docblock for why.
     *
     * @return array{result: string, message: string, output?: string, version?: string}
     */
    public function installOrUpdate(Site $site, bool $rotateSecret = false): array
    {
        $preflight = $this->preflight($site);
        if ($preflight !== null) {
            return $preflight;
        }

        try {
            $tarball = $this->tarballBuilder->acquire();
        } catch (Throwable $e) {
            return ['result' => CompanionInstaller::RESULT_FAILED, 'message' => 'Could not acquire plugin source: '.$e->getMessage()];
        }

        $psId = (string) $site->pressable_site_id;
        $nonce = bin2hex(random_bytes(6));
        $stage = self::DOCROOT."/wp-content/mu-plugins/.cw-stage-{$nonce}";

        try {
            $this->uploadTarball($psId, $stage, $tarball);
            $this->extract($psId, $stage);
            $bootstrapSecret = $this->pushBootstrapSecret($psId);
        } catch (Throwable $e) {
            // Best-effort stage cleanup; the stage dir carries no secrets.
            try {
                $this->runner->submit($psId, 'rm -rf '.escapeshellarg($stage));
            } catch (Throwable) {
                // The cleanup submit failing shouldn't mask the real error.
            }

            return ['result' => CompanionInstaller::RESULT_FAILED, 'message' => $e->getMessage()];
        }

        // Persist the bootstrap secret before the verify call — the verify
        // call ITSELF signs with $site->companion_secret.
        $previouslyInstalled = (bool) $site->companion_installed;
        $previousVersion = $site->companion_version;
        $site->companion_secret = $bootstrapSecret;
        $site->save();

        // Edge-cache-safe: a REST path hit (and cached as 404) before the
        // plugin existed would otherwise keep failing the health probe
        // forever post-install. Confirmed necessary live 2026-08-28.
        try {
            $this->client->purgeEdgeCache($psId);
        } catch (Throwable) {
            // Non-fatal — worst case the probe below hits a stale cache
            // entry and fails with a message that points at this.
        }

        try {
            $health = (new ClockworkCompanionClient($site->fresh()))->health();
        } catch (Throwable $e) {
            return [
                'result' => CompanionInstaller::RESULT_FAILED,
                'message' => 'Plugin extracted + bootstrap secret stored, but /health probe failed: '.$e->getMessage(),
            ];
        }

        $version = (string) ($health['version'] ?? '');
        $capabilities = (array) ($health['capabilities'] ?? []);

        $site->forceFill([
            'companion_installed' => true,
            'companion_version' => $version,
            'companion_capabilities' => $capabilities,
            'companion_last_seen_at' => now(),
        ])->save();

        // Retire the logged bootstrap secret over HTTPS. See class docblock.
        $rotationNote = $this->rotateAwayBootstrapSecret($site->fresh());

        // Push active white-label branding if configured (best-effort, non-blocking)
        try {
            app(CompanionBrandingManager::class)->syncSite($site->fresh());
        } catch (Throwable) {
            // Non-blocking on install
        }

        if (! $previouslyInstalled) {
            $result = CompanionInstaller::RESULT_INSTALLED;
            $message = "Companion {$version} installed on {$site->domain} (Pressable).";
        } elseif ($previousVersion !== $version) {
            $result = CompanionInstaller::RESULT_UPDATED;
            $message = "Companion upgraded {$previousVersion} → {$version} on {$site->domain} (Pressable).";
        } else {
            $result = CompanionInstaller::RESULT_ALREADY_CURRENT;
            $message = "Companion {$version} already current on {$site->domain} (Pressable); secret refreshed.";
        }

        return [
            'result' => $result,
            'message' => $message.$rotationNote,
            'version' => $version,
        ];
    }

    /**
     * @return null|array{result: string, message: string}
     */
    private function preflight(Site $site): ?array
    {
        if (! $site->is_wordpress) {
            return ['result' => CompanionInstaller::RESULT_SKIPPED_NOT_WP, 'message' => 'Not a WordPress site.'];
        }
        if (! $site->isPressable() || ! $site->pressable_site_id) {
            return ['result' => CompanionInstaller::RESULT_FAILED, 'message' => 'Site is not Pressable-tracked (no pressable_site_id) — use the SSH installer instead.'];
        }

        return null;
    }

    private function uploadTarball(string $psId, string $stage, string $base64Tarball): void
    {
        $this->runner->runOrFail($psId, 'mkdir -p '.escapeshellarg($stage).' && echo made');

        // Blind submits: each part's log entry is all command text (the 50KB
        // chunk) with any output truncated away, so per-part verification is
        // impossible — the assemble step's hash check is the real gate.
        $chunks = str_split($base64Tarball, self::CHUNK_SIZE);
        foreach ($chunks as $i => $chunk) {
            $part = sprintf('%s/part%03d', $stage, $i);
            $this->runner->submit($psId, 'printf %s '.escapeshellarg($chunk).' > '.escapeshellarg($part));
        }

        $expectedHash = hash('sha256', $base64Tarball);
        $assembled = escapeshellarg("{$stage}/plugin.b64");
        $assembleCmd = 'cat '.escapeshellarg($stage).'/part* > '.$assembled
            .' && sha256sum '.$assembled;

        $lastOutput = '';
        for ($attempt = 1; $attempt <= self::ASSEMBLE_ATTEMPTS; $attempt++) {
            $result = $this->runner->run($psId, $assembleCmd);
            $lastOutput = $result['output'];

            if ($result['ok'] && str_contains($result['output'], $expectedHash)) {
                return;
            }
            // Parts may still be draining through the queue — the runner's
            // internal polling already spaces attempts several seconds apart.
        }

        throw new \RuntimeException(
            'Tarball upload verification failed after '.self::ASSEMBLE_ATTEMPTS.' reassembly attempts. '
            ."Expected sha256 {$expectedHash}; last attempt said: {$lastOutput}"
        );
    }

    /**
     * Same atomic-swap pattern as the SSH installer: extract to a scratch
     * dir inside the stage, swap loader + dir into mu-plugins/, remove the
     * stage. A failure mid-extract leaves the previous version intact.
     */
    private function extract(string $psId, string $stage): void
    {
        $mu = self::DOCROOT.'/wp-content/mu-plugins';
        $s = escapeshellarg($stage);
        $m = escapeshellarg($mu);

        $script = "mkdir {$s}/x"
            ." && base64 -d < {$s}/plugin.b64 | tar -xzf - -C {$s}/x"
            ." && rm -f {$m}/clockwork-companion.php && rm -rf {$m}/clockwork-companion"
            ." && mv {$s}/x/clockwork-companion.php {$m}/ && mv {$s}/x/clockwork-companion {$m}/"
            ." && rm -rf {$s} && echo swapped";

        $output = $this->runner->runOrFail($psId, $script);
        if (! str_contains($output, 'swapped')) {
            throw new \RuntimeException("Extract script exited 0 but did not confirm the swap: {$output}");
        }
    }

    /**
     * Push a throwaway bootstrap secret into wp_options and return it.
     *
     * Same Redis-object-cache-safe upsert+flush approach as the SSH
     * installer's pushSecret() (Pressable sites run an object-cache.php
     * drop-in too — observed in live plugin listings — so the same stale
     * alloptions trap applies). Prefix is read live from wp-config rather
     * than trusted from our DB, for the same post-migration-drift reason.
     */
    private function pushBootstrapSecret(string $psId): string
    {
        $secret = bin2hex(random_bytes(32));

        $prefixOut = $this->runner->runOrFail(
            $psId,
            'cd '.self::DOCROOT.' && wp config get table_prefix',
        );
        // Output may carry PHP notices from ill-behaved plugins; the prefix
        // is the last whitespace-delimited token.
        $tokens = preg_split('/\s+/', trim($prefixOut)) ?: [];
        $tablePrefix = (string) end($tokens);
        if (! preg_match('/^[a-z0-9_]+$/i', $tablePrefix)) {
            throw new \RuntimeException("Could not read a sane table_prefix from wp-config (got '{$tablePrefix}').");
        }

        $sql = "INSERT INTO `{$tablePrefix}options` (option_name, option_value, autoload) "
            ."VALUES ('clockwork_companion_secret', '{$secret}', 'no') "
            ."ON DUPLICATE KEY UPDATE option_value = '{$secret}', autoload = 'no'";

        // The SQL write is the actual operation and must succeed. The cache
        // flush is only belt-and-suspenders freshness for the row we just
        // wrote — chaining it with && used to make a flush failure (seen
        // live on a site with no active object cache) fail the whole step
        // even though the secret had already landed, and swallowing its
        // output into /dev/null hid the real cause behind the prior
        // command's unrelated success message. Best-effort and separate.
        $this->runner->runOrFail($psId, 'cd '.self::DOCROOT.' && wp db query '.escapeshellarg($sql));

        try {
            $this->runner->runOrFail($psId, 'cd '.self::DOCROOT.' && wp cache flush');
        } catch (Throwable) {
            // Non-fatal — worst case the next request briefly reads a
            // stale cached value until something else flushes it.
        }

        return $secret;
    }

    /**
     * Replace the activity-log-exposed bootstrap secret with one that never
     * touched the command channel. Returns a note for the result message —
     * empty on success, a warning when the logged secret is still live.
     */
    private function rotateAwayBootstrapSecret(Site $site): string
    {
        try {
            $rotation = (new ClockworkCompanionClient($site))->rotateSecret();
        } catch (Throwable $e) {
            return ' WARNING: secret rotation threw ('.$e->getMessage().') — the bootstrap secret visible in the Pressable activity log is still live. Rotate manually: clockwork:rotate-companion-secret --site='.$site->domain;
        }

        if (! ($rotation['ok'] ?? false)) {
            $why = (string) ($rotation['error'] ?? 'unknown');

            return " WARNING: secret rotation failed ({$why}) — the bootstrap secret visible in the Pressable activity log is still live. Rotate manually: clockwork:rotate-companion-secret --site={$site->domain}";
        }

        $site->companion_secret = (string) $rotation['secret'];
        $site->save();

        return '';
    }
}
