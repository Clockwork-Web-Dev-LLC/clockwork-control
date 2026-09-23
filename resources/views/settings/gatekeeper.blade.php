@extends('layouts.app')

@section('title', 'Login Lockouts (Gatekeeper) · Clockwork')

@section('content')
    @include('settings._tabs')

    <x-page-header title="Login Lockouts (Gatekeeper)"
        subtitle="Native login brute-force throttling and white-labeled lockout screens. Replaces Limit Login Attempts Reloaded (LLAR) with silent IP lockouts, zero client-facing attack counters, and full ingest visibility for Pressable and VPS sites.">
        <x-slot:actions>
            <form method="POST" action="{{ route('settings.gatekeeper.syncNow') }}">
                @csrf
                <button type="submit" class="btn-pill-nav text-sm" title="Push current settings to all Companion and Renegade sites">
                    <i class="fa-solid fa-arrows-rotate text-[var(--color-ink-muted)]"></i>
                    <span>Push to Fleet</span>
                </button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="card p-4 mb-6 status-red">
            <div class="font-semibold text-xs mb-1">Please correct the following errors:</div>
            <ul class="list-disc pl-5 text-xs space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Metrics summary --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 max-w-5xl">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Fleet Default</div>
            <div class="text-xl font-display text-[var(--color-ink-strong)] font-semibold mt-1">
                {{ $config['enabled'] ? 'Enforced' : 'Disabled (Off)' }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">
                {{ $config['enabled'] ? 'Active native lockouts' : 'No native enforcement' }}
            </div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Lockout Threshold</div>
            <div class="text-2xl font-display text-[var(--color-brand)] font-data">{{ $config['threshold'] }} <span class="text-xs font-normal">failures</span></div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">in {{ round($config['window_seconds'] / 60) }}m window</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Gatekeeper Ready</div>
            <div class="text-2xl font-display text-[var(--color-status-green)] font-data">
                {{ number_format($gatekeeperCapableSites) }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">of {{ number_format($totalCompanionSites) }} Companion sites</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Site Overrides</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">
                {{ number_format($sitesWithOverrides) }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Custom thresholds/copy</div>
        </div>
    </div>

    <form method="POST" action="{{ route('settings.gatekeeper.update') }}" class="card p-6 max-w-5xl mb-6">
        @csrf
        @method('PATCH')

        <div class="flex items-start justify-between gap-4 pb-4 border-b border-[var(--color-border-light)] mb-6">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-shield-halved text-blue-600"></i>
                    Gatekeeper Policies
                </h2>
                <p class="text-xs text-[var(--color-ink-muted)] mt-1">
                    Configure default brute force thresholds, lockout backoff intervals, and white-labeled lockout screen messaging pushed to Companion and Renegade.
                </p>
            </div>
            <span class="text-xs px-2.5 py-1 rounded-full font-medium {{ $config['enabled'] ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800' : 'bg-slate-50 text-slate-700 dark:bg-slate-900 dark:text-slate-400 border border-slate-200 dark:border-slate-800' }}">
                {{ $config['enabled'] ? 'Default On' : 'Default Off' }}
            </span>
        </div>

        <div class="space-y-6">
            {{-- Master toggle --}}
            <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/40">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" value="1" @checked($config['enabled'])
                           class="mt-1 rounded border-[var(--color-border-light)] text-[var(--color-brand)] focus:ring-[var(--color-brand)]/20">
                    <div>
                        <div class="text-sm font-semibold text-[var(--color-ink-strong)]">
                            Enable Gatekeeper Login Lockouts by Default
                        </div>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-1 leading-relaxed">
                            When enabled, Companion and Renegade enforce native IP lockouts on WordPress login attempts before password verification runs. When disabled, Gatekeeper stays dormant unless explicitly enabled on an individual site.
                        </p>
                        <p class="text-xs text-[var(--color-ink-soft)] mt-2 leading-relaxed">
                            <strong>Note:</strong> During the transition phase, legacy LLAR continues to coexist safely. Gatekeeper and LLAR will not double-enforce; once Gatekeeper is enabled for a site, LLAR calls are suppressed.
                        </p>
                    </div>
                </label>
            </div>

            {{-- Policy Settings Grid --}}
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-3">Throttling &amp; Lockout Rules</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="threshold" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Failure Threshold
                        </label>
                        <div class="flex items-center gap-2">
                            <input type="number" id="threshold" name="threshold" min="3" max="20" value="{{ old('threshold', $config['threshold']) }}" required
                                   class="w-full text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">
                            <span class="text-xs text-[var(--color-ink-muted)]">attempts</span>
                        </div>
                        <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Number of failed logins allowed before lockout (3 - 20).</p>
                    </div>

                    <div>
                        <label for="window_seconds" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Attempt Window
                        </label>
                        <div class="flex items-center gap-2">
                            <input type="number" id="window_seconds" name="window_seconds" min="60" max="86400" value="{{ old('window_seconds', $config['window_seconds']) }}" required
                                   class="w-full text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">
                            <span class="text-xs text-[var(--color-ink-muted)]">sec</span>
                        </div>
                        <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Window during which failed attempts accumulate (e.g. 1200 = 20 min).</p>
                    </div>

                    <div>
                        <label for="lockout_seconds" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Initial Lockout Duration
                        </label>
                        <div class="flex items-center gap-2">
                            <input type="number" id="lockout_seconds" name="lockout_seconds" min="60" max="86400" value="{{ old('lockout_seconds', $config['lockout_seconds']) }}" required
                                   class="w-full text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">
                            <span class="text-xs text-[var(--color-ink-muted)]">sec</span>
                        </div>
                        <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Duration of first lockout (e.g. 1200 = 20 min).</p>
                    </div>

                    <div>
                        <label for="consecutive_lockouts_for_extended" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Extended Lockout Trigger
                        </label>
                        <div class="flex items-center gap-2">
                            <input type="number" id="consecutive_lockouts_for_extended" name="consecutive_lockouts_for_extended" min="1" max="20" value="{{ old('consecutive_lockouts_for_extended', $config['consecutive_lockouts_for_extended']) }}" required
                                   class="w-full text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">
                            <span class="text-xs text-[var(--color-ink-muted)]">lockouts</span>
                        </div>
                        <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Consecutive lockouts before escalating to 24-hour ban.</p>
                    </div>

                    <div>
                        <label for="extended_lockout_seconds" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Extended Lockout Duration
                        </label>
                        <div class="flex items-center gap-2">
                            <input type="number" id="extended_lockout_seconds" name="extended_lockout_seconds" min="60" max="604800" value="{{ old('extended_lockout_seconds', $config['extended_lockout_seconds']) }}" required
                                   class="w-full text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">
                            <span class="text-xs text-[var(--color-ink-muted)]">sec</span>
                        </div>
                        <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Escalated ban duration (e.g. 86400 = 24 hours).</p>
                    </div>
                </div>
            </div>

            {{-- Custom Copy & Branding --}}
            <div class="pt-4 border-t border-[var(--color-border-light)]">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-3">Lockout Page Presentation &amp; Messaging</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="headline" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Page Headline
                        </label>
                        <input type="text" id="headline" name="headline" value="{{ old('headline', $config['headline']) }}"
                               class="w-full text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">
                    </div>

                    <div>
                        <label for="body" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Body Text
                        </label>
                        <input type="text" id="body" name="body" value="{{ old('body', $config['body']) }}"
                               class="w-full text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">
                        <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Supports placeholder <code>{duration}</code>.</p>
                    </div>

                    <div>
                        <label for="support_label" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Support Contact Label
                        </label>
                        <input type="text" id="support_label" name="support_label" value="{{ old('support_label', $config['support_label']) }}"
                               class="w-full text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">
                    </div>

                    <div>
                        <label for="support_email" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Support Email
                        </label>
                        <input type="email" id="support_email" name="support_email" value="{{ old('support_email', $config['support_email']) }}"
                               class="w-full text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">
                    </div>

                    <div>
                        <label for="support_url" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Support URL (optional)
                        </label>
                        <input type="url" id="support_url" name="support_url" value="{{ old('support_url', $config['support_url']) }}"
                               placeholder="https://"
                               class="w-full text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">
                    </div>

                    <div>
                        <label for="unlock_url" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Unlock Hub URL (optional)
                        </label>
                        <input type="url" id="unlock_url" name="unlock_url" value="{{ old('unlock_url', $config['unlock_url']) }}"
                               placeholder="https://"
                               class="w-full text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-6 text-xs">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="hidden" name="show_ip" value="0">
                        <input type="checkbox" name="show_ip" value="1" @checked($config['show_ip'])
                               class="rounded border-[var(--color-border-light)] text-[var(--color-brand)] focus:ring-[var(--color-brand)]/20">
                        <span class="text-[var(--color-ink-strong)]">Show locked visitor IP with 1-click copy badge</span>
                    </label>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="hidden" name="show_unlock_link" value="0">
                        <input type="checkbox" name="show_unlock_link" value="1" @checked($config['show_unlock_link'])
                               class="rounded border-[var(--color-border-light)] text-[var(--color-brand)] focus:ring-[var(--color-brand)]/20">
                        <span class="text-[var(--color-ink-strong)]">Show Unlock Hub link when available</span>
                    </label>
                </div>
            </div>

            {{-- IP Ignore Lists --}}
            <div class="pt-4 border-t border-[var(--color-border-light)]">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-2">Custom Ignore Lists</h3>
                <p class="text-xs text-[var(--color-ink-muted)] mb-3">
                    Loopback (127.0.0.1/::1), Cloudflare edge proxies, and fleet server public IPs are handled automatically. Add custom agency office IPs or client CIDRs here.
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="ignore_ips" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Ignored IPs (one per line)
                        </label>
                        <textarea id="ignore_ips" name="ignore_ips" rows="3"
                                  class="w-full font-mono text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">{{ old('ignore_ips', $config['ignore_ips']) }}</textarea>
                    </div>

                    <div>
                        <label for="ignore_cidrs" class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Ignored CIDRs (one per line)
                        </label>
                        <textarea id="ignore_cidrs" name="ignore_cidrs" rows="3"
                                  class="w-full font-mono text-xs rounded-lg border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)]">{{ old('ignore_cidrs', $config['ignore_cidrs']) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 pt-4 border-t border-[var(--color-border-light)] flex items-center justify-between">
            <button type="submit" class="btn-primary text-xs">
                Save Fleet Policies
            </button>
            <span class="text-[11px] text-[var(--color-ink-soft)]">
                Changes will be automatically pushed during the nightly catch-up at 06:40 or on-demand.
            </span>
        </div>
    </form>
@endsection
