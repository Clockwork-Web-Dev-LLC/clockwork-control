<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Ssh\SshClient;
use RuntimeException;

/**
 * Idempotently ensures a `define('NAME', value);` line exists in a site's
 * wp-config.php. Inserted just BEFORE the `require_once ... wp-settings.php`
 * line — anything after that include never executes during a request, so
 * appending to EOF wouldn't take effect.
 *
 * Atomic-ish: writes the new contents to a temp file, copies the original
 * to a `.clockwork-bak` rollback artifact, then `mv`s the temp into place.
 * Both staging files live in the same directory as wp-config.php so the
 * `mv` is filesystem-local and atomic per POSIX.
 *
 * Owner + mode are preserved via `chown/chmod --reference=`. wp-config.php
 * is the most sensitive file on a WP install — getting either wrong locks
 * the site out, so we copy from the original rather than guess.
 */
class WpConfigConstantInjector
{
    public const PROVENANCE_COMMENT = '// added by Clockwork — see clockwork-companion CHANGELOG 1.16.9';

    public function __construct(
        private readonly SshClient $ssh,
        private readonly WpConfigExtractor $wpConfig,
    ) {}

    /**
     * Returns true if a write happened, false if the constant was already defined.
     *
     * @param  bool|int|string  $value  emitted as a PHP literal (true/false, integer, single-quoted string)
     */
    public function ensureDefined(Site $site, string $name, bool|int|string $value): bool
    {
        if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
            throw new RuntimeException("Invalid constant name: {$name}");
        }

        $path = $this->wpConfig->wpConfigPath($site);
        $contents = $this->readWpConfig($site, $path);

        if ($this->isAlreadyDefined($contents, $name)) {
            return false;
        }

        if (! preg_match('/^[ \t]*require_once[ \t(].*wp-settings\.php/m', $contents)) {
            throw new RuntimeException(
                "wp-config.php at {$path} has no `require_once ... wp-settings.php` marker — "
                ."refusing to inject {$name} (don't know where it'd take effect)."
            );
        }

        $newContents = $this->insertBeforeWpSettings($contents, $name, $value);

        $this->writeAtomically($site, $path, $newContents);

        // Re-read and verify the constant is now present. Closes the loop on
        // any silent SSH/sudo failure that would otherwise leave us thinking
        // we wrote successfully.
        $verify = $this->readWpConfig($site, $path);
        if (! $this->isAlreadyDefined($verify, $name)) {
            throw new RuntimeException("Inject of {$name} into {$path} appeared to succeed but post-read doesn't show it.");
        }

        return true;
    }

    private function isAlreadyDefined(string $contents, string $name): bool
    {
        $pattern = '/define\s*\(\s*[\'"]'.preg_quote($name, '/').'[\'"]\s*,/';

        return (bool) preg_match($pattern, $contents);
    }

    private function insertBeforeWpSettings(string $contents, string $name, bool|int|string $value): string
    {
        $literal = $this->phpLiteral($value);
        $injection = self::PROVENANCE_COMMENT."\ndefine('{$name}', {$literal});\n\n";

        $new = preg_replace(
            '/^([ \t]*require_once[ \t(].*wp-settings\.php.*)$/m',
            $injection.'$1',
            $contents,
            1,
            $count
        );

        if ($count !== 1 || $new === null) {
            throw new RuntimeException('preg_replace failed to insert before wp-settings marker.');
        }

        return $new;
    }

    private function phpLiteral(bool|int|string $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            default => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'",
        };
    }

    /**
     * Read wp-config via plain cat first, falling back to sudo cat for the
     * 0600-mode case. Mirrors WpConfigExtractor::extract() so behavior is
     * consistent.
     */
    private function readWpConfig(Site $site, string $path): string
    {
        $escaped = escapeshellarg($path);
        $contents = $this->ssh->exec($site->server, "cat {$escaped} 2>/dev/null");

        if ($contents === '' && $site->server->ssh_password) {
            $script = 'printf "%s\n" "$CW_SUDO_PW" | sudo -S cat '.$escaped.' 2>/dev/null';
            $cmd = sprintf(
                'CW_SUDO_PW=%s bash -c %s',
                escapeshellarg((string) $site->server->ssh_password),
                escapeshellarg($script),
            );
            $contents = $this->ssh->exec($site->server, $cmd);
        }

        if ($contents === '') {
            throw new RuntimeException("wp-config.php not found or unreadable at {$path}");
        }

        return $contents;
    }

    /**
     * Stage to a temp file, preserve owner+mode from the original, copy the
     * original to .clockwork-bak (overwrites any prior backup — only the most
     * recent pre-injection state is kept), then mv temp into place.
     *
     * Each step `&&`-chained so the first failure aborts the rest before we
     * touch the live file. The `mv` is the only step that swaps the live file,
     * and it's the last one.
     */
    private function writeAtomically(Site $site, string $path, string $newContents): void
    {
        $dir = dirname($path);
        $file = basename($path);
        $bak = $file.'.clockwork-bak';
        $tmp = $file.'.clockwork-tmp.'.bin2hex(random_bytes(4));
        $encoded = base64_encode($newContents);

        $shell = sprintf(
            'set -e; cd %s'
            .' && cp -p %s %s'
            .' && printf %%s %s | base64 -d > %s'
            .' && chown --reference=%s %s'
            .' && chmod --reference=%s %s'
            .' && mv %s %s',
            escapeshellarg($dir),
            escapeshellarg($file), escapeshellarg($bak),
            escapeshellarg($encoded), escapeshellarg($tmp),
            escapeshellarg($file), escapeshellarg($tmp),
            escapeshellarg($file), escapeshellarg($tmp),
            escapeshellarg($tmp), escapeshellarg($file),
        );

        $output = $this->execAsRoot($site, $shell);

        // Most failures will have already aborted via `set -e`, but if the
        // command produced any unexpected output, surface it.
        if ($output !== '' && ! $this->looksLikeBenignSudoBanner($output)) {
            throw new RuntimeException('wp-config write returned unexpected output: '.trim($output));
        }
    }

    /**
     * Wrap a shell command so it runs as root. Uses `printf | sudo -S` with
     * the password in an env var so the secret never appears in the process
     * list. Falls back to `sudo -n` (passwordless) if no ssh_password is set.
     */
    private function execAsRoot(Site $site, string $shellCmd): string
    {
        $pw = $site->server->ssh_password;
        if ($pw) {
            $script = 'printf "%s\n" "$CW_SUDO_PW" | sudo -S bash -c '.escapeshellarg($shellCmd);
            $wrapped = sprintf(
                'CW_SUDO_PW=%s bash -c %s',
                escapeshellarg((string) $pw),
                escapeshellarg($script),
            );

            return $this->ssh->exec($site->server, $wrapped);
        }

        return $this->ssh->exec($site->server, 'sudo -n bash -c '.escapeshellarg($shellCmd));
    }

    /**
     * Some hosts emit a sudo lecture / "Defaults env_reset" notice on stderr
     * even on success. We ran the command via 2>&1-implicit `bash -c`, so
     * those banners can leak into our output. Strip the known-benign ones
     * before deciding whether output indicates a real failure.
     */
    private function looksLikeBenignSudoBanner(string $out): bool
    {
        $trimmed = trim($out);
        if ($trimmed === '') {
            return true;
        }

        // Common harmless prefixes: PAM banner, sudo password prompt echo.
        return (bool) preg_match('/^(\[sudo\] password|We trust|Sorry, try)/m', $trimmed);
    }
}
