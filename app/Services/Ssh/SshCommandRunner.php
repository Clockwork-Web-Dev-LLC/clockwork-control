<?php

namespace App\Services\Ssh;

use App\Models\Site;
use Modules\Core\Contracts\SiteCommandRunner;
use RuntimeException;

/**
 * SiteCommandRunner adapter over SshClient, for hosting providers whose
 * sites are linked to a servers row (CAP_SSH). Mirrors SshClient::exec()'s
 * existing behavior exactly — see SiteCommandRunner's docblock for why
 * error semantics aren't unified with PressableApiCommandRunner.
 */
class SshCommandRunner implements SiteCommandRunner
{
    public function __construct(private readonly SshClient $client) {}

    public function run(Site $site, string $command, ?int $timeoutSeconds = null): string
    {
        if (! $site->server) {
            throw new RuntimeException("Site #{$site->id} ({$site->domain}) has no linked server — cannot run commands over SSH.");
        }

        return $this->client->exec($site->server, $command, $timeoutSeconds);
    }
}
