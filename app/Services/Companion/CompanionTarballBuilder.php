<?php

namespace App\Services\Companion;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds/acquires the Companion mu-plugin tarball. Extracted out of
 * CompanionInstaller (SSH transport) so PressableCompanionInstaller (async
 * command-execution transport) can share the exact same source-of-truth
 * logic without duplicating it — the tarball itself is transport-agnostic;
 * only how it gets onto the remote host differs.
 *
 * Two source modes:
 *   - dist_url:   fetch a published .zip, verify sha256, push base64
 *   - local_path: tar the dev repo at ~/Projects/clockwork-companion (default)
 */
class CompanionTarballBuilder
{
    /**
     * Returns base64-encoded tar.gz of the plugin source, shaped so that
     * `tar -xzf - -C wp-content/mu-plugins/` lays down exactly:
     *   clockwork-companion.php
     *   clockwork-companion/{src,composer.json,README.md}
     */
    public function acquire(): string
    {
        $distUrl = (string) config('clockwork.companion.dist_url');

        if ($distUrl !== '') {
            return $this->acquireFromDistUrl($distUrl);
        }

        return $this->acquireFromLocalPath((string) config('clockwork.companion.local_path'));
    }

    /**
     * Fetch a published tarball over HTTPS, verify its sha256 against the
     * configured expected hash, and return base64-encoded bytes ready for
     * the push path.
     *
     * **Why this matters.** In local_path mode (default for dev), every
     * install/upgrade tars whatever is currently sitting at
     * `~/Projects/clockwork-companion/` and pushes it to all sites. If the
     * agency laptop is compromised, an attacker who modifies that directory
     * can ship malicious code to every site on the next install. dist_url
     * mode shifts the trust boundary to a hosted artifact: only signed,
     * checksummed published tarballs ever reach a site.
     *
     * **Format.** The published artifact is the SAME tar.gz produced by
     * `clockwork:build-companion-tarball` — i.e. when extracted at
     * `wp-content/mu-plugins/`, it lays down `clockwork-companion.php` +
     * `clockwork-companion/`. No zip → tar repacking step.
     *
     * **Hash source.** `CLOCKWORK_COMPANION_DIST_SHA256` is REQUIRED when
     * `CLOCKWORK_COMPANION_DIST_URL` is set. We refuse to deploy without a
     * verified hash — that would defeat the entire point of dist_url mode.
     */
    private function acquireFromDistUrl(string $url): string
    {
        $expectedHash = trim((string) config('clockwork.companion.dist_sha256'));
        if ($expectedHash === '') {
            throw new RuntimeException(
                'CLOCKWORK_COMPANION_DIST_URL is set but CLOCKWORK_COMPANION_DIST_SHA256 is empty — '
                .'refusing to deploy without a verified hash. Run `clockwork:build-companion-tarball` to '
                .'produce a fresh artifact + hash, then set both env vars.'
            );
        }

        $response = Http::timeout(60)->get($url);
        if ($response->failed()) {
            throw new RuntimeException("Companion dist URL fetch failed: HTTP {$response->status()} from {$url}");
        }

        $bytes = $response->body();
        $actualHash = hash('sha256', $bytes);
        if (! hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException(
                'Companion dist tarball sha256 mismatch (refusing to deploy unverified source). '
                ."Expected {$expectedHash}, got {$actualHash}. The artifact at {$url} may have been "
                .'tampered with, or the env var is stale relative to a fresh publish.'
            );
        }

        return base64_encode($bytes);
    }

    private function acquireFromLocalPath(string $localPath): string
    {
        return base64_encode($this->buildLocalTarballBytes($localPath));
    }

    /**
     * Build the canonical Companion tarball from a local source directory and
     * return the raw bytes.
     *
     * Used by:
     *   - acquireFromLocalPath (dev install path, returns base64-of-this)
     *   - clockwork:build-companion-tarball (publish workflow, writes raw +
     *     sha256 to disk)
     *
     * The tarball is shaped so `tar -xzf - -C wp-content/mu-plugins/` produces:
     *   wp-content/mu-plugins/clockwork-companion.php   (loader)
     *   wp-content/mu-plugins/clockwork-companion/      (rest of source)
     */
    public function buildLocalTarballBytes(string $localPath): string
    {
        if ($localPath === '' || ! is_dir($localPath)) {
            throw new RuntimeException("Local plugin source not found at {$localPath}. Set CLOCKWORK_COMPANION_LOCAL_PATH or publish a dist URL.");
        }

        $loader = $localPath.'/clockwork-companion.php';
        if (! is_file($loader)) {
            throw new RuntimeException("Loader not found at {$loader}.");
        }

        // Stage the canonical mu-plugins layout in a temp dir, then tar it.
        $stage = sys_get_temp_dir().'/clockwork-companion-stage-'.Str::random(8);
        $sub = $stage.'/clockwork-companion';
        if (! mkdir($sub, 0755, true)) {
            throw new RuntimeException("Could not create staging dir {$sub}.");
        }

        try {
            copy($loader, $stage.'/clockwork-companion.php');

            // Mirror everything except the loader + .git + vendor + zips into
            // the sub-directory.
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($localPath, \FilesystemIterator::SKIP_DOTS),
                    function ($current) use ($localPath) {
                        $name = $current->getFilename();
                        if ($current->isDir() && in_array($name, ['.git', 'vendor', 'node_modules', '.idea', '.vscode'], true)) {
                            return false;
                        }
                        // macOS AppleDouble sidecar files — '._foo' alongside 'foo'.
                        // They carry xattrs we don't want shipped to the remote.
                        if (str_starts_with($name, '._') || $name === '.DS_Store') {
                            return false;
                        }

                        return $name !== 'clockwork-companion.php' || $current->getPath() !== rtrim($localPath, '/');
                    }
                )
            );

            foreach ($iter as $item) {
                /** @var \SplFileInfo $item */
                $relative = substr($item->getPathname(), strlen($localPath) + 1);
                $target = $sub.'/'.$relative;
                if ($item->isDir()) {
                    if (! is_dir($target)) {
                        mkdir($target, 0755, true);
                    }
                } else {
                    $dir = dirname($target);
                    if (! is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    copy($item->getPathname(), $target);
                }
            }

            $tarPath = $stage.'.tgz';
            // Run tar from stage dir so paths inside the archive are relative
            // (./clockwork-companion.php, ./clockwork-companion/...).
            // COPYFILE_DISABLE=1 stops macOS bsdtar from inserting AppleDouble
            // sidecar entries ('._foo') for files with extended attributes —
            // those would extract as real files on the Linux remote and pollute
            // mu-plugins/.
            $cmd = sprintf('COPYFILE_DISABLE=1 tar -czf %s -C %s .', escapeshellarg($tarPath), escapeshellarg($stage));
            exec($cmd, $out, $code);
            if ($code !== 0) {
                throw new RuntimeException('Local tar failed: '.implode("\n", $out));
            }

            $bytes = file_get_contents($tarPath);
            if ($bytes === false) {
                throw new RuntimeException("Could not read tarball at {$tarPath}.");
            }

            return $bytes;
        } finally {
            $this->rmTree($stage);
            @unlink($stage.'.tgz');
        }
    }

    private function rmTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
