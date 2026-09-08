@extends('layouts.app')

@section('title', 'Maintenance · Clockwork')

@section('content')
    @include('settings._tabs')

    <x-page-header title="Maintenance"
        subtitle="Operator utilities for keeping the Clockwork app itself healthy. Database backup is the big one today — download a snapshot here and store it securely.">
        <x-slot:actions>
            <a href="{{ route('settings.updates.index') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-arrows-rotate text-[var(--color-ink-muted)]"></i>
                <span>Updates</span>
            </a>
            <a href="{{ route('settings.diagnostics.index') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-stethoscope text-[var(--color-ink-muted)]"></i>
                <span>Diagnostics</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Roll-up metric tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 max-w-3xl">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Database Name</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data truncate" title="{{ $dbInfo['name'] }}">{{ $dbInfo['name'] }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">{{ $dbInfo['driver'] }} engine</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Estimated Size</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">
                @if ($totalBytes >= 1073741824)
                    {{ number_format($totalBytes / 1024 / 1024 / 1024, 1) }} GB
                @else
                    {{ number_format($totalBytes / 1024 / 1024, 1) }} MB
                @endif
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Fleet metadata + history</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">DB Host</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data truncate" title="{{ $dbInfo['host'] }}">{{ $dbInfo['host'] }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">MySQL connection</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Export Format</div>
            <div class="text-2xl font-display text-[var(--color-status-green)] font-data">Gzip SQL</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Direct browser stream</div>
        </div>
    </div>

    <div class="card p-6 max-w-3xl mb-6">
        <div class="flex items-start justify-between gap-4 mb-4 flex-wrap">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1">
                    <i class="fa-solid fa-database text-[var(--color-ink-muted)] mr-1"></i>
                    Database backup
                </h2>
                <div class="text-xs text-[var(--color-ink-muted)] font-data">
                    {{ $dbInfo['driver'] }} · {{ $dbInfo['host'] }} · <strong>{{ $dbInfo['name'] }}</strong>
                    · approx {{ $totalBytes >= 1073741824 ? number_format($totalBytes / 1024 / 1024 / 1024, 1) . ' GB' : number_format($totalBytes / 1024 / 1024, 1) . ' MB' }}
                </div>
            </div>
            <a href="{{ route('settings.maintenance.backup') }}"
               class="px-4 py-2 rounded-md bg-[var(--color-primary-600)] text-white text-sm font-medium hover:bg-[var(--color-primary-700)] inline-flex items-center gap-2">
                <i class="fa-solid fa-download"></i> Download backup
            </a>
        </div>

        <div class="text-sm text-[var(--color-ink-muted)] space-y-2">
            <p>
                Downloads a <strong>gzipped <code class="font-data text-xs">mysqldump</code></strong>
                of the full database, streamed straight to your browser. No copy is left on the server.
                Filename includes a timestamp so consecutive downloads don't collide.
            </p>
            <p>
                The dump uses <code class="font-data text-xs">--single-transaction</code> so InnoDB
                stays consistent without locking writes. Restoring is the standard
                <code class="font-data text-xs">gunzip -c file.sql.gz | mysql clockwork</code>.
            </p>
        </div>

        <div class="mt-4 pt-4 border-t border-[var(--color-border-light)] text-xs text-[var(--color-ink-soft)]">
            <strong class="text-[var(--color-status-yellow)]"><i class="fa-solid fa-triangle-exclamation"></i> Sensitive contents.</strong>
            The dump contains encrypted SSH keys, per-site DB credentials, and Companion HMAC secrets.
            Encrypted columns can only be decrypted with this app's <code class="font-data">APP_KEY</code>
            (in <code class="font-data">.env</code>), so the dump is useless on its own — but pair it
            with a stolen <code class="font-data">.env</code> and someone has the keys to your fleet.
            Store backups encrypted (e.g. <code class="font-data">age</code>, <code class="font-data">gpg</code>,
            or in an encrypted disk image) and never ship the dump and the .env to the same place.
        </div>
    </div>

    <div class="card p-6 max-w-3xl mb-6" x-data="{
        showPayload: {{ $telemetryEnabled ? 'false' : 'true' }},
        copied: false,
        copyJson() {
            navigator.clipboard.writeText({{ json_encode(json_encode($telemetryPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }});
            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
        }
    }">
        {{-- Section Header --}}
        <div class="flex items-center justify-between gap-4 mb-4 flex-wrap pb-4 border-b border-[var(--color-border-light)]">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] flex items-center gap-2">
                        <i class="fa-solid fa-chart-line text-[var(--color-brand)]"></i>
                        <span>Anonymous usage telemetry</span>
                    </h2>
                    @if ($telemetryEnabled)
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                            <i class="fa-solid fa-pause text-[9px]"></i> Off
                        </span>
                    @endif
                </div>
                <p class="text-xs text-[var(--color-ink-muted)] max-w-xl">
                    Transmits high-level platform usage (exact site count, server count, active modules, and per-module breakdown) once a week. Zero private data, URLs, or credentials.
                </p>
            </div>

            <div class="flex items-center gap-2.5">
                @if ($telemetryEnabled)
                    <form method="POST" action="{{ route('settings.maintenance.telemetry.sendNow') }}">
                        @csrf
                        <button type="submit" class="btn-pill-nav text-xs" title="Send a telemetry ping right now">
                            <i class="fa-solid fa-paper-plane text-[var(--color-ink-muted)]"></i>
                            <span>Send ping now</span>
                        </button>
                    </form>
                @endif
                <form method="POST" action="{{ route('settings.maintenance.telemetry.update') }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="enabled" value="{{ $telemetryEnabled ? '0' : '1' }}">
                    <label class="relative inline-flex items-center cursor-pointer select-none" title="Toggle telemetry reporting">
                        <input type="checkbox"
                               {{ $telemetryEnabled ? 'checked' : '' }}
                               onchange="this.form.submit()"
                               class="sr-only peer">
                        <div class="w-9 h-5 bg-[var(--color-border-light)] peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-[var(--color-brand)]"></div>
                        <span class="ml-2 text-xs font-medium text-[var(--color-ink-strong)]">
                            {{ $telemetryEnabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </label>
                </form>
            </div>
        </div>

        {{-- Roll-up Metric Tiles --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
            <div class="card px-3.5 py-2.5 bg-[var(--color-surface-alt)]/40">
                <div class="text-[10px] uppercase tracking-wider text-[var(--color-ink-soft)] font-medium">Managed Sites</div>
                <div class="text-xl font-display font-semibold text-[var(--color-ink-strong)] font-data mt-0.5">
                    {{ number_format($telemetryPayload['sites_count'] ?? 0) }}
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Exact fleet total</div>
            </div>
            <div class="card px-3.5 py-2.5 bg-[var(--color-surface-alt)]/40">
                <div class="text-[10px] uppercase tracking-wider text-[var(--color-ink-soft)] font-medium">Connected Servers</div>
                <div class="text-xl font-display font-semibold text-[var(--color-ink-strong)] font-data mt-0.5">
                    {{ number_format($telemetryPayload['servers_count'] ?? 0) }}
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Host machines</div>
            </div>
            <div class="card px-3.5 py-2.5 bg-[var(--color-surface-alt)]/40">
                <div class="text-[10px] uppercase tracking-wider text-[var(--color-ink-soft)] font-medium">Active Modules</div>
                <div class="text-xl font-display font-semibold text-[var(--color-brand)] font-data mt-0.5">
                    {{ count($telemetryPayload['modules_enabled'] ?? []) }}
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Installed &amp; enabled</div>
            </div>
            <div class="card px-3.5 py-2.5 bg-[var(--color-surface-alt)]/40">
                <div class="text-[10px] uppercase tracking-wider text-[var(--color-ink-soft)] font-medium">Schedule</div>
                <div class="text-xl font-display font-semibold text-[var(--color-status-green)] font-data mt-0.5">
                    Weekly
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Background cron ping</div>
            </div>
        </div>

        {{-- Disabled State Banner --}}
        @if (!$telemetryEnabled)
            <div class="p-3.5 rounded-lg border border-amber-200 dark:border-amber-900/50 bg-amber-500/10 mb-5 flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-2.5">
                    <i class="fa-solid fa-pause text-amber-600 text-xs"></i>
                    <span class="text-xs text-[var(--color-ink-strong)] font-medium">
                        Telemetry is currently off — no metrics are being generated or sent.
                    </span>
                </div>
                <form method="POST" action="{{ route('settings.maintenance.telemetry.update') }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="enabled" value="1">
                    <button type="submit" class="btn-pill-nav text-xs font-medium">
                        <i class="fa-solid fa-play text-emerald-600 text-[10px]"></i>
                        <span>Re-enable Telemetry</span>
                    </button>
                </form>
            </div>
        @endif

        {{-- Module Breakdown Table --}}
        <div class="mb-5">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold text-[var(--color-ink-strong)] flex items-center gap-1.5">
                    <i class="fa-solid fa-cubes text-[var(--color-ink-muted)] text-[11px]"></i>
                    Enabled Modules &amp; Fleet Breakdown
                </span>
                <span class="text-[11px] text-[var(--color-ink-soft)] font-data">
                    Exact counts transmitted
                </span>
            </div>

            <div class="border border-[var(--color-border-light)] rounded-lg overflow-hidden">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-[var(--color-surface-alt)]/60 border-b border-[var(--color-border-light)] text-[10px] uppercase tracking-wider text-[var(--color-ink-soft)]">
                            <th class="py-2 px-3 font-medium">Module Name</th>
                            <th class="py-2 px-3 font-medium">Identifier</th>
                            <th class="py-2 px-3 font-medium text-right">Servers</th>
                            <th class="py-2 px-3 font-medium text-right">Sites Utilizing</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @forelse ($moduleBreakdown as $module)
                            <tr class="hover:bg-[var(--color-surface-alt)]/30 transition-colors">
                                <td class="py-2 px-3 font-medium text-[var(--color-ink-strong)] flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 flex-shrink-0"></span>
                                    <span>{{ $module['name'] }}</span>
                                </td>
                                <td class="py-2 px-3 font-data text-[11px] text-[var(--color-ink-soft)]">
                                    {{ $module['id'] }}
                                </td>
                                <td class="py-2 px-3 text-right font-data">
                                    @if ($module['servers'] !== null)
                                        <span class="font-semibold text-[var(--color-ink-strong)] px-2 py-0.5 rounded bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                                            {{ number_format($module['servers']) }} {{ Str::plural('server', $module['servers']) }}
                                        </span>
                                    @else
                                        <span class="text-[var(--color-ink-soft)] font-data text-[11px]">—</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-right font-data">
                                    @if ($module['sites'] !== null)
                                        <span class="font-semibold text-[var(--color-ink-strong)] px-2 py-0.5 rounded bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                                            {{ number_format($module['sites']) }} {{ Str::plural('site', $module['sites']) }}
                                        </span>
                                    @else
                                        <span class="text-[var(--color-ink-soft)] italic text-[11px]">
                                            Panel-wide
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-4 text-center text-[var(--color-ink-muted)]">No active modules found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Zero-Knowledge Privacy Note --}}
        <div class="p-3 rounded-lg bg-[var(--color-surface-alt)]/30 border border-[var(--color-border-light)] mb-4 flex items-center gap-2.5 text-xs text-[var(--color-ink-muted)]">
            <i class="fa-solid fa-shield-halved text-[var(--color-brand)] text-sm flex-shrink-0"></i>
            <div>
                <strong class="text-[var(--color-ink-strong)]">Zero-Knowledge Guarantee:</strong>
                Never includes site URLs, domains, server hostnames, IP addresses, credentials, customer data, or user accounts.
            </div>
        </div>

        {{-- Payload Inspector Toggle & Code Block --}}
        <div class="pt-3 border-t border-[var(--color-border-light)]">
            <div class="flex items-center justify-between">
                <button type="button"
                        @click="showPayload = !showPayload"
                        class="text-xs font-medium text-[var(--color-brand)] hover:underline inline-flex items-center gap-1.5 py-1">
                    <i class="fa-solid fa-code text-[11px]"></i>
                    <span x-text="showPayload ? 'Hide exact payload' : 'Inspect exact payload JSON'"></span>
                    <i class="fa-solid text-[9px] transition-transform duration-200" :class="showPayload ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                </button>
                <span class="text-[11px] text-[var(--color-ink-soft)] font-data">
                    Schema v{{ $telemetryPayload['schema_version'] ?? 1 }} · Install ID: {{ \Illuminate\Support\Str::limit($telemetryPayload['install_id'] ?? 'none', 13) }}
                </span>
            </div>

            <div x-show="showPayload" x-cloak class="mt-2.5">
                <div class="rounded-lg overflow-hidden border border-[var(--color-border-light)] bg-slate-900 text-slate-100 shadow-xs">
                    <div class="flex items-center justify-between px-3 py-1.5 bg-slate-800/80 border-b border-slate-700/60 text-[11px] font-mono text-slate-300">
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span>
                            <span>Exact payload generated from current system state</span>
                        </span>
                        <button type="button"
                                @click="copyJson()"
                                class="text-slate-300 hover:text-white inline-flex items-center gap-1 text-[11px] transition-colors">
                            <i class="fa-solid" :class="copied ? 'fa-check text-emerald-400' : 'fa-copy'"></i>
                            <span x-text="copied ? 'Copied' : 'Copy JSON'"></span>
                        </button>
                    </div>
                    <pre class="p-3.5 text-xs font-mono overflow-x-auto leading-relaxed text-emerald-400 max-h-72"><code>{{ json_encode($telemetryPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                </div>
            </div>
        </div>
    </div>
@endsection
