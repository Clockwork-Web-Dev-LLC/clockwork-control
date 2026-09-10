@php
    $isPressable = $site->isPressable();
    $isSpinupWp = $site->isSpinupWp();
    $spacesConfigured = app(\App\Services\DigitalOcean\SpacesClient::class)->isConfigured();
    $relayEnabled = (bool) $site->backup_relay_enabled;
    $lastArchived = $site->backup_relay_last_archived_at;
    $hasRelayOrPressable = $isPressable || $relayEnabled || $lastArchived !== null;
    $spacesEligible = $isSpinupWp && $spacesConfigured;
    $showSnapshots = $hasRelayOrPressable || $spacesEligible;
@endphp

<div class="card p-5 flex flex-col justify-between h-full"
     x-data="{
         modalOpen: false,
         loading: false,
         loaded: false,
         data: null,
         error: null,
         spacesEligible: {{ $spacesEligible ? 'true' : 'false' }},
         hasRelayOrPressable: {{ $hasRelayOrPressable ? 'true' : 'false' }},
         spacesRunCount: 0,
         latestSpacesDate: null,
         formatBytes(bytes) {
             if (!bytes || bytes <= 0) return '—';
             const k = 1024;
             const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
             const i = Math.floor(Math.log(bytes) / Math.log(k));
             return (bytes / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i];
         },
         formatDate(dateStr) {
             if (!dateStr) return '—';
             const d = new Date(dateStr);
             return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
         },
         applyHistory(json) {
             this.data = json;
             this.loaded = true;
             const rows = json.spaces_history || [];
             this.spacesRunCount = rows.length;
             this.latestSpacesDate = rows[0] ? rows[0].date : null;
         },
         fetchHistory() {
             return fetch('{{ route('sites.backups.history', $site) }}', {
                 headers: { 'Accept': 'application/json' }
             }).then(res => {
                 if (!res.ok) throw new Error('HTTP ' + res.status);
                 return res.json();
             }).then(json => this.applyHistory(json));
         },
         prefetch() {
             if (!this.spacesEligible || this.loaded) return;
             this.fetchHistory().catch(() => {});
         },
         loadSnapshots() {
             this.modalOpen = true;
             if (this.loaded) return;
             this.loading = true;
             this.error = null;
             this.fetchHistory()
                 .catch(err => { this.error = err.message || 'Failed to load backup snapshots'; })
                 .finally(() => { this.loading = false; });
         },
         get spacesProtected() { return this.spacesRunCount > 0; },
         get showProtected() { return this.hasRelayOrPressable || this.spacesProtected; }
     }"
     x-init="prefetch()">
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-box-archive text-emerald-600"></i>
                Backups
            </h3>
            @if ($hasRelayOrPressable)
                <span class="status-pill status-green text-[10px]">
                    <span class="status-dot"></span> Protected
                </span>
            @elseif ($spacesEligible)
                <span class="status-pill text-[10px]"
                      :class="showProtected ? 'status-green' : 'status-unknown'"
                      x-text="showProtected ? 'Protected' : (loaded ? 'No snapshots' : 'Checking…')">Checking…</span>
            @else
                <span class="status-pill status-unknown text-[10px]">
                    <span class="status-dot"></span> Unconfigured
                </span>
            @endif
        </div>

        @if ($hasRelayOrPressable)
            <div class="py-2">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 text-base">
                        <i class="fa-solid fa-server"></i>
                    </div>
                    <div>
                        <div class="font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-1.5">
                            <i class="fa-solid fa-circle-check text-emerald-600 text-xs"></i>
                            Backups are successful
                        </div>
                        <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                            @if ($lastArchived)
                                Last archive: <span class="font-medium text-[var(--color-ink-strong)]">{{ $lastArchived->diffForHumans() }}</span>
                                <div class="text-[10px] text-[var(--color-ink-muted)] font-mono mt-0.5">({{ $lastArchived->format('Y-m-d H:i:s') }})</div>
                            @elseif ($isPressable)
                                Automated daily snapshots managed by Pressable API.
                            @else
                                Automated relay snapshots configured.
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mt-4 p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs space-y-1">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Destination:</span>
                        <span class="font-medium text-[var(--color-ink-strong)]">
                            @if ($relayEnabled)
                                AWS S3 Glacier Relay
                            @elseif ($isPressable)
                                Pressable Cloud Snapshot
                            @else
                                Host Snapshot
                            @endif
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Cadence:</span>
                        <span class="font-medium text-[var(--color-ink-strong)]">Daily (Nightly)</span>
                    </div>
                </div>
            </div>
        @elseif ($spacesEligible)
            <div class="py-2">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 text-base">
                        <i class="fa-solid fa-server"></i>
                    </div>
                    <div>
                        <div class="font-semibold text-sm text-[var(--color-ink-strong)]"
                             x-text="spacesProtected ? 'Backups are successful' : (loaded ? 'Spaces configured — no snapshots yet' : 'Checking DigitalOcean Spaces…')">
                            Checking DigitalOcean Spaces…
                        </div>
                        <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                            <span x-show="latestSpacesDate" x-cloak>
                                Last Spaces run: <span class="font-medium text-[var(--color-ink-strong)]" x-text="formatDate(latestSpacesDate)"></span>
                            </span>
                            <span x-show="!latestSpacesDate">
                                Automated daily backups stored in DigitalOcean Spaces. Open Snapshots to inspect runs.
                            </span>
                        </div>
                    </div>
                </div>

                <div class="mt-4 p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs space-y-1">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Destination:</span>
                        <span class="font-medium text-[var(--color-ink-strong)]">DigitalOcean Spaces (SpinupWP)</span>
                    </div>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Cadence:</span>
                        <span class="font-medium text-[var(--color-ink-strong)]">Daily (Nightly)</span>
                    </div>
                </div>
            </div>
        @else
            <div class="text-center py-6">
                <div class="w-12 h-12 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center mx-auto mb-3 text-lg">
                    <i class="fa-solid fa-box-archive"></i>
                </div>
                <div class="font-semibold text-sm text-[var(--color-ink-strong)]">No automated backup relay</div>
                <p class="text-xs text-[var(--color-ink-muted)] mt-1 max-w-xs mx-auto">
                    Enable S3 Glacier backup relay in site settings to archive this site off-site daily.
                </p>
            </div>
        @endif
    </div>

    <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
        <span class="text-[11px] text-[var(--color-ink-muted)]">
            {{ $site->hosting_provider }} hosting
        </span>
        <div class="flex items-center gap-2">
            @if ($showSnapshots)
                <button type="button"
                        @click="loadSnapshots()"
                        class="btn-pill-nav text-xs font-medium text-emerald-700 hover:underline cursor-pointer">
                    <i class="fa-solid fa-clock-rotate-left mr-1"></i> Snapshots
                </button>
            @endif
            <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'settings']) }}#backup-relay-card"
               class="btn-pill-nav text-xs font-medium text-emerald-700 hover:underline">
                Settings <i class="fa-solid fa-chevron-right text-[10px] ml-0.5"></i>
            </a>
        </div>
    </div>

    {{-- Snapshots Modal --}}
    <template x-teleport="body">
        <div x-show="modalOpen"
             x-cloak
             @keydown.escape.window="modalOpen = false"
             class="fixed inset-0 z-50 overflow-y-auto"
             role="dialog"
             aria-modal="true">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-xs transition-opacity"
                 @click="modalOpen = false"></div>

            <div class="flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-2xl rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-2xl p-6 transition-all"
                     @click.stop>
                    <div class="flex items-center justify-between pb-4 border-b border-[var(--color-border-light)] mb-4">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                                <i class="fa-solid fa-box-archive text-sm"></i>
                            </div>
                            <div>
                                <h3 class="font-display font-semibold text-base text-[var(--color-ink-strong)]">
                                    Backup Snapshots — {{ $site->domain }}
                                </h3>
                                <p class="text-xs text-[var(--color-ink-muted)]">
                                    Historical backup archives recorded for this site.
                                </p>
                            </div>
                        </div>
                        <button type="button"
                                @click="modalOpen = false"
                                class="w-8 h-8 rounded-full flex items-center justify-center text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] cursor-pointer">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <!-- Loading State -->
                    <div x-show="loading" class="py-12 text-center text-sm text-[var(--color-ink-muted)]">
                        <i class="fa-solid fa-circle-notch fa-spin text-lg text-emerald-600 mb-2"></i>
                        <p>Querying backup storage for snapshots…</p>
                    </div>

                    <!-- Error State -->
                    <div x-show="error" class="p-4 rounded-xl bg-rose-50 text-rose-700 text-xs border border-rose-200 mb-4">
                        <i class="fa-solid fa-circle-exclamation mr-1.5"></i>
                        <span x-text="error"></span>
                    </div>

                    <!-- Content State -->
                    <div x-show="!loading && !error && data">
                        {{-- Spaces Runs --}}
                        <template x-if="data && data.spaces_history && data.spaces_history.length > 0">
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">
                                        DigitalOcean Spaces Backups (<span x-text="data.spaces_history.length"></span> runs)
                                    </h4>
                                    <span class="text-[11px] text-[var(--color-ink-muted)]">Retention: 30–90 days</span>
                                </div>
                                <div class="max-h-80 overflow-y-auto border border-[var(--color-border-light)] rounded-xl divide-y divide-[var(--color-border-light)]">
                                    <template x-for="(run, idx) in data.spaces_history" :key="idx">
                                        <div class="p-3 hover:bg-[var(--color-surface-alt)]/50 transition-colors flex items-center justify-between text-xs">
                                            <div class="flex items-center gap-3">
                                                <div class="w-7 h-7 rounded-md bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] flex items-center justify-center text-xs">
                                                    <i class="fa-solid fa-database"></i>
                                                </div>
                                                <div>
                                                    <div class="font-medium text-[var(--color-ink-strong)]" x-text="formatDate(run.date)"></div>
                                                    <div class="text-[10px] text-[var(--color-ink-muted)] font-mono" x-text="run.type"></div>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <div class="font-data font-semibold text-[var(--color-ink-strong)]">
                                                    DB: <span x-text="formatBytes(run.database_bytes)"></span>
                                                    <span class="text-[var(--color-ink-muted)] font-normal mx-1">·</span>
                                                    Files: <span x-text="formatBytes(run.files_bytes)"></span>
                                                </div>
                                                <div class="text-[10px] text-[var(--color-ink-muted)]">
                                                    Total: <span x-text="formatBytes((run.database_bytes || 0) + (run.files_bytes || 0))"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                        {{-- Relay S3 Glacier Archives --}}
                        <template x-if="data && data.relay_archives && data.relay_archives.length > 0">
                            <div :class="{'mt-4': data.spaces_history && data.spaces_history.length > 0}">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">
                                        AWS S3 Glacier Relay Archives (<span x-text="data.relay_archives.length"></span>)
                                    </h4>
                                    <span class="text-[11px] text-[var(--color-ink-muted)]">Off-site Glacier IR</span>
                                </div>
                                <div class="max-h-80 overflow-y-auto border border-[var(--color-border-light)] rounded-xl divide-y divide-[var(--color-border-light)]">
                                    <template x-for="(archive, idx) in data.relay_archives" :key="idx">
                                        <div class="p-3 hover:bg-[var(--color-surface-alt)]/50 transition-colors flex items-center justify-between text-xs">
                                            <div>
                                                <div class="font-medium text-[var(--color-ink-strong)]" x-text="archive.filename || archive.key"></div>
                                                <div class="text-[10px] text-[var(--color-ink-muted)]" x-text="archive.last_modified_formatted || archive.date"></div>
                                            </div>
                                            <div class="text-right">
                                                <div class="font-data font-semibold text-[var(--color-ink-strong)]" x-text="archive.size_formatted || formatBytes(archive.size)"></div>
                                                <div class="text-[10px] text-emerald-600">Glacier IR</div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                        {{-- Empty State --}}
                        <template x-if="data && (!data.spaces_history || data.spaces_history.length === 0) && (!data.relay_archives || data.relay_archives.length === 0)">
                            <div class="py-8 text-center text-xs text-[var(--color-ink-muted)]">
                                <i class="fa-solid fa-box-open text-2xl text-[var(--color-ink-muted)]/50 mb-2"></i>
                                <p class="font-medium text-[var(--color-ink-strong)]">No historical snapshots recorded</p>
                                <p class="mt-1">Snapshots appear here automatically as scheduled backups complete.</p>
                            </div>
                        </template>
                    </div>

                    <div class="mt-5 pt-3 border-t border-[var(--color-border-light)] flex justify-end">
                        <button type="button"
                                @click="modalOpen = false"
                                class="btn-pill text-xs py-1.5 px-4 cursor-pointer">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
