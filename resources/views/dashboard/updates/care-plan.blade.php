@extends('layouts.app')

@php $carePlansEnabled = \App\Models\Site::areCarePlansEnabled(); @endphp

@section('title', ($carePlansEnabled ? 'Care-plan auto-updates' : 'Nightly auto-updates') . ' · Clockwork')

@section('content')
    <x-page-header title="{{ $carePlansEnabled ? 'Care-plan auto-updates' : 'Nightly auto-updates' }}"
        subtitle="{{ $carePlansEnabled ? 'Off by default. Enable individual sites here to put them on the nightly auto-update path (plugins only, 2:00–6:00 AM Eastern). Disable to take a site back off without dropping the care plan.' : 'Manage which fleet sites are on the nightly auto-update path (plugins only, 2:00–6:00 AM Eastern). Active sites update automatically unless paused.' }}">
        <x-slot:actions>
            <a href="{{ route('updates.index') }}" class="text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-arrow-left"></i> Back to updates
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card p-4 mb-4 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
        </div>
    @endif
    @if (session('status_error'))
        <div class="card p-4 mb-4 status-red flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i> {{ session('status_error') }}
        </div>
    @endif

    {{-- Page-scope Alpine: each toggle dispatches au-toggled with a delta
         (+1 means a site switched ON, -1 means OFF) and the counter strip
         updates without reloading. --}}
    <div x-data="{ active: {{ $totals['active'] }}, paused: {{ $totals['paused'] }} }"
         @au-toggled.window="active += $event.detail.delta; paused -= $event.detail.delta;">

        {{-- Counter strip — at-a-glance summary of the curation state --}}
        <div class="grid grid-cols-3 gap-3 mb-4">
            <div class="card p-4">
                <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">{{ $carePlansEnabled ? 'Care-plan sites' : 'Fleet sites' }}</div>
                <div class="font-display text-2xl text-[var(--color-ink-strong)]">{{ $totals['total'] }}</div>
            </div>
            <div class="card p-4">
                <div class="text-xs uppercase tracking-wide text-[var(--color-status-green)]">Auto-updates ON</div>
                <div class="font-display text-2xl text-[var(--color-status-green)]" x-text="active">{{ $totals['active'] }}</div>
            </div>
            <div class="card p-4">
                <div class="text-xs uppercase tracking-wide text-[var(--color-status-red)]">Auto-updates OFF</div>
                <div class="font-display text-2xl text-[var(--color-status-red)]" x-text="paused">{{ $totals['paused'] }}</div>
            </div>
        </div>

        {{-- Auto-ignore policy settings --}}
        <div class="card p-4 mb-4">
            <form method="POST" action="{{ route('updates.settings.update') }}" class="flex items-center justify-between gap-4 flex-wrap text-sm">
                @csrf
                <div>
                    <div class="font-medium text-[var(--color-ink-strong)] flex items-center gap-1.5">
                        <i class="fa-solid fa-pause text-[var(--color-status-yellow)]"></i>
                        Repeated failure auto-ignore policy
                    </div>
                    <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                        Pause automatic updates for a plugin or theme after repeated nightly failures. Other plugins continue updating.
                    </div>
                </div>

                <div class="flex items-center gap-4 flex-wrap">
                    <label class="flex items-center gap-2 text-xs">
                        <span class="text-[var(--color-ink-muted)]">Pause after:</span>
                        <input type="number" name="auto_ignore_after_failures" min="3" max="20"
                               value="{{ $autoIgnoreThreshold ?? 5 }}"
                               class="w-16 px-2 py-1 border border-[var(--color-border)] rounded text-center bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-xs">
                        <span class="text-[var(--color-ink-soft)]">failures</span>
                    </label>

                    <label class="flex items-center gap-2 text-xs">
                        <input type="hidden" name="auto_ignore_enabled" value="0">
                        <input type="checkbox" name="auto_ignore_enabled" value="1"
                               {{ ($autoIgnoreEnabled ?? true) ? 'checked' : '' }}
                               class="rounded">
                        <span class="text-[var(--color-ink-strong)]">Enabled</span>
                    </label>

                    <button type="submit" class="btn-pill-nav text-xs">
                        Save policy
                    </button>
                </div>
            </form>
        </div>

    @if ($sites->isEmpty())
        <div class="card p-6 text-sm text-[var(--color-ink-muted)] text-center">
            {{ $carePlansEnabled ? "No sites are currently on a care plan. Toggle a site's care-plan flag from its Settings tab to add it here." : "No eligible sites found in the fleet." }}
        </div>
    @else
        <div class="card overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-4 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-4 py-2">Server</x-sort-th>
                        <x-sort-th key="last" class="px-4 py-2">Last considered</x-sort-th>
                        <x-sort-th key="state" class="px-4 py-2 text-right">Auto-updates</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($sites as $site)
                        @php
                            $autoOn = ! $site->auto_updates_paused;
                            $lastRun = $site->auto_updates_last_run_at;
                        @endphp
                        <tr data-sort-site="{{ $site->domain }}"
                            data-sort-server="{{ $site->server?->name ?? '' }}"
                            data-sort-last="{{ $lastRun?->getTimestamp() ?? 0 }}"
                            data-sort-state="{{ $autoOn ? '0-on' : '1-off' }}">
                            <td class="px-4 py-2">
                                <a href="{{ route('sites.show', $site) }}" class="text-[var(--color-primary-600)] hover:underline font-data">
                                    {{ $site->domain }}
                                </a>
                            </td>
                            <td class="px-4 py-2 text-xs text-[var(--color-ink-muted)] font-data">
                                @if ($site->server)
                                    {{ $site->server->display_name ?? $site->server->name }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-xs text-[var(--color-ink-muted)]">
                                @if ($lastRun)
                                    <span title="{{ $lastRun->toIso8601String() }}">{{ $lastRun->diffForHumans() }}</span>
                                @else
                                    <span class="text-[var(--color-ink-soft)]">never</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{-- AJAX toggle: standard-direction iOS-style switch.
                                     OFF = red track, knob on LEFT.
                                     ON  = green track, knob on RIGHT.
                                     Click submits via fetch — no page reload. Dispatches
                                     `au-toggled` with delta so the parent counter strip
                                     stays in sync.
                                     The <noscript> form is a fallback if Alpine fails to
                                     boot — submits normally and reloads the page. --}}
                                <div x-data="{
                                        on: {{ $autoOn ? 'true' : 'false' }},
                                        busy: false,
                                        async toggle() {
                                            if (this.busy) return;
                                            this.busy = true;
                                            const targetOn = ! this.on;
                                            try {
                                                const res = await fetch('{{ route('sites.auto-updates.toggle', $site) }}', {
                                                    method: 'POST',
                                                    headers: {
                                                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                                        'Accept': 'application/json',
                                                        'Content-Type': 'application/x-www-form-urlencoded',
                                                    },
                                                    body: 'paused=' + (targetOn ? '0' : '1'),
                                                });
                                                const data = await res.json();
                                                if (data.ok) {
                                                    this.on = ! data.auto_updates_paused;
                                                    $dispatch('au-toggled', { delta: this.on ? +1 : -1 });
                                                }
                                            } catch (e) { /* swallow; visual stays */ }
                                            finally { this.busy = false; }
                                        },
                                     }"
                                     style="display: inline-block;">
                                    <button type="button"
                                            role="switch"
                                            :aria-checked="on ? 'true' : 'false'"
                                            aria-label="Toggle nightly auto-updates for {{ $site->domain }}"
                                            @click="toggle()"
                                            :disabled="busy"
                                            class="cw-switch"
                                            :class="{ 'cw-switch--on': on, 'cw-switch--busy': busy }">
                                        <span class="cw-switch__knob"></span>
                                    </button>
                                </div>
                                <noscript>
                                    <form method="POST" action="{{ route('sites.auto-updates.toggle', $site) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="paused" value="{{ $autoOn ? '1' : '0' }}">
                                        <button type="submit" class="btn-pill-nav text-xs">
                                            {{ $autoOn ? 'Turn OFF' : 'Turn ON' }}
                                        </button>
                                    </form>
                                </noscript>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-xs text-[var(--color-ink-soft)] mt-3">
            <i class="fa-solid fa-circle-info"></i>
            "Last considered" is when the nightly loop last evaluated this site — not when a plugin was actually updated.
            Per-update history lives on each site's <span class="font-data">Updates</span> tab.
        </p>
    @endif
    </div> {{-- /page-scope Alpine wrapper for the counter-strip + toggles --}}
@endsection
