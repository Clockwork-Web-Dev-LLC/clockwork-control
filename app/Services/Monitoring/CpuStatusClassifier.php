<?php

namespace App\Services\Monitoring;

use App\Models\Server;

/**
 * Maps a CPU percentage to a fleet status color, using configurable
 * red/yellow thresholds. Provider-agnostic — every CloudProvider's
 * metrics() returns a plain cpu_pct float regardless of which cloud it
 * came from, and this is the one place that turns that number into a
 * Server::STATUS_* value.
 *
 * Split out of what used to be App\Services\DigitalOcean\ServerHealthEvaluator
 * during the Phase 4 module extraction: that class mixed this genuinely
 * cross-provider logic with DO-specific metrics-payload parsing, which
 * would have forced core app code (PollServers, ServersController) to
 * depend on the DigitalOcean module for something that has nothing to do
 * with DigitalOcean. The parsing half is now Modules\DigitalOcean\DigitalOceanMetricsParser.
 */
class CpuStatusClassifier
{
    public function __construct(
        protected ?float $redThreshold = null,
        protected ?float $yellowThreshold = null,
    ) {
        $this->redThreshold ??= (float) config('clockwork.monitoring.cpu_red_threshold', 90);
        $this->yellowThreshold ??= (float) config('clockwork.monitoring.cpu_yellow_threshold', 70);
    }

    public function statusForCpu(?float $percentUsed): string
    {
        if ($percentUsed === null) {
            return Server::STATUS_UNKNOWN;
        }

        if ($percentUsed >= $this->redThreshold) {
            return Server::STATUS_RED;
        }

        if ($percentUsed >= $this->yellowThreshold) {
            return Server::STATUS_YELLOW;
        }

        return Server::STATUS_GREEN;
    }
}
