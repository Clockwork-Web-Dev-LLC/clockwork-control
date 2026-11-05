{{-- One site's last report periods as small month cells, each linking to
     that month's report. $months: ClientDirectory site row 'months';
     $context: query parameters for the report links. --}}
@php
    $stateClass = \Modules\ClientReports\Support\ReportState::class;
    $directoryClass = \Modules\ClientReports\Services\ClientDirectory::class;
@endphp
<div class="flex items-center gap-1" role="list" aria-label="Report history">
    @foreach ($months as $ym => $cell)
        @php
            $monthLabel = $cell['month']->format('M Y');
            $title = match ($cell['state']) {
                $directoryClass::CELL_NONE => "{$monthLabel}: no report",
                $directoryClass::CELL_SKIPPED => "{$monthLabel}: skipped (no care plan)",
                default => "{$monthLabel}: ".$stateClass::label($cell['state']),
            };
        @endphp
        @if ($cell['report'])
            <a role="listitem" href="{{ route('client-reports.show', [$cell['report'], ...($context ?? [])]) }}"
               class="cr-cell cr-cell-{{ $cell['state'] }}" title="{{ $title }}" aria-label="{{ $title }}">{{ $cell['month']->format('M') }}</a>
        @else
            <span role="listitem" class="cr-cell {{ $cell['state'] === $directoryClass::CELL_SKIPPED ? 'cr-cell-skipped' : 'cr-cell-none' }}" title="{{ $title }}" aria-label="{{ $title }}">{{ $cell['month']->format('M') }}</span>
        @endif
    @endforeach
</div>
