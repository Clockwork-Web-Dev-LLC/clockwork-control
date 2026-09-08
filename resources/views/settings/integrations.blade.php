@extends('layouts.app')

@section('title', 'API credentials · Clockwork')

@section('content')
    <div class="mb-8" x-data="{ showSecurityModal: false }">
        @include('settings._tabs')

        <x-page-header title="API credentials"
            subtitle="Store integration API keys here or drop them directly into your local .env file. Values are encrypted at rest and never shown back once saved.">
            <x-slot:actions>
                <a href="{{ route('settings.modules.index') }}" class="btn-pill-nav text-sm">
                    <i class="fa-solid fa-boxes-stacked text-[var(--color-ink-muted)]"></i>
                    <span>Module Directory</span>
                </a>
                <button type="button" @click="showSecurityModal = true" class="btn-pill-nav text-sm text-[var(--color-status-yellow)]">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>.env &amp; AI Security Guide</span>
                </button>
            </x-slot:actions>
        </x-page-header>

        <div class="card p-4 mb-6 border-l-4 border-[var(--color-status-yellow)] bg-[var(--color-status-yellow-bg)] text-xs flex items-start gap-3 max-w-3xl">
            <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-yellow)] text-sm mt-0.5 flex-shrink-0"></i>
            <div class="leading-relaxed text-[var(--color-ink)]">
                <strong>Security recommendation:</strong> Manually dropping keys into your local <code class="font-data text-xs">.env</code> file is preferred over database storage. <strong>Never share live API keys or passwords with AI coding assistants</strong> (Claude, Cursor, ChatGPT, etc.) when testing or vibe-coding.
                <button type="button" @click="showSecurityModal = true" class="underline font-semibold ml-1 text-[var(--color-status-yellow)] hover:opacity-80 cursor-pointer">
                    Why &amp; how to configure &rarr;
                </button>
            </div>
        </div>

        {{-- Modal: .env & AI Security Best Practices --}}
        <div x-show="showSecurityModal"
             x-cloak
             @keydown.escape.window="showSecurityModal = false"
             class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 bg-black/50 backdrop-blur-xs"
             @click.self="showSecurityModal = false"
             role="dialog"
             aria-modal="true">
            <div class="bg-[var(--color-surface)] rounded-[var(--radius-card)] shadow-2xl max-w-2xl w-full max-h-[85vh] overflow-y-auto border border-[var(--color-border)]"
                 @click.stop>
                <div class="px-6 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between gap-4 sticky top-0 bg-[var(--color-surface)] z-10">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-700 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-shield-halved text-base"></i>
                        </div>
                        <div>
                            <h3 class="font-display font-bold text-base sm:text-lg text-[var(--color-ink-strong)]">
                                API Key Security &amp; AI Safety Guide
                            </h3>
                            <p class="text-xs text-[var(--color-ink-muted)]">Why you should use .env and never feed live secrets to AI</p>
                        </div>
                    </div>
                    <button type="button" @click="showSecurityModal = false" class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] text-2xl leading-none p-1" aria-label="Close">×</button>
                </div>

                <div class="p-6 space-y-5 text-sm text-[var(--color-ink-muted)] leading-relaxed text-left">
                    <!-- Rule 1: Use .env -->
                    <div class="p-4 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                        <div class="flex items-center gap-2 text-sm font-bold text-[var(--color-ink-strong)] mb-1.5">
                            <i class="fa-solid fa-file-code text-[var(--color-primary-600)]"></i>
                            <span>1. Prefer storing credentials in your local <code>.env</code> file</span>
                        </div>
                        <p class="text-xs leading-relaxed mb-2">
                            Clockwork Control is designed to read environment variables directly. While you can save credentials in the database via the forms below, storing them in your root <code class="font-data text-xs">.env</code> file is the recommended industry best practice:
                        </p>
                        <ul class="text-xs space-y-1 list-disc list-inside text-[var(--color-ink-soft)] ml-1">
                            <li>Keys stay in local filesystem storage and are never written to the MySQL database.</li>
                            <li>Zero risk of leaking credentials in database dumps, backups, or browser memory.</li>
                            <li>The <code class="font-data text-xs">.env</code> file is automatically ignored by git (<code class="font-data text-xs">.gitignore</code>).</li>
                        </ul>
                    </div>

                    <!-- Rule 2: Never give keys to AI -->
                    <div class="p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-950">
                        <div class="flex items-center gap-2 text-sm font-bold text-rose-800 mb-1.5">
                            <i class="fa-solid fa-triangle-exclamation text-rose-600"></i>
                            <span>2. Never share live API keys or passwords with AI</span>
                        </div>
                        <p class="text-xs leading-relaxed mb-2">
                            When testing integrations, building custom modules, or vibe-coding fixes with <strong>Claude, Cursor, ChatGPT, Antigravity</strong>, or any other AI tool:
                        </p>
                        <ul class="text-xs space-y-1 list-disc list-inside ml-1 font-medium">
                            <li><strong>Always manually copy &amp; paste keys yourself</strong> directly into <code class="font-data text-xs">.env</code>.</li>
                            <li><strong>Never paste real API tokens</strong> into prompts, code snippets, or error logs sent to AI.</li>
                            <li>Use dummy placeholders like <code class="font-data text-xs">your-api-token-here</code> or <code class="font-data text-xs">sk_test_xxx</code> when asking AI to write or fix code.</li>
                            <li>AI models do <em>not</em> need your real secret keys to debug API response shapes or fix parsers!</li>
                        </ul>
                    </div>

                    <!-- How to configure .env -->
                    <div>
                        <h4 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] mb-2 flex items-center gap-2">
                            <i class="fa-solid fa-terminal text-[var(--color-ink-soft)]"></i>
                            How to manually add keys to <code>.env</code>
                        </h4>
                        <p class="text-xs mb-2">
                            Open the <code class="font-data text-xs">.env</code> file in your project root and add the keys for the providers you selected. For example:
                        </p>
                        <pre class="bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] rounded-md p-3 font-data text-xs text-[var(--color-ink-strong)] overflow-x-auto leading-relaxed"># Cloud &amp; Hosting Providers
