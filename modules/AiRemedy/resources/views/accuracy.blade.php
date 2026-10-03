@extends('layouts.app')

@section('title', 'AiRemedy · Accuracy & Outcome Report')

@section('content')
@include('operations._tabs')

<div class="space-y-6">
    <x-page-header title="AiRemedy Accuracy & Outcomes"
        subtitle="Historical verification of AiRemedy diagnostic precision, operator verdicts, and post-incident resolution telemetry.">
        <x-slot:actions>
            <a href="{{ route('ai-remedy.index') }}"
               class="btn-pill-nav text-xs md:text-sm py-1.5 px-3 flex items-center gap-2 cursor-pointer font-medium border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)]">
                <i class="fa-solid fa-arrow-left text-xs"></i>
                <span>Incident Log</span>
            </a>
            <a href="{{ route('ai-remedy.settings') }}"
               class="btn-pill-nav text-xs md:text-sm py-1.5 px-3 flex items-center gap-2 cursor-pointer font-medium border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)]">
                <i class="fa-solid fa-gear text-xs"></i>
                <span>Settings</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Filter Bar --}}
    <form method="GET" action="{{ route('ai-remedy.accuracy') }}" class="card p-4 flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <div>
                <label for="days" class="text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider block mb-1">Timeframe</label>
                <select id="days" name="days" onchange="this.form.submit()" class="form-select text-xs py-1.5 px-2.5 rounded border border-[var(--color-border)] bg-[var(--color-surface)]">
                    <option value="7" {{ $days === 7 ? 'selected' : '' }}>Last 7 days</option>
                    <option value="14" {{ $days === 14 ? 'selected' : '' }}>Last 14 days</option>
                    <option value="30" {{ $days === 30 ? 'selected' : '' }}>Last 30 days</option>
                    <option value="60" {{ $days === 60 ? 'selected' : '' }}>Last 60 days</option>
                    <option value="90" {{ $days === 90 ? 'selected' : '' }}>Last 90 days</option>
                </select>
            </div>

            <div>
                <label for="trigger" class="text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider block mb-1">Trigger</label>
                <select id="trigger" name="trigger" onchange="this.form.submit()" class="form-select text-xs py-1.5 px-2.5 rounded border border-[var(--color-border)] bg-[var(--color-surface)]">
                    <option value="">All Triggers</option>
                    <option value="server_spike" {{ $trigger === 'server_spike' ? 'selected' : '' }}>Server Spike</option>
                    <option value="site_downtime" {{ $trigger === 'site_downtime' ? 'selected' : '' }}>Site Downtime</option>
                    <option value="manual_audit" {{ $trigger === 'manual_audit' ? 'selected' : '' }}>Manual Audit</option>
                </select>
            </div>

            @if ($availableModels->isNotEmpty())
                <div>
                    <label for="model" class="text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider block mb-1">Model</label>
                    <select id="model" name="model" onchange="this.form.submit()" class="form-select text-xs py-1.5 px-2.5 rounded border border-[var(--color-border)] bg-[var(--color-surface)]">
                        <option value="">All Models</option>
                        @foreach ($availableModels as $m)
                            <option value="{{ $m }}" {{ $model === $m ? 'selected' : '' }}>{{ $m }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        <div class="text-xs text-[var(--color-ink-muted)]">
            Showing metrics for <span class="font-bold text-[var(--color-ink-strong)]">{{ $totalRuns }}</span> incidents
        </div>
    </form>

    {{-- Hero Summary Metrics --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        {{-- Accuracy --}}
        <div class="card p-5">
            <span class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] block">Diagnostic Accuracy</span>
            <div class="flex items-baseline gap-2 mt-2">
                <span class="text-3xl font-bold font-data {{ $accuracyPct !== null && $accuracyPct >= 80 ? 'text-emerald-500' : ($accuracyPct !== null ? 'text-amber-500' : 'text-[var(--color-ink-muted)]') }}">
                    {{ $accuracyPct !== null ? $accuracyPct.'%' : '—' }}
                </span>
                <span class="text-xs text-[var(--color-ink-muted)]">
                    @if ($totalVerdicts > 0)
                        ({{ $verdicts['correct'] }}/{{ $totalVerdicts }} verdicts)
                    @else
                        (No verdicts yet)
                    @endif
                </span>
            </div>
            <p class="text-xs text-[var(--color-ink-muted)] mt-2">Operator verification rate for diagnosed root causes.</p>
        </div>

        {{-- Would Have Acted, Self-Resolved --}}
        <div class="card p-5">
            <span class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] block">Avoided Premature Actions</span>
            <div class="flex items-baseline gap-2 mt-2">
                <span class="text-3xl font-bold font-data text-sky-500">
                    {{ $wouldHaveActedSelfResolved }}
                </span>
                <span class="text-xs text-[var(--color-ink-muted)]">transient spikes</span>
            </div>
            <p class="text-xs text-[var(--color-ink-muted)] mt-2">Safe Tier 1 proposed, but load normalized cleanly within 60m.</p>
        </div>

        {{-- Maintenance Precision --}}
        <div class="card p-5">
            <span class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] block">Maintenance Precision</span>
            <div class="flex items-baseline gap-2 mt-2">
                <span class="text-3xl font-bold font-data {{ $maintenancePrecisionPct !== null && $maintenancePrecisionPct >= 80 ? 'text-emerald-500' : 'text-[var(--color-ink-strong)]' }}">
                    {{ $maintenancePrecisionPct !== null ? $maintenancePrecisionPct.'%' : '—' }}
                </span>
                <span class="text-xs text-[var(--color-ink-muted)]">
                    @if ($maintenanceCount > 0)
                        ({{ $maintenanceSelfResolved }}/{{ $maintenanceCount }})
                    @else
                        (None flagged)
                    @endif
                </span>
            </div>
            <p class="text-xs text-[var(--color-ink-muted)] mt-2">Maintenance-flagged spikes that completed without incident.</p>
        </div>

        {{-- Cost Effectiveness --}}
        <div class="card p-5">
            <span class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] block">Cost Effectiveness</span>
            <div class="flex items-baseline gap-2 mt-2">
                <span class="text-3xl font-bold font-data text-[var(--color-ink-strong)]">
                    ${{ number_format($totalCost, 2) }}
                </span>
                <span class="text-xs text-[var(--color-ink-muted)]">
                    (${{ number_format($costPerRun, 3) }}/run)
                </span>
            </div>
            <p class="text-xs text-[var(--color-ink-muted)] mt-2">
                @if ($costPerCorrect)
                    ${{ number_format($costPerCorrect, 3) }} per verified correct diagnosis.
                @else
                    Continuous Shadow observability spend.
                @endif
            </p>
        </div>
    </div>

    {{-- Verdicts vs Outcomes Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        {{-- Operator Verdicts --}}
        <div class="card p-5">
            <h3 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-4 flex items-center justify-between">
                <span><i class="fa-solid fa-stamp text-[var(--color-ink-soft)] mr-2"></i>Operator Verdicts</span>
                <span class="text-xs text-[var(--color-ink-muted)] font-normal">{{ $totalVerdicts }} evaluated</span>
            </h3>

            <div class="space-y-3">
                <div class="flex items-center justify-between p-2.5 rounded bg-[var(--color-surface-alt)]">
                    <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400 flex items-center gap-2">
                        <i class="fa-solid fa-circle-check"></i> Correct Diagnosis
                    </span>
                    <span class="font-data font-bold text-sm text-[var(--color-ink-strong)]">{{ $verdicts['correct'] }}</span>
                </div>

                <div class="flex items-center justify-between p-2.5 rounded bg-[var(--color-surface-alt)]">
                    <span class="text-xs font-medium text-amber-600 dark:text-amber-400 flex items-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation"></i> Partially Correct
                    </span>
                    <span class="font-data font-bold text-sm text-[var(--color-ink-strong)]">{{ $verdicts['partial'] }}</span>
                </div>

                <div class="flex items-center justify-between p-2.5 rounded bg-[var(--color-surface-alt)]">
                    <span class="text-xs font-medium text-rose-600 dark:text-rose-400 flex items-center gap-2">
                        <i class="fa-solid fa-circle-xmark"></i> Incorrect Diagnosis
                    </span>
                    <span class="font-data font-bold text-sm text-[var(--color-ink-strong)]">{{ $verdicts['wrong'] }}</span>
                </div>

                <div class="flex items-center justify-between p-2.5 rounded bg-[var(--color-surface-alt)]">
                    <span class="text-xs font-medium text-[var(--color-ink-muted)] flex items-center gap-2">
                        <i class="fa-regular fa-circle-question"></i> Unsure / Needs Follow-up
                    </span>
                    <span class="font-data font-bold text-sm text-[var(--color-ink-strong)]">{{ $verdicts['unsure'] }}</span>
                </div>
            </div>
        </div>

        {{-- 60-Minute Resolution Outcomes --}}
        <div class="card p-5">
            <h3 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-4 flex items-center justify-between">
                <span><i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-soft)] mr-2"></i>60-Minute Resolution Outcomes</span>
                <span class="text-xs text-[var(--color-ink-muted)] font-normal">Automated telemetry evaluation</span>
            </h3>

            <div class="space-y-3">
                <div class="flex items-center justify-between p-2.5 rounded bg-[var(--color-surface-alt)]">
                    <span class="text-xs font-medium text-sky-600 dark:text-sky-400 flex items-center gap-2">
                        <i class="fa-solid fa-arrow-trend-down"></i> Self-Resolved (Transient)
                    </span>
                    <span class="font-data font-bold text-sm text-[var(--color-ink-strong)]">{{ $outcomes['self_resolved'] }}</span>
                </div>

                <div class="flex items-center justify-between p-2.5 rounded bg-[var(--color-surface-alt)]">
                    <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400 flex items-center gap-2">
                        <i class="fa-solid fa-user-check"></i> Human Resolved
                    </span>
                    <span class="font-data font-bold text-sm text-[var(--color-ink-strong)]">{{ $outcomes['human_resolved'] }}</span>
                </div>

                <div class="flex items-center justify-between p-2.5 rounded bg-[var(--color-surface-alt)]">
                    <span class="text-xs font-medium text-amber-600 dark:text-amber-400 flex items-center gap-2">
                        <i class="fa-solid fa-hourglass-half"></i> Persisted (>60m)
                    </span>
                    <span class="font-data font-bold text-sm text-[var(--color-ink-strong)]">{{ $outcomes['persisted'] }}</span>
                </div>

                <div class="flex items-center justify-between p-2.5 rounded bg-[var(--color-surface-alt)]">
                    <span class="text-xs font-medium text-rose-600 dark:text-rose-400 flex items-center gap-2">
                        <i class="fa-solid fa-fire"></i> Escalated to Critical
                    </span>
                    <span class="font-data font-bold text-sm text-[var(--color-ink-strong)]">{{ $outcomes['escalated'] }}</span>
                </div>

                <div class="flex items-center justify-between p-2.5 rounded bg-[var(--color-surface-alt)]">
                    <span class="text-xs font-medium text-[var(--color-ink-muted)] flex items-center gap-2">
                        <i class="fa-regular fa-circle-question"></i> Telemetry Unavailable
                    </span>
                    <span class="font-data font-bold text-sm text-[var(--color-ink-strong)]">{{ $outcomes['unknown'] }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
