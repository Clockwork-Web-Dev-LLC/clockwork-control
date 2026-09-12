@extends('layouts.app')

@section('title', 'Care Plans Policy · Clockwork')

@section('content')
    @include('settings._tabs')

    <x-page-header title="Care Plans Policy"
        subtitle="Configure whether your agency tracks care plan enrollments individually or treats all managed sites as covered for routine maintenance and automated scans.">
        <x-slot:actions>
            <a href="{{ route('updates.carePlan') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-moon text-[var(--color-ink-muted)]"></i>
                <span>Auto-Update Curation</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
        </div>
    @endif

    {{-- Roll-up metric tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 max-w-4xl">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Platform Policy</div>
            <div class="text-xl font-display text-[var(--color-ink-strong)] font-semibold mt-1">
                {{ $enabled ? 'Enrolled Only' : 'Fleet-Wide' }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">
                {{ $enabled ? 'Tiered care plans' : 'All sites covered' }}
            </div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Total Sites</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ number_format($totalSites) }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Managed in fleet</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Covered Sites</div>
            <div class="text-2xl font-display {{ $enabled ? 'text-[var(--color-brand)]' : 'text-[var(--color-status-green)]' }} font-data">
                {{ $enabled ? number_format($enrolledSites) : number_format($totalSites) }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">
                {{ $enabled ? 'Explicitly enrolled' : '100% of fleet' }}
            </div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Nightly Auto-Updates</div>
            <div class="text-2xl font-display text-[var(--color-status-green)] font-data">
                {{ number_format($autoUpdateSites) }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Active update path</div>
        </div>
    </div>

    <form method="POST" action="{{ route('settings.care-plans.update') }}" class="card p-6 max-w-4xl mb-6">
        @csrf
        @method('PATCH')

        <div class="flex items-start justify-between gap-4 pb-4 border-b border-[var(--color-border-light)] mb-6">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-shield-heart text-emerald-600"></i>
                    Care Plans Policy
                </h2>
                <p class="text-xs text-[var(--color-ink-muted)] mt-1">
                    Control how Clockwork Control gates automated maintenance, scans, and updates across your sites.
                </p>
            </div>
            <span class="text-xs px-2.5 py-1 rounded-full font-medium {{ $enabled ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800' : 'bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-400 border border-blue-200 dark:border-blue-800' }}">
                {{ $enabled ? 'Tiered Enrollment' : 'Fleet-Wide Coverage' }}
            </span>
        </div>

        <div class="space-y-6">
            {{-- Master toggle --}}
            <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/40">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="hidden" name="care_plans_enabled" value="0">
                    <input type="checkbox" name="care_plans_enabled" value="1" @checked($enabled)
                           class="mt-1 rounded border-[var(--color-border-light)] text-[var(--color-brand)] focus:ring-[var(--color-brand)]/20">
                    <div>
                        <div class="text-sm font-semibold text-[var(--color-ink-strong)]">
                            Enable Care Plans Tiering
                        </div>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-1 leading-relaxed">
                            When enabled, sites must be individually enrolled in a care plan to receive scheduled plugin updates, core checksum verifications, and routine security scans. Care plan badges and status indicators are shown throughout the dashboard.
                        </p>
                        <p class="text-xs text-[var(--color-ink-soft)] mt-2 leading-relaxed">
                            <strong>When disabled:</strong> Care plan tiering is turned off globally. Every site in your fleet is treated as covered for routine maintenance, automated updates, and security scans. All "Care plan" / "No care plan" badges, warning banners, and settings cards are cleanly suppressed from the interface.
                        </p>
                    </div>
                </label>
            </div>

            {{-- Policy Comparison Details --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div class="p-4 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)]">
                    <div class="font-semibold text-[var(--color-ink-strong)] flex items-center gap-1.5 mb-2">
                        <i class="fa-solid fa-toggle-on text-emerald-600"></i>
                        When Tiered (Enabled)
                    </div>
                    <ul class="space-y-1.5 text-[var(--color-ink-muted)] list-disc list-inside">
                        <li>Each site can be individually marked on or off a care plan in Site Settings.</li>
                        <li>Automated nightly plugin updates only target enrolled sites.</li>
                        <li>Sucuri SiteCheck and WP Core checksum scans run on enrolled sites.</li>
                        <li>"Care plan" and "No care plan" indicators display on site overviews and lists.</li>
                    </ul>
                </div>

                <div class="p-4 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)]">
                    <div class="font-semibold text-[var(--color-ink-strong)] flex items-center gap-1.5 mb-2">
                        <i class="fa-solid fa-toggle-off text-blue-600"></i>
                        When Fleet-Wide (Disabled)
                    </div>
                    <ul class="space-y-1.5 text-[var(--color-ink-muted)] list-disc list-inside">
                        <li>All sites are treated as covered for routine maintenance and automated updates.</li>
                        <li>All sites are included in scheduled security scans and integrity checks.</li>
                        <li>Care plan badges, warning banners, and enrollment cards are hidden from the UI.</li>
                        <li>Best for internal IT teams or agencies offering uniform maintenance to all clients.</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="pt-5 mt-6 border-t border-[var(--color-border-light)] flex justify-end">
            <button type="submit" class="btn-primary text-xs px-5 py-2">
                <i class="fa-solid fa-check mr-1.5"></i> Save Care Plans Policy
            </button>
        </div>
    </form>
@endsection
