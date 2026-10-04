<?php

namespace Modules\AiRemedy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\AiRemedy\Models\AiRemedyRun;
use Modules\AiRemedy\Services\OutcomeClassifier;

class EvaluateOutcomes extends Command
{
    protected $signature = 'clockwork:ai-remedy-evaluate-outcomes';

    protected $description = 'Evaluate 60-minute post-incident outcomes for Shadow and Copilot AiRemedy runs';

    public function handle(OutcomeClassifier $classifier): int
    {
        $cutoff = Carbon::now()->subMinutes(60);

        $runs = AiRemedyRun::query()
            ->whereNull('outcome')
            ->whereIn('actor', ['watch_mode', 'interactive', 'autonomous'])
            ->where('started_at', '<=', $cutoff)
            ->limit(100)
            ->get();

        if ($runs->isEmpty()) {
            $this->info('No pending AiRemedy runs to evaluate.');

            return self::SUCCESS;
        }

        $this->info("Evaluating outcomes for {$runs->count()} run(s)...");

        $evaluated = 0;
        foreach ($runs as $run) {
            $result = $classifier->evaluate($run);

            $run->update([
                'outcome' => $result['outcome'],
                'outcome_details' => $result['details'],
                'outcome_evaluated_at' => Carbon::now(),
            ]);

            $evaluated++;
        }

        $this->info("Evaluated {$evaluated} run(s) successfully.");

        return self::SUCCESS;
    }
}
