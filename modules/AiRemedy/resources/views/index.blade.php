@extends('layouts.app')

@section('title', 'AiRemedy · AI Operations & Self-Healing')

@section('content')
@include('operations._tabs')
@include('ai-remedy::_review_script')
@php($canManageRuns = (bool) auth()->user()?->isAdmin())
<div class="relative"
     @ai-remedy-executed.window="if (activeRun && $event.detail.run && activeRun.id === $event.detail.runId) { activeRun.status = $event.detail.run.status; }"
     x-data="{
    activeRun: null,
    drawerOpen: false,
    selected: [],
    pageIds: @js($runs->pluck('id')->map(fn ($id) => (string) $id)->values()),
    get allSelected() { return this.pageIds.length > 0 && this.pageIds.every((id) => this.selected.includes(id)); },
    toggleAll() { this.selected = this.allSelected ? [] : [...this.pageIds]; },
    openDrawer(run) {
        this.activeRun = run;
        this.drawerOpen = true;
    },
    closeDrawer() {
        this.drawerOpen = false;
        this.activeRun = null;
    },
    formatSafetyTier(tier) {
        if (!tier) return 'Unknown';
        switch (tier) {
            case 'tier_1_safe': return 'Tier 1 · Safe';
            case 'tier_2_cautious': return 'Tier 2 · Cautious';
            case 'tier_3_prohibited': return 'Tier 3 · Prohibited';
            case 'tier_unfixable':
            case 'unfixable': return 'Unfixable';
            default: return tier.replace(/^tier_/, 'Tier ').replace(/_/g, ' ');
        }
    },
    safetyBadgeClass(tier) {
        if (!tier) return 'bg-neutral-500/15 text-neutral-600 dark:text-neutral-400 border-neutral-500/30';
        switch (tier) {
            case 'tier_1_safe':
                return 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30';
            case 'tier_2_cautious':
                return 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30';
            case 'tier_3_prohibited':
            case 'tier_unfixable':
            case 'unfixable':
                return 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30';
            default:
                return 'bg-neutral-500/15 text-neutral-600 dark:text-neutral-400 border-neutral-500/30';
        }
    }
}">
    <x-page-header title="AiRemedy"
        subtitle="AI-powered server diagnostics, root-cause forensics, and self-healing remediation via OpenRouter.">
        <x-slot:actions>
            <a href="{{ route('ai-remedy.settings') }}"
               class="btn-pill-nav text-xs md:text-sm py-1.5 px-3 flex items-center gap-2 cursor-pointer font-medium border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)]">
                <i class="fa-solid fa-gear text-xs"></i>
                <span>Configure Settings</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="card p-4 flex flex-col justify-between">
            <span class="text-xs uppercase font-bold tracking-wider text-[var(--color-ink-muted)]">Total Diagnoses</span>
            <div class="flex items-baseline justify-between mt-2">
                <span class="text-2xl font-bold font-data text-[var(--color-ink-strong)]">{{ $stats['total'] }}</span>
                <i class="fa-solid fa-wand-magic-sparkles text-[var(--color-brand)] opacity-60 text-lg"></i>
            </div>
        </div>

        <div class="card p-4 flex flex-col justify-between">
            <span class="text-xs uppercase font-bold tracking-wider text-[var(--color-ink-muted)]">Resolved & Healed</span>
            <div class="flex items-baseline justify-between mt-2">
                <span class="text-2xl font-bold font-data text-emerald-600 dark:text-emerald-400">{{ $stats['resolved'] }}</span>
                <i class="fa-solid fa-circle-check text-emerald-500 opacity-60 text-lg"></i>
            </div>
        </div>

        <div class="card p-4 flex flex-col justify-between">
            <span class="text-xs uppercase font-bold tracking-wider text-[var(--color-ink-muted)]">Unfixable / Escalated</span>
            <div class="flex items-baseline justify-between mt-2">
                <span class="text-2xl font-bold font-data text-purple-600 dark:text-purple-400">{{ $stats['unfixable'] }}</span>
                <i class="fa-solid fa-triangle-exclamation text-purple-500 opacity-60 text-lg"></i>
            </div>
        </div>

        <div class="card p-4 flex flex-col justify-between">
            <span class="text-xs uppercase font-bold tracking-wider text-[var(--color-ink-muted)]">Total AI Cost</span>
            <div class="flex items-baseline justify-between mt-2">
                <span class="text-2xl font-bold font-data text-[var(--color-ink-strong)]">${{ number_format($stats['total_cost'], 4) }}</span>
                <i class="fa-solid fa-receipt text-amber-500 opacity-60 text-lg"></i>
            </div>
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="card p-3 mb-6 flex flex-wrap items-center justify-between gap-3 text-xs">
        <div class="flex items-center gap-1.5 overflow-x-auto py-1">
            <a href="{{ route('ai-remedy.index', array_filter(['server_id' => $selectedServerId])) }}"
               class="px-2.5 py-1 rounded-full font-medium transition-colors {{ empty($activeStatus) ? 'bg-[var(--color-brand)] text-white' : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}">
                All Runs
            </a>
            <a href="{{ route('ai-remedy.index', array_filter(['status' => 'resolved', 'server_id' => $selectedServerId])) }}"
               class="px-2.5 py-1 rounded-full font-medium transition-colors {{ $activeStatus === 'resolved' ? 'bg-emerald-600 text-white' : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}">
                Resolved
            </a>
            <a href="{{ route('ai-remedy.index', array_filter(['status' => 'analyzed', 'server_id' => $selectedServerId])) }}"
               class="px-2.5 py-1 rounded-full font-medium transition-colors {{ $activeStatus === 'analyzed' ? 'bg-blue-600 text-white' : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}">
                Analyzed (Watch/Pending)
            </a>
            <a href="{{ route('ai-remedy.index', array_filter(['status' => 'unfixable', 'server_id' => $selectedServerId])) }}"
               class="px-2.5 py-1 rounded-full font-medium transition-colors {{ $activeStatus === 'unfixable' ? 'bg-purple-600 text-white' : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}">
                Unfixable
            </a>
            <a href="{{ route('ai-remedy.index', array_filter(['status' => 'allowed_maintenance', 'server_id' => $selectedServerId])) }}"
               class="px-2.5 py-1 rounded-full font-medium transition-colors {{ $activeStatus === 'allowed_maintenance' ? 'bg-sky-600 text-white' : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}">
                Allowed Maint.
            </a>
            <a href="{{ route('ai-remedy.index', array_filter(['status' => 'failed', 'server_id' => $selectedServerId])) }}"
               class="px-2.5 py-1 rounded-full font-medium transition-colors {{ $activeStatus === 'failed' ? 'bg-rose-600 text-white' : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}">
                Failed
            </a>
        </div>

        {{-- Server Dropdown Filter --}}
        <form method="GET" action="{{ route('ai-remedy.index') }}" class="flex items-center gap-2">
            @if($activeStatus)
                <input type="hidden" name="status" value="{{ $activeStatus }}">
            @endif
            <select name="server_id" onchange="this.form.submit()"
                    class="input text-xs py-1 px-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)]">
                <option value="">All Servers</option>
                @foreach($servers as $srv)
                    <option value="{{ $srv->id }}" {{ (string)$selectedServerId === (string)$srv->id ? 'selected' : '' }}>
                        {{ $srv->name }} ({{ $srv->hostname }})
                    </option>
                @endforeach
            </select>
        </form>
    </div>

    {{-- Audit Log Runs Table --}}
    @if($runs->isEmpty())
        <div class="card p-12 text-center text-[var(--color-ink-muted)]">
            <i class="fa-solid fa-clipboard-check text-4xl mb-3 opacity-40"></i>
            <h3 class="text-base font-semibold text-[var(--color-ink-strong)]">No AiRemedy records found</h3>
            <p class="text-xs mt-1 max-w-md mx-auto">
                AiRemedy logs every server diagnosis, proposed command, and watch-mode audit. Run a test simulation in settings to verify.
            </p>
            <div class="mt-4">
                <a href="{{ route('ai-remedy.settings') }}" class="btn-primary text-xs py-2 px-4 inline-flex items-center gap-2">
                    <i class="fa-solid fa-play text-xs"></i>
                    <span>Run Simulation in Settings</span>
                </a>
            </div>
        </div>
    @else
        @if(session('status'))
            <div class="card p-3.5 mb-4 bg-emerald-500/10 border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-xs flex items-center gap-2">
                <i class="fa-solid fa-circle-check"></i>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if($canManageRuns)
            <form id="ai-remedy-bulk-delete" method="POST" action="{{ route('ai-remedy.runs.destroy') }}"
                  @submit="if (!confirm(`Delete ${selected.length} selected run${selected.length === 1 ? '' : 's'}? Executed fixes stay in the action log.`)) $event.preventDefault()">
                @csrf
                @method('DELETE')
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>
            </form>
            <div x-cloak x-show="selected.length > 0"
                 class="card mb-3 px-4 py-2.5 flex items-center justify-between gap-3 text-xs border-[var(--color-brand)]/30">
                <span class="font-semibold text-[var(--color-ink-strong)]"><span x-text="selected.length"></span> selected</span>
                <div class="flex items-center gap-2">
                    <button type="button" @click="toggleAll()" class="btn-pill-nav text-xs py-1 px-3 border border-[var(--color-border)] cursor-pointer" x-text="allSelected ? 'Unselect page' : 'Select page'"></button>
                    <button type="button" @click="selected = []" class="btn-pill-nav text-xs py-1 px-3 border border-[var(--color-border)] cursor-pointer">Clear</button>
                    <button type="submit" form="ai-remedy-bulk-delete"
                            class="text-xs py-1 px-3 rounded-full font-semibold bg-rose-600 hover:bg-rose-700 text-white inline-flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-trash text-[10px]"></i>
                        <span>Delete selected</span>
                    </button>
                </div>
            </div>
        @endif

        <div class="card overflow-hidden">
            {{-- Desktop Table View: Large screens and up (lg:block) --}}
            <div class="hidden lg:block overflow-x-auto">
                <table class="w-full text-left text-xs min-w-[920px]">
                    <thead class="bg-[var(--color-surface-alt)] border-b border-[var(--color-border-light)] text-[var(--color-ink-muted)] uppercase font-semibold">
                        <tr>
                            @if($canManageRuns)
                                <th class="py-3 pl-4 pr-0 w-8">
                                    <input type="checkbox" class="rounded cursor-pointer" :checked="allSelected" @change="toggleAll()" aria-label="Select all runs on this page">
                                </th>
                            @endif
                            <th class="py-3 px-4 whitespace-nowrap">Time & Mode</th>
                            <th class="py-3 px-4 whitespace-nowrap">Target Server / Site</th>
                            <th class="py-3 px-4 min-w-[200px]">Root Cause & Diagnosis</th>
                            <th class="py-3 px-4 whitespace-nowrap">Safety Tier</th>
                            <th class="py-3 px-4 whitespace-nowrap">Status</th>
                            <th class="py-3 px-4 text-right whitespace-nowrap">Cost</th>
                            <th class="py-3 px-4 text-right whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach($runs as $run)
                            <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors"
                                :class="selected.includes('{{ $run->id }}') ? 'bg-[var(--color-brand)]/5' : ''">
                                @if($canManageRuns)
                                    <td class="py-3.5 pl-4 pr-0 w-8">
                                        <input type="checkbox" class="rounded cursor-pointer" value="{{ $run->id }}" x-model="selected" aria-label="Select run #{{ $run->id }}">
                                    </td>
                                @endif
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-data font-semibold text-[var(--color-ink-strong)]">
                                        {{ $run->started_at->format('M j, Y H:i') }}
                                    </div>
                                    <div class="flex items-center gap-1.5 mt-1">
                                        <span class="px-1.5 py-0.5 rounded font-data text-[10px] font-semibold border {{ $run->modeBadgeClass() }}">
                                            {{ $run->modeLabel() }}
                                        </span>
                                        <span class="text-[10px] text-[var(--color-ink-soft)] capitalize">
                                            {{ str_replace('_', ' ', $run->trigger_type) }}
                                        </span>
                                    </div>
                                </td>

                                <td class="py-3.5 px-4 font-medium whitespace-nowrap">
                                    @if($run->server)
                                        <a href="{{ route('servers.show', $run->server) }}" class="text-[var(--color-brand)] hover:underline flex items-center gap-1.5">
                                            <i class="fa-solid fa-server text-[10px]"></i>
                                            <span>{{ $run->server->name }}</span>
                                        </a>
                                        <div class="text-[11px] font-data text-[var(--color-ink-soft)]">
                                            {{ $run->server->hostname }}
                                        </div>
                                    @elseif($run->site)
                                        <div class="text-[var(--color-ink-strong)] font-semibold flex items-center gap-1.5">
                                            <i class="fa-solid fa-globe text-[10px]"></i>
                                            <span>{{ $run->site->domain }}</span>
                                        </div>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">—</span>
                                    @endif
                                </td>

                                <td class="py-3.5 px-4">
                                    <div class="font-semibold text-[var(--color-ink-strong)] truncate max-w-xs xl:max-w-sm" title="{{ $run->root_cause }}">
                                        {{ $run->root_cause ?: 'Analysis completed' }}
                                    </div>
                                    <div class="text-[11px] text-[var(--color-ink-muted)] line-clamp-1 mt-0.5 max-w-xs xl:max-w-sm" title="{{ $run->diagnosis_summary }}">
                                        {{ $run->diagnosis_summary }}
                                    </div>
                                </td>

                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full font-data text-[10px] font-semibold border whitespace-nowrap {{ $run->safetyBadgeClass() }}">
                                        {{ $run->safetyLabel() }}
                                    </span>
                                </td>

                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full font-data text-[10px] font-semibold border whitespace-nowrap {{ $run->statusBadgeClass() }}">
                                        {{ $run->statusLabel() }}
                                    </span>
                                </td>

                                <td class="py-3.5 px-4 text-right font-data font-semibold text-[var(--color-ink-strong)] whitespace-nowrap">
                                    ${{ number_format((float)$run->total_cost_usd, 4) }}
                                </td>

                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <button type="button"
                                            @click="openDrawer({{ json_encode($run->toArray() + ['review' => $reviews[$run->id] ?? null]) }})"
                                            class="btn-pill-nav text-xs py-1 px-2.5 font-medium border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)] cursor-pointer inline-flex items-center gap-1 flex-shrink-0">
                                        <span>Forensics</span>
                                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Mobile & Tablet Stacked Card View: for smaller screens (< lg) --}}
            <div class="block lg:hidden divide-y divide-[var(--color-border-light)]">
                @foreach($runs as $run)
                    <div class="p-4 space-y-3 hover:bg-[var(--color-surface-alt)]/30 transition-colors">
                        {{-- Top line: Timestamp, Mode Badge, Status Badge & Cost --}}
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <div class="flex items-center gap-2">
                                @if($canManageRuns)
                                    <input type="checkbox" class="rounded cursor-pointer" value="{{ $run->id }}" x-model="selected" aria-label="Select run #{{ $run->id }}">
                                @endif
                                <div class="font-data font-semibold text-xs text-[var(--color-ink-strong)]">
                                    {{ $run->started_at->format('M j, Y H:i') }}
                                </div>
                                <span class="px-1.5 py-0.5 rounded font-data text-[10px] font-semibold border {{ $run->modeBadgeClass() }}">
                                    {{ $run->modeLabel() }}
                                </span>
                                <span class="text-[10px] text-[var(--color-ink-soft)] capitalize">
                                    {{ str_replace('_', ' ', $run->trigger_type) }}
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="font-data text-xs font-semibold text-[var(--color-ink-strong)]">
                                    ${{ number_format((float)$run->total_cost_usd, 4) }}
                                </span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full font-data text-[10px] font-semibold border whitespace-nowrap {{ $run->statusBadgeClass() }}">
                                    {{ $run->statusLabel() }}
                                </span>
                            </div>
                        </div>

                        {{-- Middle line: Target Server / Site & Safety Tier --}}
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <div>
                                @if($run->server)
                                    <a href="{{ route('servers.show', $run->server) }}" class="text-[var(--color-brand)] font-medium hover:underline inline-flex items-center gap-1.5 text-xs">
                                        <i class="fa-solid fa-server text-[10px]"></i>
                                        <span>{{ $run->server->name }}</span>
                                        <span class="font-data text-[11px] text-[var(--color-ink-soft)]">({{ $run->server->hostname }})</span>
                                    </a>
                                @elseif($run->site)
                                    <div class="text-[var(--color-ink-strong)] font-semibold inline-flex items-center gap-1.5 text-xs">
                                        <i class="fa-solid fa-globe text-[10px]"></i>
                                        <span>{{ $run->site->domain }}</span>
                                    </div>
                                @else
                                    <span class="text-[var(--color-ink-soft)] text-xs">—</span>
                                @endif
                            </div>
                            <div>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full font-data text-[10px] font-semibold border whitespace-nowrap {{ $run->safetyBadgeClass() }}">
                                    {{ $run->safetyLabel() }}
                                </span>
                            </div>
                        </div>

                        {{-- Root Cause & Diagnosis Box --}}
                        <div class="bg-[var(--color-surface-alt)]/60 rounded-lg p-3 text-xs border border-[var(--color-border-light)]">
                            <div class="font-semibold text-[var(--color-ink-strong)]">
                                {{ $run->root_cause ?: 'Analysis completed' }}
                            </div>
                            @if($run->diagnosis_summary)
                                <div class="text-[11px] text-[var(--color-ink-muted)] mt-1 leading-relaxed">
                                    {{ $run->diagnosis_summary }}
                                </div>
                            @endif
                        </div>

                        {{-- Actions Button --}}
                        <div class="flex items-center justify-end pt-1">
                            <button type="button"
                                    @click="openDrawer({{ json_encode($run->toArray() + ['review' => $reviews[$run->id] ?? null]) }})"
                                    class="btn-pill-nav w-full sm:w-auto text-xs py-1.5 px-3.5 font-medium border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)] cursor-pointer inline-flex items-center justify-center gap-1.5">
                                <i class="fa-solid fa-microscope text-[11px] text-[var(--color-brand)]"></i>
                                <span>Forensics &amp; Remediation</span>
                                <i class="fa-solid fa-arrow-right text-[10px]"></i>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            @if($runs->hasPages())
                <div class="p-3 border-t border-[var(--color-border-light)]">
                    {{ $runs->links() }}
                </div>
            @endif
        </div>
    @endif

    {{-- Slide-Over Drawer for Forensics & What AiRemedy Would Have Done --}}
    <div x-cloak x-show="drawerOpen" class="fixed inset-0 z-50 overflow-hidden" aria-labelledby="slide-over-title" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-xs transition-opacity" @click="closeDrawer()"></div>

        <div class="fixed inset-y-0 right-0 max-w-full flex pl-4 sm:pl-10">
            <div class="w-screen max-w-2xl bg-[var(--color-surface)] border-l border-[var(--color-border)] shadow-2xl flex flex-col"
                 @keydown.window.escape="closeDrawer()">
                
                {{-- Drawer Header --}}
                <div class="p-5 border-b border-[var(--color-border)] flex items-center justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-bold text-[var(--color-ink-strong)]">Incident Forensics & Remediation</h2>
                            <span class="px-2 py-0.5 rounded-full font-data text-[10px] font-semibold border"
                                  :class="activeRun?.status === 'resolved' ? 'bg-emerald-500/15 text-emerald-600 border-emerald-500/30' : (activeRun?.status === 'allowed_maintenance' ? 'bg-sky-500/15 text-sky-600 border-sky-500/30' : 'bg-blue-500/15 text-blue-600 border-blue-500/30')"
                                  x-text="activeRun?.status === 'allowed_maintenance' ? 'ALLOWED MAINTENANCE' : activeRun?.status?.toUpperCase()"></span>
                        </div>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-1">
                            Run #<span x-text="activeRun?.id"></span> · <span x-text="activeRun?.model_used"></span> · Mode: <span class="font-semibold" x-text="activeRun?.actor"></span> · Cost: $<span x-text="Number(activeRun?.total_cost_usd || 0).toFixed(4)"></span>
                        </p>
                    </div>
                    <button type="button" @click="closeDrawer()" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] p-1.5 cursor-pointer">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>

                {{-- Drawer Content --}}
                <div class="p-6 overflow-y-auto flex-1 space-y-6 text-xs">
                    {{-- Allowed Maintenance Banner --}}
                    <template x-if="activeRun?.status === 'allowed_maintenance'">
                        <div class="p-3.5 rounded-xl border border-sky-500/30 bg-sky-500/10 text-sky-950 dark:text-sky-200 space-y-1">
                            <div class="flex items-center gap-1.5 font-bold text-xs">
                                <i class="fa-solid fa-cloud-arrow-up text-sky-500"></i>
                                <span>Allowed Background Maintenance Detected</span>
                            </div>
                            <p class="text-[11px] leading-relaxed">
                                AiRemedy identified this resource spike as routine maintenance activity (e.g. SpinupWP S3 backup, database export, or log rotation). Safe deprioritization commands (like <code>renice</code> or <code>ionice</code>) are recommended rather than process termination, and alerting notifications were automatically muted.
                            </p>
                        </div>
                    </template>

                    {{-- Watch Mode / Simulation Banner --}}
                    <template x-if="activeRun?.status !== 'allowed_maintenance' && (activeRun?.actor === 'watch_mode' || activeRun?.actor === 'simulation')">
                        <div class="p-3.5 rounded-xl border border-indigo-500/30 bg-indigo-500/10 text-indigo-900 dark:text-indigo-200 space-y-1">
                            <div class="flex items-center gap-1.5 font-bold text-xs">
                                <i class="fa-solid fa-eye text-indigo-500"></i>
                                <span>Watch Mode Active — Passive Observability</span>
                            </div>
                            <p class="text-[11px] leading-relaxed">
                                AiRemedy ran read-only diagnostics and formulated remediation, but <strong>executed 0 commands</strong> on the server. Below is what AiRemedy would have done if autonomous healing were enabled.
                            </p>
                        </div>
                    </template>

                    {{-- Executive Diagnosis --}}
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)] mb-2">Executive Summary</h4>
                        <div class="card p-3.5 bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] text-sm leading-relaxed"
                             x-text="activeRun?.diagnosis_summary"></div>
                    </div>

                    {{-- Root Cause & Safety Tier --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div class="card p-3">
                            <span class="text-[11px] text-[var(--color-ink-muted)] font-semibold block mb-1">Identified Root Cause</span>
                            <span class="text-xs font-bold text-[var(--color-ink-strong)]" x-text="activeRun?.root_cause"></span>
                        </div>
                        <div class="card p-3">
                            <span class="text-[11px] text-[var(--color-ink-muted)] font-semibold block mb-1">Safety Tier</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full font-data font-semibold text-[11px] border whitespace-nowrap"
                                  :class="safetyBadgeClass(activeRun?.safety_tier)"
                                  x-text="formatSafetyTier(activeRun?.safety_tier)"></span>
                        </div>
                    </div>

                    {{-- Proposed / Approved Commands --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)]"
                                x-text="(activeRun?.actor === 'watch_mode' || activeRun?.actor === 'simulation') ? 'What AiRemedy Would Have Done' : 'Remediation Commands'"></h4>
                            <template x-if="activeRun?.actor === 'watch_mode' || activeRun?.actor === 'simulation'">
                                <span class="text-[10px] font-semibold text-amber-600 dark:text-amber-400">
                                    <i class="fa-solid fa-ban mr-1"></i>Not executed (Watch Mode)
                                </span>
                            </template>
                        </div>

                        <template x-if="activeRun?.approved_commands && activeRun.approved_commands.length > 0">
                            <div class="bg-slate-950 border border-slate-800 text-slate-100 p-3.5 rounded-xl font-mono text-xs overflow-x-auto space-y-1.5 shadow-sm">
                                <template x-for="cmd in activeRun.approved_commands" :key="cmd">
                                    <div class="flex items-center gap-2"><span class="text-emerald-400 font-bold select-none">$</span> <span class="text-slate-100" x-text="cmd"></span></div>
                                </template>
                            </div>
                        </template>
                        {{-- Copilot approval: re-created per run (keyed by id) so selections never leak between incidents --}}
                        <template x-for="r in ((activeRun && activeRun.review && !(activeRun.approved_commands && activeRun.approved_commands.length) && activeRun.actor !== 'watch_mode' && activeRun.actor !== 'simulation' && (activeRun.proposed_commands || []).length) ? [activeRun] : [])" :key="r.id">
                            <div>
                                @include('ai-remedy::_review_panel', ['reviewExpr' => 'r.review', 'runIdExpr' => 'r.id'])
                            </div>
                        </template>
                        <template x-if="(!activeRun?.approved_commands || activeRun.approved_commands.length === 0) && (activeRun?.actor === 'watch_mode' || activeRun?.actor === 'simulation' || !activeRun?.review)">
                            <div class="bg-slate-950 border border-slate-800 text-slate-100 p-3.5 rounded-xl font-mono text-xs overflow-x-auto space-y-1.5 shadow-sm">
                                <template x-for="cmd in (activeRun?.proposed_commands || [])" :key="cmd">
                                    <div class="flex items-center gap-2"><span class="text-indigo-400 font-bold select-none">$</span> <span class="text-slate-100" x-text="cmd"></span></div>
                                </template>
                            </div>
                        </template>
                    </div>

                    {{-- Terminal Execution Output (if executed) --}}
                    <template x-if="activeRun?.execution_output">
                        <div>
                            <h4 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)] mb-2">Terminal Execution Log (SSH)</h4>
                            <pre class="bg-slate-950 border border-slate-800 text-slate-200 p-4 rounded-xl font-mono text-xs overflow-x-auto max-h-64 whitespace-pre-wrap shadow-inner"
                                 x-text="activeRun?.execution_output"></pre>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
