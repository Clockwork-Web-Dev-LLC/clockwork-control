@extends('layouts.app')

@section('title', 'Configure your selected services · Setup · Clockwork Control')

@section('content')
    <div class="max-w-4xl mx-auto pb-16" x-data="{ showSecurityModal: false }">
        <!-- Progress & Header -->
        <div class="mb-3">
            <span class="status-pill status-green text-xs font-semibold uppercase tracking-wider">
                <span class="status-dot"></span>
                <span>Step 2 of 2 · Credential Configuration</span>
            </span>
        </div>
        <x-page-header title="Configure your selected services"
            subtitle="For maximum security, you should manually drop your API keys directly into your local .env file. You can also save credentials in the database below.">
            <x-slot:actions>
                <button type="button" @click="showSecurityModal = true" class="btn-pill-nav text-sm text-[var(--color-status-yellow)]">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>.env &amp; AI Security Guide</span>
                </button>
                <a href="{{ route('setup.step1') }}" class="btn-pill-nav text-sm">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Change services</span>
                </a>
            </x-slot:actions>
        </x-page-header>

        <div class="card p-4 mb-6 border-l-4 border-[var(--color-status-yellow)] bg-[var(--color-status-yellow-bg)] text-xs flex items-start gap-3 max-w-2xl">
            <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-yellow)] text-sm mt-0.5 flex-shrink-0"></i>
            <div class="leading-relaxed text-[var(--color-ink)]">
                <strong>Security rule:</strong> Always copy and paste your keys into <code class="font-data text-xs">.env</code> yourself manually. <strong>Do not give your live API keys or secrets to AI coding assistants</strong> (Claude, Cursor, ChatGPT, etc.) when testing or vibe-coding.
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

                <div class="p-6 space-y-5 text-sm text-[var(--color-ink-muted)] leading-relaxed">
                    <!-- Rule 1: Use .env -->
                    <div class="p-4 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                        <div class="flex items-center gap-2 text-sm font-bold text-[var(--color-ink-strong)] mb-1.5">
                            <i class="fa-solid fa-file-code text-[var(--color-primary-600)]"></i>
                            <span>1. Prefer storing credentials in your local <code>.env</code> file</span>
                        </div>
                        <p class="text-xs leading-relaxed mb-2">
                            Clockwork Control is designed to read environment variables directly. While you can save credentials in the database via the web form on this page, storing them in your root <code class="font-data text-xs">.env</code> file is the recommended industry best practice:
                        </p>
                        <ul class="text-xs space-y-1 list-disc list-inside text-[var(--color-ink-soft)] ml-1">
                            <li>Keys stay in local filesystem storage and are never written to the MySQL database.</li>
                            <li>Zero risk of leaking credentials in database dumps, backups, or browser memory.</li>
                            <li>The <code class="font-data text-xs">.env</code> file is already in <code class="font-data text-xs">.gitignore</code> by default.</li>
                        </ul>
                    </div>

                    <!-- Rule 2: Never give keys to AI -->
                    <div class="p-4 rounded-lg bg-rose-50/90 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-800/60 text-rose-950 dark:text-rose-200">
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
GRIDPANE_API_KEY=your_key_here
HETZNER_API_TOKEN=your_token_here

