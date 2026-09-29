<div x-data="{
    open: false,
    serverId: null,
    serverName: '',
    loading: false,
    executing: false,
    errorMessage: null,
    diagnosis: null,
    run: null,
    executionOutput: null,
    executedSuccess: false,
    commands: [],

    init() {
        window.addEventListener('open-ai-remedy', (e) => {
            this.startDiagnosis(e.detail.serverId, e.detail.serverName, e.detail.reason);
        });
    },

    async startDiagnosis(serverId, serverName, reason = 'Server performance spike detected') {
        this.serverId = serverId;
        this.serverName = serverName;
        this.open = true;
        this.loading = true;
        this.executing = false;
        this.errorMessage = null;
        this.diagnosis = null;
        this.run = null;
        this.executionOutput = null;
        this.executedSuccess = false;
        this.commands = [];

        try {
            const res = await fetch(`/ai-remedy/servers/${serverId}/diagnose`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content') || ''
                },
                body: JSON.stringify({ reason: reason })
            });

            const data = await res.json();
            if (data.ok) {
                this.diagnosis = data.analysis;
                this.run = data.run;
                this.commands = [...(data.analysis.commands || [])];
            } else {
                this.errorMessage = data.message || 'Diagnosis failed.';
            }
        } catch (e) {
            this.errorMessage = 'Network error: ' + e.message;
        } finally {
            this.loading = false;
        }
    },

    async runRemedy() {
        if (!this.run || this.commands.length === 0) return;
        this.executing = true;
        this.errorMessage = null;

        try {
            const res = await fetch(`/ai-remedy/runs/${this.run.id}/execute`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content') || ''
                },
                body: JSON.stringify({ commands: this.commands })
            });

            const data = await res.json();
            this.executionOutput = data.output;
            if (data.ok) {
                this.executedSuccess = true;
            } else {
                this.errorMessage = data.message || 'Execution encountered an error.';
            }
        } catch (e) {
            this.errorMessage = 'Execution request failed: ' + e.message;
        } finally {
            this.executing = false;
        }
    },

    closeModal() {
        this.open = false;
    }
}" x-cloak x-show="open" class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity" @click="closeModal()"></div>

    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative w-full max-w-2xl rounded-2xl bg-[var(--color-bg-surface)] border border-[var(--color-border-subtle)] shadow-2xl p-6 text-xs text-[var(--color-ink-strong)]"
             @keydown.window.escape="closeModal()">
            
            {{-- Header --}}
            <div class="flex items-start justify-between pb-4 border-b border-[var(--color-border-subtle)]">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-[var(--color-brand)]/10 text-[var(--color-brand)] flex items-center justify-center text-sm">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-[var(--color-ink-strong)] flex items-center gap-2">
                            <span>AiRemedy Triage</span>
                            <span class="text-xs font-normal text-[var(--color-ink-muted)]">·</span>
                            <span class="text-xs font-medium text-[var(--color-ink-muted)]" x-text="serverName"></span>
                        </h3>
                        <p class="text-[11px] text-[var(--color-ink-muted)]">Read-only SSH telemetry probe & Claude 3.5 Sonnet analysis</p>
                    </div>
                </div>
                <button type="button" @click="closeModal()" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] p-1 cursor-pointer">
                    <i class="fa-solid fa-xmark text-base"></i>
                </button>
            </div>

            {{-- Loading State --}}
            <template x-if="loading">
                <div class="py-16 text-center space-y-3">
                    <div class="inline-block animate-spin text-[var(--color-brand)] text-3xl">
                        <i class="fa-solid fa-circle-notch"></i>
                    </div>
                    <div class="font-semibold text-xs text-[var(--color-ink-strong)]">Running Forensics & Consulting Claude 3.5 Sonnet...</div>
                    <p class="text-[11px] text-[var(--color-ink-muted)] max-w-xs mx-auto">
                        Collecting process table, memory usage, load averages, and error log tails over SSH.
                    </p>
                </div>
            </template>

            {{-- Error State --}}
            <template x-if="!loading && errorMessage && !executionOutput">
                <div class="py-8">
                    <div class="p-4 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-600 dark:text-rose-400 space-y-1">
                        <div class="font-bold flex items-center gap-1.5">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span>AiRemedy Error</span>
                        </div>
                        <div x-text="errorMessage"></div>
                    </div>
                </div>
            </template>

            {{-- Diagnosis Results --}}
            <template x-if="!loading && diagnosis">
                <div class="py-4 space-y-4">
                    {{-- Executive Summary Card --}}
                    <div class="card p-3.5 bg-[var(--color-bg-subtle)] space-y-1.5">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-muted)]">Executive Diagnosis</span>
                            <span class="px-2 py-0.5 rounded-full font-data text-[10px] font-semibold border"
                                  :class="diagnosis.safety_tier === 'tier_1_safe' ? 'bg-emerald-500/15 text-emerald-600 border-emerald-500/30' : 'bg-amber-500/15 text-amber-600 border-amber-500/30'"
                                  x-text="diagnosis.safety_tier?.replace('tier_', 'Tier ')"></span>
                        </div>
                        <p class="text-xs text-[var(--color-ink-strong)] leading-relaxed" x-text="diagnosis.summary"></p>
                    </div>

                    {{-- Root Cause & Explanation --}}
                    <div>
                        <span class="text-[11px] font-semibold text-[var(--color-ink-muted)] block mb-1">Identified Culprit:</span>
                        <div class="p-2.5 rounded-lg border border-[var(--color-border-subtle)] font-medium text-xs text-[var(--color-ink-strong)]"
                             x-text="diagnosis.root_cause"></div>
                    </div>

                    {{-- Proposed Commands --}}
                    <template x-if="commands.length > 0">
                        <div>
                            <span class="text-[11px] font-semibold text-[var(--color-ink-muted)] block mb-1.5">Proposed Remediation Commands (SSH):</span>
                            <div class="bg-neutral-950 text-emerald-400 p-3 rounded-lg font-mono text-[11px] space-y-1 overflow-x-auto">
                                <template x-for="(cmd, idx) in commands" :key="idx">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-neutral-500 select-none">$</span>
                                        <input type="text" x-model="commands[idx]" class="bg-transparent border-none p-0 text-emerald-400 focus:outline-none flex-1 font-mono text-[11px]">
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    {{-- Unfixable Warning --}}
                    <template x-if="!diagnosis.is_fixable">
                        <div class="p-3.5 rounded-lg bg-purple-500/10 border border-purple-500/30 text-purple-600 dark:text-purple-400 space-y-1">
                            <span class="font-bold flex items-center gap-1.5">
                                <i class="fa-solid fa-hand-holding-hand"></i> Requires Developer Intervention
                            </span>
                            <div class="text-[11px]" x-text="diagnosis.unfixable_briefing || diagnosis.explanation"></div>
                        </div>
                    </template>

                    {{-- Terminal Output (After Run) --}}
                    <template x-if="executionOutput">
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between text-[11px]">
                                <span class="font-semibold text-[var(--color-ink-muted)]">SSH Execution Result:</span>
                                <span class="font-semibold" :class="executedSuccess ? 'text-emerald-600' : 'text-rose-600'" x-text="executedSuccess ? '✅ Successfully Resolved' : '❌ Encountered Issues'"></span>
                            </div>
                            <pre class="bg-neutral-950 text-neutral-200 p-3.5 rounded-lg font-mono text-[11px] max-h-48 overflow-y-auto whitespace-pre-wrap"
                                 x-text="executionOutput"></pre>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Footer Actions --}}
            <div class="pt-4 border-t border-[var(--color-border-subtle)] flex items-center justify-between">
                <div>
                    <template x-if="run">
                        <a :href="`/ai-remedy?server_id=${serverId}`" target="_blank" class="text-[11px] text-[var(--color-brand)] hover:underline flex items-center gap-1">
                            <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
                            <span>View in AiRemedy Audit Log</span>
                        </a>
                    </template>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" @click="closeModal()" class="btn-pill-nav text-xs py-1.5 px-3 border border-[var(--color-border-subtle)] hover:bg-[var(--color-bg-subtle)] cursor-pointer">
                        <span x-text="executedSuccess ? 'Close' : 'Cancel'"></span>
                    </button>

                    <template x-if="!loading && diagnosis && diagnosis.is_fixable && commands.length > 0 && !executedSuccess">
                        <button type="button"
                                @click="runRemedy()"
                                :disabled="executing"
                                class="btn-primary text-xs py-1.5 px-4 font-semibold flex items-center gap-1.5 cursor-pointer disabled:opacity-50">
                            <i class="fa-solid" :class="executing ? 'fa-spinner fa-spin' : 'fa-play'"></i>
                            <span x-text="executing ? 'Executing via SSH...' : 'Execute Fix via SSH'"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>
