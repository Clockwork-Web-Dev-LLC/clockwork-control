{{-- One client on the Clients list. $row: ClientDirectory row; $query: the
     list's filters, kept on the client page link for its previous/next. --}}
@php($clientUrl = route('client-reports.clients.show', [$row['key'], ...$query]))
<li class="p-4 sm:px-5 grid grid-cols-1 lg:grid-cols-[15rem_minmax(0,1fr)] gap-3 lg:gap-6">
    <div class="min-w-0">
        <a href="{{ $clientUrl }}" class="font-display font-semibold text-sm text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] break-words">
            @if ($row['client'] === null)<i class="fa-solid fa-circle-question text-[var(--color-ink-soft)] mr-1"></i>@endif{{ $row['name'] }}
        </a>
        @if ($row['contact'])
            <div class="text-[11px] text-[var(--color-ink-muted)] truncate">{{ $row['contact'] }}</div>
        @endif
        <div class="mt-1.5 flex flex-wrap gap-1">
            <span class="cr-chip"><i class="fa-solid fa-globe"></i>{{ $row['counts']['sites'] }} {{ \Illuminate\Support\Str::plural('site', $row['counts']['sites']) }}</span>
            @if ($row['counts']['waiting'] > 0)
                <span class="cr-chip status-orange" title="Sites whose latest report is waiting for review or sending">{{ $row['counts']['waiting'] }} waiting</span>
            @endif
            @if ($row['counts']['missing'] > 0)
                <span class="cr-chip status-red" title="Care-plan sites with no report schedule">{{ $row['counts']['missing'] }} no schedule</span>
            @endif
        </div>
        <a href="{{ $clientUrl }}" class="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-[var(--color-brand)] hover:underline">
            Reports &amp; schedules <i class="fa-solid fa-arrow-right text-[9px]"></i>
        </a>
    </div>

    <div class="min-w-0 space-y-2">
        @forelse ($row['sites'] as $siteRow)
            @include('client-reports::clients._site-line', ['siteRow' => $siteRow, 'context' => []])
        @empty
            <div class="text-xs text-[var(--color-ink-soft)] italic py-1">No sites assigned to this client.</div>
        @endforelse
    </div>
</li>
