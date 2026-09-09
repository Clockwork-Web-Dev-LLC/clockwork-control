<?php

namespace App\Services\Scheduler;

use Illuminate\Support\Carbon;

final class SchedulerHeartbeatStatus
{
    public const NEVER = 'never';

    public const OK = 'ok';

    public const STALE = 'stale';

    public function __construct(
        public readonly string $state,
        public readonly ?Carbon $lastAt,
        public readonly ?int $ageSeconds,
        public readonly int $thresholdMinutes,
    ) {}

    public static function never(int $thresholdMinutes): self
    {
        return new self(self::NEVER, null, null, $thresholdMinutes);
    }

    public function isOk(): bool
    {
        return $this->state === self::OK;
    }

    public function isStale(): bool
    {
        return $this->state === self::STALE;
    }

    public function neverTicked(): bool
    {
        return $this->state === self::NEVER;
    }

    public function needsAttention(): bool
    {
        return ! $this->isOk();
    }

    public function ageLabel(): string
    {
        if ($this->ageSeconds === null) {
            return 'never';
        }

        if ($this->ageSeconds < 60) {
            return $this->ageSeconds.'s ago';
        }

        $minutes = (int) round($this->ageSeconds / 60);
        if ($minutes < 60) {
            return $minutes.' min ago';
        }

        $hours = (int) floor($minutes / 60);
        $remainder = $minutes % 60;

        return $remainder === 0 ? "{$hours}h ago" : "{$hours}h {$remainder}m ago";
    }
}
