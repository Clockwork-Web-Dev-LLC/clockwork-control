@extends('layouts.app')

@section('title', 'AiRemedy Forensics #' . $run->id . ' · Clockwork')

@section('content')
<div class="max-w-5xl mx-auto space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs text-[var(--color-ink-muted)] mb-1">
                <a href="{{ route('ai-remedy.index') }}" class="hover:underline flex items-center gap-1">
                    <i class="fa-solid fa-arrow-left text-[10px]"></i>
                    <span>Back to AiRemedy Audit Log</span>
                </a>
            </div>
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-bold text-[var(--color-ink-strong)]">Incident Forensics #{{ $run->id }}</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full font-data text-xs font-semibold border whitespace-nowrap {{ $run->statusBadgeClass() }}">
                    {{ $run->statusLabel() }}
                </span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full font-data text-xs font-semibold border whitespace-nowrap {{ $run->safetyBadgeClass() }}">
                    {{ $run->safetyLabel() }}
                </span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full font-data text-xs font-semibold border whitespace-nowrap {{ $run->modeBadgeClass() }}">
                    {{ $run->modeLabel() }}
                </span>
            </div>
            <p class="text-xs text-[var(--color-ink-muted)] mt-1">
                Triggered {{ $run->started_at->format('M j, Y H:i:s') }} via {{ $run->actor }} · Model: {{ $run->model_used }} · Cost: ${{ number_format((float)$run->total_cost_usd, 4) }}
            </p>
        </div>

        @if($run->server)
            <div>
                <a href="{{ route('servers.show', $run->server) }}" class="btn-pill-nav text-xs py-1.5 px-3 flex items-center gap-1.5 border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)] font-medium">
                    <i class="fa-solid fa-server text-[11px]"></i>
                    <span>View Server ({{ $run->server->name }})</span>
                </a>
            </div>
        @endif
    </div>

    {{-- Allowed Maintenance or Watch Mode Banner --}}
    @if($run->isAllowedMaintenance())
        <div class="card p-4 border-sky-500/30 bg-sky-500/10 text-sky-950 dark:text-sky-200">
            <div class="flex items-center gap-2 font-bold text-sm mb-1">
                <i class="fa-solid fa-cloud-arrow-up text-sky-500"></i>
                <span>Allowed Background Maintenance Detected</span>
            </div>
            <p class="text-xs leading-relaxed">
                AiRemedy identified this resource spike as routine maintenance activity (e.g. SpinupWP S3 backup, database export, or log rotation). Safe deprioritization commands (like <code>renice</code> or <code>ionice</code>) are recommended rather than process termination, and alerting notifications were automatically muted.
            </p>
        </div>
    @elseif($run->isWatchMode())
        <div class="card p-4 border-indigo-500/30 bg-indigo-500/10 text-indigo-950 dark:text-indigo-200">
            <div class="flex items-center gap-2 font-bold text-sm mb-1">
                <i class="fa-solid fa-shield-halved text-indigo-500"></i>
                <span>Watch Mode Active — Passive Observability</span>
            </div>
            <p class="text-xs leading-relaxed">
                AiRemedy performed read-only SSH telemetry diagnostics and LLM reasoning, but executed <strong>0 commands</strong> on the server. Below is the full diagnosis and what AiRemedy would have done if autonomous healing had been enabled.
            </p>
        </div>
    @endif

    {{-- Operator Verdict & Outcome Evaluation --}}
    <div class="card p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)]">Operator Verdict & Outcome Evaluation</h3>
            @if($run->outcome)
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-data font-semibold border
                    {{ $run->outcome === 'self_resolved' ? 'bg-emerald-500/15 text-emerald-600 border-emerald-500/30' : ($run->outcome === 'escalated' ? 'bg-rose-500/15 text-rose-600 border-rose-500/30' : 'bg-slate-500/15 text-slate-600 border-slate-500/30') }}">
                    Outcome: {{ strtoupper(str_replace('_', ' ', $run->outcome)) }}
                </span>
            @endif
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] space-y-2">
                <span class="font-bold text-[var(--color-ink-strong)] block">60-Minute Telemetry Outcome</span>
                @if($run->outcome)
                    <div class="space-y-1">
                        <p class="text-[var(--color-ink-muted)]">
                            Status: <strong class="text-[var(--color-ink-strong)]">{{ ucwords(str_replace('_', ' ', $run->outcome)) }}</strong>
                            @if($run->outcome_evaluated_at)
                                · Evaluated {{ $run->outcome_evaluated_at->diffForHumans() }}
                            @endif
                        </p>
                        @if(!empty($run->outcome_details['summary']))
                            <p class="text-xs font-mono text-[var(--color-ink-soft)]">{{ $run->outcome_details['summary'] }}</p>
                        @endif
                    </div>
                @else
                    <p class="text-[var(--color-ink-muted)] italic">
                        Pending 60-minute evaluation window. Automatically evaluated by scheduler.
                    </p>
                @endif
            </div>

            <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] space-y-3">
                <span class="font-bold text-[var(--color-ink-strong)] block">Operator Verdict</span>
                <form method="POST" action="{{ route('ai-remedy.verdict', $run) }}" class="space-y-3">
                    @csrf
                    <div class="flex flex-wrap gap-2">
                        @foreach(['correct' => 'text-emerald-600 border-emerald-500/40 bg-emerald-500/10', 'partial' => 'text-amber-600 border-amber-500/40 bg-amber-500/10', 'wrong' => 'text-rose-600 border-rose-500/40 bg-rose-500/10', 'unsure' => 'text-slate-600 border-slate-500/40 bg-slate-500/10'] as $vKey => $vClass)
                            <label class="cursor-pointer">
                                <input type="radio" name="verdict" value="{{ $vKey }}" class="sr-only peer" {{ $run->verdict === $vKey ? 'checked' : '' }}>
                                <span class="px-3 py-1 rounded-lg border text-xs font-semibold peer-checked:ring-2 peer-checked:ring-offset-1 peer-checked:ring-indigo-500 block {{ $vClass }}">
                                    {{ ucfirst($vKey) }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <div>
                        <input type="text" name="note" value="{{ old('note', $run->verdict_note) }}" placeholder="Optional operator feedback note..." class="w-full text-xs font-data border border-[var(--color-border)] rounded-md px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500 bg-[var(--color-surface)]">
                    </div>
                    <div class="flex items-center justify-between">
                        @if($run->verdict)
                            <span class="text-[11px] text-[var(--color-ink-muted)]">
                                Set by {{ $run->verdictByUser?->name ?? 'Operator' }} {{ $run->verdict_at?->diffForHumans() }}
                            </span>
                        @else
                            <span></span>
                        @endif
                        <button type="submit" class="btn-primary text-xs py-1 px-3">
                            <i class="fa-solid fa-check mr-1"></i> Save Verdict
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Executive Summary Card --}}
    <div class="card p-6 space-y-3">
        <h3 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)]">Executive Summary & Culprit</h3>
        <div class="p-4 rounded-xl bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] text-sm leading-relaxed font-medium">
            {{ $run->diagnosis_summary }}
        </div>
        <div class="flex items-center gap-2 text-xs">
            <span class="font-bold text-[var(--color-ink-muted)]">Root Cause:</span>
            <span class="font-semibold text-[var(--color-ink-strong)]">{{ $run->root_cause ?: 'Unspecified' }}</span>
        </div>
    </div>

    {{-- Commands Card --}}
    <div class="card p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)]">
                {{ $run->isWatchMode() ? 'What AiRemedy Would Have Done' : 'Remediation Commands (SSH)' }}
            </h3>
            @if($run->isWatchMode())
                <span class="text-xs font-semibold text-amber-600 dark:text-amber-400">
                    <i class="fa-solid fa-ban mr-1"></i>Not executed (Watch Mode)
                </span>
            @endif
        </div>
        
        @if(!empty($run->approved_commands))
            <div>
                <span class="text-xs font-semibold text-[var(--color-ink-muted)] block mb-1.5">Executed Commands:</span>
                <div class="bg-slate-950 border border-slate-800 text-slate-100 p-4 rounded-xl font-mono text-xs overflow-x-auto space-y-1.5 shadow-sm">
                    @foreach($run->approved_commands as $cmd)
                        <div class="flex items-center gap-2"><span class="text-emerald-400 font-bold select-none">$</span> <span>{{ $cmd }}</span></div>
                    @endforeach
                </div>
            </div>
        @elseif(!empty($run->proposed_commands) && ! $run->isWatchMode())
            {{-- Copilot approval: pick which proposed commands to run --}}
            @include('ai-remedy::_review_panel', [
                'reviewExpr' => \Illuminate\Support\Js::from($review),
                'runIdExpr' => (int) $run->id,
            ])
        @elseif(!empty($run->proposed_commands))
            <div>
                <span class="text-xs font-semibold text-[var(--color-ink-muted)] block mb-1.5">
                    {{ $run->isWatchMode() ? 'Proposed Commands (Shadow Mode):' : 'Proposed Commands (Pending Human Review):' }}
                </span>
                <div class="bg-slate-950 border border-slate-800 text-slate-100 p-4 rounded-xl font-mono text-xs overflow-x-auto space-y-1.5 shadow-sm">
                    @foreach($run->proposed_commands as $cmd)
                        <div class="flex items-center gap-2"><span class="text-indigo-400 font-bold select-none">$</span> <span>{{ $cmd }}</span></div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Terminal Output (if executed) --}}
        @if($run->execution_output)
            <div>
                <span class="text-xs font-semibold text-[var(--color-ink-muted)] block mb-1.5">Terminal Output (stdout/stderr):</span>
                <pre class="bg-slate-950 border border-slate-800 text-slate-200 p-4 rounded-xl font-mono text-xs overflow-x-auto max-h-72 whitespace-pre-wrap shadow-inner">{{ $run->execution_output }}</pre>
            </div>
        @endif
    </div>

    @include('ai-remedy::_review_script')

    {{-- Telemetry Snapshot --}}
    @if(!empty($run->telemetry_snapshot))
        <div class="card p-6 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)]">Pre-Remediation Telemetry Snapshot</h3>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                @if(isset($run->telemetry_snapshot['loadavg']))
                    <div class="p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                        <span class="text-[11px] text-[var(--color-ink-muted)] block">Load Averages</span>
                        <span class="font-data font-bold text-sm">{{ implode(', ', $run->telemetry_snapshot['loadavg']) }}</span>
                    </div>
                @endif

                @if(isset($run->telemetry_snapshot['memory']['used_percent']))
                    <div class="p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                        <span class="text-[11px] text-[var(--color-ink-muted)] block">Memory Used</span>
                        <span class="font-data font-bold text-sm">{{ $run->telemetry_snapshot['memory']['used_percent'] }}%</span>
                    </div>
                @endif

                @if(isset($run->telemetry_snapshot['disk']['use_pct']))
                    <div class="p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                        <span class="text-[11px] text-[var(--color-ink-muted)] block">Disk Used</span>
                        <span class="font-data font-bold text-sm">{{ $run->telemetry_snapshot['disk']['use_pct'] }}</span>
                    </div>
                @endif

                @if(isset($run->telemetry_snapshot['cores']))
                    <div class="p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                        <span class="text-[11px] text-[var(--color-ink-muted)] block">CPU Cores</span>
                        <span class="font-data font-bold text-sm">{{ $run->telemetry_snapshot['cores'] }} vCPU</span>
                    </div>
                @endif
            </div>

            @if(!empty($run->telemetry_snapshot['top_cpu']))
                <div>
                    <span class="text-xs font-semibold text-[var(--color-ink-muted)] block mb-1">Top CPU Processes at Spike:</span>
                    <div class="overflow-x-auto border border-[var(--color-border-light)] rounded-lg">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] uppercase text-[10px]">
                                <tr>
                                    <th class="p-2">User</th>
                                    <th class="p-2">PID</th>
                                    <th class="p-2">%CPU</th>
                                    <th class="p-2">%MEM</th>
                                    <th class="p-2">Command</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--color-border-light)] font-mono text-[11px]">
                                @foreach($run->telemetry_snapshot['top_cpu'] as $proc)
                                    <tr>
                                        <td class="p-2">{{ $proc['user'] ?? '—' }}</td>
                                        <td class="p-2">{{ $proc['pid'] ?? '—' }}</td>
                                        <td class="p-2 font-bold text-amber-500">{{ $proc['cpu_pct'] ?? '—' }}%</td>
                                        <td class="p-2">{{ $proc['mem_pct'] ?? '—' }}%</td>
                                        <td class="p-2 truncate max-w-xs">{{ $proc['command'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
@endsection
