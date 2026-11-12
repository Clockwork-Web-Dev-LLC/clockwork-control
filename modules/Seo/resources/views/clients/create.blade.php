@extends('layouts.app')

@section('title', 'Add SEO client · Clockwork')

@section('content')
<x-page-header title="Add an SEO client" subtitle="A domain is enough to start. Everything else can be filled in or changed later on the client's profile.">
    <x-slot:actions>
        <a href="{{ route('seo.clients.index') }}" class="btn-pill-nav text-sm"><i class="fa-solid fa-arrow-left"></i> Clients</a>
    </x-slot:actions>
</x-page-header>

@include('seo::_nav')
@include('seo::_flash')

@php
    $inputClass = 'mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full';
@endphp

<form method="POST" action="{{ route('seo.clients.store') }}" class="max-w-3xl space-y-6">
    @csrf

    <x-card title="Website" icon="fa-globe">
        <label class="block text-sm mb-4">
            <span class="font-medium text-[var(--color-ink-strong)]">Domain</span>
            <input type="text" name="domain" required maxlength="255" value="{{ old('domain') }}" placeholder="example.com" list="existing-sites" autocomplete="off" class="{{ $inputClass }} font-data">
            <datalist id="existing-sites">
                @foreach ($existingSites as $s)
                    <option value="{{ $s->domain }}"></option>
                @endforeach
            </datalist>
            <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Any website works, on any host and any platform. If the site is already in Control it is reused; otherwise it is added as a monitored site.</span>
        </label>

        @if ($clients->isNotEmpty())
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Client <span class="font-normal text-[var(--color-ink-soft)]">(optional)</span></span>
                <select name="client_id" class="{{ $inputClass }}">
                    <option value="">Not linked</option>
                    @foreach ($clients as $c)
                        <option value="{{ $c->id }}" @selected((string) old('client_id') === (string) $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </x-card>

    <x-card title="Google data" icon="fa-magnifying-glass">
        @if ($connection === null)
            <p class="text-sm text-[var(--color-ink-muted)] mb-4">Google isn't connected yet. You can still add the client now; pick its property after an admin connects Google on SEO → Settings.</p>
        @elseif ($connection->isRevoked())
            <p class="text-sm text-[var(--color-status-red)] mb-4">The Google connection was revoked. The client is saved, but nothing syncs until an admin reconnects it.</p>
        @endif

        <label class="block text-sm mb-4">
            <span class="font-medium text-[var(--color-ink-strong)]">Search Console property</span>
            <select name="gsc_site_url" class="{{ $inputClass }} font-data">
                <option value="">Automatic: match the domain</option>
                @foreach ($properties as $p)
                    <option value="{{ $p->property }}" @selected(old('gsc_site_url') === $p->property)>{{ $p->property }} ({{ $p->permission_level ?? 'unknown access' }})</option>
                @endforeach
            </select>
            <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Automatic picks the property the connected Google account can see for this domain. If it can't see one, share the property with that account, then choose it on the client's profile.</span>
        </label>

        <label class="block text-sm mb-4">
            <span class="font-medium text-[var(--color-ink-strong)]">GA4 property ID <span class="font-normal text-[var(--color-ink-soft)]">(optional)</span></span>
            <input type="text" name="ga4_property_id" inputmode="numeric" value="{{ old('ga4_property_id') }}" placeholder="123456789" class="{{ $inputClass }} font-data">
            <span class="block text-xs text-[var(--color-ink-soft)] mt-1">GA4 Admin → Property details. Leave blank to track search data only.</span>
        </label>

        <label class="block text-sm mb-4">
            <span class="font-medium text-[var(--color-ink-strong)]">Brand terms <span class="font-normal text-[var(--color-ink-soft)]">(optional)</span></span>
            <input type="text" name="brand_terms" maxlength="2048" value="{{ old('brand_terms') }}" placeholder="acme, acme plumbing" class="{{ $inputClass }}">
            <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Comma separated. Searches containing these count as branded, so reports can show non-brand growth on its own.</span>
        </label>

        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="compare_yoy" value="0">
            <input type="checkbox" name="compare_yoy" value="1" @checked(old('compare_yoy'))>
            <span>Compare with the same month last year instead of the month before (for seasonal businesses)</span>
        </label>
    </x-card>

    <x-card title="Keyword research" icon="fa-key">
        @include('seo::clients._market', ['profile' => null, 'inputClass' => $inputClass])
    </x-card>

    <div class="flex items-center gap-3">
        <button type="submit" class="btn-primary text-sm"><i class="fa-solid fa-plus"></i> Add client</button>
        <a href="{{ route('seo.clients.index') }}" class="text-sm text-[var(--color-ink-muted)] hover:underline">Cancel</a>
    </div>
</form>
@endsection
