@extends('layouts.app')

@section('title', 'Report Templates · Clockwork')

@section('content')
<div class="mb-6 flex items-center justify-between flex-wrap gap-4">
    <div>
        <h1 class="display-heading text-3xl text-[var(--color-ink-strong)] flex items-center gap-2">
            <i class="fa-solid fa-file-lines text-[var(--color-brand)]"></i>
            Client Reports
        </h1>
        <p class="text-xs text-[var(--color-ink-soft)] mt-1">
            Automated, executive-ready white-labeled maintenance and performance reports for your agency clients.
        </p>
    </div>
    <div class="flex items-center gap-2">
        <a href="{{ route('client-reports.templates.create') }}" class="btn-pill-primary text-xs">
            <i class="fa-solid fa-plus mr-1"></i> New Template
        </a>
    </div>
</div>

@include('client-reports::_nav')

@if (session('status'))
    <div class="card p-4 mb-6 status-green text-xs flex items-center gap-2">
        <i class="fa-solid fa-circle-check"></i>
        <span>{{ session('status') }}</span>
    </div>
@endif

@if (session('status_error'))
    <div class="card p-4 mb-6 status-red text-xs flex items-center gap-2">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>{{ session('status_error') }}</span>
    </div>
@endif

<div class="card p-5">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="font-display font-semibold text-base text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-layer-group text-[var(--color-ink-soft)]"></i>
                Configured Templates
            </h2>
            <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                Tailor which sections appear in generated client reports for different care plan tiers.
            </p>
        </div>
        <span class="text-xs text-[var(--color-ink-muted)]">{{ $templates->count() }} template(s)</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-xs text-left">
            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] uppercase tracking-wider text-[10px]">
                <tr>
                    <th class="px-4 py-3">Template</th>
                    <th class="px-4 py-3">Included Sections</th>
                    <th class="px-4 py-3">Usage</th>
                    <th class="px-4 py-3">Default</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]">
                @forelse ($templates as $t)
                    <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors">
                        <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold">{{ $t->name }}</span>
                                @if ($t->is_default)
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-[var(--color-brand)]/10 text-[var(--color-brand)] border border-[var(--color-brand)]/20">
                                        Default
                                    </span>
                                @endif
                            </div>
                            <div class="text-[11px] text-[var(--color-ink-muted)] mt-0.5">
                                {{ count((array) $t->sections) }} of 7 sections active
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1 max-w-md">
                                @foreach ((array) $t->sections as $secKey)
                                    @php $sec = \Modules\ClientReports\Models\ClientReportTemplate::SECTIONS[$secKey] ?? null; @endphp
                                    @if ($sec)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] bg-[var(--color-surface)] border border-[var(--color-border-light)] text-[var(--color-ink-strong)]">
                                            <i class="fa-solid {{ $sec['icon'] }} text-[9px] text-[var(--color-brand)]"></i>
                                            <span>{{ $sec['label'] }}</span>
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3 text-[var(--color-ink-soft)] whitespace-nowrap">
                            <div>{{ $t->reports_count }} report(s)</div>
                            <div class="text-[10px] text-[var(--color-ink-muted)]">{{ $t->schedules_count }} schedule(s)</div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            @if ($t->is_default)
                                <span class="status-pill status-green text-xs">
                                    <span class="status-dot"></span> Active Default
                                </span>
                            @else
                                <span class="text-[var(--color-ink-muted)] text-[11px]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('client-reports.templates.edit', $t) }}" class="btn-pill-nav text-xs py-1.5 px-2.5" title="Edit Template">
                                    <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                                </a>
                                @if (! $t->is_default)
                                    <form method="POST" action="{{ route('client-reports.templates.destroy', $t) }}" class="inline" onsubmit="return confirm('Delete template \'{{ addslashes($t->name) }}\'? Existing reports will retain their data.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-pill-nav text-xs py-1.5 px-2 text-rose-600 hover:bg-rose-50" title="Delete Template">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center py-8 text-[var(--color-ink-muted)]">
                            No templates found. Create your first template above.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
