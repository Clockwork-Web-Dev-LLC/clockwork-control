{{-- A report's state as a pill. $state: a ReportState constant. --}}
@php($stateClass = \Modules\ClientReports\Support\ReportState::class)
<span class="cr-pill {{ $stateClass::pill($state) }}" title="{{ $stateClass::hint($state) }}">
    <i class="fa-solid {{ $stateClass::icon($state) }}"></i>{{ $stateClass::label($state) }}
</span>
