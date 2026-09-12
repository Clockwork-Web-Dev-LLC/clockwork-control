@extends('layouts.app')

@section('title', 'Scheduled Jobs · Clockwork')

@section('content')
    @include('settings._tabs')

    <x-page-header title="Scheduled Jobs"
        subtitle="Every entry in the live cron schedule, its last outcome, and a Run now button — so a silently failing job never goes unnoticed again.">
    </x-page-header>

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
        </div>
    @endif
    @if (session('queue_error'))
        <div class="card p-4 mb-6 status-red flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i> {{ session('queue_error') }}
        </div>
    @endif

    @php
        $failing = $jobs->where('last_status', 'failed')->count();
        $gated = $jobs->where('currently_gated', true)->count();
        $neverRun = $jobs->whereNull('last_status')->count();
    @endphp

    {{-- Roll-up metric tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 max-w-4xl">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Total Jobs</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ $jobs->count() }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">In the live schedule</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Failing</div>
            <div class="text-2xl font-display {{ $failing > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-strong)]' }} font-data">{{ $failing }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Last run errored</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Currently Gated</div>
            <div class="text-2xl font-display {{ $gated > 0 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-ink-strong)]' }} font-data">{{ $gated }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Would skip right now</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Never Run</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ $neverRun }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">No history yet</div>
        </div>
    </div>

    <div class="card p-0 overflow-hidden" x-data="{ q: '' }">
        <div class="p-4 border-b border-[var(--color-border-light)]">
            <input type="text" x-model="q" placeholder="Filter by command…"
                   class="w-full max-w-sm text-sm rounded-md border border-[var(--color-border-light)] bg-[var(--color-surface)] px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]/20">
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] border-b border-[var(--color-border-light)]">
                    <tr>
                        <th class="px-4 py-2.5">Command</th>
                        <th class="px-4 py-2.5">Schedule</th>
                        <th class="px-4 py-2.5">Last Run</th>
                        <th class="px-4 py-2.5">Status</th>
                        <th class="px-4 py-2.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($jobs as $job)
                        <tr x-show="q === '' || '{{ strtolower($job['command']) }}'.includes(q.toLowerCase())">
                            <td class="px-4 py-3 align-top">
                                <div class="font-data text-xs text-[var(--color-ink-strong)]">{{ $job['command'] }}</div>
                                @if ($job['description'])
                                    <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">{{ $job['description'] }}</div>
                                @endif
                                @if ($job['runs_in_background'])
                                    <div class="text-[10px] text-[var(--color-ink-soft)] mt-1" title="Backgrounded jobs report a successful launch here, not necessarily final success — check its own log for the real outcome.">
                                        <i class="fa-solid fa-circle-info"></i> Runs in background
                                    </div>
                                @endif
                                @if ($job['currently_gated'])
                                    <div class="text-[10px] text-[var(--color-status-yellow)] mt-1">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                        Currently skipped — condition not met
                                        @if ($job['gate_hint'])
                                            · <a href="{{ route($job['gate_hint']['route']) }}" class="underline hover:no-underline">Enable in {{ $job['gate_hint']['label'] }}</a>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top font-data text-xs text-[var(--color-ink-muted)]">
                                {{ $job['expression'] }}
                                @if ($job['next_due'])
                                    <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Next {{ $job['next_due'] }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top text-xs text-[var(--color-ink-muted)]">
                                @if ($job['last_ran_at'])
                                    {{ $job['last_ran_at']->diffForHumans() }}
                                    @if ($job['last_duration_ms'] !== null)
                                        <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">{{ number_format($job['last_duration_ms']) }}ms</div>
                                    @endif
                                @else
                                    <span class="text-[var(--color-ink-soft)]">never</span>
                                @endif
                                @if ($job['last_status'] === 'failed' && $job['last_output'])
                                    <div class="text-[10px] text-[var(--color-status-red)] mt-1 max-w-xs truncate" title="{{ $job['last_output'] }}">{{ $job['last_output'] }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top">
                                @if ($job['last_status'] === 'success')
                                    <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">OK</span>
                                @elseif ($job['last_status'] === 'failed')
                                    <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-red-50 text-red-700 dark:bg-red-950/60 dark:text-red-400 border border-red-200 dark:border-red-800">Failed</span>
                                @elseif ($job['last_status'] === 'skipped')
                                    <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-400 border border-amber-200 dark:border-amber-800">Skipped</span>
                                @else
                                    <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] border border-[var(--color-border-light)]">Never run</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top text-right">
                                <form method="POST" action="{{ route('settings.scheduled-jobs.run') }}">
                                    @csrf
                                    <input type="hidden" name="command" value="{{ $job['command'] }}">
                                    <button type="submit" class="text-xs px-3 py-1.5 rounded-md border border-[var(--color-border-light)] hover:bg-[var(--color-surface-alt)] whitespace-nowrap">
                                        <i class="fa-solid fa-play text-[10px] mr-1"></i> Run now
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="p-3 border-t border-[var(--color-border-light)] text-[10px] text-[var(--color-ink-soft)]">
            History is kept {{ $retentionDays }} days (<code class="font-data">clockwork:prune-scheduled-job-runs</code>). "Currently skipped" reflects each job's own <code class="font-data">-&gt;when()</code>/<code class="font-data">-&gt;skip()</code> gate evaluated right now — not exhaustive of every setting that could cause a no-op.
        </div>
    </div>
@endsection
