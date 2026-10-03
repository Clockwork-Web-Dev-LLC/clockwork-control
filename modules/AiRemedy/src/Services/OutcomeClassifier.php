<?php

namespace Modules\AiRemedy\Services;

use App\Models\ActionLog;
use App\Models\ServerMetric;
use App\Models\SiteUptimeEvent;
use Illuminate\Support\Carbon;
use Modules\AiRemedy\Models\AiRemedyRun;

class OutcomeClassifier
{
    /**
     * Evaluate the post-incident outcome of an AiRemedy run 60+ minutes after diagnosis.
     *
     * @return array{outcome: string, details: array<string, mixed>}
     */
    public function evaluate(AiRemedyRun $run): array
    {
        $windowStart = $run->started_at;
        $windowEnd = $run->started_at->copy()->addMinutes(60);

        // Check if a human operator performed actions on the affected server/site in the window
        $hasHumanAction = $this->hasHumanAction($run, $windowStart, $windowEnd);

        if ($run->trigger_type === AiRemedyRun::TRIGGER_SERVER_SPIKE) {
            return $this->evaluateServerSpike($run, $windowStart, $windowEnd, $hasHumanAction);
        }

        if ($run->trigger_type === AiRemedyRun::TRIGGER_SITE_DOWNTIME) {
            return $this->evaluateSiteDowntime($run, $windowStart, $windowEnd, $hasHumanAction);
        }

        return [
            'outcome' => AiRemedyRun::OUTCOME_UNKNOWN,
            'details' => ['reason' => 'Non-evaluable trigger type'],
        ];
    }

    private function hasHumanAction(AiRemedyRun $run, Carbon $start, Carbon $end): bool
    {
        $query = ActionLog::query()
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('ran_at', [$start, $end])
                    ->orWhereBetween('created_at', [$start, $end]);
            })
            ->whereNotIn('action_type', ['ai_remedy_triage', 'ai_remediation', 'ai_remedy_run_hidden', 'ai_remedy_verdict']);

        if ($run->server_id && $run->site_id) {
            $query->where(function ($q) use ($run) {
                $q->where('server_id', $run->server_id)
                    ->orWhere('site_id', $run->site_id);
            });
        } elseif ($run->server_id) {
            $query->where('server_id', $run->server_id);
        } elseif ($run->site_id) {
            $query->where('site_id', $run->site_id);
        } else {
            return false;
        }

        return $query->exists();
    }

    private function evaluateServerSpike(AiRemedyRun $run, Carbon $start, Carbon $end, bool $hasHumanAction): array
    {
        if (! $run->server_id) {
            return [
                'outcome' => AiRemedyRun::OUTCOME_UNKNOWN,
                'details' => ['reason' => 'No server associated with run'],
            ];
        }

        $metrics = ServerMetric::query()
            ->where('server_id', $run->server_id)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('recorded_at', [$start, $end])
                    ->orWhereBetween('created_at', [$start, $end]);
            })
            ->orderBy('recorded_at')
            ->get();

        if ($metrics->isEmpty()) {
            return [
                'outcome' => AiRemedyRun::OUTCOME_UNKNOWN,
                'details' => ['reason' => 'No server metrics recorded in window (unlinked or unmonitored server)'],
            ];
        }

        $lastMetric = $metrics->last();
        $cpu = (float) ($lastMetric->cpu_pct ?? 0);
        $ram = (float) ($lastMetric->memory_pct ?? 0);

        // Critical escalation threshold: >= 95% CPU or >= 98% RAM
        if ($cpu >= 95 || $ram >= 98) {
            return [
                'outcome' => AiRemedyRun::OUTCOME_ESCALATED,
                'details' => [
                    'final_cpu' => $cpu,
                    'final_ram' => $ram,
                    'reason' => 'Server reached critical resource saturation after incident',
                ],
            ];
        }

        // Recovery threshold: below standard warning triggers (CPU < 85%, RAM < 90%)
        $isRecovered = $cpu < 85 && $ram < 90;

        if ($isRecovered) {
            if ($hasHumanAction) {
                return [
                    'outcome' => AiRemedyRun::OUTCOME_HUMAN_RESOLVED,
                    'details' => [
                        'final_cpu' => $cpu,
                        'final_ram' => $ram,
                        'reason' => 'Metrics recovered following operator intervention',
                    ],
                ];
            }

            return [
                'outcome' => AiRemedyRun::OUTCOME_SELF_RESOLVED,
                'details' => [
                    'final_cpu' => $cpu,
                    'final_ram' => $ram,
                    'reason' => 'Metrics normalized back under thresholds with no operator intervention',
                ],
            ];
        }

        return [
            'outcome' => AiRemedyRun::OUTCOME_PERSISTED,
            'details' => [
                'final_cpu' => $cpu,
                'final_ram' => $ram,
                'reason' => 'Resource pressure remained above warning thresholds at 60 minutes',
            ],
        ];
    }

    private function evaluateSiteDowntime(AiRemedyRun $run, Carbon $start, Carbon $end, bool $hasHumanAction): array
    {
        if (! $run->site_id) {
            return [
                'outcome' => AiRemedyRun::OUTCOME_UNKNOWN,
                'details' => ['reason' => 'No site associated with run'],
            ];
        }

        $events = SiteUptimeEvent::query()
            ->where('site_id', $run->site_id)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('event_at', [$start, $end])
                    ->orWhereBetween('created_at', [$start, $end]);
            })
            ->orderBy('event_at')
            ->get();

        $recovered = $events->contains(fn ($e) => $e->event_type === SiteUptimeEvent::TYPE_UP);

        if ($recovered) {
            if ($hasHumanAction) {
                return [
                    'outcome' => AiRemedyRun::OUTCOME_HUMAN_RESOLVED,
                    'details' => ['reason' => 'Site recovered following operator intervention'],
                ];
            }

            return [
                'outcome' => AiRemedyRun::OUTCOME_SELF_RESOLVED,
                'details' => ['reason' => 'Site recovered automatically within 60 minutes'],
            ];
        }

        return [
            'outcome' => AiRemedyRun::OUTCOME_PERSISTED,
            'details' => ['reason' => 'Site was not detected as up within the 60-minute window'],
        ];
    }
}
