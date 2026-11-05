{{-- One site on the Clients list: care plan, schedule, recipients and the
     last months of reports. $siteRow: ClientDirectory site row. --}}
@php
    $site = $siteRow['site'];
    $schedule = $siteRow['schedule'];
    $carePlansOn = \App\Models\Site::areCarePlansEnabled();
    $recipients = array_values(array_filter((array) ($schedule?->recipients ?? [])));
@endphp
<div class="rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface)] px-3 py-2.5 grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-2 items-center {{ $site->is_inactive ? 'opacity-60' : '' }}">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-1.5">
            <a href="{{ route('sites.show', $site) }}" class="font-mono text-xs font-semibold text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] break-all">{{ $site->domain }}</a>
            @if ($site->is_inactive)
                <span class="cr-chip">Inactive</span>
            @endif
            @if ($carePlansOn)
                @if ($siteRow['eligible'])
                    <span class="cr-pill status-green"><i class="fa-solid fa-shield-heart"></i>Care plan</span>
                @else
                    <span class="cr-pill status-unknown" title="Reports are only sent for care-plan sites; months without one are skipped."><i class="fa-solid fa-ban"></i>No care plan — reports won't send</span>
                @endif
            @endif
        </div>
        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-[var(--color-ink-muted)]">
            @if ($siteRow['schedule_state'] === 'scheduled')
                <a href="{{ route('client-reports.schedules.edit', $schedule) }}" class="cr-pill status-green hover:opacity-80" title="Edit schedule"><i class="fa-solid fa-clock"></i>Scheduled · {{ $schedule->delivery_mode === \Modules\ClientReports\Models\ClientReportSchedule::MODE_DRAFT ? 'draft' : 'auto' }}</a>
            @elseif ($siteRow['schedule_state'] === 'disabled')
                <a href="{{ route('client-reports.schedules.edit', $schedule) }}" class="cr-pill status-red hover:opacity-80" title="Edit schedule"><i class="fa-solid fa-pause"></i>Schedule disabled</a>
            @elseif ($siteRow['missing'])
                <span class="cr-pill status-red"><i class="fa-solid fa-triangle-exclamation"></i>No schedule</span>
                <form method="POST" action="{{ route('client-reports.schedules.quick', $site) }}" class="inline">
                    @csrf
                    <button type="submit" class="cr-btn py-0.5"><i class="fa-solid fa-plus"></i>Create schedule</button>
                </form>
            @else
                <span class="cr-pill status-unknown"><i class="fa-regular fa-clock"></i>No schedule</span>
            @endif
            @if ($schedule)
                <span class="min-w-0 break-all" title="Report recipients">
                    <i class="fa-solid fa-at text-[9px] text-[var(--color-ink-soft)]"></i>
                    {{ $recipients !== [] ? implode(', ', $recipients) : 'No recipients (drafts only)' }}
                </span>
            @endif
        </div>
    </div>
    @include('client-reports::partials.history-strip', ['months' => $siteRow['months'], 'context' => $context ?? []])
</div>