# Notifications
CLOCKWORK_SLACK_WEBHOOK_URL=https://hooks.slack.com/...
CLOCKWORK_MATTERMOST_WEBHOOK_URL=https://chat.agency.com/hooks/...</pre>
                        <p class="text-[11px] text-[var(--color-ink-soft)] mt-2">
                            Once added to <code class="font-data text-xs">.env</code>, refresh this page — Clockwork Control will detect them automatically and display the <span class="status-pill status-blue text-[10px] py-0 px-1.5"><i class="fa-solid fa-code"></i> In .env</span> badge!
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

        @if (count($services) === 0)
            <div class="card p-8 text-center max-w-xl mx-auto my-8">
                <div class="w-12 h-12 rounded-full bg-[var(--color-surface-alt)] flex items-center justify-center mx-auto mb-4 text-[var(--color-ink-soft)] text-xl">
                    <i class="fa-solid fa-check"></i>
                </div>
                <h2 class="text-xl font-display font-semibold text-[var(--color-ink-strong)] mb-2">
                    No external services selected
                </h2>
                <p class="text-sm text-[var(--color-ink-muted)] mb-6">
                    You chose not to enable any external cloud or hosting integrations. Clockwork Control will run in standalone mode. You can always add integrations later under Settings.
                </p>
                <a href="{{ route('dashboard') }}" class="btn-primary inline-flex items-center gap-2">
                    <span>Go to Fleet Dashboard</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        @else
            <form method="POST" action="{{ route('setup.step2.save') }}" class="space-y-6">
                @csrf

                @foreach ($services as $id => $service)
                    <div class="card p-6 border border-[var(--color-border)]">
                        <!-- Header Row -->
                        <div class="flex items-start justify-between flex-wrap gap-3 pb-4 mb-4 border-b border-[var(--color-border-light)]">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 flex items-center justify-center flex-shrink-0">
                                    <x-service-logo :service="$id" class="w-8 h-8" />
                                </div>
                                <div>
                                    <h2 class="font-display font-semibold text-lg text-[var(--color-ink-strong)]">
                                        {{ $service['name'] }}
                                    </h2>
                                    <p class="text-xs text-[var(--color-ink-muted)]">{{ $service['description'] }}</p>
                                </div>
                            </div>

                            @if (!empty($service['url']))
                                <a href="{{ $service['url'] }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="btn-pill-nav text-xs flex items-center gap-1.5 hover:text-[var(--color-primary-500)]">
                                    <span>Get API Key</span>
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                </a>
                            @endif
                        </div>

                        <!-- Guide Banner -->
                        @if (!empty($service['guide']))
                            <div class="mb-5 p-3 rounded-md bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] flex items-start gap-2.5 text-xs text-[var(--color-ink-muted)]">
                                <i class="fa-solid fa-circle-info text-[var(--color-primary-500)] mt-0.5 flex-shrink-0"></i>
                                <span class="leading-relaxed">{{ $service['guide'] }}</span>
                            </div>
                        @endif

                        <!-- Fields -->
                        @if (count($service['fields']) === 0)
                            <p class="text-xs text-[var(--color-ink-soft)] italic">
                                This module does not require API credentials.
                            </p>
                        @else
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                @foreach ($service['fields'] as $key => $field)
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <label for="input_{{ $id }}_{{ $key }}" class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-strong)]">
                                                {{ $field['label'] }}
                                            </label>

                                            @if ($field['source'] === 'database')
                                                <span class="status-pill status-green text-[10px] py-0 px-2">
                                                    <i class="fa-solid fa-lock"></i> Saved in DB
                                                </span>
                                            @elseif ($field['source'] === 'env')
                                                <span class="status-pill status-blue text-[10px] py-0 px-2">
                                                    <i class="fa-solid fa-code"></i> In .env
                                                </span>
                                            @else
                                                <span class="text-[10px] font-mono text-[var(--color-ink-soft)]">
                                                    Not set
                                                </span>
                                            @endif
                                        </div>

                                        <div class="relative">
                                            <input type="{{ $field['secret'] ? 'password' : 'text' }}"
                                                   name="value_{{ $id }}_{{ $key }}"
                                                   id="input_{{ $id }}_{{ $key }}"
                                                   placeholder="{{ $field['source'] !== 'unset' ? 'Leave blank to keep existing' : 'Enter value…' }}"
                                                   autocomplete="off"
                                                   class="w-full font-mono text-xs border border-[var(--color-border)] rounded-md px-3 py-2 bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                                        </div>

                                        @if ($field['source'] === 'database')
                                            <label class="inline-flex items-center gap-1.5 text-[11px] text-[var(--color-status-red)] mt-1 cursor-pointer">
                                                <input type="checkbox" name="clear_{{ $id }}_{{ $key }}" value="1" class="rounded border-[var(--color-border)]">
                                                <span>Clear saved database key</span>
                                            </label>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach

                <!-- Action Bar -->
                <div class="card p-5 flex items-center justify-between flex-wrap gap-4 shadow-lg border border-[var(--color-border)] bg-[var(--color-surface)] mt-8">
                    <div class="flex items-center gap-3">
                        <button type="submit" class="btn-primary flex items-center gap-2 text-sm px-6 py-2.5">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <span>Save Credentials & Finish</span>
                        </button>
                        <a href="{{ route('setup.step1') }}" class="btn-pill-nav text-sm">
                            Back
                        </a>
                    </div>
                    <a href="{{ route('dashboard') }}" class="text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] underline">
                        Skip for now (configure later in Settings) &rarr;
                    </a>
                </div>
            </form>
        @endif
    </div>
@endsection
