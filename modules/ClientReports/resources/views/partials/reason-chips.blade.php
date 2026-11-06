{{-- Short chips for why a report is held, one per category; the full
     sentences are in each chip's tooltip. $reasons: list of review reasons. --}}
@php($groups = \Modules\ClientReports\Support\ReviewReasonCategories::group($reasons ?? []))
@if ($groups !== [])
    <div class="flex flex-wrap gap-1 mt-1">
        @foreach ($groups as $category => $texts)
            <span class="cr-chip" title="{{ implode("\n", $texts) }}">
                <i class="fa-solid {{ \Modules\ClientReports\Support\ReviewReasonCategories::icon($category) }}"></i>{{ \Modules\ClientReports\Support\ReviewReasonCategories::short($category) }}@if (count($texts) > 1) ×{{ count($texts) }}@endif
            </span>
        @endforeach
    </div>
@endif
