{{-- Operator preview only (never the public /reports/view page, never
     printed): a sticky bar to step through the list the report was opened
     from and act on it. $report, $nav (ClientReportsController::navigation). --}}
@php
    $stateClass = \Modules\ClientReports\Support\ReportState::class;
    $state = $stateClass::of($report);
    $query = $nav['query'] ?? [];
    $blocked = $stateClass::sendBlockedReason($report);
    $operatorEmail = auth()->user()?->email;
    $btn = 'px-3 py-1.5 rounded-lg border border-slate-300 bg-white text-slate-700 text-xs font-semibold hover:bg-slate-50 transition-colors inline-flex items-center gap-1.5 whitespace-nowrap';
    $btnOff = 'px-3 py-1.5 rounded-lg border border-slate-200 bg-slate-50 text-slate-400 text-xs font-semibold inline-flex items-center gap-1.5 whitespace-nowrap cursor-not-allowed';
    $pillColours = [
        $stateClass::NEEDS_REVIEW => 'bg-orange-100 text-orange-800',
        $stateClass::OUTDATED => 'bg-violet-100 text-violet-800',
        $stateClass::READY => 'bg-blue-100 text-blue-800',
        $stateClass::PARTLY_SENT => 'bg-amber-100 text-amber-800',
        $stateClass::SENT => 'bg-emerald-100 text-emerald-800',
        $stateClass::SUPERSEDED => 'bg-slate-200 text-slate-700',
    ];
@endphp
<div class="no-print relative sm:sticky top-0 z-40 -mt-8 mb-6 -mx-4 sm:-mx-6 lg:-mx-8 bg-white/95 backdrop-blur border-b border-slate-200 shadow-sm" id="operator-toolbar">
    <div class="max-w-6xl mx-auto px-4 py-2.5 space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ $nav['back_url'] }}" class="{{ $btn }}" data-nav="back"><i class="fa-solid fa-arrow-left"></i> {{ $nav['back_label'] }}</a>
                @if ($nav['previous'])
                    <a href="{{ route('client-reports.show', [$nav['previous'], ...$query]) }}" class="{{ $btn }}" data-nav="prev" title="Previous report (←)"><i class="fa-solid fa-chevron-left"></i><span class="hidden sm:inline">Previous</span></a>
                @else
                    <span class="{{ $btnOff }}" aria-disabled="true"><i class="fa-solid fa-chevron-left"></i><span class="hidden sm:inline">Previous</span></span>
                @endif
                @if ($nav['position'])
                    <span class="text-[11px] text-slate-500 px-1 whitespace-nowrap">{{ $nav['position'] }} of {{ $nav['count'] }}</span>
                @endif
                @if ($nav['next'])
                    <a href="{{ route('client-reports.show', [$nav['next'], ...$query]) }}" class="{{ $btn }}" data-nav="next" title="Next report (→)"><span class="hidden sm:inline">Next</span><i class="fa-solid fa-chevron-right"></i></a>
                @else
                    <span class="{{ $btnOff }}" aria-disabled="true"><span class="hidden sm:inline">Next</span><i class="fa-solid fa-chevron-right"></i></span>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ route('client-reports.email', [$report, ...$query]) }}" class="{{ $btn }}" data-nav="email" title="The email exactly as the client gets it (e)"><i class="fa-solid fa-envelope"></i> Email version</a>
                {{-- Unsent scheduled reports only: refresh from current data. Never sends. --}}
                @if ($report->canRecompile())
                    <form method="POST" action="{{ route('client-reports.recompile', $report) }}"
                          onsubmit="return confirm(@js('Recompile this report for '.$report->period_start->format('M j').' – '.$report->period_end->format('M j, Y').' from current data? This copy is replaced. Nothing is sent.'));">
                        @csrf
                        @foreach ($query as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endforeach
                        <button type="submit" class="{{ $btn }}" title="Compile this period again from current data. Nothing is sent.">
                            <i class="fa-solid fa-rotate"></i> Recompile
                        </button>
                    </form>
                @endif
                <button type="button" onclick="window.print()" class="{{ $btn }}" title="Print, or save as PDF">
                    <i class="fa-solid fa-print"></i> Print / PDF
                </button>
                <form method="POST" action="{{ route('client-reports.send-test', $report) }}"
                      onsubmit="return confirm(@js($stateClass::testConfirmation($operatorEmail)));">
                    @csrf
                    <button type="submit" class="{{ $btn }}" title="Email this exact email to {{ $operatorEmail ?: 'you' }} only, marked [TEST]. Changes nothing.">
                        <i class="fa-solid fa-flask"></i> Test to me
                    </button>
                </form>
                @if ($blocked !== null)
                    <span class="{{ $btnOff }}" aria-disabled="true" title="{{ $blocked }}"><i class="fa-solid fa-paper-plane"></i> Send</span>
                @else
                    <form method="POST" action="{{ route('client-reports.send', $report) }}"
                        @if ($report->recipients() === [])
                            onsubmit="const to = prompt('Send this report to which email address(es)?'); if (! to) return false; this.email.value = to; return true;"
                        @else
                            onsubmit="return confirm(@js($stateClass::sendConfirmation($report, "\n\n")));"
                        @endif
                    >
                        @csrf
                        <input type="hidden" name="email" value="">
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-900 text-white text-xs font-semibold hover:bg-slate-800 transition-colors inline-flex items-center gap-1.5 whitespace-nowrap" title="Email it to the client">
                            <i class="fa-solid fa-paper-plane"></i> Send
                        </button>
                    </form>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-[11px] text-slate-500">
            <div class="flex flex-wrap items-center gap-2 min-w-0">
                <span class="px-2 py-0.5 rounded-full font-semibold {{ $pillColours[$state] ?? 'bg-slate-100 text-slate-700' }}">{{ $stateClass::label($state) }}</span>
                <span class="font-mono text-slate-700 break-all">{{ $report->site?->domain }}</span>
                <span>{{ $report->period_start->format('M j') }} – {{ $report->period_end->format('M j, Y') }}</span>
                @if ($blocked !== null)
                    <span class="text-slate-600"><i class="fa-solid fa-circle-info"></i> Send unavailable: {{ $blocked }}</span>
                @elseif ($report->recipientsToSend() !== [])
                    <span>Send goes to {{ implode(', ', $report->recipientsToSend()) }}</span>
                @endif
            </div>
            <span class="hidden sm:inline whitespace-nowrap">Keys: <kbd class="px-1 rounded border border-slate-300 bg-slate-50">←</kbd> <kbd class="px-1 rounded border border-slate-300 bg-slate-50">→</kbd> previous / next · <kbd class="px-1 rounded border border-slate-300 bg-slate-50">e</kbd> email version</span>
        </div>
    </div>
</div>
<script>
    document.addEventListener('keydown', function (e) {
        if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey || e.shiftKey) { return; }
        var t = e.target;
        if (t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable)) { return; }
        var key = { ArrowLeft: 'prev', ArrowRight: 'next', e: 'email', E: 'email' }[e.key];
        var link = key ? document.querySelector('#operator-toolbar a[data-nav="' + key + '"]') : null;
        if (link) { e.preventDefault(); window.location.href = link.href; }
    });
</script>
