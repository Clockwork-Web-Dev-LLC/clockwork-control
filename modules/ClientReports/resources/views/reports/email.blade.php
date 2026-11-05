@extends('layouts.app')

@section('title', 'Email · '.($report->site?->domain ?? $report->title).' · Client Reports')

@php
    $stateClass = \Modules\ClientReports\Support\ReportState::class;
    $categoryClass = \Modules\ClientReports\Support\ReviewReasonCategories::class;
    $state = $stateClass::of($report);
    $query = $nav['query'] ?? [];
    // What pressing Send would do right now, so the reviewer sees the real "To".
    $to = $report->recipientsToSend();
    $toLabel = match (true) {
        $to !== [] => implode(', ', $to),
        $report->recipients() !== [] => 'Everyone on this report already has it',
        default => 'No recipient saved — Send will ask for one',
    };
    $reasons = $stateClass::reasons($report);
    $tabLabels = ['security' => 'Security tab', 'updates' => 'Updates tab', 'traffic' => 'Traffic tab', 'forms' => 'Forms tab', 'overview' => 'site overview'];
@endphp

@section('content')
@include('client-reports::_nav')
@include('client-reports::partials.flash')

{{-- Same stepping and actions as the preview --}}
<div class="flex flex-wrap items-center justify-between gap-2 mb-4" id="email-toolbar">
    <div class="flex flex-wrap items-center gap-1.5">
        <a href="{{ $nav['back_url'] }}" class="cr-btn" data-nav="back"><i class="fa-solid fa-arrow-left"></i>{{ $nav['back_label'] }}</a>
        @if ($nav['previous'])
            <a href="{{ route('client-reports.email', [$nav['previous'], ...$query]) }}" class="cr-btn" data-nav="prev" title="Previous report's email (←)"><i class="fa-solid fa-chevron-left"></i>Previous</a>
        @else
            <span class="cr-btn is-disabled"><i class="fa-solid fa-chevron-left"></i>Previous</span>
        @endif
        @if ($nav['position'])
            <span class="text-[11px] text-[var(--color-ink-muted)] px-1 whitespace-nowrap">{{ $nav['position'] }} of {{ $nav['count'] }}</span>
        @endif
        @if ($nav['next'])
            <a href="{{ route('client-reports.email', [$nav['next'], ...$query]) }}" class="cr-btn" data-nav="next" title="Next report's email (→)">Next<i class="fa-solid fa-chevron-right"></i></a>
        @else
            <span class="cr-btn is-disabled">Next<i class="fa-solid fa-chevron-right"></i></span>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-1.5">
        <a href="{{ route('client-reports.show', [$report, ...$query]) }}" class="cr-btn" data-nav="view" title="The report page, which prints as the PDF (v)"><i class="fa-solid fa-file-lines"></i>Report page</a>
        @include('client-reports::partials.report-actions', ['report' => $report, 'context' => $query, 'hide' => ['view', 'email']])
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_22rem] gap-6 items-start">
    <div class="space-y-4 min-w-0">
        {{-- Envelope --}}
        <div class="card p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <div class="min-w-0">
                    <div class="font-mono text-sm font-semibold text-[var(--color-ink-strong)] break-all">{{ $report->site?->domain }}</div>
                    <div class="text-[11px] text-[var(--color-ink-muted)]">{{ $report->period_start->format('M j') }} – {{ $report->period_end->format('M j, Y') }} · This is the email exactly as the client gets it.</div>
                </div>
                @include('client-reports::partials.state-pill', ['state' => $state])
            </div>
            <dl class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm">
                <dt class="text-[var(--color-ink-muted)]">From</dt>
                <dd class="text-[var(--color-ink-strong)] break-all">{{ $from ?? '' }}</dd>
                <dt class="text-[var(--color-ink-muted)]">To</dt>
                <dd class="text-[var(--color-ink-strong)] break-all">{{ $toLabel }}</dd>
                @if (! empty($report->sent_to))
                    <dt class="text-[var(--color-ink-muted)]">Sent to</dt>
                    <dd class="text-[var(--color-ink-strong)] break-all">{{ implode(', ', $report->sent_to) }}@if ($report->sent_at) <span class="text-[var(--color-ink-muted)] text-xs">· {{ $report->sent_at->format('M j, Y') }}</span>@endif</dd>
                @endif
                <dt class="text-[var(--color-ink-muted)]">Subject</dt>
                <dd class="font-semibold text-[var(--color-ink-strong)]">{{ $subject }}</dd>
            </dl>
        </div>

        {{-- HTML and plain-text parts --}}
        <div class="card overflow-hidden" x-data="{ part: 'html' }">
            <div class="px-4 pt-3 border-b border-[var(--color-border-light)] flex flex-wrap items-end justify-between gap-2">
                <div class="flex gap-1" role="tablist" aria-label="Email part">
                    <button type="button" role="tab" @click="part = 'html'" :aria-selected="part === 'html'"
                            class="px-3 py-2 text-sm font-medium border-b-2 -mb-px" :class="part === 'html' ? 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]' : 'border-transparent text-[var(--color-ink-muted)]'">
                        <i class="fa-solid fa-envelope-open-text text-xs mr-1"></i>HTML email
                    </button>
                    <button type="button" role="tab" @click="part = 'text'" :aria-selected="part === 'text'"
                            class="px-3 py-2 text-sm font-medium border-b-2 -mb-px" :class="part === 'text' ? 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]' : 'border-transparent text-[var(--color-ink-muted)]'">
                        <i class="fa-solid fa-align-left text-xs mr-1"></i>Plain-text version
                    </button>
                </div>
                <span class="text-[11px] text-[var(--color-ink-muted)] pb-2" x-show="part === 'text'" x-cloak>What mail apps that don't show HTML display instead.</span>
            </div>
            <div x-show="part === 'html'" class="p-3 sm:p-4 bg-[var(--color-surface-alt)]">
                {{-- No scripts inside; allow-same-origin only so the frame can be sized to its content. --}}
                <iframe srcdoc="{{ $html }}" sandbox="allow-same-origin" title="Report email" style="display: block; width: 100%; height: 80vh; background: #ffffff; border: 1px solid var(--color-border); border-radius: 12px;"
                    onload="this.style.height = this.contentDocument.documentElement.scrollHeight + 'px'"></iframe>
            </div>
            <div x-show="part === 'text'" x-cloak class="p-4">
                <pre class="text-xs leading-relaxed text-[var(--color-ink-strong)]" style="white-space: pre-wrap; font-family: ui-monospace, monospace;">{{ $text }}</pre>
            </div>
        </div>
    </div>

    {{-- Why it's held --}}
    <aside class="space-y-4 order-first xl:order-none xl:sticky xl:top-4">
        @if ($report->status === \Modules\ClientReports\Models\ClientReport::STATUS_SUPERSEDED)
            <div class="card p-4 text-sm text-[var(--color-ink-muted)]"><i class="fa-solid fa-clone mr-1"></i>A newer report for this schedule and period replaced this one. It can't be sent.</div>
        @elseif ($state === $stateClass::OUTDATED)
            <div class="card p-4 text-sm">
                <div class="font-semibold text-[var(--color-ink-strong)]"><i class="fa-solid fa-clock-rotate-left mr-1"></i>Outdated</div>
                <p class="text-xs text-[var(--color-ink-muted)] mt-1">{{ $stateClass::sendBlockedReason($report) }}</p>
            </div>
        @endif
        @if ($reasons !== [])
            <div class="card p-4 card-status-orange">
                <div class="font-semibold text-sm text-[var(--color-ink-strong)] mb-2"><i class="fa-solid fa-hand text-[var(--color-status-orange)] mr-1"></i>Check these before sending</div>
                <div class="space-y-3">
                    @foreach ($categoryClass::group($reasons) as $category => $texts)
                        @php($tab = $categoryClass::siteTab($category))
                        <div>
                            <div class="flex flex-wrap items-center justify-between gap-1">
                                <span class="cr-chip"><i class="fa-solid {{ $categoryClass::icon($category) }}"></i>{{ $categoryClass::label($category) }}</span>
                                @if ($category === $categoryClass::FIRST_REPORT && $report->schedule)
                                    <a href="{{ route('client-reports.schedules.edit', $report->schedule) }}" class="text-[11px] font-semibold text-[var(--color-brand)] hover:underline">Schedule →</a>
                                @elseif ($tab !== null && $report->site)
                                    <a href="{{ route('sites.show', ['site' => $report->site, 'tab' => $tab]) }}" class="text-[11px] font-semibold text-[var(--color-brand)] hover:underline">{{ ucfirst($tabLabels[$tab] ?? $tab) }} →</a>
                                @endif
                            </div>
                            <ul class="list-disc ml-5 mt-1 space-y-0.5 text-xs text-[var(--color-ink)]">
                                @foreach ($texts as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif ($state !== \Modules\ClientReports\Support\ReportState::SUPERSEDED && $state !== $stateClass::OUTDATED)
            <div class="card p-4 text-xs text-[var(--color-ink-muted)]"><i class="fa-solid fa-circle-check text-[var(--color-status-green)] mr-1"></i>Nothing flagged for review.</div>
        @endif
        <p class="text-[11px] text-[var(--color-ink-soft)]">Keys: <kbd class="cr-chip">←</kbd> <kbd class="cr-chip">→</kbd> previous / next · <kbd class="cr-chip">v</kbd> report page</p>
    </aside>
</div>

<script>
    document.addEventListener('keydown', function (e) {
        if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey || e.shiftKey) { return; }
        var t = e.target;
        if (t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable)) { return; }
        var key = { ArrowLeft: 'prev', ArrowRight: 'next', v: 'view', V: 'view' }[e.key];
        var link = key ? document.querySelector('#email-toolbar a[data-nav="' + key + '"]') : null;
        if (link) { e.preventDefault(); window.location.href = link.href; }
    });
</script>
@endsection
