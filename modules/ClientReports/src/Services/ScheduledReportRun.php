<?php

namespace Modules\ClientReports\Services;

use Modules\ClientReports\Models\ClientReport;

final class ScheduledReportRun
{
    /**
     * @param  list<string>  $sentTo  recipients emailed during this run
     * @param  list<string>  $failed  recipients whose send threw during this run
     */
    public function __construct(
        public readonly ClientReport $report,
        public readonly bool $isDraft,
        public readonly bool $resumed,
        public readonly array $sentTo = [],
        public readonly array $failed = [],
    ) {}

    public function complete(): bool
    {
        return $this->failed === [];
    }
}
