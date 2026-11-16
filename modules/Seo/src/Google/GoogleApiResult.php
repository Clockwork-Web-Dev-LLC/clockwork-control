<?php

namespace Modules\Seo\Google;

/**
 * The outcome of one Google API call. Failures come back as values, not
 * exceptions, so one site's error never stops the next site's sync.
 */
final readonly class GoogleApiResult
{
    /**
     * @param  array<string, mixed>  $json
     */
    public function __construct(
        public bool $ok,
        public int $status,
        public array $json = [],
        public ?string $error = null,
        public ?string $reason = null,
    ) {}

    /** @param array<string, mixed> $json */
    public static function success(int $status, array $json): self
    {
        return new self(true, $status, $json);
    }

    public static function failure(int $status, string $error, ?string $reason = null): self
    {
        return new self(false, $status, [], mb_substr($error, 0, 480), $reason);
    }

    /** Google said slow down: stop this property for the night, don't retry. */
    public function isQuota(): bool
    {
        return $this->status === 429
            || ($this->status === 403 && in_array($this->reason, ['quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded', 'RESOURCE_EXHAUSTED'], true));
    }

    public function isRevoked(): bool
    {
        return $this->reason === 'invalid_grant';
    }
}
