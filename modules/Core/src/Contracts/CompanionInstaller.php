<?php

namespace Modules\Core\Contracts;

use App\Models\Site;

/**
 * Installs/updates the Clockwork Companion mu-plugin on a site, whatever
 * the underlying transport. `App\Services\Companion\CompanionInstaller`
 * (SSH) and `Modules\Pressable\PressableCompanionInstaller` (async command
 * API) both implement this — see `HostingProvider::companionInstaller()`.
 *
 * $rotateSecret is SSH-specific in spirit — CompanionInstaller can do a
 * lightweight verify/heal pass without generating a new HMAC secret.
 * PressableCompanionInstaller accepts the parameter for interface
 * compatibility but ignores it: Pressable's transport pushes a fresh
 * bootstrap secret on every single install by construction (chunked
 * reassembly with a fresh nonce each call), so there's no "don't rotate"
 * mode to honor there — every Pressable install already behaves as if
 * $rotateSecret were true.
 */
interface CompanionInstaller
{
    /**
     * @return array{result: string, message: string, output?: string, version?: string}
     */
    public function installOrUpdate(Site $site, bool $rotateSecret = false): array;
}
