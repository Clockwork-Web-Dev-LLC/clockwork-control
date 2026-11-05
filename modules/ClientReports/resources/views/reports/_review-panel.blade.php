{{-- Operator preview only: why the report is held, grouped by category,
     each with a link to where it can be looked at. $report, $reasons. --}}
@php
    $categoryClass = \Modules\ClientReports\Support\ReviewReasonCategories::class;
    $groups = $categoryClass::group($reasons);
    $tabLabels = ['security' => 'Security tab', 'updates' => 'Updates tab', 'traffic' => 'Traffic tab', 'forms' => 'Forms tab', 'overview' => 'site overview'];
@endphp
<div class="no-print mb-6 bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-4 text-sm">
    <div class="font-semibold mb-2"><i class="fa-solid fa-hand"></i> {{ $report->schedule?->delivery_mode === \Modules\ClientReports\Models\ClientReportSchedule::MODE_AUTO ? 'Held for review instead of being emailed automatically' : 'Check these before sending' }}</div>
    <div class="space-y-2.5">
        @foreach ($groups as $category => $texts)
            @php($tab = $categoryClass::siteTab($category))
            <div class="rounded-lg bg-white/60 border border-amber-100 px-3 py-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-bold uppercase tracking-wide text-amber-800"><i class="fa-solid {{ $categoryClass::icon($category) }}"></i> {{ $categoryClass::label($category) }}</span>
                    @if ($category === $categoryClass::FIRST_REPORT && $report->schedule)
                        <a href="{{ route('client-reports.schedules.edit', $report->schedule) }}" class="text-xs font-semibold text-amber-900 underline hover:no-underline">Check the schedule's recipients →</a>
                    @elseif ($tab !== null && $report->site)
                        <a href="{{ route('sites.show', ['site' => $report->site, 'tab' => $tab]) }}" class="text-xs font-semibold text-amber-900 underline hover:no-underline">Open the {{ $tabLabels[$tab] ?? $tab }} →</a>
                    @endif
                </div>
                <ul class="list-disc ml-5 mt-1 space-y-0.5">
                    @foreach ($texts as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
    <div class="mt-3 text-xs text-amber-800">
        @if ($report->canRecompile())
            Fix or confirm these, then press <strong>Recompile</strong> to refresh this report from current data (it never sends), or <strong>Send</strong> it as it is.
        @else
            Fix or confirm these, then <strong>Send</strong> it (or generate a fresh one).
        @endif
    </div>
</div>
