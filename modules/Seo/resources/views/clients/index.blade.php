@extends('layouts.app')

@section('title', 'SEO clients · Clockwork')

@section('content')
<x-page-header title="SEO clients" subtitle="Every site with an SEO profile, with last month's Google clicks and whether its data is syncing.">
    <x-slot:actions>
        <a href="{{ route('seo.clients.create') }}" class="btn-primary text-sm"><i class="fa-solid fa-plus"></i> Add client</a>
    </x-slot:actions>
</x-page-header>

@include('seo::_nav')
@include('seo::_flash')

@if (! $setupComplete || $profiles->isEmpty())
    <section class="card mb-6" data-setup-checklist>
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-list-check text-[var(--color-ink-soft)] mr-2"></i>
                Getting started
            </h2>
            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Do these in order. Everything uses your own accounts and keys.</p>
        </div>
        <ol class="divide-y divide-[var(--color-border-light)]">
            @foreach ($steps as $i => $step)
                <li class="px-5 py-3 flex items-start gap-3" data-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? '1' : '0' }}">
                    <span class="mt-0.5 w-5 text-center">
                        @if ($step['done'])
                            <i class="fa-solid fa-circle-check text-[var(--color-status-green)]" aria-label="Done"></i>
                        @else
                            <span class="text-sm font-semibold text-[var(--color-ink-muted)]">{{ $i + 1 }}</span>
                        @endif
                    </span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">
                            {{ $step['title'] }}
                            @if ($step['optional'])
                                <span class="font-normal text-xs text-[var(--color-ink-soft)]">optional</span>
                            @endif
                        </p>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">{{ $step['detail'] }}</p>
                    </div>
                    @if (! $step['done'] && $step['url'])
                        @if (! $step['admin'] || auth()->user()?->isAdmin())
                            <a href="{{ $step['url'] }}" class="btn-pill-nav text-xs whitespace-nowrap">{{ $step['action'] }}</a>
                        @else
                            <span class="text-xs text-[var(--color-ink-soft)] whitespace-nowrap">Ask an admin</span>
                        @endif
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif

<section class="card">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
            <i class="fa-solid fa-users text-[var(--color-ink-soft)] mr-2"></i>
            Clients
        </h2>
        <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
            {{ $profiles->count() }} {{ Str::plural('client', $profiles->count()) }} · clicks are Google Search clicks for {{ $month->format('F Y') }}.
        </p>
    </div>

    @if ($profiles->isEmpty())
        <div class="px-5 py-10 text-center">
            <p class="text-sm text-[var(--color-ink-muted)] mb-4">No SEO clients yet. Add one with just its domain.</p>
            <a href="{{ route('seo.clients.create') }}" class="btn-primary text-sm"><i class="fa-solid fa-plus"></i> Add your first client</a>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                    <tr>
                        <th class="px-5 py-2 font-semibold">Site</th>
                        <th class="px-5 py-2 font-semibold">Status</th>
                        <th class="px-5 py-2 font-semibold text-right">Clicks</th>
                        <th class="px-5 py-2 font-semibold text-right">vs month before</th>
                        <th class="px-5 py-2 font-semibold">Last sync</th>
                        <th class="px-5 py-2 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($profiles as $p)
                        @php
                            $state = $p->state();
                            $pill = match ($state) {
                                'ok' => 'status-green',
                                'syncing' => 'status-yellow',
                                'sync_error' => 'status-red',
                                default => 'status-unknown',
                            };
                            // A month with no stored rows is unknown, never zero.
                            $now = $clicks[$p->site_id] ?? null;
                            $before = $previousClicks[$p->site_id] ?? null;
                            $change = ($now !== null && $before !== null && (int) $before > 0)
                                ? round(((int) $now - (int) $before) / (int) $before * 100, 1)
                                : null;
                            $lastSync = collect([$p->last_gsc_synced_at, $p->last_ga4_synced_at])->filter()->max();
                        @endphp
                        <tr data-client-row="{{ $p->site_id }}">
                            <td class="px-5 py-2">
                                <a href="{{ route('seo.sites.show', $p->site) }}" class="font-data text-[var(--color-primary-600)] hover:underline">{{ $p->site->domain }}</a>
                                @if ($p->site->client)
                                    <span class="block text-xs text-[var(--color-ink-soft)]">{{ $p->site->client->name }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-2">
                                <span class="status-pill {{ $pill }} text-[10px] whitespace-nowrap">{{ $p->stateLabel() }}</span>
                            </td>
                            <td class="px-5 py-2 text-right font-data">{{ $now !== null ? number_format((int) $now) : '—' }}</td>
                            <td class="px-5 py-2 text-right font-data">
                                @if ($change === null)
                                    <span class="text-[var(--color-ink-soft)]">—</span>
                                @else
                                    <span class="{{ $change >= 0 ? 'text-[var(--color-status-green)]' : 'text-[var(--color-status-red)]' }}">{{ $change > 0 ? '+' : '' }}{{ $change }}%</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $lastSync?->diffForHumans() ?? 'Never' }}</td>
                            <td class="px-5 py-2 text-right whitespace-nowrap">
                                <a href="{{ route('seo.sites.show', $p->site) }}" class="btn-pill-nav text-xs">Numbers</a>
                                <a href="{{ route('seo.sites.rankings', $p->site) }}" class="btn-pill-nav text-xs">Rankings</a>
                                <a href="{{ route('seo.sites.keyword-map', $p->site) }}" class="btn-pill-nav text-xs">Map</a>
                                <a href="{{ route('seo.sites.research', $p->site) }}" class="btn-pill-nav text-xs">Research</a>
                                <a href="{{ route('seo.sites.profile', $p->site) }}" class="btn-pill-nav text-xs">Profile</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
