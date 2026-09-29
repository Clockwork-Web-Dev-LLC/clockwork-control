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
                                Autonomously executes safe, non-destructive Tier 1 remediations (PHP-FPM worker reload, removing stale <code>.maintenance</code> marker) and verifies recovery.
                            </p>
                        </div>
                        <div class="mt-3 pt-2.5 border-t border-[var(--color-border-light)] text-[10px] font-medium text-amber-600 dark:text-amber-400 flex items-center gap-1">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span>Tier 2 (restarts/kills) still requires review</span>
                        </div>
                    </label>
                </div>
            </div>

            {{-- 2. OpenRouter API Key --}}
            <div class="card p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-sm font-bold text-[var(--color-ink-strong)]">OpenRouter API Key</h3>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                            Your universal API key for Claude 3.5 Sonnet, GPT-4o, and DeepSeek. Stored directly in your <code>.env</code> file (<code>OPENROUTER_API_KEY</code>). Never saved in the database.
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
