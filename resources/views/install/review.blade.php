@extends('layouts.install')

@section('title', 'Review & Confirm')

@section('content')
<div class="card p-6 md:p-8 shadow-sm"
     x-data="{ installing: false, accepted: false }">
    <div class="mb-6">
        <h1 class="font-display text-2xl font-bold tracking-tight text-[var(--color-ink-strong)]">
            Step 9: Review Configuration
        </h1>
        <p class="text-sm text-[var(--color-ink-muted)] mt-1">
            Please verify your installation settings before applying them. All secrets are safely masked.
        </p>
    </div>

    @if ($errors->any())
        <div class="p-4 rounded-xl border border-[var(--color-status-red)]/30 bg-[var(--color-status-red-bg)] text-xs text-[var(--color-status-red)] mb-6">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
            {{ $errors->first() }}
        </div>
    @endif

    <div class="space-y-4 mb-8">
        <!-- Database Card -->
        <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/30">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-soft)] flex items-center gap-1.5">
                    <i class="fa-solid fa-database text-[var(--color-brand)]"></i> Database
                </span>
                <a href="{{ route('install.database') }}" class="text-xs text-[var(--color-brand)] hover:underline">Edit</a>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs font-mono">
                <div><span class="text-[var(--color-ink-soft)] block font-sans">Host:</span> {{ $wizard['database']['host'] }}:{{ $wizard['database']['port'] }}</div>
                <div><span class="text-[var(--color-ink-soft)] block font-sans">Database:</span> {{ $wizard['database']['database'] }}</div>
                <div><span class="text-[var(--color-ink-soft)] block font-sans">User:</span> {{ $wizard['database']['username'] }}</div>
                <div><span class="text-[var(--color-ink-soft)] block font-sans">Password:</span> ••••••••</div>
            </div>
        </div>

        <!-- Application Card -->
        <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/30">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-soft)] flex items-center gap-1.5">
                    <i class="fa-solid fa-sliders text-[var(--color-brand)]"></i> Application
                </span>
                <a href="{{ route('install.app') }}" class="text-xs text-[var(--color-brand)] hover:underline">Edit</a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <div><span class="text-[var(--color-ink-soft)] block">Panel Name:</span> <span class="font-medium text-[var(--color-ink-strong)]">{{ $wizard['app']['name'] ?? 'Clockwork Control' }}</span></div>
                <div><span class="text-[var(--color-ink-soft)] block">Public URL:</span> <span class="font-mono text-[var(--color-ink-strong)]">{{ $wizard['app']['url'] ?? '' }}</span></div>
                <div><span class="text-[var(--color-ink-soft)] block">Timezone:</span> <span class="font-mono text-[var(--color-ink-strong)]">{{ $wizard['app']['timezone'] ?? 'UTC' }}</span></div>
            </div>
        </div>

        <!-- Mail Card -->
        <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/30">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-soft)] flex items-center gap-1.5">
                    <i class="fa-solid fa-envelope text-[var(--color-brand)]"></i> Outbound Mail
                </span>
                <a href="{{ route('install.mail') }}" class="text-xs text-[var(--color-brand)] hover:underline">Edit</a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                @if (!empty($wizard['mail']['skipped']))
                    <div class="col-span-3 text-[var(--color-ink-muted)] italic">Skipped — mailer defaulted to log.</div>
                @else
                    <div><span class="text-[var(--color-ink-soft)] block">Mailer:</span> {{ strtoupper($wizard['mail']['mailer'] ?? 'SMTP') }} ({{ $wizard['mail']['host'] ?? '' }}:{{ $wizard['mail']['port'] ?? '587' }})</div>
                    <div><span class="text-[var(--color-ink-soft)] block">From Email:</span> <span class="font-mono">{{ $wizard['mail']['from_address'] ?? '' }}</span></div>
                    <div><span class="text-[var(--color-ink-soft)] block">From Name:</span> {{ $wizard['mail']['from_name'] ?? '' }}</div>
                @endif
            </div>
        </div>

        <!-- Google OAuth Card -->
        <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/30">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-soft)] flex items-center gap-1.5">
                    <i class="fa-brands fa-google text-red-500"></i> Google OAuth
                </span>
                <a href="{{ route('install.google') }}" class="text-xs text-[var(--color-brand)] hover:underline">Edit</a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                @if (!empty($wizard['google']['skipped']) || empty($wizard['google']['client_id']))
                    <div class="col-span-2 text-[var(--color-ink-muted)] italic">Skipped — using local password authentication (SSO can be configured later in Settings).</div>
                @else
                    <div><span class="text-[var(--color-ink-soft)] block">Client ID:</span> <span class="font-mono text-[var(--color-ink-strong)] truncate block">{{ $wizard['google']['client_id'] ?? '' }}</span></div>
                    <div><span class="text-[var(--color-ink-soft)] block">Hosted Domain:</span> <span class="font-mono text-[var(--color-ink-strong)]">{{ $wizard['google']['hd'] ?: 'Any Domain (No restriction)' }}</span></div>
                @endif
            </div>
        </div>

        <!-- Administrator Card -->
        <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/30">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-soft)] flex items-center gap-1.5">
                    <i class="fa-solid fa-user-shield text-[var(--color-brand)]"></i> Administrator
                </span>
                <a href="{{ route('install.admin') }}" class="text-xs text-[var(--color-brand)] hover:underline">Edit</a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <div><span class="text-[var(--color-ink-soft)] block">Name:</span> <span class="font-medium text-[var(--color-ink-strong)]">{{ $wizard['admin']['name'] ?? '' }}</span></div>
                <div><span class="text-[var(--color-ink-soft)] block">Email:</span> <span class="font-mono text-[var(--color-ink-strong)]">{{ $wizard['admin']['email'] ?? '' }}</span></div>
                <div>
                    <span class="text-[var(--color-ink-soft)] block">Password:</span>
                    @if (!empty($wizard['admin']['password']))
                        <span class="font-mono text-[var(--color-status-green)] flex items-center gap-1">
                            <i class="fa-solid fa-circle-check text-[10px]"></i> Configured
                        </span>
                    @else
                        <span class="text-[var(--color-ink-muted)] italic">SSO-only</span>
                    @endif
                </div>
            </div>
        </div>

        <!-- Hosting Infrastructure Card -->
        <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/30">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-soft)] flex items-center gap-1.5">
                    <i class="fa-solid fa-server text-[var(--color-brand)]"></i> Hosting Infrastructure
                </span>
                <a href="{{ route('install.hosting') }}" class="text-xs text-[var(--color-brand)] hover:underline">Edit</a>
            </div>
            @php
                $selectedProviders = $wizard['hosting']['providers'] ?? (isset($wizard['hosting']['provider']) && $wizard['hosting']['provider'] !== 'skip' ? [$wizard['hosting']['provider']] : []);
            @endphp
            @if (empty($selectedProviders) || in_array('skip', $selectedProviders, true))
                <div class="text-xs text-[var(--color-ink-muted)] italic">Skipped — you can connect hosting providers anytime in Settings &rarr; API credentials.</div>
            @else
                <div class="flex flex-wrap gap-2 pt-1">
                    @foreach ($selectedProviders as $provKey)
                        @if (isset($providers[$provKey]))
                            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-[var(--color-surface)] border border-[var(--color-border-light)] text-xs font-medium text-[var(--color-ink-strong)] shadow-xs">
                                <span class="w-5 h-5 flex items-center justify-center flex-shrink-0">
                                    <x-service-logo :service="$provKey" class="w-4 h-4" />
                                </span>
                                <span>{{ $providers[$provKey]['name'] }}</span>
                            </span>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Cloud Infrastructure & VPS Card -->
        <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/30">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-soft)] flex items-center gap-1.5">
                    <i class="fa-solid fa-microchip text-blue-500"></i> Cloud Infrastructure &amp; VPS
                </span>
                <a href="{{ route('install.vps') }}" class="text-xs text-[var(--color-brand)] hover:underline">Edit</a>
            </div>
            @php
                $selectedVps = $wizard['vps']['providers'] ?? (isset($wizard['vps']['provider']) && $wizard['vps']['provider'] !== 'skip' ? [$wizard['vps']['provider']] : []);
            @endphp
            @if (empty($selectedVps) || in_array('skip', $selectedVps, true))
                <div class="text-xs text-[var(--color-ink-muted)] italic">Skipped — you can connect cloud compute providers anytime in Settings &rarr; API credentials.</div>
            @else
                <div class="flex flex-wrap gap-2 pt-1">
                    @foreach ($selectedVps as $vpsKey)
                        @if (isset($cloudProviders[$vpsKey]))
                            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-[var(--color-surface)] border border-[var(--color-border-light)] text-xs font-medium text-[var(--color-ink-strong)] shadow-xs">
                                <span class="w-5 h-5 flex items-center justify-center flex-shrink-0">
                                    <x-service-logo :service="$vpsKey" class="w-4 h-4" />
                                </span>
                                <span>{{ $cloudProviders[$vpsKey]['name'] }}</span>
                            </span>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <!-- Care Plans Policy Card -->
    <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/30 text-xs text-[var(--color-ink-muted)] mb-4">
        <label class="flex items-start gap-2.5 cursor-pointer">
            <input type="hidden" name="care_plans_enabled" value="0" form="install-review-form">
            <input type="checkbox" name="care_plans_enabled" value="1" checked form="install-review-form"
                   class="mt-0.5 rounded border-[var(--color-border-light)]">
            <span>
                <strong class="text-[var(--color-ink-strong)]">Enable Care Plans Tiering</strong> — track which sites are on client care plans and gate automated maintenance, routine plugin updates, and scans to enrolled sites. If unchecked, all sites are treated as covered for maintenance and care plan labels are hidden across the platform. Change this anytime in Settings &rarr; Care Plans.
            </span>
        </label>
    </div>

    <!-- Anonymous Telemetry Opt-in Card -->
    <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/30 text-xs text-[var(--color-ink-muted)] mb-4">
        <label class="flex items-start gap-2.5 cursor-pointer">
            <input type="checkbox" name="telemetry_opt_in" value="1" checked form="install-review-form"
                   class="mt-0.5 rounded border-[var(--color-border-light)]">
            <span>
                <strong class="text-[var(--color-ink-strong)]">Help improve Clockwork Control</strong> — send a small anonymous usage report about once a week: roughly how many sites this install manages and which optional modules are enabled. No site URLs, credentials, hosting platforms, content, or IP data are ever included. On by default; change this anytime in Settings &rarr; Maintenance.
            </span>
        </label>
    </div>

    <!-- Required Disclaimer Card -->
    <div class="p-4 rounded-xl border border-[var(--color-status-yellow)]/30 bg-[var(--color-status-yellow-bg)] text-xs text-[var(--color-ink-muted)] mb-8">
        <div class="flex items-start gap-2.5 mb-3">
            <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-yellow)] text-base mt-0.5"></i>
            <div>
                <strong>Before you continue:</strong>
                Clockwork Control stores real fleet credentials and can take real automated actions (bans, updates, firewall changes) on your servers. It's provided under the MIT license — no warranty, no guaranteed support — and you're responsible for reviewing any feature before you enable it. Full text: <a href="https://clockworkcontrol.com/intended-usage#disclaimer" target="_blank" rel="noopener noreferrer" class="text-[var(--color-brand)] underline">Security, Support &amp; Liability Disclaimer</a>.
            </div>
        </div>
        <label class="flex items-center gap-2 pl-6 text-[var(--color-ink-strong)] font-medium cursor-pointer">
            <input type="checkbox" name="disclaimer_accepted" value="1" form="install-review-form" required
                   x-model="accepted"
                   class="rounded border-[var(--color-border-light)]">
            I understand and accept the terms above.
        </label>
    </div>

    <div class="p-4 rounded-xl border border-[var(--color-brand)]/20 bg-[var(--color-brand)]/5 text-xs text-[var(--color-ink-muted)] mb-8 flex items-start gap-2.5">
        <i class="fa-solid fa-shield-halved text-[var(--color-brand)] text-base mt-0.5"></i>
        <div>
            <strong>Ready to apply:</strong>
            Submitting below will atomically write these settings to your <code class="px-1 py-0.5 rounded bg-black/5 font-mono">.env</code> file, execute database migrations, provision your administrator account, and permanently seal the installer gate.
        </div>
    </div>

    <form id="install-review-form" method="POST" action="{{ route('install.run') }}" @submit="installing = true">
        @csrf

        <div class="flex items-center justify-between pt-5 border-t border-[var(--color-border-light)]">
            <a href="{{ route('install.vps') }}"
               class="inline-flex items-center gap-2 text-xs font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]"
               :class="installing ? 'opacity-50 pointer-events-none' : ''">
                <i class="fa-solid fa-arrow-left text-[10px]"></i>
                <span>Back</span>
            </a>

            <button type="submit"
                    :disabled="installing || !accepted"
                    class="inline-flex items-center gap-2 px-6 py-3 rounded-full bg-[var(--color-brand)] text-white font-medium text-sm hover:bg-[var(--color-brand)]/90 transition-all shadow-md cursor-pointer disabled:opacity-75">
                <i class="fa-solid" :class="installing ? 'fa-spinner fa-spin' : 'fa-rocket'"></i>
                <span x-show="!installing">Install &amp; Complete Setup</span>
                <span x-show="installing" x-cloak>Running Installation...</span>
            </button>
        </div>
    </form>
</div>
@endsection
