@extends('layouts.app')

@section('title', 'AiRemedy Settings · Clockwork')

@section('content')
<div class="max-w-4xl mx-auto" x-data="{
    apiKey: '',
    testing: false,
    testResult: null,
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
    }
}">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs text-[var(--color-ink-muted)] mb-1">
                <a href="{{ route('ai-remedy.index') }}" class="hover:underline flex items-center gap-1">
                    <i class="fa-solid fa-arrow-left text-[10px]"></i>
                    <span>Back to AiRemedy Audit Log</span>
                </a>
            </div>
            <h1 class="text-xl font-bold text-[var(--color-ink-strong)]">AiRemedy Configuration</h1>
            <p class="text-xs text-[var(--color-ink-muted)] mt-1">Configure your OpenRouter API key, model selection, and self-healing automation.</p>
        </div>
    </div>

    @if(session('status'))
        <div class="card p-3 mb-6 bg-emerald-500/10 border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-xs flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('ai-remedy.settings.update') }}" class="space-y-6">
        @csrf

        {{-- 1. OpenRouter API Key --}}
        <div class="card p-6">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 class="text-sm font-bold text-[var(--color-ink-strong)]">OpenRouter API Key</h3>
                    <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                        Your universal API key for Claude 3.5 Sonnet, GPT-4o, and DeepSeek. Stored directly in your <code>.env</code> file (<code>OPENROUTER_API_KEY</code>). Never saved in the database.
                    </p>
                </div>
                @if($hasKey)
                    <span class="px-2 py-0.5 rounded-full font-data text-xs font-semibold bg-emerald-500/15 text-emerald-600 border border-emerald-500/30 flex items-center gap-1">
                        <i class="fa-solid fa-shield-halved text-[10px]"></i> Saved in .env
                    </span>
                @else
                    <span class="px-2 py-0.5 rounded-full font-data text-xs font-semibold bg-amber-500/15 text-amber-600 border border-amber-500/30 flex items-center gap-1">
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
                           class="input text-xs flex-1 rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-bg-surface)] text-[var(--color-ink-strong)] p-2.5 font-mono">

                    <button type="button"
                            @click="testConnection()"
                            :disabled="testing"
                            class="btn-pill-nav text-xs py-2 px-3 border border-[var(--color-border-subtle)] hover:bg-[var(--color-bg-subtle)] flex items-center gap-1.5 cursor-pointer font-medium disabled:opacity-50">
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

        {{-- 2. Model Selection --}}
        <div class="card p-6">
            <h3 class="text-sm font-bold text-[var(--color-ink-strong)] mb-1">Intelligence Model</h3>
            <p class="text-xs text-[var(--color-ink-muted)] mb-4">
                Select the primary LLM used to analyze server metrics and generate bash remediation scripts.
            </p>

            <div class="space-y-2.5">
                @foreach($availableModels as $slug => $label)
                    <label class="flex items-center gap-3 p-3 rounded-lg border border-[var(--color-border-subtle)] hover:bg-[var(--color-bg-subtle)] cursor-pointer transition-colors">
                        <input type="radio" name="model" value="{{ $slug }}" {{ $currentModel === $slug ? 'checked' : '' }}
                               class="text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                        <div class="flex-1">
                            <span class="text-xs font-semibold text-[var(--color-ink-strong)]">{{ $label }}</span>
                            <span class="block text-[11px] font-mono text-[var(--color-ink-muted)] mt-0.5">{{ $slug }}</span>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- 3. Operating Mode & Safety --}}
        <div class="card p-6">
            <h3 class="text-sm font-bold text-[var(--color-ink-strong)] mb-1">Self-Healing Automation</h3>
            <p class="text-xs text-[var(--color-ink-muted)] mb-4">
                Configure whether AiRemedy operates as a human-in-the-loop copilot or auto-executes non-destructive Tier 1 fixes.
            </p>

            <label class="flex items-start gap-3 p-3 rounded-lg border border-[var(--color-border-subtle)] cursor-pointer">
                <input type="checkbox" name="auto_heal" value="1" {{ $autoHeal ? 'checked' : '' }}
                       class="mt-1 rounded text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                <div>
                    <span class="text-xs font-bold text-[var(--color-ink-strong)]">Enable Autonomous Self-Healing on Site Outages (Tier 1 Safe Only)</span>
                    <span class="block text-[11px] text-[var(--color-ink-muted)] mt-0.5 leading-relaxed">
                        When enabled, if a monitored site fails 2 consecutive checks due to hung PHP-FPM pools or stale maintenance markers, AiRemedy will automatically execute safe restarts, re-probe, and notify the team in chat. Cautious actions (process killing, MySQL restarts) always require manual review.
                    </span>
                </div>
            </label>
        </div>

        {{-- Submit Button --}}
        <div class="flex justify-end">
            <button type="submit" class="btn-primary text-xs md:text-sm py-2 px-5 font-semibold shadow-xs cursor-pointer">
                Save AiRemedy Settings
            </button>
        </div>
    </form>
</div>
@endsection