SPINUPWP_API_TOKEN=your_token_here
DIGITALOCEAN_TOKEN=your_token_here
CLOCKWORK_WPENGINE_API_USER_ID=your_user_id
CLOCKWORK_WPENGINE_API_PASSWORD=your_password
CLOCKWORK_KINSTA_API_KEY=your_key_here
CLOCKWORK_CLOUDWAYS_API_KEY=your_key_here
CLOCKWORK_CLOUDWAYS_EMAIL=you@agency.com
HETZNER_API_TOKEN=your_token_here

# Notifications
CLOCKWORK_SLACK_WEBHOOK_URL=https://hooks.slack.com/...
CLOCKWORK_MATTERMOST_WEBHOOK_URL=https://chat.agency.com/hooks/...</pre>
                        <p class="text-[11px] text-[var(--color-ink-soft)] mt-2">
                            Once added to <code class="font-data text-xs">.env</code>, refresh this page — Clockwork Control will detect them automatically and show the <span class="status-pill status-green text-[10px] py-0 px-1.5"><i class="fa-solid fa-circle-check"></i> Configured · .env</span> badge!
                        </p>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button type="button" @click="showSecurityModal = false" class="btn-primary text-xs px-4 py-2">
                            Got it, thanks!
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
        </div>
    @endif
    @if (session('status_error'))
        <div class="card p-4 mb-6 status-red flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i> {{ session('status_error') }}
        </div>
    @endif

    <form method="POST" action="{{ route('settings.integrations.update') }}" autocomplete="off" class="space-y-5 mb-8">
        @csrf
        @method('PATCH')

        @foreach ($integrations as $id => $integration)
            <div class="card p-6 max-w-3xl" id="integration-{{ $id }}">
                <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">{{ $integration['label'] }}</h2>
                    <div class="flex items-center gap-2">
                        @php
                            $rateLimitEntry = app(\App\Support\ServiceRateLimitRegistry::class)->get($id);
                        @endphp
                        @if ($rateLimitEntry)
                            <a href="{{ route('settings.integrations.limits', $rateLimitEntry['id']) }}" class="btn-pill-nav text-xs py-1 px-2.5" title="API limits, quota headers & docs">
                                <i class="fa-solid fa-gauge-high text-[var(--color-ink-muted)]"></i>
                                <span>API Limits &amp; Docs</span>
                            </a>
                        @endif
                        @if (($integration['status'] ?? 'verified') === 'looking_for_testers')
                            <span class="status-pill status-yellow text-[11px]" title="Code is written; looking for live agency testing">
                                <i class="fa-solid fa-flask"></i> Looking for testers
                            </span>
                        @else
                            <span class="status-pill status-green text-[11px]" title="Tested and verified in active production">
                                <i class="fa-solid fa-circle-check"></i> Verified in production
                            </span>
                        @endif
                    </div>
                </div>

                @if (!empty($integration['description']))
                    <p class="text-xs text-[var(--color-ink-muted)] mb-3 leading-relaxed">
                        {{ $integration['description'] }}
                    </p>
                @endif

                @if (!empty($integration['capabilities']))
                    <div class="mb-5 pb-3.5 border-b border-[var(--color-border-light)]">
                        <span class="text-[10px] font-mono uppercase tracking-wider text-[var(--color-ink-soft)] block mb-1.5">Features &amp; Capabilities</span>
                        <div class="flex items-center gap-1.5 flex-wrap">
                            @foreach ($integration['capabilities'] as $cap)
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] font-data border border-[var(--color-border-light)]">
                                    <i class="fa-solid fa-check text-[10px] text-emerald-500"></i>
                                    <span>{{ $cap }}</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (($integration['status'] ?? 'verified') === 'looking_for_testers')
                    <div class="mb-5 p-4 rounded-xl border flex items-start gap-3.5" style="background: var(--color-status-yellow-bg); border-color: var(--color-status-yellow-border); color: var(--color-status-yellow-text);">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style="background: var(--color-status-yellow-border); color: var(--color-status-yellow-text);">
                            <i class="fa-solid fa-flask text-sm"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-display font-semibold text-sm mb-1 flex items-center gap-2" style="color: var(--color-status-yellow-text);">
                                <span>Code written — looking for testers!</span>
                                <span class="status-pill status-yellow text-[10px] font-mono">
                                    <span class="status-dot"></span>
                                    Community Beta
                                </span>
                            </div>
                            <p class="text-sm leading-relaxed mb-2 font-normal" style="color: var(--color-status-yellow-text);">
                                {{ $integration['status_note'] ?? 'This module is written and ready, but needs real-world testing with live API credentials.' }}
                            </p>
                            <p class="text-xs sm:text-sm opacity-90 leading-relaxed">
                                Have an account with {{ $integration['label'] }}? Check out our <a href="{{ route('docs.show', 'getting-started/contributing') }}" class="font-semibold underline underline-offset-2 hover:opacity-80 transition-opacity">Contributing Guide</a> to test endpoints for your agency, vibe code any fixes with Claude, and submit a pull request!
                            </p>
                        </div>
                    </div>
                @endif

                <div class="space-y-4">
                    @foreach ($integration['fields'] as $key => $field)
                        @php $inputName = "value_{$id}_{$key}"; @endphp
                        <div>
                            <div class="flex items-center justify-between gap-3 flex-wrap mb-1">
                                <span class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">{{ $field['label'] }}</span>
                                @if ($field['configured'])
                                    <span class="status-pill status-green text-[11px]">
                                        <i class="fa-solid fa-circle-check"></i>
                                        Configured · {{ $field['source'] === 'database' ? 'database' : '.env' }}
                                    </span>
                                @else
                                    <span class="status-pill status-unknown text-[11px]">
                                        <i class="fa-regular fa-circle"></i>
                                        Not set
                                    </span>
                                @endif
                            </div>
                            <input type="{{ $field['secret'] ? 'password' : 'text' }}" name="{{ $inputName }}"
                                   autocomplete="new-password"
                                   placeholder="{{ $field['configured'] ? 'Currently set — leave empty to keep, or type a new value' : 'Not set' }}"
                                   class="w-full font-data text-sm text-[var(--color-ink-strong)] border border-[var(--color-border)] rounded-md px-3 py-2 placeholder:text-[var(--color-ink-soft)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                            @if ($field['source'] === 'database')
                                <label class="flex items-center gap-2 mt-2 text-xs text-[var(--color-ink-muted)]">
                                    <input type="checkbox" name="clear_{{ $id }}_{{ $key }}" value="1" class="rounded border-[var(--color-border)]">
                                    Clear the stored value (falls back to <code class="font-data text-xs px-1 py-0.5 rounded bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">.env</code> if set there)
                                </label>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="max-w-3xl flex justify-end">
            <button type="submit"
                    class="px-4 py-2 rounded-md bg-[var(--color-primary-600)] text-white text-sm font-medium hover:bg-[var(--color-primary-700)]">
                Save credentials
            </button>
        </div>
    </form>

    @php $testable = collect($integrations)->filter(fn ($i) => $i['testable']); @endphp
    @if ($testable->isNotEmpty())
        <div class="card p-6 max-w-3xl">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-3">Test connection</h2>
            <p class="text-sm text-[var(--color-ink-muted)] mb-4">Runs the same live check as <a class="text-[var(--color-primary-600)] hover:underline" href="{{ route('settings.diagnostics.index') }}">/settings/diagnostics</a> for one integration.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach ($testable as $id => $integration)
                    <form method="POST" action="{{ route('settings.integrations.test', $id) }}">
                        @csrf
                        <button type="submit"
                                class="w-full text-left px-4 py-3 rounded-md border border-[var(--color-border-light)] hover:bg-[var(--color-surface-alt)]">
                            <div class="text-sm font-medium text-[var(--color-ink-strong)]">
                                <i class="fa-solid fa-plug mr-1 text-[var(--color-ink-soft)]"></i>
                                Test {{ $integration['label'] }}
                            </div>
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif
@endsection
