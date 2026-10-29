@extends('layouts.app')

@section('title', 'SEO · Clockwork')

@section('content')
<x-page-header title="SEO"
    subtitle="Search engine indexability across the fleet, checked on every uptime probe (robots.txt daily)." />

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <x-stat-box label="Indexable" :value="$totals['indexable']" icon="fa-circle-check" />
    <x-stat-box label="Blocking search" :value="$totals['blocking']" icon="fa-triangle-exclamation" />
    <x-stat-box label="Not checked yet" :value="$totals['unchecked']" icon="fa-hourglass-half" />
    <x-stat-box label="Monitoring off" :value="$totals['disabled']" icon="fa-eye-slash" />
</div>

<section class="card">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
            <i class="fa-solid fa-magnifying-glass text-[var(--color-ink-soft)] mr-2"></i>
            Indexability
        </h2>
        <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
            {{ $totals['sites'] }} monitored {{ Str::plural('site', $totals['sites']) }} · sites needing attention first.
        </p>
    </div>

    @if ($sites->isEmpty())
        <p class="px-5 py-8 text-sm text-[var(--color-ink-muted)] text-center">No monitored sites yet.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                    <tr>
                        <th class="px-5 py-2 font-semibold">Site</th>
                        <th class="px-5 py-2 font-semibold">Server</th>
                        <th class="px-5 py-2 font-semibold">Status</th>
                        <th class="px-5 py-2 font-semibold">Reason</th>
                        <th class="px-5 py-2 font-semibold">Checked</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($sites as $s)
                        @php
                            $unchecked = $s->seo_monitoring_enabled && $s->seo_checked_at === null;
                            [$pillClass, $statusLabel] = match (true) {
                                ! $s->seo_monitoring_enabled => ['status-unknown', 'Disabled'],
                                ! $s->seo_indexable => ['status-red', 'Blocking Search Engines'],
                                $unchecked => ['status-yellow', 'Not checked yet'],
                                default => ['status-green', 'Indexable'],
                            };
                            $reasonLabel = match ($s->seo_blocked_reason) {
                                'meta_noindex' => 'Meta noindex',
                                'header_noindex' => 'X-Robots-Tag',
                                'robots_disallow_all' => 'robots.txt Disallow',
                                'wp_blog_public_zero' => 'WordPress "Discourage search engines"',
                                default => $s->seo_blocked_reason,
                            };
                        @endphp
                        <tr data-site-row="{{ $s->id }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                @if ($s->server)
                                    <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server->name }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-2">
                                <span class="status-pill {{ $pillClass }} text-[10px] whitespace-nowrap">{{ $statusLabel }}</span>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink)]">
                                @if (! $s->seo_indexable && $reasonLabel)
                                    {{ $reasonLabel }}
                                @else
                                    <span class="text-[var(--color-ink-soft)]">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)]">
                                {{ $s->seo_checked_at?->diffForHumans() ?? 'never' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
