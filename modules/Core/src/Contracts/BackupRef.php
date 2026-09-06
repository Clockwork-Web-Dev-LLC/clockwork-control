<?php

namespace Modules\Core\Contracts;

use DateTimeInterface;

class BackupRef
{
    public function __construct(
        public readonly string $provider,
        public readonly string $externalId,
        public readonly DateTimeInterface $createdAt,
        public readonly ?int $sizeBytes = null,
        public readonly ?array $metadata = [],
    ) {}
}
