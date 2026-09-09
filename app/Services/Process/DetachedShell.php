<?php

namespace App\Services\Process;

/**
 * Thin wrapper around exec() so tests can assert the launched command
 * without namespace-function shadowing. Production always detaches via
 * the caller (nohup + trailing &).
 */
class DetachedShell
{
    public function run(string $command): void
    {
        exec($command);
    }
}
