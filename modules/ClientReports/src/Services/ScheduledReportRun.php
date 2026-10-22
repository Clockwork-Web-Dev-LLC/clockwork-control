<?php

namespace Modules\ClientReports\Services;

use Modules\ClientReports\Models\ClientReport;

final class ScheduledReportRun
{
    /**
     * @param  list<string>  $sentTo  recipients emailed during this run
     * @param  list<string>  $failed  recipients whose send threw during this run
     * @param  list<string>  $heldReasons  why an auto report was kept as a draft instead of sent
     */
    public function __construct(
        public readonly ClientReport $report,
        public readonly bool $isDraft,
        public readonly bool $resumed,
        public readonly array $sentTo = [],
        public readonly array $failed = [],
        public readonly array $heldReasons = [],
    ) {}

    public function held(): bool
    {
        return $this->heldReasons !== [];
    }

    public function complete(): bool
    {
        return $this->failed === [];
    }
}
