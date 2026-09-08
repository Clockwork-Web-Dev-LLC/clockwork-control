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
        subtitle="Controls when Clockwork pulls active blocks from per-site security plugins. The window and cadence are shared; each source has an independent enable toggle." />

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
