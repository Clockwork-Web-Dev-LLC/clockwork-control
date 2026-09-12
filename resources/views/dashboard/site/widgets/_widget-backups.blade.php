@php
    $isPressable = $site->isPressable();
    $isSpinupWp = $site->isSpinupWp();
    $isCustom = $site->isCustom();
    $spacesConfigured = app(\App\Services\DigitalOcean\SpacesClient::class)->isConfigured();
    $relayEnabled = (bool) $site->backup_relay_enabled;
    $lastArchived = $site->backup_relay_last_archived_at;
    $relayFrequency = $site->backupRelayFrequency();
    $nextScheduled = $site->backupRelayNextScheduledAt();
    $hasRelayOrPressable = $isPressable || $relayEnabled || $lastArchived !== null;
    $spacesEligible = $isSpinupWp && $spacesConfigured;
    $showSnapshots = $hasRelayOrPressable || $spacesEligible || $isCustom;
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
         isCustom: {{ $isCustom ? 'true' : 'false' }},
         spacesRunCount: 0,
         latestSpacesDate: null,
         enabled: {{ $relayEnabled ? 'true' : 'false' }},
         frequency: @js($relayFrequency),
         lastAt: @js($lastArchived?->toIso8601String()),
         nextAt: @js($nextScheduled?->toIso8601String()),
         saving: false,
         runningNow: false,
         runMessage: null,
         latestArchive: null,
         csrf: @js(csrf_token()),
         restoreModalOpen: false,
         restoreArchive: null,
         restoreConfirmDomain: '',
         restoreNote: '',
         restoring: false,
         restoreMessage: null,
         restoreError: null,
         restoreState: { status: 'idle' },
         shaAvailable: null,
         precheckLoading: false,
         siteDomain: @js($site->domain),
         hasRestoreCapability: {{ in_array('backup-restore', $site->companion_capabilities ?? [], true) ? 'true' : 'false' }},
         openRestore(archive) {
             this.restoreArchive = archive;
             this.restoreConfirmDomain = '';
             this.restoreNote = '';
             this.restoreMessage = null;
             this.restoreError = null;
             this.restoreState = { status: 'idle' };
             this.shaAvailable = null;
             this.restoreModalOpen = true;
             this.checkRestoreStatus();
             this.precheckRestore(archive);
         },
         async precheckRestore(archive) {
             this.precheckLoading = true;
             try {
                 const res = await fetch('{{ route('sites.backup-relay.restore.precheck', $site) }}?key=' + encodeURIComponent(archive.key), {
                     headers: { 'Accept': 'application/json' }
                 });
                 if (res.ok) {
                     const data = await res.json();
                     this.shaAvailable = !!data.sha256_available;
                 } else {
                     this.shaAvailable = false;
                 }
             } catch (e) {
                 // Network hiccup: leave unknown; the server-side stage guard still refuses hashless archives.
                 this.shaAvailable = null;
             } finally {
                 this.precheckLoading = false;
             }
         },
         async checkRestoreStatus() {
             try {
                 const res = await fetch('{{ route('sites.backup-relay.restore.status', $site) }}', {
                     headers: { 'Accept': 'application/json' }
                 });
                 if (res.ok) {
                     this.restoreState = await res.json();
                 }
             } catch (e) {}
         },
         async stageRestore() {
             if (this.restoring || !this.restoreArchive) return;
             if (this.restoreConfirmDomain !== this.siteDomain) {
                 this.restoreError = 'Confirmation text does not match site domain.';
                 return;
             }
             this.restoring = true;
             this.restoreError = null;
             this.restoreMessage = 'Submitting stage request…';
             try {
                 const res = await fetch('{{ route('sites.backup-relay.restore.stage', $site) }}', {
                     method: 'POST',
                     headers: {
                         'X-CSRF-TOKEN': this.csrf,
                         'Accept': 'application/json',
                         'Content-Type': 'application/json',
                     },
                     body: JSON.stringify({
                         archive_key: this.restoreArchive.key,
                         confirm_domain: this.restoreConfirmDomain,
                         note: this.restoreNote,
                     }),
                 });
                 const data = await res.json();
                 if (!res.ok) {
                     this.restoreError = data.message || 'Failed to start restore staging.';
                     this.restoring = false;
                     return;
                 }
                 this.restoreMessage = data.message;
                 await this.pollRestoreUntil(['staged', 'failed']);
             } catch (e) {
                 this.restoreError = e.message || 'Request failed';
             } finally {
                 this.restoring = false;
             }
         },
         async applyRestore() {
             if (this.restoring) return;
             this.restoring = true;
             this.restoreError = null;
             this.restoreMessage = 'Applying restore…';
             try {
                 const res = await fetch('{{ route('sites.backup-relay.restore.apply', $site) }}', {
                     method: 'POST',
                     headers: {
                         'X-CSRF-TOKEN': this.csrf,
                         'Accept': 'application/json',
                         'Content-Type': 'application/json',
                     },
                     body: JSON.stringify({
                         archive_key: (this.restoreState && this.restoreState.archive_key) || (this.restoreArchive && this.restoreArchive.key) || '',
                     }),
                 });
                 const data = await res.json();
                 if (!res.ok) {
                     this.restoreError = data.message || 'Failed to start applying restore.';
                     this.restoring = false;
                     return;
                 }
                 this.restoreMessage = data.message;
                 await this.pollRestoreUntil(['applied', 'failed']);
             } catch (e) {
                 this.restoreError = e.message || 'Request failed';
             } finally {
                 this.restoring = false;
             }
         },
         async pollRestoreUntil(targetStatuses) {
             // 160 x 5s = 800s, comfortably past the command's 600s poll cap so
             // the widget never gives up before the server has decided.
             for (let i = 0; i < 160; i++) {
                 await new Promise(r => setTimeout(r, 5000));
                 await this.checkRestoreStatus();
                 const status = this.restoreState ? this.restoreState.status : null;
                 if (targetStatuses.includes(status)) {
                     if (status === 'failed') {
                         this.restoreError = this.restoreState.error_detail || this.restoreState.error || 'Restore failed';
                     }
                     return;
                 }
             }
             this.restoreMessage = 'Still running — reopen this panel to refresh.';
         },
         async discardRestore() {
             if (this.restoring) return;
             try {
                 await fetch('{{ route('sites.backup-relay.restore.discard', $site) }}', {
                     method: 'POST',
                     headers: {
                         'X-CSRF-TOKEN': this.csrf,
                         'Accept': 'application/json',
                     },
                 });
             } catch (e) {}
             this.restoreState = { status: 'idle' };
             this.restoreModalOpen = false;
         },
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
         formatStamp(dateStr) {
             if (!dateStr) return '—';
             const d = new Date(dateStr);
             const pad = (n) => String(n).padStart(2, '0');
             return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ', ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
         },
         applyHistory(json) {
             this.data = json;
             this.loaded = true;
             const rows = json.spaces_history || [];
             this.spacesRunCount = rows.length;
             this.latestSpacesDate = rows[0] ? rows[0].date : null;
             const archives = json.relay_archives || [];
             this.latestArchive = archives.length > 0 ? archives[0] : null;
             if (json.schedule) {
                 this.enabled = !!json.schedule.enabled;
                 if (json.schedule.frequency) this.frequency = json.schedule.frequency;
                 this.lastAt = json.schedule.last_archived_at || this.lastAt;
                 this.nextAt = json.schedule.next_scheduled_at || this.nextAt;
             }
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
             if ((!this.spacesEligible && !this.isCustom) || this.loaded) return;
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
         async saveSchedule() {
             if (this.saving) return;
             this.saving = true;
             try {
                 const res = await fetch('{{ route('sites.backup-relay.update', $site) }}', {
                     method: 'PATCH',
                     headers: {
                         'X-CSRF-TOKEN': this.csrf,
                         'Accept': 'application/json',
                         'Content-Type': 'application/json',
                     },
                     body: JSON.stringify({ enabled: this.enabled, frequency: this.frequency }),
                 });
                 const data = await res.json();
                 if (data.ok) {
                     this.nextAt = data.next_scheduled_at;
                 }
             } catch (e) { /* keep previous UI */ }
             finally { this.saving = false; }
         },
         async runNow() {
             if (this.runningNow || !this.enabled) return;
             this.runningNow = true;
             this.runMessage = null;
             const started = this.lastAt;
             try {
                 const res = await fetch('{{ route('sites.backup-relay.run-now', $site) }}', {
                     method: 'POST',
                     headers: {
                         'X-CSRF-TOKEN': this.csrf,
                         'Accept': 'application/json',
                     },
                 });
                 const data = await res.json();
                 this.runMessage = data.message || (res.ok ? 'Backup started.' : 'Could not start backup.');
                 if (!res.ok) {
                     this.runningNow = false;
                     return;
                 }
                 for (let i = 0; i < 90; i++) {
                     await new Promise(r => setTimeout(r, 5000));
                     await this.fetchHistory().catch(() => {});
                     if (this.lastAt && this.lastAt !== started) {
                         this.runMessage = 'Backup completed.';
                         this.runningNow = false;
                         return;
                     }
                 }
                 this.runMessage = 'Backup is still running. Refresh in a few minutes.';
             } catch (e) {
                 this.runMessage = e.message || 'Request failed.';
             }
             this.runningNow = false;
         },
         backupKind(archive) {
             if (!archive) return 'Backup';
             return /^\d{4}-\d{2}-\d{2}\.zip$/.test(archive.filename || '') ? 'Scheduled backup' : 'Manual backup';
         },
         get spacesProtected() { return this.spacesRunCount > 0; },
         get showProtected() { return this.hasRelayOrPressable || this.spacesProtected || (this.isCustom && this.enabled); }
     }"
     x-init="prefetch(); if (isCustom) checkRestoreStatus()">
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-box-archive text-emerald-600"></i>
                Backups
            </h3>
            @if ($isCustom)
                <button type="button"
                        role="switch"
                        :aria-checked="enabled ? 'true' : 'false'"
                        aria-label="Toggle Glacier backups for {{ $site->domain }}"
                        @click="enabled = !enabled; saveSchedule()"
                        :disabled="saving"
                        class="cw-switch"
                        :class="{ 'cw-switch--on': enabled, 'cw-switch--busy': saving }">
                    <span class="cw-switch__knob"></span>
                </button>
            @elseif ($hasRelayOrPressable)
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

        @if ($isCustom)
            {{-- Alert: Fail-closed maintenance banner --}}
            <div class="mb-3 rounded-xl bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900/60 p-3 flex items-start gap-2.5 text-xs text-red-700 dark:text-red-300"
                 x-show="restoreState && restoreState.maintenance_left_on"
                 x-cloak>
                <i class="fa-solid fa-triangle-exclamation text-sm shrink-0 mt-0.5 text-red-600"></i>
                <div>
                    <div class="font-semibold">Restore failed after import began</div>
                    <p class="mt-0.5 text-[11px] opacity-90">The site was left in maintenance mode to prevent database inconsistencies. Investigate before manually lifting maintenance.</p>
                </div>
            </div>

            <div class="py-1">
                {{-- Status Hero Row --}}
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 text-base"
                         :class="enabled ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-400'">
                        <i class="fa-solid" :class="enabled ? 'fa-cloud-arrow-up' : 'fa-box-archive'"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-1.5">
                            <i class="fa-solid fa-circle-check text-emerald-600 text-xs" x-show="enabled && lastAt"></i>
                            <span x-text="enabled ? (lastAt ? 'Backups are successful' : 'Glacier relay active') : 'Backups disabled'">
                                {{ $relayEnabled && $lastArchived ? 'Backups are successful' : ($relayEnabled ? 'Glacier relay active' : 'Backups disabled') }}
                            </span>
                        </div>
                        <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                            @if ($lastArchived)
                                Latest backup: <span class="font-medium text-[var(--color-ink-strong)]">{{ $lastArchived->diffForHumans() }}</span>
                                <div class="text-[10px] text-[var(--color-ink-muted)] font-mono mt-0.5">({{ $lastArchived->format('Y-m-d H:i:s') }})</div>
                            @else
                                <span x-show="lastAt" x-cloak>
                                    Latest backup: <span class="font-medium text-[var(--color-ink-strong)]" x-text="formatStamp(lastAt)"></span>
                                </span>
                                <span x-show="!lastAt">
                                    Latest backup: Awaiting initial scheduled run.
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Configuration / Cadence metadata box --}}
                <div class="mt-3.5 p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs space-y-2 border border-[var(--color-border-light)]/40">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Destination:</span>
                        <span class="font-medium text-[var(--color-ink-strong)] flex items-center gap-1.5">
                            <i class="fa-brands fa-aws text-amber-600"></i> AWS S3 Glacier IR
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Cadence:</span>
                        <select x-model="frequency"
                                @change="saveSchedule()"
                                :disabled="saving || !enabled"
                                class="text-[11px] rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-2 py-0.5 text-[var(--color-ink-strong)] cursor-pointer disabled:opacity-50">
                            <option value="daily">Daily</option>
                            <option value="twice_weekly">Twice weekly</option>
                            <option value="weekly">Weekly</option>
                        </select>
                    </div>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Next backup:</span>
                        <span class="font-medium text-[var(--color-ink-strong)]" x-text="enabled && nextAt ? formatStamp(nextAt) : '—'">
                            {{ $relayEnabled && $nextScheduled ? $nextScheduled->format('Y-m-d, H:i') : '—' }}
                        </span>
                    </div>
                </div>

                {{-- Latest Snapshot Quick Access --}}
                <div class="mt-3 p-2.5 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)]"
                     x-show="latestArchive"
                     x-cloak>
                    <div class="flex items-center justify-between text-xs">
                        <div class="min-w-0 pr-2">
                            <div class="font-medium text-[var(--color-ink-strong)] truncate flex items-center gap-1.5">
                                <i class="fa-solid fa-file-zipper text-emerald-600 text-[11px]"></i>
                                <span x-text="latestArchive?.filename || 'Latest archive'"></span>
                            </div>
                            <div class="text-[10px] text-[var(--color-ink-muted)] flex items-center gap-2 mt-0.5">
                                <span x-text="latestArchive?.archived_at_formatted || latestArchive?.last_modified_formatted || (lastAt ? formatStamp(lastAt) : '')"></span>
                                <span>·</span>
                                <span class="font-data" x-text="latestArchive?.size_formatted || formatBytes(latestArchive?.size_bytes)"></span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <a :href="latestArchive?.download_url"
                               class="text-xs text-emerald-700 hover:underline flex items-center gap-1"
                               x-show="latestArchive?.download_url"
                               title="Download archive from S3">
                                <i class="fa-solid fa-download text-[10px]"></i> Download
                            </a>
                            <button type="button"
                                    @click="openRestore(latestArchive)"
                                    :disabled="!hasRestoreCapability || restoring || runningNow"
                                    class="text-xs text-indigo-600 hover:text-indigo-700 hover:underline cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline font-medium flex items-center gap-1"
                                    :title="!hasRestoreCapability ? 'Companion backup-restore capability required' : 'Restore this archive to the site'">
                                <i class="fa-solid fa-clock-rotate-left text-[10px]"></i> Restore…
                            </button>
                        </div>
                    </div>
                </div>


                {{-- Backup Now Action Button --}}
                <div class="mt-3">
                    <button type="button"
                            @click="runNow()"
                            :disabled="runningNow || !enabled"
                            class="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-2 cursor-pointer transition-colors shadow-xs">
                        <i class="fa-solid" :class="runningNow ? 'fa-circle-notch fa-spin' : 'fa-cloud-arrow-up'"></i>
                        <span x-text="runningNow ? 'Backing up to Glacier…' : 'Backup Now'">Backup Now</span>
                    </button>
                    <p class="text-[11px] text-center text-[var(--color-ink-muted)] mt-1.5" x-show="runMessage" x-text="runMessage" x-cloak></p>
                </div>
            </div>
        @elseif ($hasRelayOrPressable)
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
                    Host backups (SpinupWP or Pressable) cover this site. Glacier relay is for unhosted WordPress sites.
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

                    <div x-show="loading" class="py-12 text-center text-sm text-[var(--color-ink-muted)]">
                        <i class="fa-solid fa-circle-notch fa-spin text-lg text-emerald-600 mb-2"></i>
                        <p>Querying backup storage for snapshots…</p>
                    </div>

                    <div x-show="error" class="p-4 rounded-xl bg-rose-50 text-rose-700 text-xs border border-rose-200 mb-4">
                        <i class="fa-solid fa-circle-exclamation mr-1.5"></i>
                        <span x-text="error"></span>
                    </div>

                    <div x-show="!loading && !error && data">
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
                                        <div class="p-3 hover:bg-[var(--color-surface-alt)]/50 transition-colors flex items-center justify-between text-xs gap-3">
                                            <div class="min-w-0">
                                                <div class="font-medium text-[var(--color-ink-strong)] truncate" x-text="archive.filename || archive.key"></div>
                                                <div class="text-[10px] text-[var(--color-ink-muted)]" x-text="archive.archived_at_formatted || archive.last_modified_formatted || archive.date"></div>
                                            </div>
                                            <div class="flex items-center gap-3 shrink-0">
                                                <div class="text-right">
                                                    <div class="font-data font-semibold text-[var(--color-ink-strong)]" x-text="archive.size_formatted || formatBytes(archive.size_bytes || archive.size)"></div>
                                                    <div class="text-[10px] text-emerald-600">Glacier IR</div>
                                                </div>
                                                @if ($isCustom)
                                                    <div class="flex items-center gap-2">
                                                        <a :href="archive.download_url"
                                                           class="text-xs text-emerald-700 hover:underline"
                                                           x-show="archive.download_url"
                                                           title="Download archive from S3">
                                                            Download
                                                        </a>
                                                        <button type="button"
                                                                @click="modalOpen = false; openRestore(archive)"
                                                                :disabled="!hasRestoreCapability || restoring || runningNow"
                                                                class="text-xs text-indigo-600 hover:text-indigo-700 hover:underline cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline font-medium"
                                                                :title="!hasRestoreCapability ? 'Companion backup-restore capability required' : 'Restore this archive to the site'">
                                                            Restore…
                                                        </button>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

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

    {{-- Restore Modal --}}
    <template x-teleport="body">
        <div x-show="restoreModalOpen"
             x-cloak
             @keydown.escape.window="if (!restoring) restoreModalOpen = false"
             class="fixed inset-0 z-50 overflow-y-auto"
             role="dialog"
             aria-modal="true">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-xs transition-opacity"
                 @click="if (!restoring) restoreModalOpen = false"></div>

            <div class="flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-xl rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-2xl p-6 transition-all"
                     @click.stop>
                    <div class="flex items-center justify-between pb-4 border-b border-[var(--color-border-light)] mb-4">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                <i class="fa-solid fa-clock-rotate-left text-sm"></i>
                            </div>
                            <div>
                                <h3 class="font-display font-semibold text-base text-[var(--color-ink-strong)]">
                                    Restore Backup — {{ $site->domain }}
                                </h3>
                                <p class="text-xs text-[var(--color-ink-muted)]">
                                    Operator-confirmed Glacier restore via Clockwork Companion.
                                </p>
                            </div>
                        </div>
                        <button type="button"
                                :disabled="restoring"
                                @click="restoreModalOpen = false"
                                class="w-8 h-8 rounded-full flex items-center justify-center text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] cursor-pointer disabled:opacity-50">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <div class="space-y-4 text-xs">
                        <template x-if="restoreArchive">
                            <div class="p-3 rounded-lg bg-[var(--color-surface-alt)]/60 border border-[var(--color-border-light)] flex items-center justify-between text-xs">
                                <div>
                                    <div class="font-medium text-[var(--color-ink-strong)]" x-text="restoreArchive.filename || restoreArchive.key"></div>
                                    <div class="text-[11px] text-[var(--color-ink-muted)]" x-text="restoreArchive.archived_at_formatted || restoreArchive.archived_at"></div>
                                </div>
                                <span class="font-data text-[var(--color-ink-muted)]" x-text="restoreArchive.size_formatted || formatBytes(restoreArchive.size_bytes)"></span>
                            </div>
                        </template>

                        <div x-show="restoreError" class="p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-xs flex items-start gap-2" x-cloak>
                            <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i>
                            <span x-text="restoreError"></span>
                        </div>

                        {{-- Precheck: no integrity hash on record --}}
                        <div x-show="shaAvailable === false && (!restoreState || restoreState.status === 'idle' || restoreState.status === 'failed')"
                             class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs flex items-start gap-2"
                             x-cloak>
                            <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0 text-amber-600"></i>
                            <span>No integrity hash on record for this archive — restore unavailable.</span>
                        </div>

                        {{-- Step 1: Confirmation & Stage --}}
                        <div x-show="shaAvailable !== false && (!restoreState || restoreState.status === 'idle' || restoreState.status === 'failed')">
                            <p class="text-[var(--color-ink-muted)] mb-3 leading-relaxed">
                                Restoring will download the archive from S3 Glacier to the site, verify its cryptographic hash, unpack database tables and files, and require your final confirmation before applying.
                            </p>
                            <div class="space-y-3">
                                <div>
                                    <label class="block font-medium text-[var(--color-ink-strong)] mb-1">
                                        Type the site domain <span class="font-mono text-indigo-600 select-all">{{ $site->domain }}</span> to confirm:
                                    </label>
                                    <input type="text"
                                           x-model="restoreConfirmDomain"
                                           placeholder="{{ $site->domain }}"
                                           class="w-full px-3 py-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:ring-2 focus:ring-indigo-500 font-mono text-xs">
                                </div>
                                <div>
                                    <label class="block font-medium text-[var(--color-ink-strong)] mb-1">
                                        Reason / Note (optional):
                                    </label>
                                    <input type="text"
                                           x-model="restoreNote"
                                           placeholder="e.g. Rolling back after plugin conflict"
                                           class="w-full px-3 py-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:ring-2 focus:ring-indigo-500 text-xs">
                                </div>
                            </div>
                            <div class="mt-5 flex items-center justify-end gap-2">
                                <button type="button"
                                        @click="restoreModalOpen = false"
                                        class="px-3 py-1.5 rounded-lg border border-[var(--color-border)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)] font-medium cursor-pointer">
                                    Cancel
                                </button>
                                <button type="button"
                                        @click="stageRestore()"
                                        :disabled="restoring || precheckLoading || restoreConfirmDomain !== siteDomain"
                                        class="px-4 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer flex items-center gap-2">
                                    <i class="fa-solid" :class="restoring ? 'fa-circle-notch fa-spin' : 'fa-download'"></i>
                                    <span x-text="restoring ? 'Staging…' : 'Stage Restore'"></span>
                                </button>
                            </div>
                        </div>

                        {{-- Step 2: Staging in progress --}}
                        <div x-show="restoring && restoreState && ['downloading', 'verifying', 'extracting', 'scanning'].includes(restoreState.status)" class="text-center py-6 space-y-3" x-cloak>
                            <div class="w-10 h-10 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center mx-auto text-lg">
                                <i class="fa-solid fa-circle-notch fa-spin"></i>
                            </div>
                            <div class="font-medium text-[var(--color-ink-strong)] capitalize" x-text="(restoreState.status || '') + ' archive…'"></div>
                            <p class="text-[11px] text-[var(--color-ink-muted)] max-w-sm mx-auto" x-text="restoreMessage || 'Downloading from Glacier and verifying integrity hash.'"></p>
                        </div>

                        {{-- Step 3: Staged — ready to apply --}}
                        <div x-show="restoreState && restoreState.status === 'staged'" class="space-y-4" x-cloak>
                            <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-start gap-2.5">
                                <i class="fa-solid fa-circle-check text-emerald-600 mt-0.5 text-sm"></i>
                                <div>
                                    <div class="font-semibold">Archive staged & verified</div>
                                    <div class="text-[11px] text-emerald-700 mt-0.5">
                                        SHA-256 hash verified. Database dump and files are unpacked on the server.
                                    </div>
                                </div>
                            </div>
                            <div class="p-3 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs space-y-1.5 border border-[var(--color-border-light)]">
                                <div class="flex justify-between gap-3">
                                    <span class="text-[var(--color-ink-muted)] shrink-0">Staged archive:</span>
                                    <span class="font-medium font-mono text-[11px] truncate" x-text="restoreState.filename || restoreState.archive_key || '—'"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-[var(--color-ink-muted)]">Database dump:</span>
                                    <span class="font-medium" x-text="restoreState.has_sql ? 'Present (prefix: ' + (restoreState.table_prefix || 'standard') + ')' : 'None'"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-[var(--color-ink-muted)]">Files (wp-content):</span>
                                    <span class="font-medium" x-text="restoreState.has_files ? 'Present' : 'None'"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-[var(--color-ink-muted)]">Verified hash:</span>
                                    <span class="font-mono text-[10px] text-[var(--color-ink-strong)]" x-text="(restoreState.actual_sha256 || '').slice(0, 16) + '…'"></span>
                                </div>
                            </div>
                            <div class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[11px] flex items-start gap-2">
                                <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0 text-amber-600"></i>
                                <span>Applying will place the site in maintenance mode, import matching database tables, and overwrite modified wp-content files. Maintenance mode is automatically lifted on success.</span>
                            </div>
                            <div class="mt-5 flex items-center justify-between">
                                <button type="button"
                                        @click="discardRestore()"
                                        :disabled="restoring"
                                        class="text-xs text-[var(--color-ink-muted)] hover:text-red-600 cursor-pointer disabled:opacity-50">
                                    Discard staged restore
                                </button>
                                <button type="button"
                                        @click="applyRestore()"
                                        :disabled="restoring"
                                        class="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white font-semibold disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer flex items-center gap-2 text-xs">
                                    <i class="fa-solid" :class="restoring ? 'fa-circle-notch fa-spin' : 'fa-bolt'"></i>
                                    <span x-text="restoring ? 'Applying…' : 'Apply Restore Now'"></span>
                                </button>
                            </div>
                        </div>

                        {{-- Step 4: Applying in progress --}}
                        <div x-show="restoring && restoreState && ['applying_sql', 'applying_files', 'finalizing'].includes(restoreState.status)" class="text-center py-6 space-y-3" x-cloak>
                            <div class="w-10 h-10 rounded-full bg-red-50 text-red-600 flex items-center justify-center mx-auto text-lg">
                                <i class="fa-solid fa-circle-notch fa-spin"></i>
                            </div>
                            <div class="font-medium text-[var(--color-ink-strong)] capitalize" x-text="(restoreState.status || '').replace('_', ' ') + '…'"></div>
                            <p class="text-[11px] text-[var(--color-ink-muted)] max-w-sm mx-auto">Maintenance mode is active. Restoring database tables and files.</p>
                        </div>

                        {{-- Step 5: Applied successfully --}}
                        <div x-show="restoreState && restoreState.status === 'applied'" class="text-center py-6 space-y-3" x-cloak>
                            <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto text-xl">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                            <div class="font-semibold text-sm text-[var(--color-ink-strong)]">Restore completed successfully!</div>
                            <p class="text-xs text-[var(--color-ink-muted)] max-w-xs mx-auto">
                                The site has been restored to the selected archive state. Maintenance mode has been lifted.
                            </p>
                            <div class="pt-2">
                                <button type="button"
                                        @click="discardRestore(); fetchHistory()"
                                        class="px-4 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold cursor-pointer">
                                    Done
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
