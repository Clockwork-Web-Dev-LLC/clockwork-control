@php($stateClass = \Modules\ClientReports\Support\ReportState::class)
<div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[11px] text-[var(--color-ink-muted)]">
    @foreach ([$stateClass::SENT, $stateClass::NEEDS_REVIEW, $stateClass::OUTDATED, $stateClass::READY, $stateClass::PARTLY_SENT] as $legendState)
        <span class="inline-flex items-center gap-1.5"><span class="cr-cell cr-cell-{{ $legendState }}" style="width: 1.1rem; height: .8rem;"></span>{{ $stateClass::label($legendState) }}</span>
    @endforeach
    <span class="inline-flex items-center gap-1.5"><span class="cr-cell cr-cell-skipped" style="width: 1.1rem; height: .8rem;"></span>Skipped (no care plan)</span>
    <span class="inline-flex items-center gap-1.5"><span class="cr-cell cr-cell-none" style="width: 1.1rem; height: .8rem;"></span>No report</span>
</div>
