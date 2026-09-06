<?php

namespace Modules\Core\Contracts;

use App\Models\Site;

/**
 * Runs a shell/wp-cli command on a site's host, transport-agnostic (SSH vs
 * Pressable's async command API). New as of Phase 5 — no such abstraction
 * existed before; every call site picked between SshClient::exec() and
 * PressableCommandRunner by hand.
 *
 * Error semantics are deliberately NOT unified across implementations in
 * this first pass: SshCommandRunner mirrors SshClient::exec()'s existing
 * behavior (returns output as-is regardless of exit code — callers that
 * need exit-code checking already parse $? themselves), while
 * PressableApiCommandRunner mirrors PressableCommandRunner::runOrFail()'s
 * existing behavior (throws on non-zero exit). Pretending these already
 * matched would misrepresent real, pre-existing transport differences
 * rather than fix them — that's follow-on work, not this contract's job.
 */
interface SiteCommandRunner
{
    public function run(Site $site, string $command, ?int $timeoutSeconds = null): string;
}
