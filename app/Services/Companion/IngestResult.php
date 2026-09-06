<?php

namespace App\Services\Companion;

use Illuminate\Support\Carbon;

/** Small DTO returned by ResourceMetricsIngestor::ingest(). */
final class IngestResult
{
    public function __construct(
        public readonly int $rowsWritten,
        public readonly ?Carbon $latestBucket,
        public readonly ?string $skipReason,
    ) {}
}
