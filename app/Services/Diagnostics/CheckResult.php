<?php

namespace App\Services\Diagnostics;

/**
 * Result of running a single diagnostic check. Status is a string (ok | fail
 * | skipped) so the value is trivially serializable for the view and for any
 * future JSON endpoint.
 *
 * "skipped" means the integration isn't configured — not an error. The view
 * styles those neutrally so a missing optional integration doesn't shout red.
 */
class CheckResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAIL = 'fail';

    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        public readonly string $status,
        public readonly string $summary,
        public readonly ?string $detail = null,
        public readonly int $durationMs = 0,
    ) {}

    public static function ok(string $summary, ?string $detail = null, int $durationMs = 0): self
    {
        return new self(self::STATUS_OK, $summary, $detail, $durationMs);
    }

    public static function fail(string $summary, ?string $detail = null, int $durationMs = 0): self
    {
        return new self(self::STATUS_FAIL, $summary, $detail, $durationMs);
    }

    public static function skipped(string $summary, ?string $detail = null): self
    {
        return new self(self::STATUS_SKIPPED, $summary, $detail, 0);
    }
}
