@extends('layouts.app')

@section('title', 'Scheduling · Clockwork')

@php
    use Illuminate\Support\Carbon;

    $sourceLabels = [
        'llar' => [
            'name' => 'Limit Login Attempts Reloaded',
            'short' => 'LLAR',
            'desc' => 'Reads each WordPress site\'s LLAR plugin tables for active failed-login lockouts.',
            'icon' => 'fa-key',
        ],
        'wordfence' => [
            'name' => 'Wordfence',
            'short' => 'Wordfence',
            'desc' => 'Reads <code>wfBlocks7</code> on each site where Wordfence is installed. Filters to IP-targeting block types only (excludes country/pattern blocks).',
            'icon' => 'fa-shield',
        ],
    ];
@endphp

@section('content')
    @include('settings._tabs')

    <x-page-header title="Scheduling"
        subtitle="Plugin pull window plus how long raw nginx rows stay in threat_logs. Daily traffic rollups are kept forever." />

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            {{ session('status') }}
        </div>
    @endif
    @if (session('queue_error'))
        <div class="card p-4 mb-6 status-red flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i>
            {{ session('queue_error') }}
        </div>
    @endif

    <form method="POST" action="{{ route('settings.ingest.update') }}" class="card p-6 max-w-2xl">
        @csrf
        @method('PATCH')

        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-4">Window &amp; cadence</h2>

        <div x-data="{ alwaysOn: {{ old('always_on', $config['always_on']) ? 'true' : 'false' }} }">
            <label class="flex items-start gap-3 mb-5 cursor-pointer">
                <input type="checkbox" name="always_on" value="1" x-model="alwaysOn"
                       class="mt-1 rounded border-[var(--color-border)]">
                <div class="flex-1">
                    <span class="font-medium text-[var(--color-ink-strong)]">Run 24/7</span>
                    <span class="text-xs text-[var(--color-ink-soft)] block mt-0.5">
                        No window — pulls run continuously at the cadence below. Uncheck to constrain to a daily window.
                    </span>
                </div>
            </label>

            <div :class="alwaysOn ? 'opacity-40 pointer-events-none' : ''">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                    <label class="block">
                        <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Window start</span>
                        <input type="time" name="start_time" value="{{ old('start_time', $config['start_time']) }}" required
                               class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                        @error('start_time') <span class="text-xs text-[var(--color-status-red)] mt-1 block">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Window end</span>
                        <input type="time" name="end_time" value="{{ old('end_time', $config['end_time']) }}" required
                               class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                        @error('end_time') <span class="text-xs text-[var(--color-status-red)] mt-1 block">{{ $message }}</span> @enderror
                    </label>
                </div>
                <p class="text-xs text-[var(--color-ink-soft)] mb-5">
                    Wall-clock times in the timezone below. If end is earlier than start, the window wraps past midnight.
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Timezone</span>
                <select name="timezone"
                        class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                    @foreach ($timezones as $tz)
                        <option value="{{ $tz }}" {{ $config['timezone'] === $tz ? 'selected' : '' }}>{{ $tz }}</option>
                    @endforeach
                    @if (! in_array($config['timezone'], $timezones, true))
                        <option value="{{ $config['timezone'] }}" selected>{{ $config['timezone'] }} (custom)</option>
                    @endif
                </select>
                @error('timezone') <span class="text-xs text-[var(--color-status-red)] mt-1 block">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Run every (minutes)</span>
                <input type="number" name="frequency_minutes" value="{{ old('frequency_minutes', $config['frequency_minutes']) }}"
                       min="5" max="1440" required
                       class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                @error('frequency_minutes') <span class="text-xs text-[var(--color-status-red)] mt-1 block">{{ $message }}</span> @enderror
                <span class="text-xs text-[var(--color-ink-soft)] mt-1 block">e.g. 60 = once per hour during the window.</span>
            </label>
        </div>

        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-3">Sources</h2>
        <div class="divide-y divide-[var(--color-border-light)] mb-6">
            @foreach ($sourceLabels as $key => $meta)
                @php $src = $config['sources'][$key] ?? ['enabled' => true, 'last_run_at' => null]; @endphp
                <label class="flex items-start gap-3 py-4 cursor-pointer">
                    <input type="checkbox" name="sources[{{ $key }}][enabled]" value="1" {{ $src['enabled'] ? 'checked' : '' }}
                           class="mt-1 rounded border-[var(--color-border)]">
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid {{ $meta['icon'] }} text-[var(--color-ink-muted)]"></i>
                            <span class="font-medium text-[var(--color-ink-strong)]">{{ $meta['name'] }}</span>
                            @if ($src['last_run_at'])
                                <span class="text-xs text-[var(--color-ink-soft)]">
                                    · last run {{ Carbon::parse($src['last_run_at'])->diffForHumans() }}
                                </span>
                            @endif
                        </div>
                        <div class="text-xs text-[var(--color-ink-muted)] mt-0.5">{!! $meta['desc'] !!}</div>
                    </div>
                </label>
            @endforeach
        </div>

        <div class="flex items-center gap-3 pt-4 border-t border-[var(--color-border-light)]">
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-floppy-disk"></i> Save schedule
            </button>
            <a href="{{ route('dashboard') }}" class="btn-pill-nav">Cancel</a>
        </div>
    </form>

    <div class="card p-6 max-w-2xl mt-6"
         x-data="{
            amount: {{ (int) old('retention_amount', $retentionState['amount']) }},
            unit: @js(old('retention_unit', $retentionState['unit'])),
            get days() {
                const n = Number(this.amount) || 0;
                return this.unit === 'weeks' ? n * 7 : n;
            }
         }">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1">Raw nginx log retention</h2>
        <p class="text-xs text-[var(--color-ink-muted)] mb-4">
            <code class="font-data text-xs">threat_logs</code> is every access-log line. Nightly prune deletes rows older than this window. On MySQL the table is monthly-partitioned so dropped months free disk; chunked DELETE is the fallback. <code class="font-data text-xs">site_traffic_daily</code> rollups are not touched.
        </p>

        @if (! empty($partitionStatus['supported']))
            @if (! empty($partitionStatus['partitioned']))
                <div class="rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] p-3 mb-5">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                <i class="fa-solid fa-circle-check text-[10px]"></i> Monthly partitioned
                            </span>
                            <span class="text-xs font-data text-[var(--color-ink-muted)]">
                                {{ count($partitionStatus['partitions']) }} partition(s) active
                            </span>
                        </div>
                        <span class="text-xs font-data text-[var(--color-ink-soft)]">
                            ~{{ number_format($partitionStatus['row_count']) }} rows · {{ round(($partitionStatus['data_bytes'] + $partitionStatus['index_bytes']) / 1024 / 1024 / 1024, 1) }} GB
                        </span>
                    </div>
                    <div class="text-[11px] text-[var(--color-ink-soft)] font-data">
                        Active: {{ implode(', ', $partitionStatus['partitions']) }}
                    </div>
                </div>
            @else
                <div class="rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50 p-4 mb-5">
                    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200">
                                    <i class="fa-solid fa-triangle-exclamation text-[10px]"></i> Unpartitioned table
                                </span>
                                <span class="text-xs font-data text-[var(--color-ink-soft)]">
                                    ~{{ number_format($partitionStatus['row_count']) }} rows · {{ round(($partitionStatus['data_bytes'] + $partitionStatus['index_bytes']) / 1024 / 1024 / 1024, 1) }} GB
                                </span>
                            </div>
                            <p class="text-xs text-[var(--color-ink-muted)] mb-2">
                                Your <code class="font-data text-xs">threat_logs</code> table is a single InnoDB file. Pruning runs slow chunked DELETEs and <strong>does not reclaim disk space</strong>. Rebuilding into monthly partitions copies your retention window into clean partitions and drops the old table, freeing gigabytes.
                            </p>
                            <p class="text-xs text-[var(--color-ink-soft)] font-data">
                                CLI: php artisan clockwork:rebuild-threat-logs-partitions
                            </p>
                        </div>
                        <form method="POST" action="{{ route('settings.ingest.rebuildPartitions') }}">
                            @csrf
                            <button type="submit" class="btn-pill-nav shrink-0 whitespace-nowrap">
                                <i class="fa-solid fa-arrows-rotate"></i> Rebuild partitions
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        @endif

        <form method="POST" action="{{ route('settings.ingest.retention') }}" class="mb-5">
            @csrf
            @method('PATCH')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-4">
                <label class="block">
                    <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Keep</span>
                    <input type="number" name="retention_amount" x-model.number="amount"
                           min="1" max="365" required
                           value="{{ old('retention_amount', $retentionState['amount']) }}"
                           class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                    @error('retention_amount') <span class="text-xs text-[var(--color-status-red)] mt-1 block">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Unit</span>
                    <select name="retention_unit" x-model="unit"
                            class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                        <option value="days" @selected(old('retention_unit', $retentionState['unit']) === 'days')>Days</option>
                        <option value="weeks" @selected(old('retention_unit', $retentionState['unit']) === 'weeks')>Weeks</option>
                    </select>
                    @error('retention_unit') <span class="text-xs text-[var(--color-status-red)] mt-1 block">{{ $message }}</span> @enderror
                </label>
            </div>
            <p class="text-xs text-[var(--color-ink-muted)] mb-4">
                That is <span class="font-data text-[var(--color-ink-strong)]" x-text="days">{{ $retentionState['days'] }}</span> days.
                Minimum {{ \App\Services\Logs\ThreatLogRetention::MIN_DAYS }} days (live views only read a week of raw rows). Default is 30 days. On MySQL, dropping old months reclaims disk.
            </p>
            @if ($retentionState['last_run_at'])
                <p class="text-xs text-[var(--color-ink-soft)] mb-4 font-data">
                    Last prune {{ Carbon::parse($retentionState['last_run_at'])->diffForHumans() }}
                    · {{ number_format($retentionState['last_deleted']) }} row(s) deleted
                </p>
            @endif
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-floppy-disk"></i> Save retention
            </button>
        </form>

        <form method="POST" action="{{ route('settings.ingest.pruneNow') }}" class="pt-4 border-t border-[var(--color-border-light)]">
            @csrf
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-[var(--color-ink-muted)]">
                    Run the chunked delete now using the saved window. First catch-up on a huge table can take a while.
                </p>
                <button type="submit" class="btn-pill-nav shrink-0">
                    <i class="fa-solid fa-broom"></i> Prune now
                </button>
            </div>
        </form>

        @if (! empty($partitionStatus['supported']) && ! empty($partitionStatus['binlog_expire_seconds']))
            <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-start gap-2 text-[11px] text-[var(--color-ink-soft)]">
                <i class="fa-solid fa-database mt-0.5 text-[var(--color-ink-muted)]"></i>
                <span>
                    <strong>MySQL Binary Logs:</strong> Expire setting is <code class="font-data text-[11px]">{{ number_format($partitionStatus['binlog_expire_seconds']) }}s</code> (~{{ round($partitionStatus['binlog_expire_seconds'] / 86400, 1) }} days). On standalone/local MySQL without replication, high ingest churn can fill disk with binary logs. Setting <code class="font-data text-[11px]">binlog_expire_logs_seconds = 259200</code> (3 days) in <code class="font-data text-[11px]">my.cnf</code> keeps log disk usage lean.
                </span>
            </div>
        @endif
    </div>

    {{-- Per-source ad-hoc trigger. Each in its own form so saving the schedule is independent. --}}
    <div class="card p-6 max-w-2xl mt-6">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1">Run now</h2>
        <p class="text-xs text-[var(--color-ink-muted)] mb-4">Trigger an ad-hoc pull, ignoring the window. Useful for testing.</p>

        <div class="space-y-3">
            @foreach ($sourceLabels as $key => $meta)
                @php $src = $config['sources'][$key] ?? ['enabled' => true, 'last_run_at' => null]; @endphp
                <form method="POST" action="{{ route('settings.ingest.runNow') }}"
                      class="flex items-center justify-between gap-3 py-2 border-b border-[var(--color-border-light)] last:border-0">
                    @csrf
                    <input type="hidden" name="source" value="{{ $key }}">
                    <div class="flex-1">
                        <div class="text-sm font-medium text-[var(--color-ink-strong)]">
                            <i class="fa-solid {{ $meta['icon'] }} text-[var(--color-ink-muted)] mr-1"></i>
                            {{ $meta['short'] }}
                        </div>
                        @if ($src['last_run_at'])
                            <div class="text-xs text-[var(--color-ink-soft)] mt-0.5 font-data">
                                Last run: {{ Carbon::parse($src['last_run_at'])->diffForHumans() }}
                            </div>
                        @else
                            <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">No recorded runs yet.</div>
                        @endif
                    </div>
                    <button type="submit" class="btn-pill-nav">
                        <i class="fa-solid fa-play"></i> Run now
                    </button>
                </form>
            @endforeach
        </div>
    </div>
@endsection
