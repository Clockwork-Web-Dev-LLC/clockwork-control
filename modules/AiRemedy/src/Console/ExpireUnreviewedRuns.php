<?php

namespace Modules\AiRemedy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\AiRemedy\Models\AiRemedyRun;

class ExpireUnreviewedRuns extends Command
{
    protected $signature = 'clockwork:ai-remedy-expire-unreviewed';

    protected $description = 'Auto-mark unreviewed Copilot incidents older than 24 hours as expired';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subHours(24);

        $expiredCount = AiRemedyRun::query()
            ->where('actor', 'interactive')
            ->whereIn('status', [AiRemedyRun::STATUS_ANALYZED, AiRemedyRun::STATUS_PENDING])
            ->where('started_at', '<=', $cutoff)
            ->update([
                'status' => AiRemedyRun::STATUS_EXPIRED,
            ]);

        $this->info("Expired {$expiredCount} unreviewed Copilot run(s).");

        return self::SUCCESS;
    }
}
