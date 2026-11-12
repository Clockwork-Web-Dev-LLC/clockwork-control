@extends('layouts.app')

@section('title', 'SEO profile · '.$site->domain.' · Clockwork')

@section('content')
<x-page-header :title="$site->domain" subtitle="SEO profile: which Search Console and GA4 properties to sync, and the notes the monthly report follows.">
    <x-slot:actions>
        @if ($profile->exists)
            <a href="{{ route('seo.sites.show', $site) }}" class="btn-pill-nav text-sm"><i class="fa-solid fa-table"></i> Numbers</a>
        @endif
        <a href="{{ route('sites.show', $site) }}" class="btn-pill-nav text-sm"><i class="fa-solid fa-arrow-up-right-from-square"></i> Site</a>
    </x-slot:actions>
</x-page-header>

@include('seo::_nav')
@include('seo::_flash')

@php
    $inputClass = 'mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full';
@endphp

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <form method="POST" action="{{ route('seo.sites.profile.update', $site) }}" class="lg:col-span-2 space-y-6">
        @csrf
        @method('PUT')

        <x-card title="Google properties" icon="fa-magnifying-glass">
            @if ($connection === null)
                <p class="text-sm text-[var(--color-ink-muted)] mb-4">
                    Google isn't connected yet, so there is no property list. An admin can connect it on SEO → Settings.
                </p>
            @elseif ($connection->isRevoked())
                <p class="text-sm text-[var(--color-status-red)] mb-4">The Google connection was revoked. Syncing is stopped until an admin reconnects it.</p>
            @endif

            <label class="block text-sm mb-4">
                <span class="font-medium text-[var(--color-ink-strong)]">Search Console property</span>
                <select name="gsc_site_url" class="{{ $inputClass }} font-data">
                    <option value="">Not set</option>
                    @foreach ($properties as $p)
                        <option value="{{ $p['property'] }}" @selected(old('gsc_site_url', $profile->gsc_site_url ?? $suggestedProperty) === $p['property'])>
                            {{ $p['property'] }} ({{ $p['permission'] ?? 'unknown access' }}){{ $p['suggested'] ? ' · matches this site' : '' }}{{ $p['unseen'] ? ' · no longer visible to Google connection' : '' }}
                        </option>
                    @endforeach
                    @if ($profile->gsc_site_url && ! $properties->contains('property', $profile->gsc_site_url))
                        <option value="{{ $profile->gsc_site_url }}" selected>{{ $profile->gsc_site_url }} (not in the list)</option>
                    @endif
                </select>
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Keep the property your reports already use, so numbers stay comparable. A URL-prefix property and a domain (sc-domain) property are separate, and the connected account may only have access to one.</span>
            </label>
            @if ($connection)
                <button type="submit" form="refresh-properties" class="btn-pill-nav text-xs mb-4"><i class="fa-solid fa-rotate"></i> Refresh list</button>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block text-sm">
                    <span class="font-medium text-[var(--color-ink-strong)]">GA4 property ID</span>
                    <input type="text" name="ga4_property_id" inputmode="numeric" value="{{ old('ga4_property_id', $profile->ga4_property_id) }}" placeholder="123456789" class="{{ $inputClass }} font-data">
                    <span class="block text-xs text-[var(--color-ink-soft)] mt-1">GA4 Admin → Property details. The client roster lists it.</span>
                </label>
                <label class="block text-sm">
                    <span class="font-medium text-[var(--color-ink-strong)]">GA4 property name (optional)</span>
                    <input type="text" name="ga4_property_name" value="{{ old('ga4_property_name', $profile->ga4_property_name) }}" class="{{ $inputClass }}">
                </label>
            </div>
            <label class="flex items-start gap-2 text-sm mt-3">
                <input type="hidden" name="ga4_custom_primary_channel" value="0">
                <input type="checkbox" name="ga4_custom_primary_channel" value="1" class="mt-1" @checked(old('ga4_custom_primary_channel', $profile->ga4_custom_primary_channel))>
                <span>This GA4 property has a custom primary channel group <span class="text-xs text-[var(--color-ink-soft)]">(GA4 Admin → Channel groups). The sync then reads Session primary channel group, as the Traffic acquisition report does.</span></span>
            </label>
        </x-card>

        <x-card title="Report settings" icon="fa-file-lines">
            <label class="block text-sm mb-4">
                <span class="font-medium text-[var(--color-ink-strong)]">Brand terms</span>
                <input type="text" name="brand_terms" value="{{ old('brand_terms', $profile->brand_terms) }}" placeholder="acme, acme roofing, a+ plumbing" class="{{ $inputClass }}">
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Comma separated, any case. Queries containing any of them are left out of the non-branded numbers.</span>
            </label>
            <label class="block text-sm mb-4">
                <span class="font-medium text-[var(--color-ink-strong)]">Key events</span>
                <input type="text" name="key_events" value="{{ old('key_events', implode(', ', $profile->keyEvents())) }}" class="{{ $inputClass }} font-data">
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">GA4 event names the report counts.</span>
            </label>
            @include('seo::clients._market', ['profile' => $profile, 'inputClass' => $inputClass])
            <label class="block text-sm mb-4">
                <span class="font-medium text-[var(--color-ink-strong)]">Semrush domain</span>
                <input type="text" name="semrush_domain" value="{{ old('semrush_domain', $profile->semrush_domain) }}" class="{{ $inputClass }} font-data">
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Matches the exported keyword history. Removed after Semrush is cancelled.</span>
            </label>
            <label class="block text-sm mb-4">
                <span class="font-medium text-[var(--color-ink-strong)]">Report notes</span>
                <textarea name="report_notes" rows="4" class="{{ $inputClass }}" placeholder="Tone, angle, things to avoid">{{ old('report_notes', $profile->report_notes) }}</textarea>
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">The monthly report follows these. They can only be edited here, never from Claude.</span>
            </label>
            <label class="flex items-center gap-2 text-sm mb-2">
                <input type="hidden" name="compare_yoy" value="0">
                <input type="checkbox" name="compare_yoy" value="1" @checked(old('compare_yoy', $profile->compare_yoy))>
                <span>Seasonal client: compare with the same month last year</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_enabled" value="0">
                <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $profile->exists ? $profile->is_enabled : true))>
                <span>Sync this site nightly</span>
            </label>
        </x-card>

        @if ($profile->exists)
            <label class="flex items-start gap-2 text-sm p-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]">
                <input type="hidden" name="confirm_purge" value="0">
                <input type="checkbox" name="confirm_purge" value="1" class="mt-1">
                <span>
                    <strong>Confirm history changes.</strong>
                    Changing a property deletes this site's stored history for it and re-pulls 16 months.
                    Changing brand terms re-marks stored queries and re-pulls the non-branded totals Google still holds.
                </span>
            </label>
        @endif

        <div class="flex gap-2">
            <button type="submit" class="btn-primary text-sm px-4 py-2">Save profile</button>
        </div>
    </form>

    <div class="space-y-6">
        @if ($profile->exists)
            <x-card title="Sync" icon="fa-rotate">
                <dl class="text-sm grid grid-cols-[8rem_1fr] gap-y-1">
                    <dt class="text-[var(--color-ink-muted)]">History from</dt><dd>{{ $profile->history_starts_on?->toDateString() ?? 'not set' }}</dd>
                    <dt class="text-[var(--color-ink-muted)]">Search Console</dt><dd>{{ $profile->last_gsc_synced_at?->diffForHumans() ?? 'not yet' }}</dd>
                    <dt class="text-[var(--color-ink-muted)]">GA4</dt><dd>{{ $profile->last_ga4_synced_at?->diffForHumans() ?? 'not yet' }}</dd>
                </dl>
                @if ($profile->last_sync_error)
                    <p class="text-xs text-[var(--color-status-red)] mt-2">{{ $profile->last_sync_error }}</p>
                @endif
                <form method="POST" action="{{ route('seo.sites.sync', $site) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="btn-pill-nav text-xs"><i class="fa-solid fa-rotate"></i> Sync now</button>
                </form>
            </x-card>
        @endif

        <x-card title="Brand terms preview" icon="fa-filter">
            @if ($brandPreview['month'] === null)
                <p class="text-sm text-[var(--color-ink-muted)]">Top queries show here after the first sync.</p>
            @else
                <p class="text-xs text-[var(--color-ink-soft)] mb-2">Top queries for {{ $brandPreview['month'] }}, as saved. Struck-through ones count as branded.</p>
                <ul class="text-xs space-y-1 font-data">
                    @foreach ($brandPreview['queries'] as $q)
                        <li class="flex justify-between gap-2 {{ $q['branded'] ? 'line-through text-[var(--color-ink-soft)]' : '' }}">
                            <span class="truncate">{{ $q['query'] }}</span><span>{{ number_format($q['impressions']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        <x-card title="AI impressions" icon="fa-robot" subtitle="From Search Console's Generative AI report, which has no API.">
            <form method="POST" action="{{ route('seo.sites.ai-impressions.store', $site) }}" class="space-y-2 text-sm">
                @csrf
                <div class="grid grid-cols-2 gap-2">
                    <label class="block">
                        <span class="text-xs text-[var(--color-ink-muted)]">Month</span>
                        <input type="month" name="month" value="{{ old('month', $lastMonth) }}" required class="{{ $inputClass }} font-data">
                    </label>
                    <label class="block">
                        <span class="text-xs text-[var(--color-ink-muted)]">AI impressions</span>
                        <input type="number" name="ai_impressions" min="0" value="{{ old('ai_impressions') }}" class="{{ $inputClass }} font-data">
                    </label>
                </div>
                <label class="flex items-center gap-2 text-xs">
                    <input type="radio" name="status" value="entered" checked> Number from the report
                </label>
                <label class="flex items-center gap-2 text-xs">
                    <input type="radio" name="status" value="not_available"> The report isn't available for this site
                </label>
                <input type="text" name="note" maxlength="200" placeholder="Note (optional)" class="{{ $inputClass }}">
                <button type="submit" class="btn-pill-nav text-xs">Save</button>
            </form>
            @if ($aiEntries->isNotEmpty())
                <ul class="text-xs mt-4 space-y-1">
                    @foreach ($aiEntries as $entry)
                        <li class="flex justify-between">
                            <span class="font-data">{{ $entry->month->format('Y-m') }}</span>
                            <span>{{ $entry->status === 'not_available' ? 'not available' : number_format((int) $entry->ai_impressions) }} <span class="text-[var(--color-ink-soft)]">({{ $entry->source }})</span></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</div>

<form id="refresh-properties" method="POST" action="{{ route('seo.sites.properties.refresh', $site) }}" class="hidden">@csrf</form>
@endsection
