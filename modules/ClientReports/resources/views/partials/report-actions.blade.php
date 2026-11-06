{{-- A report's actions on the app-styled pages (list, client page, email
     version): View, Email version, Recompile, Send, Send test to me.
     $report; $context: query parameters that keep the list the operator came
     from (filters, client page); $hide: actions to leave out (e.g. 'view'). --}}
@php
    $stateClass = \Modules\ClientReports\Support\ReportState::class;
    $context = $context ?? [];
    $hide = $hide ?? [];
    $blocked = $stateClass::sendBlockedReason($report);
    $hasRecipients = $report->recipients() !== [];
    $operatorEmail = auth()->user()?->email;
@endphp
<div class="flex items-center justify-end gap-1.5 flex-wrap xl:flex-nowrap">
    @unless (in_array('view', $hide, true))
        <a href="{{ route('client-reports.show', [$report, ...$context]) }}" class="cr-btn" title="The report as the client reads it (prints as the PDF)">
            <i class="fa-solid fa-eye"></i><span>View</span>
        </a>
    @endunless
    @unless (in_array('email', $hide, true))
        <a href="{{ route('client-reports.email', [$report, ...$context]) }}" class="cr-btn" title="The email exactly as the client gets it">
            <i class="fa-solid fa-envelope"></i><span>Email</span>
        </a>
    @endunless
    {{-- Unsent scheduled reports only: refresh from current data. Never sends. --}}
    @if ($report->canRecompile())
        <form method="POST" action="{{ route('client-reports.recompile', $report) }}" class="inline"
              data-confirm="Recompile this report for {{ $report->period_start->format('M j') }} – {{ $report->period_end->format('M j, Y') }} from current data? The current copy is replaced by a fresh one. Nothing is sent."
              data-confirm-btn="Recompile" data-confirm-variant="primary">
            @csrf
            {{-- The new copy opens in the same list context (filters, client page). --}}
            @foreach ($context as $contextKey => $contextValue)
                <input type="hidden" name="{{ $contextKey }}" value="{{ $contextValue }}">
            @endforeach
            <button type="submit" class="cr-btn" title="Compile this period again from current data. Nothing is sent.">
                <i class="fa-solid fa-rotate"></i><span>Recompile</span>
            </button>
        </form>
    @endif
    @if ($blocked !== null)
        <button type="button" class="cr-btn cr-btn-send is-disabled" disabled title="{{ $blocked }}" aria-label="Send (unavailable: {{ $blocked }})">
            <i class="fa-solid fa-paper-plane"></i><span>Send</span>
        </button>
    @else
        <form method="POST" action="{{ route('client-reports.send', $report) }}" class="inline"
            @if (! $hasRecipients)
                onsubmit="const to = prompt('Send this report to which email address(es)?'); if (! to) return false; this.email.value = to; return true;"
            @else
                data-confirm="{{ $stateClass::sendConfirmation($report) }}" data-confirm-btn="Send to client" data-confirm-variant="warning"
            @endif
        >
            @csrf
            <input type="hidden" name="email" value="">
            <button type="submit" class="cr-btn cr-btn-send" title="Email it to the client">
                <i class="fa-solid fa-paper-plane"></i><span>Send</span>
            </button>
        </form>
    @endif
    <form method="POST" action="{{ route('client-reports.send-test', $report) }}" class="inline"
          data-confirm="{{ $stateClass::testConfirmation($operatorEmail) }}" data-confirm-btn="Send test to me" data-confirm-variant="primary">
        @csrf
        <button type="submit" class="cr-btn" title="Email this exact email to {{ $operatorEmail ?: 'you' }} only, marked [TEST]. Changes nothing.">
            <i class="fa-solid fa-flask"></i><span>Test to me</span>
        </button>
    </form>
</div>
