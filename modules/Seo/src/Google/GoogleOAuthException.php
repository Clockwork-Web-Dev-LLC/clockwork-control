<?php

namespace Modules\Seo\Google;

use RuntimeException;

/**
 * A failed Google OAuth call. $errorCode carries Google's `error` value
 * (e.g. invalid_grant), so callers can tell a revoked grant from a blip.
 */
class GoogleOAuthException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null)
    {
        parent::__construct(mb_substr($message, 0, 480));
    }

    public function isRevoked(): bool
    {
        return $this->errorCode === 'invalid_grant';
    }
}
