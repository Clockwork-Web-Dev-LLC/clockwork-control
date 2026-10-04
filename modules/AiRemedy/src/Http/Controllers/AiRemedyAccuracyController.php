<?php

namespace Modules\AiRemedy\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Modules\AiRemedy\Models\AiRemedyRun;

class AiRemedyAccuracyController extends Controller
{
    public function index(Request $request): View
    {
        $days = (int) $request->input('days', 30);
        $days = in_array($days, [7, 14, 30, 60, 90], true) ? $days : 30;
        $since = Carbon::now()->subDays($days);

        $trigger = $request->input('trigger');
        $model = $request->input('model');

        $query = AiRemedyRun::query()
            ->where('started_at', '>=', $since)
            ->whereNull('hidden_at');

        if ($trigger) {
            $query->where('trigger_type', $trigger);
        }

        if ($model) {
            $query->where('model_used', $model);
        }

        $allRuns = (clone $query)->get();

        $totalRuns = $allRuns->count();
        $totalCost = (float) $allRuns->sum('total_cost_usd');

        // Verdict breakdown
        $verdicts = [
            AiRemedyRun::VERDICT_CORRECT => $allRuns->where('verdict', AiRemedyRun::VERDICT_CORRECT)->count(),
            AiRemedyRun::VERDICT_PARTIAL => $allRuns->where('verdict', AiRemedyRun::VERDICT_PARTIAL)->count(),
            AiRemedyRun::VERDICT_WRONG => $allRuns->where('verdict', AiRemedyRun::VERDICT_WRONG)->count(),
            AiRemedyRun::VERDICT_UNSURE => $allRuns->where('verdict', AiRemedyRun::VERDICT_UNSURE)->count(),
        ];
        $totalVerdicts = array_sum($verdicts);
        $evaluatedVerdicts = $verdicts[AiRemedyRun::VERDICT_CORRECT] + $verdicts[AiRemedyRun::VERDICT_PARTIAL] + $verdicts[AiRemedyRun::VERDICT_WRONG];
        $accuracyPct = $evaluatedVerdicts > 0
            ? round(($verdicts[AiRemedyRun::VERDICT_CORRECT] / $evaluatedVerdicts) * 100, 1)
            : null;

        // Outcome breakdown
        $outcomes = [
            AiRemedyRun::OUTCOME_SELF_RESOLVED => $allRuns->where('outcome', AiRemedyRun::OUTCOME_SELF_RESOLVED)->count(),
            AiRemedyRun::OUTCOME_HUMAN_RESOLVED => $allRuns->where('outcome', AiRemedyRun::OUTCOME_HUMAN_RESOLVED)->count(),
            AiRemedyRun::OUTCOME_PERSISTED => $allRuns->where('outcome', AiRemedyRun::OUTCOME_PERSISTED)->count(),
            AiRemedyRun::OUTCOME_ESCALATED => $allRuns->where('outcome', AiRemedyRun::OUTCOME_ESCALATED)->count(),
            AiRemedyRun::OUTCOME_UNKNOWN => $allRuns->where('outcome', AiRemedyRun::OUTCOME_UNKNOWN)->count(),
        ];

        // "Would have acted, but it self-resolved"
        $wouldHaveActedSelfResolved = $allRuns
            ->where('outcome', AiRemedyRun::OUTCOME_SELF_RESOLVED)
            ->filter(fn ($r) => ! empty($r->proposed_commands) && $r->safety_tier === AiRemedyRun::TIER_1_SAFE)
            ->count();

        // Allowed maintenance precision: percentage of allowed_maintenance runs that self-resolved
        $maintenanceRuns = $allRuns->where('status', AiRemedyRun::STATUS_ALLOWED_MAINTENANCE);
        $maintenanceCount = $maintenanceRuns->count();
        $maintenanceSelfResolved = $maintenanceRuns->where('outcome', AiRemedyRun::OUTCOME_SELF_RESOLVED)->count();
        $maintenancePrecisionPct = $maintenanceCount > 0
            ? round(($maintenanceSelfResolved / $maintenanceCount) * 100, 1)
            : null;

        // Cost per run and cost per correct verdict
        $costPerRun = $totalRuns > 0 ? round($totalCost / $totalRuns, 4) : 0.0;
        $costPerCorrect = $verdicts[AiRemedyRun::VERDICT_CORRECT] > 0
            ? round($totalCost / $verdicts[AiRemedyRun::VERDICT_CORRECT], 4)
            : null;

        // Models available for filter
        $availableModels = AiRemedyRun::query()
            ->whereNotNull('model_used')
            ->distinct()
            ->pluck('model_used');

        return view('ai-remedy::accuracy', compact(
            'days',
            'trigger',
            'model',
            'totalRuns',
            'totalCost',
            'verdicts',
            'totalVerdicts',
            'accuracyPct',
            'outcomes',
            'wouldHaveActedSelfResolved',
            'maintenanceCount',
            'maintenanceSelfResolved',
            'maintenancePrecisionPct',
            'costPerRun',
            'costPerCorrect',
            'availableModels',
            'allRuns'
        ));
    }
}
