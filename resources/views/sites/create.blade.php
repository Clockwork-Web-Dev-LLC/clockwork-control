@extends('layouts.app')

@section('title', 'Connect WordPress Site · Clockwork')

@section('content')
    <div class="max-w-3xl mx-auto">
        <div class="mb-6">
            <a href="{{ route('sites.index') }}" class="inline-flex items-center gap-1.5 text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] transition-colors">
                <i class="fa-solid fa-arrow-left text-[10px]"></i>
                <span>Back to Sites</span>
            </a>
        </div>

        <x-page-header
            title="Connect WordPress Site"
            subtitle="Enroll a site via the Clockwork Companion plugin. Works on WP Engine, Kinsta, or any host without requiring hosting provider API or SSH access."
        />

        @if ($errors->any())
            <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-700 dark:text-red-400 text-sm">
                <div class="flex items-center gap-2 font-semibold mb-1">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Could not connect site</span>
                </div>
                <ul class="list-disc list-inside space-y-1 text-xs">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div x-data="{
            mode: 'key', // 'key' or 'manual'
            connectionKey: '',
            domain: @js(old('domain', '')),
            secret: '',
            submitting: false,

            parseKey() {
                let raw = this.connectionKey.trim();
                if (!raw) return;

                // Try JSON directly
                if (raw.startsWith('{')) {
                    try {
                        let parsed = JSON.parse(raw);
                        if (parsed.url || parsed.domain) this.domain = (parsed.url || parsed.domain).replace(/^https?:\/\//i, '').replace(/\/.*$/, '');
                        if (parsed.secret) this.secret = parsed.secret;
                        return;
                    } catch (e) {}
                }

                // Try base64
                let b64 = raw.replace(/^cw_/, '');
                try {
                    let decoded = atob(b64);
                    let parsed = JSON.parse(decoded);
                    if (parsed.url || parsed.domain) this.domain = (parsed.url || parsed.domain).replace(/^https?:\/\//i, '').replace(/\/.*$/, '');
                    if (parsed.secret) this.secret = parsed.secret;
                    return;
                } catch (e) {}

                // If 64 hex chars, treat as raw secret
                if (raw.length === 64 && /^[0-9a-fA-F]+$/.test(raw)) {
                    this.secret = raw;
                }
            },

            generateSecret() {
                let array = new Uint8Array(32);
                window.crypto.getRandomValues(array);
                this.secret = Array.from(array, b => b.toString(16).padStart(2, '0')).join('');
            }
        }" class="space-y-6">

            {{-- Card 1: Companion Plugin Download & Instructions --}}
            <div class="p-6 rounded-[var(--radius-card)] bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs">
                <div class="flex items-start justify-between flex-wrap gap-4 mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[var(--color-brand)]/10 text-[var(--color-brand)] flex items-center justify-center font-bold text-lg">
                            <i class="fa-solid fa-puzzle-piece"></i>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-[var(--color-ink-strong)]">Step 1: Install Clockwork Companion</h2>
                            <p class="text-xs text-[var(--color-ink-muted)]">Upload the plugin to your WordPress site if not already installed</p>
                        </div>
                    </div>
                    <a href="{{ route('companion.download') }}" class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-[var(--color-surface-alt)] hover:bg-[var(--color-border-light)] border border-[var(--color-border)] text-[var(--color-ink-strong)] transition-colors">
                        <i class="fa-solid fa-download text-xs text-[var(--color-brand)]"></i>
                        <span>Download Plugin (.zip)</span>
                    </a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs text-[var(--color-ink-muted)]">
                    <div class="p-3 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                        <span class="font-bold text-[var(--color-ink-strong)] block mb-1">1. Upload & Activate</span>
                        In wp-admin, go to <strong>Plugins → Add New → Upload Plugin</strong> and activate <code>clockwork-companion.zip</code>.
                    </div>
                    <div class="p-3 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                        <span class="font-bold text-[var(--color-ink-strong)] block mb-1">2. Copy Connection Key</span>
                        Navigate to <strong>Tools → Clockwork</strong> and copy the generated <strong>Connection Key</strong>.
                    </div>
                    <div class="p-3 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                        <span class="font-bold text-[var(--color-ink-strong)] block mb-1">3. Pair with Clockwork</span>
                        Paste the Connection Key below and click <strong>Verify & Connect</strong>.
                    </div>
                </div>
            </div>

            {{-- Card 2: Connection Form --}}
            <div class="p-6 rounded-[var(--radius-card)] bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs">
                <div class="flex items-center justify-between border-b border-[var(--color-border-light)] pb-4 mb-6">
                    <div>
                        <h2 class="text-base font-bold text-[var(--color-ink-strong)]">Step 2: Connect & Verify</h2>
                        <p class="text-xs text-[var(--color-ink-muted)]">Authenticate Clockwork Control with the site's Companion plugin</p>
                    </div>
                    <div class="inline-flex items-center bg-[var(--color-surface-alt)] p-1 rounded-lg border border-[var(--color-border-light)] text-xs">
                        <button type="button" @click="mode = 'key'"
                                :class="mode === 'key' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-muted)]'"
                                class="px-2.5 py-1 rounded-md transition-all cursor-pointer">
                            Connection Key
                        </button>
                        <button type="button" @click="mode = 'manual'"
                                :class="mode === 'manual' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-muted)]'"
                                class="px-2.5 py-1 rounded-md transition-all cursor-pointer">
                            Manual Setup
                        </button>
                    </div>
                </div>

                <form method="POST" action="{{ route('sites.store') }}" @submit="submitting = true" class="space-y-5">
                    @csrf

                    {{-- Connection Key Input (ManageWP Style) --}}
                    <div x-show="mode === 'key'" class="space-y-4">
                        <div>
                            <label for="connection_key" class="block text-xs font-bold text-[var(--color-ink-strong)] uppercase tracking-wider mb-1.5">
                                Connection Key
                            </label>
                            <textarea
                                name="connection_key"
                                id="connection_key"
                                rows="3"
                                x-model="connectionKey"
                                @input="parseKey()"
                                placeholder="Paste the Connection Key from wp-admin → Tools → Clockwork here…"
                                class="w-full font-mono text-xs p-3 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] focus:bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-brand)] text-[var(--color-ink-strong)]"
                            ></textarea>
                            <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">
                                Pasting the connection key automatically extracts the domain and security secret.
                            </p>
                        </div>
                    </div>

                    {{-- Domain field --}}
                    <div>
                        <label for="domain" class="block text-xs font-bold text-[var(--color-ink-strong)] uppercase tracking-wider mb-1.5">
                            Site Domain
                        </label>
                        <div class="relative">
                            <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-[var(--color-ink-soft)] font-mono">https://</span>
                            <input
                                type="text"
                                name="domain"
                                id="domain"
                                x-model="domain"
                                required
                                placeholder="client-site.com"
                                class="w-full pl-20 pr-4 py-2.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] focus:bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-brand)] text-sm font-mono text-[var(--color-ink-strong)]"
                            >
                        </div>
                    </div>

                    {{-- Companion Secret --}}
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label for="companion_secret" class="block text-xs font-bold text-[var(--color-ink-strong)] uppercase tracking-wider">
                                Companion Shared Secret
                            </label>
                            <button type="button" @click="generateSecret()" class="text-[11px] text-[var(--color-brand)] hover:underline cursor-pointer">
                                Generate Random Secret
                            </button>
                        </div>
                        <input
                            type="text"
                            name="companion_secret"
                            id="companion_secret"
                            x-model="secret"
                            required
                            placeholder="32-byte hex secret (e.g. a1b2c3d4...)"
                            class="w-full px-4 py-2.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] focus:bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-brand)] text-xs font-mono text-[var(--color-ink-strong)]"
                        >
                        <div x-show="mode === 'manual'" class="mt-2 p-3 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)]">
                            <span class="font-semibold text-[var(--color-ink-strong)]">wp-config.php constant alternative:</span>
                            <div class="mt-1 font-mono text-[11px] text-[var(--color-brand)] bg-[var(--color-surface)] p-2 rounded select-all">
                                define('CLOCKWORK_COMPANION_SECRET', '<span x-text="secret || 'YOUR_SECRET_HERE'"></span>');
                            </div>
                        </div>
                    </div>

                    {{-- Standalone enroll is always Custom / Standalone. Host API
                         providers (WP Engine, Pressable, …) are a different path. --}}
                    <input type="hidden" name="hosting_provider" value="custom">
                    <p class="text-[11px] text-[var(--color-ink-soft)]">
                        Enrolled as <strong class="text-[var(--color-ink-strong)]">Custom / Standalone</strong>
                        (no host API or SSH). Uptime, live TLS, Companion REST, and daily Glacier backups (changeable per site) apply.
                    </p>

                    {{-- Submit button --}}
                    <div class="pt-4 flex items-center justify-end gap-3 border-t border-[var(--color-border-light)]">
                        <a href="{{ route('sites.index') }}" class="px-4 py-2 rounded-xl text-sm font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] transition-colors">
                            Cancel
                        </a>
                        <button
                            type="submit"
                            :disabled="submitting"
                            class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-bold bg-[var(--color-brand)] text-white hover:bg-[var(--color-brand-hover)] shadow-xs transition-all cursor-pointer disabled:opacity-50"
                        >
                            <i class="fa-solid fa-link" x-show="!submitting"></i>
                            <i class="fa-solid fa-spinner fa-spin" x-show="submitting" style="display: none;"></i>
                            <span x-text="submitting ? 'Verifying Companion…' : 'Verify & Connect Site'">Verify & Connect Site</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
