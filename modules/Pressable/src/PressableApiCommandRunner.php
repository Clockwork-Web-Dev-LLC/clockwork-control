<?php

namespace Modules\Pressable;

use App\Models\Site;
use Modules\Core\Contracts\SiteCommandRunner;
use RuntimeException;

/**
 * SiteCommandRunner adapter over PressableCommandRunner. Mirrors
 * runOrFail()'s existing behavior exactly — see SiteCommandRunner's
 * docblock for why error semantics aren't unified with SshCommandRunner.
 */
class PressableApiCommandRunner implements SiteCommandRunner
{
    public function __construct(private readonly PressableCommandRunner $runner) {}

    public function run(Site $site, string $command, ?int $timeoutSeconds = null): string
    {
        if (! $site->pressable_site_id) {
            throw new RuntimeException("Site #{$site->id} ({$site->domain}) has no pressable_site_id — cannot run commands via the Pressable API.");
        }

        return $this->runner->runOrFail($site->pressable_site_id, $command, $timeoutSeconds ?? 120);
    }
}
