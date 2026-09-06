<?php

namespace App\Services\Domains;

use Carbon\CarbonImmutable;

final class RdapDomainResult
{
    public function __construct(
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?string $registrar,
        public readonly ?string $status,
        public readonly ?string $rawError = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->expiresAt !== null;
    }
}
