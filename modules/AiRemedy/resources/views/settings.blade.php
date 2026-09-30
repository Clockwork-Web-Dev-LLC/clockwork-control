@extends('layouts.app')

@section('title', 'AiRemedy Settings · Clockwork')

@section('content')
    @include('settings._tabs')

    <div class="max-w-5xl mx-auto space-y-6" x-data="{
        apiKey: '',
        testing: false,
        testResult: null,
        simulating: false,
        simResult: null,
        simServerId: '{{ $servers->first()?->id ?? '' }}',
        simScenario: 'Simulated PHP-FPM pool exhaustion and 95% CPU spike',
        mode: '{{ $currentMode }}',
        actionTiers: {
            @foreach($actionsCatalog as $action)
                '{{ $action['id'] }}': '{{ $action['tier'] }}',
            @endforeach
        },
        actionsList: {{ Js::from(array_values($actionsCatalog)) }},
        draggedActionId: null,
        dragOverTier: null,
        getActionsForTier(tier) {
            return this.actionsList.filter(a => (this.actionTiers[a.id] || a.default_tier) === tier);
        },
        dragStart(actionId) {
            this.draggedActionId = actionId;
        },
        dragEnd() {
            this.draggedActionId = null;
            this.dragOverTier = null;
        },
        dropAction(tier) {
            if (this.draggedActionId) {
                this.actionTiers[this.draggedActionId] = tier;
                this.draggedActionId = null;
                this.dragOverTier = null;
            }
        },
        moveAction(actionId, tier) {
            this.actionTiers[actionId] = tier;
        },
        resetToDefaults() {
            this.actionsList.forEach(a => {
                this.actionTiers[a.id] = a.default_tier;
            });
        },
        async testConnection() {
            this.testing = true;
            this.testResult = null;
            try {
                const res = await fetch('{{ route('ai-remedy.test-connection') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content') || ''
                    },
                    body: JSON.stringify({ api_key: this.apiKey })
                });
                this.testResult = await res.json();
            } catch (e) {
                this.testResult = { ok: false, message: 'Request failed: ' + e.message };
            } finally {
                this.testing = false;
            }
        },
        async runSimulation() {
            if (!this.simServerId) return;
            this.simulating = true;
            this.simResult = null;
            try {
                const res = await fetch('{{ route('ai-remedy.simulate') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content') || ''
                    },
                    body: JSON.stringify({
                        server_id: this.simServerId,
                        scenario: this.simScenario
                    })
                });
                this.simResult = await res.json();
            } catch (e) {
                this.simResult = { ok: false, message: 'Simulation request failed: ' + e.message };
            } finally {
                this.simulating = false;
            }
        }
    }">
        <x-page-header title="AiRemedy Settings"
            subtitle="Configure universal AI operations via OpenRouter, set your passive observability mode, and test simulations safely.">
            <x-slot:actions>
                <a href="{{ route('ai-remedy.index') }}"
                   class="btn-pill-nav text-xs py-1.5 px-3 flex items-center gap-1.5 border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)] font-medium">
                    <i class="fa-solid fa-clipboard-list text-xs"></i>
                    <span>Audit Log</span>
                </a>
            </x-slot:actions>
        </x-page-header>

        @if(session('status'))
            <div class="card p-3.5 bg-emerald-500/10 border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-xs flex items-center gap-2">
                <i class="fa-solid fa-circle-check"></i>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('ai-remedy.settings.update') }}" class="space-y-6">
            @csrf

            {{-- 1. Operating Mode Selection --}}
            <div class="card p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-sm font-bold text-[var(--color-ink-strong)]">Operating Mode & Automation Policy</h3>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                            Control whether AiRemedy acts purely as a passive observer (recommended), an interactive copilot, or an autonomous self-healer.
                        </p>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-full font-data text-xs font-semibold"
                          :class="mode === 'watch' ? 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30' : (mode === 'interactive' ? 'bg-blue-500/15 text-blue-600 dark:text-blue-400 border border-blue-500/30' : 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30')">
                        <span x-text="mode === 'watch' ? 'Watch Mode (Active)' : (mode === 'interactive' ? 'Interactive (Active)' : 'Auto-Heal (Active)')"></span>
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- Watch Mode --}}
                    <label class="relative flex flex-col justify-between p-4 rounded-xl border cursor-pointer transition-all"
                           :class="mode === 'watch' ? 'border-[var(--color-brand)] bg-[var(--color-surface-alt)] shadow-xs' : 'border-[var(--color-border)] hover:border-[var(--color-border-light)]'">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="mode" value="watch" x-model="mode"
                                           class="text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                                    <span class="text-xs font-bold text-[var(--color-ink-strong)]">Watch Mode</span>
                                </div>
                                <span class="px-1.5 py-0.5 rounded font-data text-[10px] font-semibold bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30">
                                    Recommended
                                </span>
                            </div>
                            <p class="text-[11px] text-[var(--color-ink-muted)] leading-relaxed">
                                <strong>Passive Observability.</strong> Diagnoses site outages and server spikes, records forensic root-causes, and logs <em>"What AiRemedy Would Have Done"</em>.
                            </p>
                        </div>
                        <div class="mt-3 pt-2.5 border-t border-[var(--color-border-light)] text-[10px] font-medium text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                            <i class="fa-solid fa-shield-halved"></i>
                            <span>Zero commands or file mutations executed</span>
                        </div>
                    </label>

                    {{-- Interactive Copilot --}}
                    <label class="relative flex flex-col justify-between p-4 rounded-xl border cursor-pointer transition-all"
                           :class="mode === 'interactive' ? 'border-[var(--color-brand)] bg-[var(--color-surface-alt)] shadow-xs' : 'border-[var(--color-border)] hover:border-[var(--color-border-light)]'">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="mode" value="interactive" x-model="mode"
                                           class="text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                                    <span class="text-xs font-bold text-[var(--color-ink-strong)]">Interactive Copilot</span>
                                </div>
                                <span class="px-1.5 py-0.5 rounded font-data text-[10px] font-semibold bg-blue-500/15 text-blue-600 dark:text-blue-400 border border-blue-500/30">
                                    Human Approval
                                </span>
                            </div>
                            <p class="text-[11px] text-[var(--color-ink-muted)] leading-relaxed">
                                AI analyzes outages and drafts proposed bash commands. Commands stay staged in the audit log until an operator clicks <strong>Approve & Execute</strong>.
                            </p>
                        </div>
                        <div class="mt-3 pt-2.5 border-t border-[var(--color-border-light)] text-[10px] font-medium text-[var(--color-ink-muted)] flex items-center gap-1">
                            <i class="fa-solid fa-user-check"></i>
                            <span>Requires manual confirmation</span>
                        </div>
                    </label>

                    {{-- Auto-Heal Mode --}}
                    <label class="relative flex flex-col justify-between p-4 rounded-xl border cursor-pointer transition-all"
                           :class="mode === 'auto_heal' ? 'border-[var(--color-brand)] bg-[var(--color-surface-alt)] shadow-xs' : 'border-[var(--color-border)] hover:border-[var(--color-border-light)]'">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <input type="radio" name="mode" value="auto_heal" x-model="mode"
                                           class="text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                                    <span class="text-xs font-bold text-[var(--color-ink-strong)]">Autonomous Self-Healing</span>
                                </div>
                                <span class="px-1.5 py-0.5 rounded font-data text-[10px] font-semibold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">
                                    Tier 1 Auto
                                </span>
                            </div>
                            <p class="text-[11px] text-[var(--color-ink-muted)] leading-relaxed">
                                Autonomously executes safe, non-destructive Tier 1 remediations (PHP-FPM worker reload, deprioritizing CPU priority, clearing stuck flags) and verifies recovery.
                            </p>
                        </div>
                        <div class="mt-3 pt-2.5 border-t border-[var(--color-border-light)] text-[10px] font-medium text-amber-600 dark:text-amber-400 flex items-center gap-1">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span>Tier 2 (restarts/kills) still requires review</span>
                        </div>
                    </label>
                </div>
            </div>

            {{-- 2. Configurable Remediation Safety Tier Matrix (Kanban Board) --}}
            <div class="card p-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-bold text-[var(--color-ink-strong)]">Remediation Safety Tier Policy Matrix</h3>
                            <span class="px-2 py-0.5 rounded-full font-data text-[10px] font-semibold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">
                                Drag & Drop Configurable
                            </span>
                        </div>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                            Organize specific remediation actions into safety tiers. In <strong>Auto-Heal</strong> mode, Tier 1 actions run autonomously; Tier 2 actions always require human confirmation in <strong>Copilot</strong> mode; Tier 3 actions are blocked. Drag cards between columns or use the dropdown selectors.
                        </p>
                    </div>
                    <button type="button"
                            @click="resetToDefaults()"
                            class="btn-pill-nav text-xs py-1.5 px-3 border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] flex items-center gap-1.5 self-start sm:self-auto cursor-pointer font-medium">
                        <i class="fa-solid fa-rotate-left text-[10px]"></i>
                        <span>Reset to Defaults</span>
                    </button>
                </div>

                {{-- Hidden input containing the JSON map of action_id => tier --}}
                <input type="hidden" name="safety_tier_rules" :value="JSON.stringify(actionTiers)">

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- Column 1: Tier 1 Safe / Autonomous --}}
                    <div class="flex flex-col rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-3.5 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-1.5 font-bold text-xs text-emerald-700 dark:text-emerald-400">
                                <i class="fa-solid fa-circle-check"></i>
                                <span>Tier 1 · Safe (Autonomous)</span>
                            </div>
                            <span class="font-data text-[11px] font-bold px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-800 dark:text-emerald-300"
                                  x-text="getActionsForTier('tier_1_safe').length"></span>
                        </div>
                        <p class="text-[11px] text-[var(--color-ink-muted)] leading-relaxed">
                            Non-destructive actions executed automatically when Auto-Heal is enabled. Zero disruption to active web traffic.
                        </p>

                        {{-- Drop Area --}}
                        <div class="space-y-2 flex-1 min-h-[140px] p-2 rounded-lg transition-colors"
                             :class="dragOverTier === 'tier_1_safe' ? 'bg-emerald-500/20 border-2 border-dashed border-emerald-500/60' : 'bg-[var(--color-surface)]/70 border border-emerald-500/20'"
                             @dragover.prevent="dragOverTier = 'tier_1_safe'"
                             @dragleave="if (dragOverTier === 'tier_1_safe') dragOverTier = null"
                             @drop="dropAction('tier_1_safe')">
                            
                            <template x-for="action in getActionsForTier('tier_1_safe')" :key="action.id">
                                <div draggable="true"
                                     @dragstart="dragStart(action.id)"
                                     @dragend="dragEnd()"
                                     class="p-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] shadow-2xs hover:shadow-xs transition-all cursor-grab active:cursor-grabbing space-y-1.5 select-none">
                                    <div class="flex items-start justify-between gap-1.5">
                                        <div class="flex items-center gap-1.5 font-semibold text-xs text-[var(--color-ink-strong)]">
                                            <i class="fa-solid fa-grip-vertical text-neutral-400 text-[10px]"></i>
                                            <span x-text="action.label"></span>
                                        </div>
                                        <select @change="moveAction(action.id, $event.target.value)"
                                                class="text-[10px] py-0.5 px-1 rounded border border-[var(--color-border)] bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] cursor-pointer">
                                            <option value="tier_1_safe" selected>Tier 1</option>
                                            <option value="tier_2_cautious">Tier 2</option>
                                            <option value="tier_3_prohibited">Tier 3</option>
                                        </select>
                                    </div>
                                    <div class="text-[11px] text-[var(--color-ink-muted)] leading-snug" x-text="action.description"></div>
                                    <div class="font-mono text-[10px] text-neutral-600 dark:text-neutral-400 bg-neutral-100 dark:bg-neutral-900 px-1.5 py-1 rounded truncate border border-[var(--color-border-light)]"
                                         :title="action.command_example"
                                         x-text="action.command_example"></div>
                                </div>
                            </template>

                            <template x-if="getActionsForTier('tier_1_safe').length === 0">
                                <div class="h-24 flex items-center justify-center text-center text-[11px] text-[var(--color-ink-soft)] border border-dashed border-[var(--color-border)] rounded-lg">
                                    Drop actions here for autonomous execution
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Column 2: Tier 2 Cautious / One-Click Approval --}}
                    <div class="flex flex-col rounded-xl border border-amber-500/30 bg-amber-500/5 p-3.5 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-1.5 font-bold text-xs text-amber-700 dark:text-amber-400">
                                <i class="fa-solid fa-user-check"></i>
                                <span>Tier 2 · Cautious (Human Approval)</span>
                            </div>
                            <span class="font-data text-[11px] font-bold px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-800 dark:text-amber-300"
                                  x-text="getActionsForTier('tier_2_cautious').length"></span>
                        </div>
                        <p class="text-[11px] text-[var(--color-ink-muted)] leading-relaxed">
                            Potentially disruptive actions (service restarts, process kills). Requires manual confirmation in Copilot mode; never run autonomously.
                        </p>

                        {{-- Drop Area --}}
                        <div class="space-y-2 flex-1 min-h-[140px] p-2 rounded-lg transition-colors"
                             :class="dragOverTier === 'tier_2_cautious' ? 'bg-amber-500/20 border-2 border-dashed border-amber-500/60' : 'bg-[var(--color-surface)]/70 border border-amber-500/20'"
                             @dragover.prevent="dragOverTier = 'tier_2_cautious'"
                             @dragleave="if (dragOverTier === 'tier_2_cautious') dragOverTier = null"
                             @drop="dropAction('tier_2_cautious')">
                            
                            <template x-for="action in getActionsForTier('tier_2_cautious')" :key="action.id">
                                <div draggable="true"
                                     @dragstart="dragStart(action.id)"
                                     @dragend="dragEnd()"
                                     class="p-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] shadow-2xs hover:shadow-xs transition-all cursor-grab active:cursor-grabbing space-y-1.5 select-none">
                                    <div class="flex items-start justify-between gap-1.5">
                                        <div class="flex items-center gap-1.5 font-semibold text-xs text-[var(--color-ink-strong)]">
                                            <i class="fa-solid fa-grip-vertical text-neutral-400 text-[10px]"></i>
                                            <span x-text="action.label"></span>
                                        </div>
                                        <select @change="moveAction(action.id, $event.target.value)"
                                                class="text-[10px] py-0.5 px-1 rounded border border-[var(--color-border)] bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] cursor-pointer">
                                            <option value="tier_1_safe">Tier 1</option>
                                            <option value="tier_2_cautious" selected>Tier 2</option>
                                            <option value="tier_3_prohibited">Tier 3</option>
                                        </select>
                                    </div>
                                    <div class="text-[11px] text-[var(--color-ink-muted)] leading-snug" x-text="action.description"></div>
                                    <div class="font-mono text-[10px] text-neutral-600 dark:text-neutral-400 bg-neutral-100 dark:bg-neutral-900 px-1.5 py-1 rounded truncate border border-[var(--color-border-light)]"
                                         :title="action.command_example"
                                         x-text="action.command_example"></div>
                                </div>
                            </template>

                            <template x-if="getActionsForTier('tier_2_cautious').length === 0">
                                <div class="h-24 flex items-center justify-center text-center text-[11px] text-[var(--color-ink-soft)] border border-dashed border-[var(--color-border)] rounded-lg">
                                    Drop actions here to require operator review
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Column 3: Tier 3 Prohibited / Blocked --}}
                    <div class="flex flex-col rounded-xl border border-rose-500/30 bg-rose-500/5 p-3.5 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-1.5 font-bold text-xs text-rose-700 dark:text-rose-400">
                                <i class="fa-solid fa-ban"></i>
                                <span>Tier 3 · Prohibited (Blocked)</span>
                            </div>
                            <span class="font-data text-[11px] font-bold px-2 py-0.5 rounded-full bg-rose-500/20 text-rose-800 dark:text-rose-300"
                                  x-text="getActionsForTier('tier_3_prohibited').length"></span>
                        </div>
                        <p class="text-[11px] text-[var(--color-ink-muted)] leading-relaxed">
                            Prohibited actions. The security engine will strictly block these commands from being proposed or executed.
                        </p>

                        {{-- Drop Area --}}
                        <div class="space-y-2 p-2 rounded-lg transition-colors"
                             :class="dragOverTier === 'tier_3_prohibited' ? 'bg-rose-500/20 border-2 border-dashed border-rose-500/60' : 'bg-[var(--color-surface)]/70 border border-rose-500/20'"
                             @dragover.prevent="dragOverTier = 'tier_3_prohibited'"
                             @dragleave="if (dragOverTier === 'tier_3_prohibited') dragOverTier = null"
                             @drop="dropAction('tier_3_prohibited')">
                            
                            <template x-for="action in getActionsForTier('tier_3_prohibited')" :key="action.id">
                                <div draggable="true"
                                     @dragstart="dragStart(action.id)"
                                     @dragend="dragEnd()"
                                     class="p-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] shadow-2xs hover:shadow-xs transition-all cursor-grab active:cursor-grabbing space-y-1.5 select-none">
                                    <div class="flex items-start justify-between gap-1.5">
                                        <div class="flex items-center gap-1.5 font-semibold text-xs text-[var(--color-ink-strong)]">
                                            <i class="fa-solid fa-grip-vertical text-neutral-400 text-[10px]"></i>
                                            <span x-text="action.label"></span>
                                        </div>
                                        <select @change="moveAction(action.id, $event.target.value)"
                                                class="text-[10px] py-0.5 px-1 rounded border border-[var(--color-border)] bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] cursor-pointer">
                                            <option value="tier_1_safe">Tier 1</option>
                                            <option value="tier_2_cautious">Tier 2</option>
                                            <option value="tier_3_prohibited" selected>Tier 3</option>
                                        </select>
                                    </div>
                                    <div class="text-[11px] text-[var(--color-ink-muted)] leading-snug" x-text="action.description"></div>
                                    <div class="font-mono text-[10px] text-neutral-600 dark:text-neutral-400 bg-neutral-100 dark:bg-neutral-900 px-1.5 py-1 rounded truncate border border-[var(--color-border-light)]"
                                         :title="action.command_example"
                                         x-text="action.command_example"></div>
                                </div>
                            </template>

                            <template x-if="getActionsForTier('tier_3_prohibited').length === 0">
                                <div class="py-4 text-center text-[11px] text-[var(--color-ink-soft)] border border-dashed border-[var(--color-border)] rounded-lg">
                                    Drop actions here to forbid execution
                                </div>
                            </template>
                        </div>

                        {{-- Locked Guardrails Section (Permanent Security Floor) --}}
                        <div class="pt-3 border-t border-rose-500/20 space-y-2">
                            <div class="flex items-center gap-1.5 text-[11px] font-bold text-rose-700 dark:text-rose-400">
                                <i class="fa-solid fa-lock text-[10px]"></i>
                                <span>Security Floor (Hardcoded & Unmovable)</span>
                            </div>
                            <div class="space-y-1.5">
                                @foreach($lockedGuards as $locked)
                                    <div class="p-2 rounded-lg border border-rose-500/20 bg-rose-500/5 text-[11px] space-y-0.5">
                                        <div class="flex items-center justify-between font-semibold text-[var(--color-ink-strong)]">
                                            <span>{{ $locked['label'] }}</span>
                                            <span class="text-[9px] font-mono px-1 rounded bg-rose-500/20 text-rose-700 dark:text-rose-300 font-bold border border-rose-500/30 flex items-center gap-1">
                                                <i class="fa-solid fa-lock text-[8px]"></i> LOCKED
                                            </span>
                                        </div>
                                        <div class="font-mono text-[10px] text-rose-600 dark:text-rose-400 truncate" title="{{ $locked['pattern'] }}">
                                            {{ $locked['pattern'] }}
                                        </div>
                                        <div class="text-[10px] text-[var(--color-ink-soft)]">{{ $locked['reason'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 3. Allowed Maintenance & Noise Filtering --}}
            <div class="card p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-bold text-[var(--color-ink-strong)]">Allowed Maintenance & Noise Filtering</h3>
                            <span class="px-2 py-0.5 rounded-full font-data text-[10px] font-semibold bg-sky-500/15 text-sky-600 dark:text-sky-400 border border-sky-500/30">
                                Smart Noise Reduction
                            </span>
                        </div>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                            Classify predictable background system workloads (e.g. SpinupWP S3 backups, mysqldump, logrotate) so heavy CPU usage is recognized as expected and alert channels are not spammed.
                        </p>
                    </div>
                </div>

                <div class="space-y-4">
                    <label class="flex items-start gap-3 p-3.5 rounded-xl border border-[var(--color-border)] cursor-pointer hover:bg-[var(--color-surface-alt)] transition-colors">
                        <input type="checkbox" name="auto_mute_maintenance_alerts" value="1" {{ $autoMuteMaintenance ? 'checked' : '' }}
                               class="mt-1 rounded text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                        <div class="flex-1">
                            <span class="text-xs font-bold text-[var(--color-ink-strong)]">Mute Chat Notifications for Allowed Maintenance</span>
                            <span class="block text-[11px] text-[var(--color-ink-muted)] mt-0.5 leading-relaxed">
                                When enabled, server spikes identified as allowed background maintenance will be marked as <strong class="text-sky-600 dark:text-sky-400">Allowed Maintenance</strong> in the audit log and will suppress Slack and Mattermost alert notifications.
                            </span>
                        </div>
                    </label>

                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Allowed Maintenance Process Patterns (Comma-separated)
                        </label>
                        <input type="text"
                               name="allowed_maintenance_processes"
                               value="{{ $allowedProcessesString }}"
                               class="input text-xs w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] p-2.5 font-mono">
                        <span class="text-[11px] text-[var(--color-ink-soft)] mt-1.5 block leading-relaxed">
                            Processes matching these names (e.g. <code>rclone, mysqldump, logrotate, borgbackup, borg, gpbup, restic, duplicity</code>) will not be flagged as unfixable runaway incidents when they spike CPU.
                        </span>
                    </div>

                    <div class="p-3.5 rounded-xl bg-sky-500/10 border border-sky-500/25 text-xs text-sky-950 dark:text-sky-200 flex items-start gap-3">
                        <div class="w-7 h-7 rounded-lg bg-sky-500/20 text-sky-600 dark:text-sky-400 flex items-center justify-center shrink-0 mt-0.5">
                            <i class="fa-solid fa-cloud-arrow-up text-sm"></i>
                        </div>
                        <div class="space-y-1">
                            <div class="font-bold text-sky-900 dark:text-sky-200">How Cloud Backups (SpinupWP, GridPane, Borg & S3) are Handled</div>
                            <div class="text-[11px] text-sky-800/90 dark:text-sky-300 leading-relaxed">
                                Control panels run heavy automated backup tasks in the background: <strong>SpinupWP</strong> uses <code>rclone</code> to stream site snapshots to S3 / DigitalOcean Spaces, while <strong>GridPane</strong> runs <code>borg</code> (BorgBackup via <code>gpbup</code>), <code>duplicity</code>, or <code>restic</code>. On 1–2 vCPU servers, chunk hashing, gzip/zstd compression, and encryption naturally demand 100%+ CPU during backup windows. Instead of killing backup jobs, AiRemedy recommends deprioritizing the task with <code>sudo renice -n 19</code> and <code>sudo ionice -c 3</code> so backups proceed peacefully while web traffic receives immediate CPU priority.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 4. Continuous Spike Monitoring & Watchdog --}}
            <div class="card p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-bold text-[var(--color-ink-strong)]">Automated Server Spike Monitoring</h3>
                            <span class="px-2 py-0.5 rounded-full font-data text-[10px] font-semibold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">
                                5-Min Watchdog
                            </span>
                        </div>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                            Automatically triggers AiRemedy when a server experiences sustained CPU or load spikes, diagnosing root causes under your active mode (Shadow Mode or Auto-Heal).
                        </p>
                    </div>
                </div>

                <div class="space-y-4">
                    <label class="flex items-start gap-3 p-3.5 rounded-xl border border-[var(--color-border)] cursor-pointer hover:bg-[var(--color-surface-alt)] transition-colors">
                        <input type="checkbox" name="auto_triage_spikes" value="1" {{ $autoTriageSpikes ? 'checked' : '' }}
                               class="mt-1 rounded text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                        <div class="flex-1">
                            <span class="text-xs font-bold text-[var(--color-ink-strong)]">Enable Continuous Fleet Spike Watchdog</span>
                            <span class="block text-[11px] text-[var(--color-ink-muted)] mt-0.5 leading-relaxed">
                                When enabled, the scheduled watchdog (<code>clockwork:watch-server-spikes</code> & <code>clockwork:poll-servers</code>) actively evaluates CPU and load metrics every 5 minutes across cloud and unlinked VPS boxes. In <strong>Shadow Mode</strong>, it logs the full diagnosis and what it would have done with <strong>0 server mutations</strong>.
                            </span>
                        </div>
                    </label>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-1">
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                                CPU Spike Trigger Threshold (%)
                            </label>
                            <input type="number"
                                   name="cpu_spike_threshold"
                                   value="{{ $cpuSpikeThreshold }}"
                                   min="50"
                                   max="99"
                                   class="input text-xs w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] p-2.5 font-data">
                            <span class="text-[11px] text-[var(--color-ink-soft)] mt-1 block">
                                Servers reaching or exceeding this CPU % will trigger automated root-cause analysis (default: 85%).
                            </span>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                                Cooldown Window Between Triage (Minutes)
                            </label>
                            <input type="number"
                                   name="cooldown_minutes"
                                   value="{{ $cooldownMinutes }}"
                                   min="5"
                                   max="1440"
                                   class="input text-xs w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] p-2.5 font-data">
                            <span class="text-[11px] text-[var(--color-ink-soft)] mt-1 block">
                                Prevents duplicate diagnoses and excessive token spend if a server stays hot while under review (default: 30 min).
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 3. OpenRouter API Key --}}
            <div class="card p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-sm font-bold text-[var(--color-ink-strong)]">OpenRouter API Key</h3>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                            Your universal API key for Claude, GPT-4o, and DeepSeek models. Stored directly in your <code>.env</code> file (<code>OPENROUTER_API_KEY</code>). Never saved in the database.
                        </p>
                    </div>
                    @if($hasKey)
                        <span class="px-2.5 py-0.5 rounded-full font-data text-xs font-semibold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 flex items-center gap-1">
                            <i class="fa-solid fa-shield-halved text-[10px]"></i> Saved in .env
                        </span>
                    @else
                        <span class="px-2.5 py-0.5 rounded-full font-data text-xs font-semibold bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30 flex items-center gap-1">
                            <i class="fa-solid fa-triangle-exclamation text-[10px]"></i> Key Required in .env
                        </span>
                    @endif
                </div>

                <div class="space-y-3">
                    <div class="flex gap-2">
                        <input type="password"
                               name="openrouter_api_key"
                               x-model="apiKey"
                               placeholder="{{ $hasKey ? '•••••••••••••••••••••••••••••••• (leave blank to keep current .env key)' : 'sk-or-v1-...' }}"
                               class="input text-xs flex-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] p-2.5 font-mono">

                        <button type="button"
                                @click="testConnection()"
                                :disabled="testing"
                                class="btn-pill-nav text-xs py-2 px-3 border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)] flex items-center gap-1.5 cursor-pointer font-medium disabled:opacity-50">
                            <i class="fa-solid" :class="testing ? 'fa-spinner fa-spin' : 'fa-plug'"></i>
                            <span x-text="testing ? 'Testing...' : 'Test Connection'"></span>
                        </button>
                    </div>

                    {{-- Test Connection Output Toast/Alert --}}
                    <template x-if="testResult">
                        <div class="p-3 rounded-lg text-xs flex items-center gap-2 border"
                             :class="testResult.ok ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/10 border-rose-500/30 text-rose-600 dark:text-rose-400'">
                            <i class="fa-solid" :class="testResult.ok ? 'fa-circle-check' : 'fa-circle-xmark'"></i>
                            <span x-text="testResult.message"></span>
                        </div>
                    </template>

                    <p class="text-[11px] text-[var(--color-ink-muted)]">
                        Get an API key at <a href="https://openrouter.ai/keys" target="_blank" rel="noopener" class="text-[var(--color-brand)] hover:underline">openrouter.ai/keys</a>. You can also manually add <code>OPENROUTER_API_KEY=sk-or-v1-...</code> to your <code>.env</code> file. Average cost per incident diagnosis is ~$0.012 (1.2 cents).
                    </p>
                </div>
            </div>

            {{-- 3. Model Selection --}}
            <div class="card p-6">
                <h3 class="text-sm font-bold text-[var(--color-ink-strong)] mb-1">Intelligence Model</h3>
                <p class="text-xs text-[var(--color-ink-muted)] mb-4">
                    Select the primary LLM used to analyze telemetry, determine root causes, and craft safe bash remediation scripts.
                </p>

                <div class="space-y-2.5">
                    @foreach($availableModels as $slug => $label)
                        <label class="flex items-center gap-3 p-3.5 rounded-xl border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)] cursor-pointer transition-colors">
                            <input type="radio" name="model" value="{{ $slug }}" {{ $currentModel === $slug ? 'checked' : '' }}
                                   class="text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                            <div class="flex-1">
                                <span class="text-xs font-semibold text-[var(--color-ink-strong)]">{{ $label }}</span>
                                <span class="block text-[11px] font-mono text-[var(--color-ink-soft)] mt-0.5">{{ $slug }}</span>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Submit Button --}}
            <div class="flex justify-end">
                <button type="submit" class="btn-primary text-xs md:text-sm py-2.5 px-6 font-semibold shadow-xs cursor-pointer">
                    Save AiRemedy Settings
                </button>
            </div>
        </form>

        {{-- 4. On-Demand Test Simulation Card (Watch Mode) --}}
        <div class="card p-6 border-indigo-500/30">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-bold text-[var(--color-ink-strong)]">Run Safe Test Simulation (Watch Mode)</h3>
                        <span class="px-2 py-0.5 rounded-full font-data text-[10px] font-semibold bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30">
                            Zero Mutations
                        </span>
                    </div>
                    <p class="text-xs text-[var(--color-ink-muted)] mt-1">
                        Test AiRemedy against any connected server right now. Telemetry will be gathered via SSH, analyzed by your configured LLM, and logged in the audit trail without executing any mutating commands.
                    </p>
                </div>
            </div>

            @if($servers->isEmpty())
                <div class="p-4 rounded-xl bg-[var(--color-surface-alt)] text-center text-xs text-[var(--color-ink-muted)]">
                    No active servers found to simulate diagnostics against. Add a server first.
                </div>
            @else
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Target Server</label>
                            <select x-model="simServerId"
                                    class="w-full text-xs rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] p-2.5">
                                @foreach($servers as $srv)
                                    <option value="{{ $srv->id }}">{{ $srv->name }} ({{ $srv->hostname }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Simulated Outage Scenario</label>
                            <input type="text"
                                   x-model="simScenario"
                                   placeholder="e.g. Nginx 502 Bad Gateway / High CPU load"
                                   class="w-full text-xs rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] p-2.5">
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <span class="text-[11px] text-[var(--color-ink-soft)]">
                            <i class="fa-solid fa-circle-info mr-1 text-[var(--color-brand)]"></i>
                            Simulation runs under Watch Mode. Proposed remediation commands will be logged but never executed.
                        </span>
                        <button type="button"
                                @click="runSimulation()"
                                :disabled="simulating || !simServerId"
                                class="btn-pill-nav text-xs py-2 px-4 bg-[var(--color-ink-strong)] text-[var(--color-surface)] hover:opacity-90 flex items-center gap-2 cursor-pointer font-semibold disabled:opacity-50">
                            <i class="fa-solid" :class="simulating ? 'fa-spinner fa-spin' : 'fa-play'"></i>
                            <span x-text="simulating ? 'Simulating Triage...' : 'Run Simulation'"></span>
                        </button>
                    </div>

                    {{-- Simulation Result Banner --}}
                    <template x-if="simResult">
                        <div class="mt-4 p-4 rounded-xl border text-xs space-y-3"
                             :class="simResult.ok ? 'bg-indigo-500/5 border-indigo-500/20' : 'bg-rose-500/10 border-rose-500/30 text-rose-600 dark:text-rose-400'">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid" :class="simResult.ok ? 'fa-circle-check text-indigo-500' : 'fa-circle-xmark text-rose-500'"></i>
                                    <span class="font-bold text-[var(--color-ink-strong)]" x-text="simResult.message"></span>
                                </div>
                                <template x-if="simResult.run?.id">
                                    <a :href="'{{ url('/ai-remedy/runs') }}/' + simResult.run.id"
                                       class="text-[var(--color-brand)] font-semibold hover:underline flex items-center gap-1">
                                        <span>View Forensics #<span x-text="simResult.run.id"></span></span>
                                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                    </a>
                                </template>
                            </div>

                            <template x-if="simResult.ok && simResult.analysis">
                                <div class="space-y-2 pt-2 border-t border-[var(--color-border-light)]">
                                    <div>
                                        <span class="text-[11px] font-semibold text-[var(--color-ink-muted)]">Diagnosis:</span>
                                        <p class="font-medium text-[var(--color-ink-strong)]" x-text="simResult.analysis.summary"></p>
                                    </div>
                                    <div>
                                        <span class="text-[11px] font-semibold text-[var(--color-ink-muted)]">Identified Root Cause:</span>
                                        <p class="font-medium text-amber-600 dark:text-amber-400" x-text="simResult.analysis.root_cause"></p>
                                    </div>
                                    <template x-if="simResult.analysis.commands && simResult.analysis.commands.length > 0">
                                        <div>
                                            <span class="text-[11px] font-semibold text-[var(--color-ink-muted)] block mb-1">What AiRemedy Would Have Done (Proposed Commands):</span>
                                            <div class="bg-neutral-950 text-indigo-300 p-3 rounded-lg font-mono text-[11px] space-y-1">
                                                <template x-for="cmd in simResult.analysis.commands" :key="cmd">
                                                    <div><span class="text-neutral-500">$</span> <span x-text="cmd"></span></div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            @endif
        </div>
    </div>
@endsection
