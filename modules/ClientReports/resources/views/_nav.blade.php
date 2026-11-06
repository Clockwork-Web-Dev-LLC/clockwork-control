@php
    $navCounts = \Modules\ClientReports\Support\ModuleNavCounts::get();
    $navTabs = [
        [
            'label' => 'Clients',
            'icon' => 'fa-users',
            'url' => route('client-reports.index'),
            'active' => request()->routeIs('client-reports.index', 'client-reports.clients.*'),
            'count' => null,
            'count_title' => null,
        ],
        [
            'label' => 'Reports',
            'icon' => 'fa-file-lines',
            'url' => route('client-reports.reports'),
            'active' => request()->routeIs('client-reports.reports', 'client-reports.email'),
            'count' => $navCounts['waiting'] ?: null,
            'count_title' => $navCounts['waiting'].' '.($navCounts['period_label'] ?? '').' report(s) waiting for review or sending',
        ],
        [
            'label' => 'Scheduling',
            'icon' => 'fa-clock',
            'url' => route('client-reports.schedules.index'),
            'active' => request()->routeIs('client-reports.schedules.*'),
            'count' => $navCounts['missing'] ?: null,
            'count_title' => $navCounts['missing'].' care-plan site(s) without a report schedule',
        ],
        [
            'label' => 'Templates',
            'icon' => 'fa-layer-group',
            'url' => route('client-reports.templates.index'),
            'active' => request()->routeIs('client-reports.templates.*'),
            'count' => null,
            'count_title' => null,
        ],
    ];
@endphp

@include('client-reports::partials.styles')

<nav class="cw-tab-bar mb-6" aria-label="Client reports sections">
    @foreach ($navTabs as $tab)
        <a href="{{ $tab['url'] }}" @if ($tab['active']) aria-current="page" @endif
           class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap
                  {{ $tab['active']
                        ? 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]'
                        : 'border-transparent text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:border-[var(--color-border)]' }}">
            <i class="fa-solid {{ $tab['icon'] }} text-xs"></i>
            <span>{{ $tab['label'] }}</span>
            @if ($tab['count'])
                <span class="cr-count status-orange" title="{{ $tab['count_title'] }}">{{ $tab['count'] }}</span>
            @endif
        </a>
    @endforeach
</nav>
