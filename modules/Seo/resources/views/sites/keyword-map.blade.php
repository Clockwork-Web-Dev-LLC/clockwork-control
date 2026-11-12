@extends('layouts.app')

@section('title', 'Keyword map · '.$site->domain.' · Clockwork')

@section('content')
<x-page-header :title="$site->domain" subtitle="Keyword map: which page targets which keyword, the title and description proposed for it, and how far the work has got.">
    <x-slot:actions>
        @if ($map)
            <a href="{{ route('seo.sites.keyword-map.download', [$site, $map]) }}" class="btn-pill-nav text-sm"><i class="fa-solid fa-download"></i> Download CSV</a>
        @endif
    </x-slot:actions>
</x-page-header>

@include('seo::_nav')
@include('seo::sites._tabs')
@include('seo::_flash')

@php
    $inputClass = 'mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full';
    $statusPill = ['live' => 'status-green', 'drafted' => 'status-yellow', 'planned_new' => 'status-unknown', 'not_started' => 'status-unknown'];
@endphp

@if ($map)
    <section class="card mb-6">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-map text-[var(--color-ink-soft)] mr-2"></i>{{ $map->name }}
                    @unless ($map->isCurrent())
                        <span class="status-pill status-unknown text-[10px] align-middle">Older version, read-only</span>
                    @endunless
                </h2>
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                    {{ $rows->where('excluded', false)->count() }} pages ·
                    {{ $rows->where('excluded', false)->where('ship_status', 'live')->count() }} live ·
                    {{ $rows->where('excluded', false)->where('ship_status', 'drafted')->count() }} drafted ·
                    created {{ $map->created_at?->toDateString() }}.
                    Rows are edited by asking Claude; this page shows the result.
                </p>
            </div>
            @if ($versions->count() > 1)
                <form method="GET" action="{{ route('seo.sites.keyword-map', $site) }}" class="text-sm">
                    <label class="sr-only" for="version">Version</label>
                    <select id="version" name="version" onchange="this.form.submit()" class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded">
                        @foreach ($versions as $v)
                            <option value="{{ $v->id }}" @selected($v->id === $map->id)>{{ $v->name }}{{ $v->isCurrent() ? ' (current)' : '' }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
        </div>

        @if ($next)
            <div class="px-5 py-3 border-b border-[var(--color-border-light)] text-sm" data-next-page>
                <span class="font-semibold text-[var(--color-ink-strong)]">Work on next:</span>
                {{ $next['page'] ?? $next['url'] }}@if ($next['primary_keyword']) for "{{ $next['primary_keyword'] }}"@endif.
                <span class="text-[var(--color-ink-muted)]">{{ $next['reason'] }}</span>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                    <tr>
                        <th class="px-4 py-2 font-semibold">#</th>
                        <th class="px-4 py-2 font-semibold">Page</th>
                        <th class="px-4 py-2 font-semibold">Primary keyword</th>
                        <th class="px-4 py-2 font-semibold text-right">Volume</th>
                        <th class="px-4 py-2 font-semibold text-right">Difficulty</th>
                        <th class="px-4 py-2 font-semibold">Proposed title and description</th>
                        <th class="px-4 py-2 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($rows as $row)
                        @php
                            $titleLength = $row->proposed_title !== null ? mb_strlen($row->proposed_title) : null;
                            $metaLength = $row->proposed_meta !== null ? mb_strlen($row->proposed_meta) : null;
                            $titleOff = $titleLength !== null && ($titleLength < 50 || $titleLength > 60);
                            $metaOff = $metaLength !== null && ($metaLength < 140 || $metaLength > 160);
                        @endphp
                        <tr data-map-row="{{ $row->id }}" class="align-top {{ $row->excluded ? 'opacity-50' : '' }}">
                            <td class="px-4 py-2 font-data text-[var(--color-ink-soft)]">{{ $row->sort }}</td>
                            <td class="px-4 py-2">
                                <span class="text-[var(--color-ink-strong)]">{{ $row->page_name ?? '—' }}</span>
                                @if ($row->is_new_page)<span class="status-pill status-unknown text-[10px]">New page</span>@endif
                                @if ($row->excluded)<span class="status-pill status-unknown text-[10px]">Hidden</span>@endif
                                <span class="block text-xs font-data text-[var(--color-ink-soft)] break-all">{{ $row->url }}</span>
                            </td>
                            <td class="px-4 py-2">
                                {{ $row->primary_keyword ?? '—' }}
                                @if ($row->secondary_keywords)
                                    <span class="block text-xs text-[var(--color-ink-soft)]">+ {{ collect($row->secondary_keywords)->pluck('keyword')->take(4)->implode(', ') }}{{ count($row->secondary_keywords) > 4 ? '…' : '' }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right font-data">{{ $row->volume !== null ? number_format($row->volume) : '—' }}</td>
                            <td class="px-4 py-2 text-right font-data whitespace-nowrap">
                                @if ($row->difficulty === null)
                                    —
                                @elseif ($row->metrics_provider === 'dataforseo')
                                    {{ $row->difficulty }} <span class="text-xs text-[var(--color-ink-soft)]">{{ $bands($row->difficulty) }}</span>
                                @else
                                    {{ $row->difficulty }} <span class="text-xs text-[var(--color-ink-soft)]" title="Imported from another tool; not comparable with DataForSEO difficulty">imported</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 max-w-md">
                                @if ($row->proposed_title)
                                    <span class="block text-[var(--color-ink-strong)]">{{ $row->proposed_title }}
                                        <span class="text-xs font-data {{ $titleOff ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-soft)]' }}">{{ $titleLength }}</span></span>
                                @endif
                                @if ($row->proposed_meta)
                                    <span class="block text-xs text-[var(--color-ink-muted)] mt-1">{{ $row->proposed_meta }}
                                        <span class="font-data {{ $metaOff ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-soft)]' }}">{{ $metaLength }}</span></span>
                                @endif
                                @if ($row->notes)
                                    <span class="block text-xs text-[var(--color-ink-soft)] mt-1 italic">{{ Str::limit($row->notes, 200) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                <span class="status-pill {{ $statusPill[$row->ship_status] ?? 'status-unknown' }} text-[10px]">{{ \Modules\Seo\Models\KeywordMapRow::STATUSES[$row->ship_status] ?? $row->ship_status }}</span>
                                @if ($row->shipped_on)<span class="block text-xs text-[var(--color-ink-soft)] mt-1">{{ $row->shipped_on->toDateString() }}</span>@endif
                                @if ($row->ship_note)<span class="block text-xs text-[var(--color-ink-soft)] mt-1 whitespace-normal">{{ $row->ship_note }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if ($warnings !== [])
        <x-card title="To check ({{ count($warnings) }})" icon="fa-triangle-exclamation" class="mb-6">
            <ul class="text-sm space-y-1 list-disc list-inside text-[var(--color-ink)]" data-map-warnings>
                @foreach (array_slice($warnings, 0, 60) as $warning)
                    <li>{{ $warning['message'] }}</li>
                @endforeach
            </ul>
            @if (count($warnings) > 60)
                <p class="text-xs text-[var(--color-ink-soft)] mt-2">And {{ count($warnings) - 60 }} more.</p>
            @endif
        </x-card>
    @endif
@else
    <section class="card mb-6">
        <p class="px-5 py-8 text-sm text-[var(--color-ink-muted)] text-center">
            No keyword map yet. Import one from a CSV below, or start an empty one and ask Claude to build it from this site's search data.
        </p>
    </section>
@endif

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
    <x-card title="Import from CSV" icon="fa-file-import">
        <form method="POST" action="{{ route('seo.sites.keyword-map.import', $site) }}" enctype="multipart/form-data">
            @csrf
            <label class="block text-sm mb-4">
                <span class="font-medium text-[var(--color-ink-strong)]">CSV file</span>
                <input type="file" name="file" accept=".csv,text/csv" class="{{ $inputClass }}">
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">In Google Sheets: File → Download → Comma-separated values, with the map tab open. Columns are matched by name: Page, URL, Primary Keyword, Volume, Difficulty (or KD), CPC, Secondary Keywords, Intent, Proposed Title, Proposed Meta, Schema, Status, Notes. Title rows above the header are fine.</span>
            </label>
            <label class="block text-sm mb-4">
                <span class="font-medium text-[var(--color-ink-strong)]">Name for this version <span class="font-normal text-[var(--color-ink-soft)]">(optional)</span></span>
                <input type="text" name="name" maxlength="120" value="{{ old('name') }}" placeholder="Keyword map {{ now()->toDateString() }}" class="{{ $inputClass }}">
            </label>
            <p class="text-xs text-[var(--color-ink-muted)] mb-4">The import becomes the current map. {{ $map ? 'The map above is kept as an older, read-only version.' : '' }}</p>
            <button type="submit" class="btn-primary text-sm px-4 py-2">Import</button>
        </form>
    </x-card>

    <x-card title="Versions and figures" icon="fa-code-branch">
        <form method="POST" action="{{ route('seo.sites.keyword-map.version', $site) }}" class="mb-6">
            @csrf
            <label class="block text-sm mb-3">
                <span class="font-medium text-[var(--color-ink-strong)]">Start a new dated version</span>
                <input type="text" name="name" maxlength="120" placeholder="Keyword map {{ now()->toDateString() }}" class="{{ $inputClass }}">
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">{{ $map ? 'Copies the current map so this month\'s changes don\'t alter last month\'s record.' : 'Starts an empty map.' }}</span>
            </label>
            <button type="submit" class="btn-pill-nav text-sm">{{ $map ? 'Copy to a new version' : 'Start an empty map' }}</button>
        </form>

        @if ($map && $map->isCurrent())
            <form method="POST" action="{{ route('seo.sites.keyword-map.fill', $site) }}">
                @csrf
                <p class="text-sm font-medium text-[var(--color-ink-strong)]">Fill volume, difficulty and CPC</p>
                <p class="text-xs text-[var(--color-ink-soft)] mt-1 mb-3">Uses keyword data Control has already bought, so it costs nothing. Keywords with no stored figures stay blank until someone buys them through Claude.</p>
                <button type="submit" class="btn-pill-nav text-sm">Fill from stored data</button>
            </form>
        @endif
    </x-card>
</div>
@endsection
