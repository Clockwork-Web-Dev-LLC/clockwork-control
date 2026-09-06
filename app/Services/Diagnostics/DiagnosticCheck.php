<?php

namespace App\Services\Diagnostics;

/**
 * One diagnostic check. Implementations should be side-effect-free —
 * connectivity probes, token validations, HEAD requests, etc. Anything
 * that mutates external state (sending mail, posting webhooks) belongs
 * behind a separate explicit "send test X" button, not in run().
 *
 * Implementations should defend against their own exceptions: catch and
 * convert to CheckResult::fail() rather than letting the runner stop.
 */
interface DiagnosticCheck
{
    /**
     * Stable identifier for the check (e.g. "database", "cloudflare"). Used
     * as an HTML id and for any future JSON output. Lowercase + hyphens only.
     */
    public function id(): string;

    /**
     * Human-readable label shown in the UI. e.g. "Cloudflare API".
     */
    public function name(): string;

    /**
     * One-line description of what's being checked. Shown under the label.
     */
    public function description(): string;

    public function run(): CheckResult;
}
