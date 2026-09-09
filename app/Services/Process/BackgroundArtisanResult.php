<?php

namespace App\Services\Process;

final class BackgroundArtisanResult
{
    public const STARTED = 'started';

    public const ALREADY_RUNNING = 'already_running';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $state,
        public readonly ?string $error = null,
    ) {}

    public static function ok(): self
    {
        return new self(self::STARTED);
    }

    public static function busy(): self
    {
        return new self(self::ALREADY_RUNNING);
    }

    public static function error(string $error): self
    {
        return new self(self::FAILED, $error);
    }

    public function started(): bool
    {
        return $this->state === self::STARTED;
    }

    public function alreadyRunning(): bool
    {
        return $this->state === self::ALREADY_RUNNING;
    }

    public function failed(): bool
    {
        return $this->state === self::FAILED;
    }
}
