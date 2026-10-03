@extends('layouts.app')

@section('title', 'Email Authentication · Clockwork')

@section('content')
<div class="relative"
     x-data="{
        activeDomain: null,
        drawerOpen: false,
        scanningDomain: null,
        customSelectorsInput: '',
        savingSelectors: false,

        openDrawer(domainData) {
            this.activeDomain = domainData;
            this.customSelectorsInput = (domainData.custom_dkim_selectors || []).join(', ');
            this.drawerOpen = true;
        },

        closeDrawer() {
            this.drawerOpen = false;
            this.activeDomain = null;
        },

        async scanDomain(domain) {
            this.scanningDomain = domain;
            try {
                const res = await fetch('{{ route('email-auth.scan') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ domain: domain })
                });
                const data = await res.json();
                if (data.ok && this.activeDomain && this.activeDomain.domain === domain) {
                    this.activeDomain.latest_check = data.check;
                    this.activeDomain.last_overall_status = data.check.overall_status;
                    this.activeDomain.last_checked_at = data.check.checked_at;
                }
                window.location.reload();
            } catch (e) {
                console.error('Scan failed:', e);
            } finally {
                this.scanningDomain = null;
            }
        },

        async toggleIgnore(domain) {
            try {
                const res = await fetch(`/email-auth/domains/${domain}/ignore`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({})
                });
                window.location.reload();
            } catch (e) {
                console.error(e);
            }
        },

        async saveSelectors(domain) {
            this.savingSelectors = true;
            try {
                const res = await fetch(`/email-auth/domains/${domain}/selectors`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ selectors: this.customSelectorsInput })
                });
                const data = await res.json();
                if (data.ok && this.activeDomain) {
                    this.activeDomain.custom_dkim_selectors = data.custom_dkim_selectors;
                    this.activeDomain.latest_check = data.check;
                }
                window.location.reload();
            } catch (e) {
                console.error(e);
            } finally {
                this.savingSelectors = false;
            }
        },

        copyToClipboard(text) {
            navigator.clipboard.writeText(text);
            alert('Copied to clipboard!');
        }
     }">

    <x-page-header title="Email Authentication"
        subtitle="Monitor SPF 10-lookup limits, recursive loop detection, DMARC enforcement policies, and DKIM selector validation across your fleet.">
        <x-slot:actions>
            <form method="POST" action="{{ route('email-auth.scan') }}" class="flex items-center gap-2">
                @csrf
                <input type="text" name="domain" placeholder="Test apex domain..." required
                       class="text-xs font-data border border-[var(--color-border)] rounded-lg px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500 bg-[var(--color-surface)]">
                <button type="submit" class="btn-primary text-xs py-1.5 px-3">
                    <i class="fa-solid fa-magnifying-glass mr-1"></i> Scan Domain
                </button>
            </form>
        </x-slot:actions>
    </x-page-header>

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        <a href="{{ route('email-auth.index') }}"
           class="card p-4 border transition-all {{ $status === null ? 'border-indigo-500/40 ring-1 ring-indigo-500/20' : 'hover:border-[var(--color-border)]' }}">
            <span class="text-[11px] font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider block">Monitored Domains</span>
            <span class="text-2xl font-bold font-data text-[var(--color-ink-strong)] mt-1 block">{{ $totalMonitored }}</span>
        </a>

        <a href="{{ route('email-auth.index', ['status' => 'pass']) }}"
           class="card p-4 border transition-all {{ $status === 'pass' ? 'border-emerald-500/40 ring-1 ring-emerald-500/20' : 'hover:border-[var(--color-border)]' }}">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider block">Passing</span>
                <i class="fa-solid fa-circle-check text-emerald-500 text-xs"></i>
            </div>
            <span class="text-2xl font-bold font-data text-emerald-600 dark:text-emerald-400 mt-1 block">{{ $totalPass }}</span>
        </a>

        <a href="{{ route('email-auth.index', ['status' => 'warn']) }}"
           class="card p-4 border transition-all {{ $status === 'warn' ? 'border-amber-500/40 ring-1 ring-amber-500/20' : 'hover:border-[var(--color-border)]' }}">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider block">Warnings</span>
                <i class="fa-solid fa-triangle-exclamation text-amber-500 text-xs"></i>
            </div>
            <span class="text-2xl font-bold font-data text-amber-600 dark:text-amber-400 mt-1 block">{{ $totalWarn }}</span>
        </a>

        <a href="{{ route('email-auth.index', ['status' => 'fail']) }}"
           class="card p-4 border transition-all {{ $status === 'fail' ? 'border-rose-500/40 ring-1 ring-rose-500/20' : 'hover:border-[var(--color-border)]' }}">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold text-rose-600 dark:text-rose-400 uppercase tracking-wider block">Failing</span>
                <i class="fa-solid fa-circle-xmark text-rose-500 text-xs"></i>
            </div>
            <span class="text-2xl font-bold font-data text-rose-600 dark:text-rose-400 mt-1 block">{{ $totalFail }}</span>
        </a>

        <a href="{{ route('email-auth.index', ['status' => 'ignored']) }}"
           class="card p-4 border transition-all {{ $status === 'ignored' ? 'border-slate-500/40 ring-1 ring-slate-500/20' : 'hover:border-[var(--color-border)]' }}">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider block">Ignored</span>
                <i class="fa-solid fa-eye-slash text-slate-400 text-xs"></i>
            </div>
            <span class="text-2xl font-bold font-data text-slate-500 mt-1 block">{{ $totalIgnored }}</span>
        </a>
    </div>

    {{-- Filter & Search Bar --}}
    <div class="card p-4 flex flex-wrap items-center justify-between gap-4 mb-6">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('email-auth.index', array_filter(['search' => $search])) }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold border transition {{ $status === null ? 'bg-indigo-500/10 text-indigo-600 border-indigo-500/30' : 'border-[var(--color-border)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}">
                All Domains
            </a>
            <a href="{{ route('email-auth.index', array_filter(['status' => 'pass', 'search' => $search])) }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold border transition {{ $status === 'pass' ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/30' : 'border-[var(--color-border)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}">
                Pass
            </a>
            <a href="{{ route('email-auth.index', array_filter(['status' => 'warn', 'search' => $search])) }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold border transition {{ $status === 'warn' ? 'bg-amber-500/10 text-amber-600 border-amber-500/30' : 'border-[var(--color-border)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}">
                Warning
            </a>
            <a href="{{ route('email-auth.index', array_filter(['status' => 'fail', 'search' => $search])) }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold border transition {{ $status === 'fail' ? 'bg-rose-500/10 text-rose-600 border-rose-500/30' : 'border-[var(--color-border)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}">
                Fail
            </a>
            <a href="{{ route('email-auth.index', array_filter(['status' => 'ignored', 'search' => $search])) }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold border transition {{ $status === 'ignored' ? 'bg-slate-500/10 text-slate-600 border-slate-500/30' : 'border-[var(--color-border)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}">
                Ignored
            </a>
        </div>

        <form method="GET" action="{{ route('email-auth.index') }}" class="flex items-center gap-2">
            @if ($status)
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <input type="text" name="search" value="{{ $search }}" placeholder="Search apex domains..."
                   class="text-xs font-data border border-[var(--color-border)] rounded-md px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500 bg-[var(--color-surface)]">
            <button type="submit" class="btn-pill-nav text-xs py-1.5 px-3 border border-[var(--color-border)]">Search</button>
            @if ($search)
                <a href="{{ route('email-auth.index', array_filter(['status' => $status])) }}" class="text-xs text-[var(--color-ink-muted)] hover:underline">Clear</a>
            @endif
        </form>
    </div>

    {{-- Fleet Table --}}
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] uppercase text-[10px] tracking-wider border-b border-[var(--color-border)]">
                    <tr>
                        <th class="p-3.5">Apex Domain</th>
                        <th class="p-3.5">Sites</th>
                        <th class="p-3.5">Posture</th>
                        <th class="p-3.5">SPF Status</th>
                        <th class="p-3.5">DMARC Policy</th>
                        <th class="p-3.5">DKIM Selectors</th>
                        <th class="p-3.5">Last Checked</th>
                        <th class="p-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border)]">
                    @forelse ($domains as $domain)
                        @php
                            $check = $domain->latestCheck;
                        @endphp
                        <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors {{ $domain->isIgnored() ? 'opacity-60 bg-slate-500/5' : '' }}">
                            <td class="p-3.5 font-medium font-data text-sm">
                                <span class="text-[var(--color-ink-strong)]">{{ $domain->domain }}</span>
                                @if ($domain->isIgnored())
                                    <span class="ml-1 text-[10px] uppercase font-bold text-slate-400">(Ignored)</span>
                                @endif
                            </td>

                            <td class="p-3.5 font-data">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-[var(--color-surface-alt)] border border-[var(--color-border)]">
                                    {{ $siteCounts[$domain->domain] ?? 0 }} {{ ($siteCounts[$domain->domain] ?? 0) === 1 ? 'site' : 'sites' }}
                                </span>
                            </td>

                            <td class="p-3.5">
                                @if ($domain->isIgnored())
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full font-data text-[11px] font-semibold border bg-slate-500/15 text-slate-500 border-slate-500/30">
                                        IGNORED
                                    </span>
                                @elseif ($check)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full font-data text-[11px] font-semibold border {{ $check->statusBadgeClass() }}">
                                        {{ strtoupper($check->overall_status) }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full font-data text-[11px] font-semibold border bg-neutral-500/15 text-neutral-500 border-neutral-500/30">
                                        UNSCANNED
                                    </span>
                                @endif
                            </td>

                            <td class="p-3.5 font-data">
                                @if ($check)
                                    <div class="flex items-center gap-1.5">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border {{ $check->statusBadgeClass($check->spf_status) }}">
                                            {{ strtoupper($check->spf_status) }}
                                        </span>
                                        <span class="text-[11px] text-[var(--color-ink-muted)]">
                                            ({{ $check->spf_lookup_count }}/10)
                                        </span>
                                    </div>
                                @else
                                    <span class="text-[var(--color-ink-muted)]">—</span>
                                @endif
                            </td>

                            <td class="p-3.5 font-data">
                                @if ($check)
                                    <div class="flex items-center gap-1.5">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border {{ $check->statusBadgeClass($check->dmarc_status) }}">
                                            {{ strtoupper($check->dmarc_status) }}
                                        </span>
                                        <span class="text-[11px] text-[var(--color-ink-muted)] font-mono">
                                            {{ $check->dmarc_policy ? 'p='.$check->dmarc_policy : 'none' }}
                                        </span>
                                    </div>
                                @else
                                    <span class="text-[var(--color-ink-muted)]">—</span>
                                @endif
                            </td>

                            <td class="p-3.5 font-data">
                                @if ($check)
                                    <div class="flex items-center gap-1.5">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border {{ $check->statusBadgeClass($check->dkim_status) }}">
                                            {{ strtoupper($check->dkim_status) }}
                                        </span>
                                        <span class="text-[11px] text-[var(--color-ink-muted)]">
                                            {{ count($check->dkim_selectors_found ?? []) }} found
                                        </span>
                                    </div>
                                @else
                                    <span class="text-[var(--color-ink-muted)]">—</span>
                                @endif
                            </td>

                            <td class="p-3.5 text-[var(--color-ink-muted)] font-data text-[11px]">
                                {{ $domain->last_checked_at ? $domain->last_checked_at->diffForHumans() : 'Never' }}
                            </td>

                            <td class="p-3.5 text-right">
                                <button type="button"
                                        @click="openDrawer({{ json_encode($domain->toArray() + ['latest_check' => $check?->toArray()]) }})"
                                        class="btn-pill-nav text-xs py-1 px-2.5 font-medium border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)]">
                                    <i class="fa-solid fa-sliders text-[10px] mr-1"></i> Inspect
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-8 text-center text-[var(--color-ink-muted)] italic">
                                No email authentication domains found matching criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($domains->hasPages())
            <div class="p-4 border-t border-[var(--color-border)]">
                {{ $domains->links() }}
            </div>
        @endif
    </div>

    {{-- Slide-Over Inspector Drawer --}}
    <div x-show="drawerOpen"
         style="display: none;"
         class="fixed inset-0 z-50 overflow-hidden"
         aria-labelledby="slide-over-title" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm transition-opacity"
             x-show="drawerOpen"
             x-transition:enter="ease-in-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in-out duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             @click="closeDrawer()"></div>

        <div class="fixed inset-y-0 right-0 pl-10 max-w-full flex">
            <div class="w-screen max-w-xl bg-[var(--color-surface)] border-l border-[var(--color-border)] shadow-2xl flex flex-col"
                 x-show="drawerOpen"
                 x-transition:enter="transform transition ease-in-out duration-300"
                 x-transition:enter-start="translate-x-full"
                 x-transition:enter-end="translate-x-0"
                 x-transition:leave="transform transition ease-in-out duration-200"
                 x-transition:leave-start="translate-x-0"
                 x-transition:leave-end="translate-x-full">
                
                {{-- Drawer Header --}}
                <div class="p-5 border-b border-[var(--color-border)] flex items-center justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-bold font-data text-[var(--color-ink-strong)]" x-text="activeDomain?.domain"></h2>
                            <template x-if="activeDomain?.latest_check">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border uppercase"
                                      :class="activeDomain.latest_check.overall_status === 'pass' ? 'bg-emerald-500/15 text-emerald-600 border-emerald-500/30' : (activeDomain.latest_check.overall_status === 'warn' ? 'bg-amber-500/15 text-amber-600 border-amber-500/30' : 'bg-rose-500/15 text-rose-600 border-rose-500/30')"
                                      x-text="activeDomain.latest_check.overall_status"></span>
                            </template>
                        </div>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                            Last checked <span x-text="activeDomain?.last_checked_at || 'Never'"></span>
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" @click="scanDomain(activeDomain?.domain)"
                                :disabled="scanningDomain !== null"
                                class="btn-primary text-xs py-1.5 px-3">
                            <i class="fa-solid fa-arrows-rotate mr-1" :class="scanningDomain ? 'fa-spin' : ''"></i> Re-check
                        </button>
                        <button type="button" @click="closeDrawer()"
                                class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] p-1.5 rounded-md">
                            <i class="fa-solid fa-xmark text-sm"></i>
                        </button>
                    </div>
                </div>

                {{-- Drawer Body --}}
                <div class="flex-1 overflow-y-auto p-5 space-y-5">
                    {{-- Ignore Toggle --}}
                    <div class="card p-3 bg-[var(--color-surface-alt)] flex items-center justify-between">
                        <div class="text-xs">
                            <span class="font-bold text-[var(--color-ink-strong)] block">Alert Suppression</span>
                            <span class="text-[var(--color-ink-muted)]" x-text="activeDomain?.ignored_at ? 'Currently ignored from degradation alerts' : 'Actively monitored for chat alerts'"></span>
                        </div>
                        <button type="button" @click="toggleIgnore(activeDomain?.domain)"
                                class="btn-pill-nav text-xs py-1 px-3 border border-[var(--color-border)]"
                                x-text="activeDomain?.ignored_at ? 'Unignore Domain' : 'Ignore from Alerts'"></button>
                    </div>

                    {{-- Findings Section --}}
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)] mb-2">Posture Findings</h4>
                        <template x-if="activeDomain?.latest_check?.findings && activeDomain.latest_check.findings.length > 0">
                            <div class="space-y-2">
                                <template x-for="f in activeDomain.latest_check.findings" :key="f.code">
                                    <div class="p-3 rounded-lg border text-xs leading-relaxed"
                                         :class="f.severity === 'fail' ? 'bg-rose-500/10 border-rose-500/30 text-rose-900 dark:text-rose-200' : (f.severity === 'warn' ? 'bg-amber-500/10 border-amber-500/30 text-amber-900 dark:text-amber-200' : 'bg-emerald-500/10 border-emerald-500/30 text-emerald-900 dark:text-emerald-200')">
                                        <div class="flex items-center gap-1.5 font-bold mb-1">
                                            <i class="fa-solid" :class="f.severity === 'fail' ? 'fa-circle-xmark text-rose-500' : (f.severity === 'warn' ? 'fa-triangle-exclamation text-amber-500' : 'fa-circle-check text-emerald-500')"></i>
                                            <span class="uppercase text-[10px]" x-text="f.check"></span> · <span x-text="f.code"></span>
                                        </div>
                                        <div x-text="f.message"></div>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <template x-if="!activeDomain?.latest_check?.findings || activeDomain.latest_check.findings.length === 0">
                            <div class="card p-4 text-center text-xs text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border-emerald-500/20 font-medium">
                                <i class="fa-solid fa-shield-check mr-1.5"></i> All email authentication checks passed with 0 warnings.
                            </div>
                        </template>
                    </div>

                    {{-- SPF Record Inspector --}}
                    <div class="card p-4 space-y-2.5">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)]">SPF (RFC 7208)</h4>
                            <span class="text-xs font-data font-semibold text-[var(--color-ink-muted)]"
                                  x-text="(activeDomain?.latest_check?.spf_lookup_count || 0) + ' / 10 DNS lookups'"></span>
                        </div>
                        <div class="bg-slate-950 p-3 rounded-lg border border-slate-800 text-slate-100 font-mono text-xs break-all flex items-center justify-between gap-2">
                            <span x-text="activeDomain?.latest_check?.spf_record || 'None published'"></span>
                            <button type="button" x-show="activeDomain?.latest_check?.spf_record"
                                    @click="copyToClipboard(activeDomain.latest_check.spf_record)"
                                    class="text-slate-400 hover:text-white shrink-0 p-1" title="Copy SPF">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    {{-- DMARC Record Inspector --}}
                    <div class="card p-4 space-y-2.5">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)]">DMARC Policy (RFC 7489)</h4>
                            <span class="text-xs font-data font-semibold text-[var(--color-ink-muted)]"
                                  x-text="'Policy: ' + (activeDomain?.latest_check?.dmarc_policy || 'none')"></span>
                        </div>
                        <div class="bg-slate-950 p-3 rounded-lg border border-slate-800 text-slate-100 font-mono text-xs break-all flex items-center justify-between gap-2">
                            <span x-text="activeDomain?.latest_check?.dmarc_record || 'None published at _dmarc'"></span>
                            <button type="button" x-show="activeDomain?.latest_check?.dmarc_record"
                                    @click="copyToClipboard(activeDomain.latest_check.dmarc_record)"
                                    class="text-slate-400 hover:text-white shrink-0 p-1" title="Copy DMARC">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    {{-- DKIM Selectors Inspector & Config --}}
                    <div class="card p-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)]">DKIM Probing</h4>
                            <span class="text-xs font-data text-emerald-500 font-semibold"
                                  x-text="(activeDomain?.latest_check?.dkim_selectors_found || []).length + ' detected'"></span>
                        </div>

                        <div>
                            <span class="text-[11px] text-[var(--color-ink-muted)] font-semibold block mb-1">Detected Selectors:</span>
                            <div class="flex flex-wrap gap-1.5">
                                <template x-for="sel in (activeDomain?.latest_check?.dkim_selectors_found || [])" :key="sel">
                                    <span class="px-2 py-0.5 rounded bg-emerald-500/10 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 font-mono text-xs font-semibold" x-text="sel"></span>
                                </template>
                                <template x-if="!(activeDomain?.latest_check?.dkim_selectors_found || []).length">
                                    <span class="text-xs text-[var(--color-ink-muted)] italic">None of standard probes found.</span>
                                </template>
                            </div>
                        </div>

                        <div class="pt-2 border-t border-[var(--color-border-light)]">
                            <label class="block text-xs font-semibold text-[var(--color-ink-muted)] mb-1">
                                Custom Selectors (comma-separated):
                            </label>
                            <div class="flex items-center gap-2">
                                <input type="text" x-model="customSelectorsInput" placeholder="e.g. mandrill, s1, k1, selector1"
                                       class="flex-1 text-xs font-data border border-[var(--color-border)] rounded-md px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500 bg-[var(--color-surface-alt)]">
                                <button type="button" @click="saveSelectors(activeDomain.domain)"
                                        :disabled="savingSelectors"
                                        class="btn-primary text-xs py-1.5 px-3 shrink-0">
                                    Save & Probe
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
