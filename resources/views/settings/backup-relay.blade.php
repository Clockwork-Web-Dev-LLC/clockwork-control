@extends('layouts.app')

@section('title', 'Backup Relay Settings')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8" x-data="backupRelaySettings()">
    @include('settings._tabs')

    {{-- Flash Notifications --}}
    @if (session('status'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-700 dark:text-emerald-300 text-sm flex items-center gap-3 shadow-2xs">
            <i class="fa-solid fa-circle-check text-emerald-600 dark:text-emerald-400 text-base"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif
    @if (session('warning'))
        <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300 text-sm flex items-center gap-3 shadow-2xs">
            <i class="fa-solid fa-triangle-exclamation text-amber-600 dark:text-amber-400 text-base"></i>
            <span>{{ session('warning') }}</span>
        </div>
    @endif
    @if (session('error'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-sm flex items-center gap-3 shadow-2xs">
            <i class="fa-solid fa-circle-xmark text-rose-600 dark:text-rose-400 text-base"></i>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    {{-- Live AJAX Feedback Toast --}}
    <div x-show="toastMessage"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-2"
         class="p-4 rounded-xl text-sm flex items-center gap-3 shadow-sm border"
         :class="toastType === 'error' ? 'bg-rose-500/10 border-rose-500/20 text-rose-700 dark:text-rose-300' : 'bg-emerald-500/10 border-emerald-500/20 text-emerald-700 dark:text-emerald-300'"
         style="display: none;">
        <i class="fa-solid text-base" :class="toastType === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-check'"></i>
        <span x-text="toastMessage"></span>
    </div>

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border)] flex items-center justify-center text-[var(--color-brand)] shadow-2xs">
                    <i class="fa-solid fa-cloud-arrow-up text-lg"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-semibold text-[var(--color-ink-strong)]">Backup Relay</h1>
                    <p class="text-sm text-[var(--color-ink-muted)]">Off-site snapshot streaming to S3 Glacier Instant Retrieval</p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="button"
                    @click="openPolicyModal()"
                    class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border)] text-xs sm:text-sm font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface)] transition-colors cursor-pointer shadow-2xs">
                <i class="fa-solid fa-sliders text-[var(--color-brand)]"></i>
                <span>Policy &amp; Schedule Settings</span>
            </button>

            @if ($mode === 'in_repo')
                <form action="{{ route('settings.backup-relay.runNow') }}" method="POST" @submit="running = true">
                    @csrf
                    <button type="submit"
                            :disabled="running"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-[var(--color-brand)] text-white text-sm font-medium hover:opacity-95 transition-opacity disabled:opacity-50 shadow-sm cursor-pointer">
                        <i class="fa-solid" :class="running ? 'fa-spinner fa-spin' : 'fa-play'"></i>
                        <span x-text="running ? 'Archiving...' : 'Run Relay Now'">Run Relay Now</span>
                    </button>
                </form>
            @else
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border)] text-xs text-[var(--color-ink-muted)]"
                     title="External agent mode processes runs via the standalone backup relay droplet.">
                    <i class="fa-solid fa-server text-[var(--color-ink-soft)]"></i>
                    <span>Managed by External Droplet</span>
                </div>
            @endif
        </div>
    </div>

    {{-- Mode & Configuration Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
        {{-- Policy & Cadence Card --}}
        <div class="p-5 rounded-xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">Cadence &amp; Policy</span>
                    <button type="button" @click="openPolicyModal()" class="text-xs text-[var(--color-brand)] hover:underline flex items-center gap-1 cursor-pointer font-medium">
                        <i class="fa-solid fa-gear text-[10px]"></i> Edit
                    </button>
                </div>
                <div class="text-lg font-bold font-display text-[var(--color-ink-strong)]" x-text="frequencyLabel">
                    {{ $frequency === 'weekly' ? '1 a week (Weekly)' : ($frequency === 'twice_weekly' ? '2 a week' : 'Daily') }}
                </div>
                <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                    Retention: <span class="font-semibold text-[var(--color-ink-strong)]" x-text="retentionDays + ' days'">{{ $retentionDays }} days</span>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] text-xs text-[var(--color-ink-soft)] flex items-center justify-between">
                <span>Lifecycle:</span>
                <span class="font-medium text-[var(--color-ink-strong)]">S3 Glacier IR</span>
            </div>
        </div>

        {{-- Mode Card --}}
        <div class="p-5 rounded-xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">Operational Mode</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                          :class="mode === 'in_repo' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-blue-500/10 text-blue-600 dark:text-blue-400'">
                        <i class="fa-solid fa-circle text-[8px] mr-1.5"></i>
                        <span x-text="mode === 'in_repo' ? 'In-Repo (Native)' : 'External Agent'">{{ $mode === 'in_repo' ? 'In-Repo (Native)' : 'External Agent' }}</span>
                    </span>
                </div>
                <div class="text-xs text-[var(--color-ink-muted)] leading-relaxed">
                    @if ($mode === 'in_repo')
                        Queue jobs stream backups straight to S3 with Glacier IR storage class.
                    @else
                        Manifest handoff to standalone droplet for zero-impact streaming.
                    @endif
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] text-xs text-[var(--color-ink-soft)] flex items-center justify-between">
                <span>Disk: <code class="font-mono text-[var(--color-ink-strong)]">{{ $diskName }}</code></span>
                <span>Prefix: <code class="font-mono text-[var(--color-ink-strong)]">{{ $mode === 'in_repo' ? $archivePrefix : $s3Prefix }}</code></span>
            </div>
        </div>

        {{-- Coverage Card --}}
        <div class="p-5 rounded-xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">Fleet Coverage</span>
                    <span class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ $enabledCount }} / {{ $supportedCount }}</span>
                </div>
                <div class="text-xs text-[var(--color-ink-muted)] leading-relaxed">
                    {{ $enabledCount }} site(s) enabled for automatic archiving across {{ $supportedCount }} supported target(s).
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] text-xs text-[var(--color-ink-soft)] flex items-center justify-between">
                <span>Supported hosts:</span>
                <span class="font-medium text-[var(--color-ink-strong)]">Pressable, SpinupWP</span>
            </div>
        </div>

        {{-- Last Run Card --}}
        <div class="p-5 rounded-xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">Last Relay Run</span>
                    @if ($lastRunAt)
                        <span class="text-xs text-[var(--color-ink-muted)]" title="{{ $lastRunAt }}">
                            {{ \Carbon\Carbon::parse($lastRunAt)->diffForHumans() }}
                        </span>
                    @else
                        <span class="text-xs text-[var(--color-ink-soft)]">Never</span>
                    @endif
                </div>
                @if (!empty($lastRunStats))
                    <div class="flex items-center gap-4 mt-1">
                        <div class="text-center">
                            <span class="block text-base font-semibold text-emerald-600 dark:text-emerald-400">{{ $lastRunStats['sites_archived'] ?? 0 }}</span>
                            <span class="text-[10px] text-[var(--color-ink-soft)]">Archived</span>
                        </div>
                        <div class="text-center">
                            <span class="block text-base font-semibold text-[var(--color-ink-muted)]">{{ $lastRunStats['sites_skipped'] ?? 0 }}</span>
                            <span class="text-[10px] text-[var(--color-ink-soft)]">Skipped</span>
                        </div>
                        <div class="text-center">
                            <span class="block text-base font-semibold text-rose-600 dark:text-rose-400">{{ $lastRunStats['sites_failed'] ?? 0 }}</span>
                            <span class="text-[10px] text-[var(--color-ink-soft)]">Failed</span>
                        </div>
                    </div>
                @else
                    <div class="text-xs text-[var(--color-ink-soft)] mt-1">No completed relay runs recorded yet.</div>
                @endif
            </div>
            <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] text-xs text-[var(--color-ink-soft)] flex items-center justify-between">
                <span>Cron dispatch:</span>
                <span class="font-medium text-[var(--color-ink-strong)]">04:58 UTC</span>
            </div>
        </div>
    </div>

    {{-- Sites Selection Table --}}
    <div class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-xl shadow-sm overflow-hidden">
        <div class="p-5 border-b border-[var(--color-border)] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Site Relay Targets</h2>
                <p class="text-sm text-[var(--color-ink-muted)]">Toggle sites to include in off-host S3 Glacier archival runs.</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] text-xs"></i>
                    <input type="text"
                           x-model="search"
                           placeholder="Filter domains..."
                           class="pl-8 pr-3 py-1.5 text-xs rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)] w-48">
                </div>
                <button type="button"
                        @click="selectAllSupported()"
                        class="px-2.5 py-1.5 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)] transition-colors cursor-pointer">
                    Select Supported
                </button>
                <button type="button"
                        @click="deselectAll()"
                        class="px-2.5 py-1.5 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)] transition-colors cursor-pointer">
                    Clear All
                </button>
            </div>
        </div>

        <form action="{{ route('settings.backup-relay.update') }}" method="POST" id="bulk-relay-form">
            @csrf
            @method('PATCH')

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-[var(--color-ink-strong)]">
                    <thead class="bg-[var(--color-surface-alt)] text-xs uppercase text-[var(--color-ink-soft)] border-b border-[var(--color-border-light)] font-semibold">
                        <tr>
                            <th class="px-6 py-3 w-12 text-center">
                                <span class="sr-only">Toggle</span>
                            </th>
                            <th class="px-6 py-3">Domain</th>
                            <th class="px-6 py-3">Hosting Provider</th>
                            <th class="px-6 py-3">Capability</th>
                            <th class="px-6 py-3">Last Archived</th>
                            <th class="px-6 py-3 text-right">Relay Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @forelse ($sites as $site)
                            <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors"
                                x-show="matchesSearch('{{ $site['domain'] }}', '{{ $site['provider'] }}')">
                                <td class="px-6 py-3.5 text-center">
                                    <input type="checkbox"
                                           name="sites[]"
                                           value="{{ $site['id'] }}"
                                           x-model="enabledSites"
                                           :disabled="! {{ $site['supports_relay'] ? 'true' : 'false' }}"
                                           class="rounded border-[var(--color-border)] text-[var(--color-brand)] focus:ring-[var(--color-brand)] cursor-pointer disabled:opacity-40">
                                </td>
                                <td class="px-6 py-3.5 font-medium">
                                    <span class="text-[var(--color-ink-strong)]">{{ $site['domain'] }}</span>
                                </td>
                                <td class="px-6 py-3.5">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--color-surface-alt)] border border-[var(--color-border)] text-[var(--color-ink-muted)]">
                                        {{ ucfirst($site['provider']) }}
                                    </span>
                                </td>
                                <td class="px-6 py-3.5">
                                    @if ($site['supports_relay'])
                                        <span class="inline-flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                                            <i class="fa-solid fa-check"></i> Supported
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 text-xs text-[var(--color-ink-soft)]" title="Provider does not currently implement remote backup streaming.">
                                            <i class="fa-solid fa-minus"></i> No Adapter
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 text-xs text-[var(--color-ink-muted)]">
                                    @if ($site['last_archived_at'])
                                        <span title="{{ $site['last_archived_at'] }}">
                                            {{ \Carbon\Carbon::parse($site['last_archived_at'])->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    <button type="button"
                                            @click="toggleSite({{ $site['id'] }})"
                                            :disabled="! {{ $site['supports_relay'] ? 'true' : 'false' }}"
                                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium transition-colors cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                                            :class="isSiteEnabled({{ $site['id'] }}) ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] border border-[var(--color-border)]'">
                                        <i class="fa-solid" :class="isSiteEnabled({{ $site['id'] }}) ? 'fa-toggle-on text-emerald-500' : 'fa-toggle-off text-[var(--color-ink-soft)]'"></i>
                                        <span x-text="isSiteEnabled({{ $site['id'] }}) ? 'Enabled' : 'Disabled'"></span>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-sm text-[var(--color-ink-muted)]">
                                    No sites found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-[var(--color-border)] bg-[var(--color-surface-alt)]/30 flex items-center justify-between">
                <span class="text-xs text-[var(--color-ink-muted)]" x-text="enabledSites.length + ' site(s) selected for backup relay'"></span>
                <button type="submit"
                        class="px-4 py-2 rounded-lg bg-[var(--color-brand)] text-white text-xs font-medium hover:opacity-95 transition-opacity cursor-pointer shadow-sm">
                    Save Changes
                </button>
            </div>
        </form>
    </div>

    {{-- Run History --}}
    <div class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-xl shadow-sm overflow-hidden">
        <div class="p-5 border-b border-[var(--color-border)]">
            <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Relay Run History</h2>
            <p class="text-sm text-[var(--color-ink-muted)]">Historical runs recorded by in-repo scheduler or external droplet.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-[var(--color-ink-strong)]">
                <thead class="bg-[var(--color-surface-alt)] text-xs uppercase text-[var(--color-ink-soft)] border-b border-[var(--color-border-light)] font-semibold">
                    <tr>
                        <th class="px-6 py-3">Completed</th>
                        <th class="px-6 py-3">Duration</th>
                        <th class="px-6 py-3 text-center">Total Sites</th>
                        <th class="px-6 py-3 text-center">Archived</th>
                        <th class="px-6 py-3 text-center">Skipped</th>
                        <th class="px-6 py-3 text-center">Failed</th>
                        <th class="px-6 py-3 text-right">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @forelse ($runs as $run)
                        <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors">
                            <td class="px-6 py-3.5 font-medium">
                                <span title="{{ $run->finished_at?->toIso8601String() }}">
                                    {{ $run->finished_at ? $run->finished_at->diffForHumans() : '—' }}
                                </span>
                                <span class="block text-xs text-[var(--color-ink-soft)]">{{ $run->finished_at?->format('M j, Y H:i T') }}</span>
                            </td>
                            <td class="px-6 py-3.5 text-xs text-[var(--color-ink-muted)]">
                                @if ($run->started_at && $run->finished_at)
                                    {{ $run->started_at->diffInSeconds($run->finished_at) }}s
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-6 py-3.5 text-center font-medium">{{ $run->sites_total }}</td>
                            <td class="px-6 py-3.5 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                    {{ $run->sites_archived }}
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]">
                                    {{ $run->sites_skipped }}
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-center">
                                @if ($run->sites_failed > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-rose-500/10 text-rose-600 dark:text-rose-400">
                                        {{ $run->sites_failed }}
                                    </span>
                                @else
                                    <span class="text-xs text-[var(--color-ink-soft)]">0</span>
                                @endif
                            </td>
                            <td class="px-6 py-3.5 text-right text-xs text-[var(--color-ink-muted)]">
                                @if (!empty($run->failures))
                                    <span class="text-rose-500 font-medium" title="{{ json_encode($run->failures) }}">
                                        {{ count($run->failures) }} error(s)
                                    </span>
                                @else
                                    <span class="text-emerald-600 dark:text-emerald-400">Clean</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-sm text-[var(--color-ink-muted)]">
                                No relay runs recorded yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Policy & Schedule Settings Modal --}}
    <div x-show="showPolicyModal"
         x-cloak
         @keydown.escape.window="showPolicyModal = false"
         class="fixed inset-0 z-50 overflow-y-auto"
         style="display: none;">
        {{-- Backdrop --}}
        <div class="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity"
             @click="showPolicyModal = false"></div>

        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative w-full max-w-xl rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-2xl p-6 sm:p-7 space-y-6"
                 @click.stop>
                {{-- Modal Header --}}
                <div class="flex items-center justify-between pb-4 border-b border-[var(--color-border-light)]">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border)] flex items-center justify-center text-[var(--color-brand)] shadow-2xs">
                            <i class="fa-solid fa-sliders text-base"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-[var(--color-ink-strong)]">Relay Policy &amp; Schedule</h3>
                            <p class="text-xs text-[var(--color-ink-muted)]">Configure archival cadence, S3 Glacier retention, and execution engine.</p>
                        </div>
                    </div>
                    <button type="button"
                            @click="showPolicyModal = false"
                            class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] p-1 rounded-md transition-colors cursor-pointer">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>

                {{-- Modal Body Form --}}
                <form @submit.prevent="savePolicy()" class="space-y-5">
                    {{-- Frequency / Cadence --}}
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] mb-2">
                            Backup Cadence (Frequency)
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <label class="relative flex flex-col p-3.5 rounded-xl border cursor-pointer transition-all"
                                   :class="formFrequency === 'weekly' ? 'border-[var(--color-brand)] bg-[var(--color-brand)]/5 ring-1 ring-[var(--color-brand)]' : 'border-[var(--color-border)] bg-[var(--color-surface-alt)]/40 hover:bg-[var(--color-surface-alt)]'">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-bold text-[var(--color-ink-strong)]">1 a week</span>
                                    <input type="radio" name="frequency" value="weekly" x-model="formFrequency" class="sr-only">
                                    <i class="fa-solid fa-circle-check text-xs" :class="formFrequency === 'weekly' ? 'text-[var(--color-brand)]' : 'text-transparent'"></i>
                                </div>
                                <span class="text-[11px] text-[var(--color-ink-muted)] leading-tight">Weekly Sunday run (Recommended)</span>
                            </label>

                            <label class="relative flex flex-col p-3.5 rounded-xl border cursor-pointer transition-all"
                                   :class="formFrequency === 'twice_weekly' ? 'border-[var(--color-brand)] bg-[var(--color-brand)]/5 ring-1 ring-[var(--color-brand)]' : 'border-[var(--color-border)] bg-[var(--color-surface-alt)]/40 hover:bg-[var(--color-surface-alt)]'">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-bold text-[var(--color-ink-strong)]">2 a week</span>
                                    <input type="radio" name="frequency" value="twice_weekly" x-model="formFrequency" class="sr-only">
                                    <i class="fa-solid fa-circle-check text-xs" :class="formFrequency === 'twice_weekly' ? 'text-[var(--color-brand)]' : 'text-transparent'"></i>
                                </div>
                                <span class="text-[11px] text-[var(--color-ink-muted)] leading-tight">Sunday &amp; Wednesday runs</span>
                            </label>

                            <label class="relative flex flex-col p-3.5 rounded-xl border cursor-pointer transition-all"
                                   :class="formFrequency === 'daily' ? 'border-[var(--color-brand)] bg-[var(--color-brand)]/5 ring-1 ring-[var(--color-brand)]' : 'border-[var(--color-border)] bg-[var(--color-surface-alt)]/40 hover:bg-[var(--color-surface-alt)]'">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-bold text-[var(--color-ink-strong)]">Daily</span>
                                    <input type="radio" name="frequency" value="daily" x-model="formFrequency" class="sr-only">
                                    <i class="fa-solid fa-circle-check text-xs" :class="formFrequency === 'daily' ? 'text-[var(--color-brand)]' : 'text-transparent'"></i>
                                </div>
                                <span class="text-[11px] text-[var(--color-ink-muted)] leading-tight">Every morning at 04:58 UTC</span>
                            </label>
                        </div>
                    </div>

                    {{-- Retention Days --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">
                                Retention Window (S3 Glacier IR)
                            </label>
                            <span class="text-xs text-[var(--color-brand)] font-medium" x-text="formRetentionDays + ' days retention'"></span>
                        </div>
                        <select x-model.number="formRetentionDays"
                                class="w-full text-xs font-medium rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] px-3 py-2.5 focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]">
                            <option :value="30">30 Days (1 Month)</option>
                            <option :value="60">60 Days (2 Months)</option>
                            <option :value="90">90 Days (Quarterly — Recommended)</option>
                            <option :value="180">180 Days (6 Months)</option>
                            <option :value="365">365 Days (1 Year)</option>
                        </select>
                        <p class="text-[11px] text-[var(--color-ink-soft)] mt-1.5">
                            Snapshots older than this retention period are expired from your S3 Glacier Instant Retrieval bucket.
                        </p>
                    </div>

                    {{-- Execution Mode --}}
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] mb-2">
                            Execution Engine
                        </label>
                        <div class="space-y-2.5">
                            <label class="relative flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-all"
                                   :class="formMode === 'external_agent' ? 'border-[var(--color-brand)] bg-[var(--color-brand)]/5 ring-1 ring-[var(--color-brand)]' : 'border-[var(--color-border)] bg-[var(--color-surface-alt)]/30 hover:bg-[var(--color-surface-alt)]'">
                                <input type="radio" name="mode" value="external_agent" x-model="formMode" class="mt-1 text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                                <div class="text-xs">
                                    <div class="font-bold text-[var(--color-ink-strong)]">External Agent Droplet</div>
                                    <p class="text-[11px] text-[var(--color-ink-muted)] mt-0.5 leading-normal">
                                        Backups are streamed by a dedicated worker droplet via S3 targets manifest. Zero RAM/CPU load on this app server. Requires standing up and maintaining a separate droplet codebase.
                                    </p>
                                </div>
                            </label>

                            <label class="relative flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-all"
                                   :class="formMode === 'in_repo' ? 'border-[var(--color-brand)] bg-[var(--color-brand)]/5 ring-1 ring-[var(--color-brand)]' : 'border-[var(--color-border)] bg-[var(--color-surface-alt)]/30 hover:bg-[var(--color-surface-alt)]'">
                                <input type="radio" name="mode" value="in_repo" x-model="formMode" class="mt-1 text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                                <div class="text-xs">
                                    <div class="font-bold text-[var(--color-ink-strong)] flex items-center gap-1.5">
                                        <span>In-Repo Native (Queue Worker)</span>
                                        <span class="px-1.5 py-0.2 rounded text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">Recommended</span>
                                    </div>
                                    <p class="text-[11px] text-[var(--color-ink-muted)] mt-0.5 leading-normal">
                                        Backups are queued and streamed directly within this Clockwork Control instance via Laravel queue jobs. No external infrastructure to stand up — just add S3 credentials.
                                    </p>
                                </div>
                            </label>
                        </div>
                    </div>

                    {{-- Modal Actions --}}
                    <div class="pt-4 border-t border-[var(--color-border-light)] flex items-center justify-end gap-3">
                        <button type="button"
                                @click="showPolicyModal = false"
                                :disabled="savingPolicy"
                                class="px-4 py-2 rounded-lg border border-[var(--color-border)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)] text-xs font-medium transition-colors cursor-pointer disabled:opacity-50">
                            Cancel
                        </button>
                        <button type="submit"
                                :disabled="savingPolicy"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-[var(--color-brand)] text-white text-xs font-semibold hover:opacity-95 transition-opacity disabled:opacity-50 shadow-sm cursor-pointer">
                            <i class="fa-solid" :class="savingPolicy ? 'fa-circle-notch fa-spin' : 'fa-check'"></i>
                            <span x-text="savingPolicy ? 'Saving & Syncing...' : 'Save Policy Settings'">Save Policy Settings</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function backupRelaySettings() {
    return {
        running: false,
        search: '',
        showPolicyModal: false,
        savingPolicy: false,
        toastMessage: '',
        toastType: 'success',

        frequency: '{{ $frequency }}',
        retentionDays: {{ $retentionDays }},
        mode: '{{ $mode }}',

        formFrequency: '{{ $frequency }}',
        formRetentionDays: {{ $retentionDays }},
        formMode: '{{ $mode }}',

        enabledSites: @json($sites->where('enabled', true)->pluck('id')->values()),
        supportedSites: @json($sites->where('supports_relay', true)->pluck('id')->values()),

        get frequencyLabel() {
            if (this.frequency === 'weekly') return '1 a week (Weekly)';
            if (this.frequency === 'twice_weekly') return '2 a week';
            if (this.frequency === 'daily') return 'Daily';
            return this.frequency;
        },

        openPolicyModal() {
            this.formFrequency = this.frequency;
            this.formRetentionDays = this.retentionDays;
            this.formMode = this.mode;
            this.showPolicyModal = true;
        },

        showToast(msg, type = 'success') {
            this.toastMessage = msg;
            this.toastType = type;
            setTimeout(() => {
                this.toastMessage = '';
            }, 4500);
        },

        savePolicy() {
            this.savingPolicy = true;
            fetch('{{ route('settings.backup-relay.update') }}', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    policy_update: 1,
                    frequency: this.formFrequency,
                    retention_days: this.formRetentionDays,
                    mode: this.formMode,
                }),
            })
            .then(res => res.json())
            .then(data => {
                this.savingPolicy = false;
                if (data.success) {
                    this.frequency = data.frequency;
                    this.retentionDays = data.retention_days;
                    this.mode = data.mode;
                    this.showPolicyModal = false;
                    this.showToast(data.message || 'Policy settings saved successfully.');
                } else {
                    this.showToast(data.message || 'Error updating policy settings.', 'error');
                }
            })
            .catch(err => {
                this.savingPolicy = false;
                this.showToast('Network error saving policy: ' + (err.message || 'Unknown error'), 'error');
            });
        },

        isSiteEnabled(id) {
            return this.enabledSites.includes(id);
        },

        toggleSite(id) {
            const idx = this.enabledSites.indexOf(id);
            const willEnable = idx === -1;

            if (willEnable) {
                this.enabledSites.push(id);
            } else {
                this.enabledSites.splice(idx, 1);
            }

            // AJAX toggle for instant response
            fetch('{{ route('settings.backup-relay.update') }}', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    site_id: id,
                    enabled: willEnable,
                }),
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.showToast(data.message);
                }
            })
            .catch(() => {
                // Revert on network failure
                if (willEnable) {
                    const i = this.enabledSites.indexOf(id);
                    if (i > -1) this.enabledSites.splice(i, 1);
                } else {
                    this.enabledSites.push(id);
                }
                this.showToast('Network error toggling site.', 'error');
            });
        },

        selectAllSupported() {
            this.enabledSites = [...this.supportedSites];
        },

        deselectAll() {
            this.enabledSites = [];
        },

        matchesSearch(domain, provider) {
            if (!this.search) return true;
            const term = this.search.toLowerCase();
            return domain.toLowerCase().includes(term) || provider.toLowerCase().includes(term);
        }
    };
}
</script>
@endsection
