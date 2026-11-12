@extends('layouts.app')

@section('title', ($row->exists ? 'Edit page' : 'Add a page').' · Keyword map · '.$site->domain.' · Clockwork')

@section('content')
<x-page-header :title="$site->domain" :subtitle="($row->exists ? 'Edit a page in' : 'Add a page to').' the keyword map “'.$map->name.'”.'">
    <x-slot:actions>
        <a href="{{ route('seo.sites.keyword-map', $site) }}" class="btn-pill-nav text-sm"><i class="fa-solid fa-arrow-left"></i> Back to the map</a>
    </x-slot:actions>
</x-page-header>

@include('seo::_nav')
@include('seo::sites._tabs')
@include('seo::_flash')

@php
    $inputClass = 'mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full';
    $secondary = old('secondary_keywords', collect($row->secondary_keywords ?? [])->pluck('keyword')->implode("\n"));
@endphp

<form method="POST" class="max-w-3xl space-y-6"
      action="{{ $row->exists ? route('seo.sites.keyword-map.rows.update', [$site, $row]) : route('seo.sites.keyword-map.rows.store', $site) }}">
    @csrf
    @if ($row->exists)
        @method('PUT')
        {{-- The version this form was loaded with. Not old(): after a clash the newest version must be used. --}}
        <input type="hidden" name="expected_updated_at" value="{{ $row->updated_at?->toIso8601String() }}">
    @endif

    <x-card title="Page" icon="fa-file-lines">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Page name</span>
                <input type="text" name="page_name" maxlength="191" value="{{ old('page_name', $row->page_name) }}" placeholder="Services" class="{{ $inputClass }}">
            </label>
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">URL</span>
                <input type="text" name="url" maxlength="2000" value="{{ old('url', $row->url) }}" placeholder="/services/" class="{{ $inputClass }} font-data">
            </label>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="is_new_page" value="0">
            <input type="checkbox" name="is_new_page" value="1" @checked(old('is_new_page', $row->is_new_page))>
            <span>This page does not exist yet</span>
        </label>
    </x-card>

    <x-card title="Keywords" icon="fa-key">
        <label class="block text-sm mb-4">
            <span class="font-medium text-[var(--color-ink-strong)]">Primary keyword</span>
            <input type="text" name="primary_keyword" maxlength="191" value="{{ old('primary_keyword', $row->primary_keyword) }}" class="{{ $inputClass }}">
            <span class="block text-xs text-[var(--color-ink-soft)] mt-1">
                @if ($row->exists && $row->volume !== null)
                    Stored figures: volume {{ number_format($row->volume) }}{{ $row->difficulty !== null ? ', difficulty '.$row->difficulty : '' }}{{ $row->metrics_provider === 'imported' ? ' (imported from another tool)' : '' }}. Changing the keyword clears them.
                @else
                    Volume and difficulty are filled from bought keyword data ("Fill from stored data" on the map), not typed in.
                @endif
            </span>
        </label>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Secondary keywords, one per line</span>
                <textarea name="secondary_keywords" rows="4" class="{{ $inputClass }}">{{ $secondary }}</textarea>
            </label>
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Intent</span>
                <select name="intent" class="{{ $inputClass }}">
                    <option value="">Not set</option>
                    @foreach (['informational', 'commercial', 'transactional', 'navigational'] as $intent)
                        <option value="{{ $intent }}" @selected(old('intent', $row->intent) === $intent)>{{ ucfirst($intent) }}</option>
                    @endforeach
                    @if ($row->intent && ! in_array($row->intent, ['informational', 'commercial', 'transactional', 'navigational'], true))
                        <option value="{{ $row->intent }}" selected>{{ $row->intent }}</option>
                    @endif
                </select>
            </label>
        </div>
    </x-card>

    <x-card title="Proposed title and description" icon="fa-heading">
        <div x-data="{ title: @js(old('proposed_title', $row->proposed_title) ?? ''), meta: @js(old('proposed_meta', $row->proposed_meta) ?? '') }">
            <label class="block text-sm mb-4">
                <span class="font-medium text-[var(--color-ink-strong)]">Title</span>
                <input type="text" name="proposed_title" maxlength="500" x-model="title" class="{{ $inputClass }}">
                <span class="block text-xs mt-1" :class="[...title].length === 0 || ([...title].length >= 50 && [...title].length <= 60) ? 'text-[var(--color-ink-soft)]' : 'text-[var(--color-status-red)]'">
                    <span x-text="[...title].length"></span> characters. Aim for 50 to 60.
                </span>
            </label>
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Meta description</span>
                <textarea name="proposed_meta" rows="3" maxlength="1000" x-model="meta" class="{{ $inputClass }}"></textarea>
                <span class="block text-xs mt-1" :class="[...meta].length === 0 || ([...meta].length >= 140 && [...meta].length <= 160) ? 'text-[var(--color-ink-soft)]' : 'text-[var(--color-status-red)]'">
                    <span x-text="[...meta].length"></span> characters. Aim for 140 to 160. Em dashes are not accepted.
                </span>
            </label>
        </div>
        <label class="block text-sm mt-4">
            <span class="font-medium text-[var(--color-ink-strong)]">Schema types <span class="font-normal text-[var(--color-ink-soft)]">(comma separated)</span></span>
            <input type="text" name="schema_types" maxlength="500" value="{{ old('schema_types', implode(', ', $row->schema_types ?? [])) }}" placeholder="Service, FAQPage" class="{{ $inputClass }}">
        </label>
    </x-card>

    <x-card title="Progress" icon="fa-list-check">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Status</span>
                <select name="ship_status" class="{{ $inputClass }}">
                    @foreach (\Modules\Seo\Models\KeywordMapRow::STATUSES as $value => $label)
                        <option value="{{ $value }}" @selected(old('ship_status', $row->ship_status) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Went live on</span>
                <input type="date" name="shipped_on" value="{{ old('shipped_on', $row->shipped_on?->toDateString()) }}" class="{{ $inputClass }}">
            </label>
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Status note</span>
                <input type="text" name="ship_note" maxlength="255" value="{{ old('ship_note', $row->ship_note) }}" placeholder="title + meta" class="{{ $inputClass }}">
            </label>
        </div>
        <label class="block text-sm mb-4">
            <span class="font-medium text-[var(--color-ink-strong)]">Notes</span>
            <textarea name="notes" rows="3" maxlength="5000" class="{{ $inputClass }}">{{ old('notes', $row->notes) }}</textarea>
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="excluded" value="0">
            <input type="checkbox" name="excluded" value="1" @checked(old('excluded', $row->excluded))>
            <span>Hide this page from the map <span class="text-[var(--color-ink-soft)]">(it is kept, and left out of the CSV)</span></span>
        </label>
    </x-card>

    <div class="flex items-center gap-3">
        <button type="submit" class="btn-primary text-sm px-4 py-2">{{ $row->exists ? 'Save' : 'Add to the map' }}</button>
        <a href="{{ route('seo.sites.keyword-map', $site) }}" class="text-sm text-[var(--color-ink-muted)] hover:underline">Cancel</a>
    </div>
</form>
@endsection
