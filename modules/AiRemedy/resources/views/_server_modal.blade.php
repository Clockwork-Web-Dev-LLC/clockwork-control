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
    copiedCommands: false,
    copiedOutput: false,
    loadingStep: 1,
    stepTimer1: null,
    stepTimer2: null,

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
        this.copiedCommands = false;
        this.copiedOutput = false;
        this.loadingStep = 1;

        if (this.stepTimer1) clearTimeout(this.stepTimer1);
        if (this.stepTimer2) clearTimeout(this.stepTimer2);

        this.stepTimer1 = setTimeout(() => {
            if (this.loading) this.loadingStep = 2;
        }, 1200);

        this.stepTimer2 = setTimeout(() => {
            if (this.loading) this.loadingStep = 3;
        }, 2800);

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
            if (this.stepTimer1) clearTimeout(this.stepTimer1);
            if (this.stepTimer2) clearTimeout(this.stepTimer2);
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
        if (this.stepTimer1) clearTimeout(this.stepTimer1);
        if (this.stepTimer2) clearTimeout(this.stepTimer2);
    },

    copyCommands() {
        if (!this.commands.length) return;
        navigator.clipboard.writeText(this.commands.join('\n'));
        this.copiedCommands = true;
        setTimeout(() => { this.copiedCommands = false; }, 2000);
    },

    copyOutput() {
        if (!this.executionOutput) return;
        navigator.clipboard.writeText(this.executionOutput);
        this.copiedOutput = true;
        setTimeout(() => { this.copiedOutput = false; }, 2000);
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
        if (!tier) return 'bg-neutral-500/10 text-neutral-600 dark:text-neutral-400 border-neutral-500/25';
        switch (tier) {
            case 'tier_1_safe':
                return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/30';
            case 'tier_2_cautious':
                return 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/30';
            case 'tier_3_prohibited':
            case 'tier_unfixable':
            case 'unfixable':
                return 'bg-rose-500/10 text-rose-700 dark:text-rose-300 border-rose-500/30';
            default:
                return 'bg-neutral-500/10 text-neutral-600 dark:text-neutral-400 border-neutral-500/25';
        }
    }
}" x-cloak x-show="open" class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    {{-- Backdrop --}}
    <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-xs transition-opacity"
         x-show="open"
         x-transition:enter="ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="closeModal()"></div>

    <div class="flex min-h-full items-center justify-center p-4 sm:p-6">
        <div class="relative w-full max-w-2xl rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-2xl p-6 sm:p-7 text-xs text-[var(--color-ink)] transition-all overflow-hidden"
             x-show="open"
             x-transition:enter="ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95 translate-y-2"
             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
             x-transition:leave="ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100 translate-y-0"
             x-transition:leave-end="opacity-0 scale-95 translate-y-2"
             @keydown.window.escape="closeModal()">

            {{-- Header --}}
            <div class="flex items-start justify-between pb-4 border-b border-[var(--color-border-light)]">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[var(--color-brand)]/10 text-[var(--color-brand)] border border-[var(--color-brand)]/20 flex items-center justify-center text-base shadow-xs shrink-0">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-display font-bold text-[var(--color-ink-strong)] tracking-tight">AiRemedy Triage</h3>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-[var(--color-ink-muted)]">
                                <i class="fa-solid fa-server text-[9px] mr-1 text-[var(--color-ink-soft)]"></i>
                                <span x-text="serverName"></span>
                            </span>
                        </div>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">Read-only SSH telemetry probe & AI analysis</p>
                    </div>
                </div>
                <button type="button"
                        @click="closeModal()"
                        class="w-8 h-8 rounded-lg flex items-center justify-center text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors cursor-pointer"
                        aria-label="Close dialog">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            {{-- Loading State --}}
            <template x-if="loading">
                <div class="py-10 px-2 space-y-6">
                    <div class="text-center space-y-2">
                        <div class="relative w-14 h-14 mx-auto mb-3 flex items-center justify-center">
                            <div class="absolute inset-0 rounded-2xl bg-[var(--color-brand)]/15 animate-ping opacity-70"></div>
                            <div class="relative w-12 h-12 rounded-2xl bg-gradient-to-br from-[var(--color-brand)] to-blue-600 text-white flex items-center justify-center text-lg shadow-md shadow-blue-500/25">
                                <i class="fa-solid fa-wand-magic-sparkles animate-pulse"></i>
                            </div>
                        </div>
                        <h4 class="text-sm font-display font-bold text-[var(--color-ink-strong)]">Running Forensics & AI Triage</h4>
                        <p class="text-xs text-[var(--color-ink-muted)] max-w-sm mx-auto leading-relaxed">
                            Inspecting live processes, memory footprint, load averages, and error log tails over SSH.
                        </p>
                    </div>

                    {{-- Stepped Progress Indicator --}}
                    <div class="max-w-md mx-auto rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] p-4 space-y-3">
                        {{-- Step 1 --}}
                        <div class="flex items-center justify-between text-xs">
                            <div class="flex items-center gap-2.5">
                                <div class="w-5 h-5 rounded-full flex items-center justify-center text-[10px]"
                                     :class="loadingStep > 1 ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 font-bold' : (loadingStep === 1 ? 'bg-[var(--color-brand)]/20 text-[var(--color-brand)] font-bold' : 'bg-neutral-500/10 text-neutral-400')">
                                    <template x-if="loadingStep > 1"><i class="fa-solid fa-check"></i></template>
                                    <template x-if="loadingStep === 1"><i class="fa-solid fa-circle-notch fa-spin"></i></template>
                                    <template x-if="loadingStep < 1"><span>1</span></template>
                                </div>
                                <span :class="loadingStep >= 1 ? 'text-[var(--color-ink-strong)] font-medium' : 'text-[var(--color-ink-muted)]'">SSH Telemetry Probe (Top CPU, RAM & Load)</span>
                            </div>
                            <span class="text-[10px] font-mono text-[var(--color-ink-muted)]" x-text="loadingStep > 1 ? 'Done' : (loadingStep === 1 ? 'Connecting…' : 'Pending')"></span>
                        </div>

                        {{-- Step 2 --}}
                        <div class="flex items-center justify-between text-xs">
                            <div class="flex items-center gap-2.5">
                                <div class="w-5 h-5 rounded-full flex items-center justify-center text-[10px]"
                                     :class="loadingStep > 2 ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 font-bold' : (loadingStep === 2 ? 'bg-[var(--color-brand)]/20 text-[var(--color-brand)] font-bold' : 'bg-neutral-500/10 text-neutral-400')">
                                    <template x-if="loadingStep > 2"><i class="fa-solid fa-check"></i></template>
                                    <template x-if="loadingStep === 2"><i class="fa-solid fa-circle-notch fa-spin"></i></template>
                                    <template x-if="loadingStep < 2"><span>2</span></template>
                                </div>
                                <span :class="loadingStep >= 2 ? 'text-[var(--color-ink-strong)] font-medium' : 'text-[var(--color-ink-muted)]'">Process Table & Error Log Inspection</span>
                            </div>
                            <span class="text-[10px] font-mono text-[var(--color-ink-muted)]" x-text="loadingStep > 2 ? 'Done' : (loadingStep === 2 ? 'Analyzing…' : 'Pending')"></span>
                        </div>

                        {{-- Step 3 --}}
                        <div class="flex items-center justify-between text-xs">
                            <div class="flex items-center gap-2.5">
                                <div class="w-5 h-5 rounded-full flex items-center justify-center text-[10px]"
                                     :class="loadingStep === 3 ? 'bg-[var(--color-brand)]/20 text-[var(--color-brand)] font-bold' : 'bg-neutral-500/10 text-neutral-400'">
                                    <template x-if="loadingStep === 3"><i class="fa-solid fa-circle-notch fa-spin"></i></template>
                                    <template x-if="loadingStep < 3"><span>3</span></template>
                                </div>
                                <span :class="loadingStep === 3 ? 'text-[var(--color-ink-strong)] font-medium' : 'text-[var(--color-ink-muted)]'">AI Reasoning & Fix Synthesis</span>
                            </div>
                            <span class="text-[10px] font-mono text-[var(--color-ink-muted)]" x-text="loadingStep === 3 ? 'Synthesizing…' : 'Pending'"></span>
                        </div>
                    </div>
                </div>
            </template>

            {{-- Error State --}}
            <template x-if="!loading && errorMessage && !executionOutput">
                <div class="py-6">
                    <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 space-y-1">
                        <div class="font-bold flex items-center gap-2 text-xs">
                            <i class="fa-solid fa-triangle-exclamation text-rose-500"></i>
                            <span>AiRemedy Error</span>
                        </div>
                        <div class="text-xs leading-relaxed" x-text="errorMessage"></div>
                    </div>
                </div>
            </template>

            {{-- Diagnosis Results --}}
            <template x-if="!loading && diagnosis">
                <div class="py-4 space-y-4">
                    {{-- Allowed Maintenance Banner --}}
                    <template x-if="run?.status === 'allowed_maintenance'">
                        <div class="rounded-xl border border-sky-500/30 bg-sky-500/10 p-3.5 space-y-1 text-sky-950 dark:text-sky-200">
                            <div class="flex items-center gap-1.5 font-bold text-xs">
                                <i class="fa-solid fa-cloud-arrow-up text-sky-500"></i>
                                <span>Allowed Background Maintenance Detected</span>
                            </div>
                            <p class="text-[11px] leading-relaxed">
                                Identified as routine background maintenance (e.g. SpinupWP S3 backup or database dump). Safe priority tuning (<code>renice</code> / <code>ionice</code>) is recommended instead of terminating processes.
                            </p>
                        </div>
                    </template>

                    {{-- Executive Diagnosis Card --}}
                    <div class="rounded-xl border border-blue-500/25 dark:border-blue-500/35 bg-gradient-to-br from-blue-50/70 via-indigo-50/30 to-transparent dark:from-blue-950/25 dark:via-indigo-950/15 dark:to-transparent p-4 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[var(--color-brand)] flex items-center gap-1.5">
                                <i class="fa-solid fa-brain-circuit"></i>
                                <span>Executive Diagnosis</span>
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full font-data text-[10px] font-semibold border whitespace-nowrap"
                                  :class="safetyBadgeClass(diagnosis.safety_tier)"
                                  x-text="formatSafetyTier(diagnosis.safety_tier)"></span>
                        </div>
                        <p class="text-xs sm:text-[13px] text-[var(--color-ink-strong)] leading-relaxed font-medium" x-text="diagnosis.summary"></p>
                    </div>

                    {{-- Identified Culprit --}}
                    <div class="space-y-1.5">
                        <div class="text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-muted)] flex items-center gap-1.5">
                            <i class="fa-solid fa-magnifying-glass text-[10px]"></i>
                            <span>Identified Culprit</span>
                        </div>
                        <div class="p-3.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-xs text-[var(--color-ink-strong)] leading-relaxed font-normal"
                             x-text="diagnosis.root_cause"></div>
                    </div>

                    {{-- Proposed Remediation Commands --}}
                    <template x-if="commands.length > 0">
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] font-bold uppercase tracking-wider text-[var(--color-ink-muted)] flex items-center gap-1.5">
                                    <i class="fa-solid fa-terminal text-[10px]"></i>
                                    <span>Proposed Remediation Commands (SSH)</span>
                                </span>
                                <button type="button"
                                        @click="copyCommands()"
                                        class="text-[11px] font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] flex items-center gap-1.5 transition-colors px-2 py-0.5 rounded-md hover:bg-[var(--color-surface-alt)] cursor-pointer">
                                    <i class="fa-regular" :class="copiedCommands ? 'fa-check text-emerald-500' : 'fa-copy'"></i>
                                    <span x-text="copiedCommands ? 'Copied' : 'Copy'"></span>
                                </button>
                            </div>

                            {{-- Terminal Window --}}
                            <div class="rounded-xl bg-slate-950 border border-slate-800 text-slate-100 overflow-hidden shadow-sm font-mono text-xs">
                                <div class="flex items-center justify-between px-3 py-1.5 bg-slate-900/90 border-b border-slate-800/80 text-[10px] text-slate-400 select-none">
                                    <div class="flex items-center gap-1.5">
                                        <span class="w-2.5 h-2.5 rounded-full bg-rose-500/80"></span>
                                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500/80"></span>
                                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500/80"></span>
                                        <span class="ml-2 font-mono text-[10px] text-slate-400">ssh-remedy</span>
                                    </div>
                                    <span class="text-[10px] text-slate-500">editable</span>
                                </div>
                                <div class="p-3 space-y-1.5 font-mono text-xs">
                                    <template x-for="(cmd, idx) in commands" :key="idx">
                                        <div class="flex items-center gap-2">
                                            <span class="text-emerald-400 font-bold select-none">$</span>
                                            <input type="text" x-model="commands[idx]" class="bg-transparent border-none p-0 text-slate-100 focus:text-white focus:outline-none focus:ring-0 flex-1 font-mono text-xs w-full tracking-wide">
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Unfixable Warning --}}
                    <template x-if="!diagnosis.is_fixable">
                        <div class="p-4 rounded-xl bg-purple-500/10 border border-purple-500/30 text-purple-700 dark:text-purple-300 space-y-1.5">
                            <div class="font-bold flex items-center gap-2 text-xs">
                                <i class="fa-solid fa-hand-holding-hand text-purple-500"></i>
                                <span>Requires Developer Intervention</span>
                            </div>
                            <div class="text-xs leading-relaxed" x-text="diagnosis.unfixable_briefing || diagnosis.explanation"></div>
                        </div>
                    </template>

                    {{-- Terminal Output (After Run) --}}
                    <template x-if="executionOutput">
                        <div class="space-y-2 pt-2 border-t border-[var(--color-border-light)]">
                            {{-- Outcome Banner --}}
                            <template x-if="executedSuccess">
                                <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 flex items-center justify-between text-xs text-emerald-800 dark:text-emerald-300">
                                    <div class="flex items-center gap-2 font-semibold">
                                        <i class="fa-solid fa-circle-check text-sm text-emerald-600 dark:text-emerald-400"></i>
                                        <span>Remediation Successfully Applied</span>
                                    </div>
                                    <span class="text-[10px] font-mono px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-800 dark:text-emerald-300 font-bold border border-emerald-500/30">Exit Code 0</span>
                                </div>
                            </template>

                            <template x-if="!executedSuccess">
                                <div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3.5 space-y-2 text-xs">
                                    <div class="flex items-center justify-between text-rose-700 dark:text-rose-300 font-semibold">
                                        <div class="flex items-center gap-2">
                                            <i class="fa-solid fa-circle-exclamation text-sm text-rose-600 dark:text-rose-400"></i>
                                            <span>Remediation Encountered an Issue</span>
                                        </div>
                                        <span class="text-[10px] font-mono px-2 py-0.5 rounded-full bg-rose-500/20 text-rose-700 dark:text-rose-300 font-bold border border-rose-500/30">Non-zero exit</span>
                                    </div>

                                    {{-- Sudo password guidance --}}
                                    <template x-if="executionOutput && (executionOutput.includes('sudo: a password is required') || executionOutput.includes('terminal is required to read the password'))">
                                        <div class="rounded-lg bg-rose-500/15 border border-rose-500/30 p-2.5 text-[11px] text-rose-900 dark:text-rose-200 flex items-start gap-2 leading-relaxed">
                                            <i class="fa-solid fa-key mt-0.5 text-xs text-rose-600 dark:text-rose-400 shrink-0"></i>
                                            <div>
                                                <strong>Passwordless Sudo Privilege Required:</strong> This server requires a password for sudo. In your server's <code class="font-mono bg-black/10 dark:bg-black/30 px-1 py-0.5 rounded">/etc/sudoers</code>, allow the SSH user passwordless command execution (e.g. <code class="font-mono bg-black/10 dark:bg-black/30 px-1 py-0.5 rounded">%sudo ALL=(ALL) NOPASSWD: ALL</code>) so non-interactive remediation commands can run without a TTY.
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Raw stdout/stderr terminal box --}}
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-[11px]">
                                    <span class="font-bold uppercase tracking-wider text-[var(--color-ink-muted)]">SSH Execution Log:</span>
                                    <button type="button"
                                            @click="copyOutput()"
                                            class="text-[11px] font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] flex items-center gap-1 transition-colors px-2 py-0.5 rounded hover:bg-[var(--color-surface-alt)] cursor-pointer">
                                        <i class="fa-regular" :class="copiedOutput ? 'fa-check text-emerald-500' : 'fa-copy'"></i>
                                        <span x-text="copiedOutput ? 'Copied' : 'Copy Log'"></span>
                                    </button>
                                </div>
                                <pre class="bg-slate-950 text-slate-200 p-3.5 rounded-xl font-mono text-[11px] max-h-48 overflow-y-auto whitespace-pre-wrap border border-slate-800 shadow-inner"
                                     x-text="executionOutput"></pre>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Footer Actions --}}
            <div class="pt-4 border-t border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <template x-if="run">
                        <a :href="`/ai-remedy?server_id=${serverId}`" target="_blank" class="text-xs font-semibold text-[var(--color-brand)] hover:underline flex items-center gap-1.5 transition-colors">
                            <span>View in Audit Log</span>
                            <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                        </a>
                    </template>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button"
                            @click="closeModal()"
                            class="btn-pill-nav text-xs py-2 px-4 border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)] font-medium cursor-pointer transition-colors">
                        <span x-text="executedSuccess ? 'Close' : 'Cancel'"></span>
                    </button>

                    <template x-if="!loading && diagnosis && diagnosis.is_fixable && commands.length > 0 && !executedSuccess">
                        <button type="button"
                                @click="runRemedy()"
                                :disabled="executing"
                                class="btn-primary text-xs py-2 px-5 font-semibold flex items-center gap-2 shadow-sm hover:shadow transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fa-solid" :class="executing ? 'fa-circle-notch fa-spin' : 'fa-play text-[10px]'"></i>
                            <span x-text="executing ? 'Executing via SSH...' : 'Execute Fix via SSH'"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>
