@php
    $formsModuleEnabled = app(\Modules\Core\ModuleStateResolver::class)->isEnabled('contact-forms');
    $hasForms = $formsModuleEnabled && ($formTestsCount > 0 || $site->contact_form_plugin || $latestFormRun);
    $runSuccess = $latestFormRun && $latestFormRun->status === 'success';

    $env = $site->companion_snapshot['environment'] ?? [];
    $rawPhpVersion = $env['php_version'] ?? null;
    $phpEolInfo = null;
    if ($rawPhpVersion) {
        $phpCycles = (array) app(\App\Support\Settings::class)->get('runtime_eol.php_cycles', []);
        $phpEolInfo = app(\App\Services\Runtime\RuntimeEolEvaluator::class)->evaluate($phpCycles, $rawPhpVersion);
    }

    $certStatusClass = 'status-unknown';
    $certStatusText = 'No SSL';
    if ($site->cert_source === 'redirect_only') {
        $certStatusClass = 'status-unknown';
        $certStatusText = 'Redirect only';
    } elseif ($site->cert_expires_at) {
        $daysRemaining = (int) now()->diffInDays($site->cert_expires_at, false);
        if ($site->cert_expires_at->isPast() || $daysRemaining < 0) {
            $certStatusClass = 'status-red';
            $certStatusText = 'Expired';
        } elseif ($daysRemaining < 14) {
            $certStatusClass = 'status-red';
            $certStatusText = 'Expires soon';
        } elseif ($daysRemaining < 30) {
            $certStatusClass = 'status-yellow';
            $certStatusText = 'Expiring';
        } else {
            $certStatusClass = 'status-green';
            $certStatusText = 'Valid SSL';
        }
    }
@endphp

<div class="card p-5 flex flex-col justify-between h-full" x-data="{ envModalOpen: false }">
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                @if ($hasForms)
                    <i class="fa-solid fa-envelope-circle-check text-[var(--color-ink-muted)]"></i>
                    Form Dispatch &amp; Leads
                @else
                    <i class="fa-solid fa-server text-[var(--color-ink-muted)]"></i>
                    Environment &amp; SSL
                @endif
            </h3>
            @if ($hasForms)
                @if ($runSuccess)
                    <span class="status-pill status-green text-[10px]">
                        <span class="status-dot"></span> Functional
                    </span>
                @elseif ($latestFormRun)
                    <span class="status-pill status-red text-[10px]">
                        <span class="status-dot"></span> Failing
                    </span>
                @else
                    <span class="status-pill status-unknown text-[10px]">Untested</span>
                @endif
            @else
                <span class="status-pill status-green text-[10px]">
                    <span class="status-dot"></span> Active
                </span>
            @endif
        </div>

        @if ($hasForms)
            <div class="space-y-2.5 py-1">
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                    <span class="text-[var(--color-ink-muted)]">Detected Plugin:</span>
                    <span class="font-medium text-[var(--color-ink-strong)] capitalize">
                        {{ $site->contact_form_plugin ?: 'Custom / Standard' }}
                    </span>
                </div>
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                    <span class="text-[var(--color-ink-muted)]">Last Automated Test:</span>
                    @if ($runSuccess)
                        <span class="text-[10px] text-emerald-700 font-semibold flex items-center gap-1">
                            <i class="fa-solid fa-check text-[9px]"></i> Dispatched &amp; Verified
                        </span>
                    @elseif ($latestFormRun)
                        <span class="text-[10px] text-rose-700 font-semibold">
                            Failed (Streak: {{ $site->contact_form_test_failure_streak }})
                        </span>
                    @else
                        <span class="text-[10px] text-[var(--color-ink-muted)]">Awaiting test cycle</span>
                    @endif
                </div>
            </div>
        @else
            {{-- Fallback: Environment & Tech Stack card matching ManageWP subtext --}}
            <div class="space-y-2.5 py-1 cursor-pointer" @click="envModalOpen = true" title="Click to view full environment &amp; SSL details">
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 hover:bg-[var(--color-surface-alt)] transition-colors">
                    <span class="text-[var(--color-ink-muted)]">PHP Version:</span>
                    <span class="font-mono font-medium text-[var(--color-ink-strong)] flex items-center gap-1.5">
                        {{ $rawPhpVersion ?? 'Standard' }}
                        @if ($phpEolInfo && $phpEolInfo['status'] === 'eol')
                            <span class="status-pill status-red text-[10px]" title="{{ $phpEolInfo['detail'] }}">EOL</span>
                        @elseif ($phpEolInfo && $phpEolInfo['status'] === 'security_only')
                            <span class="status-pill status-yellow text-[10px]" title="{{ $phpEolInfo['detail'] }}">Security only</span>
                        @endif
                    </span>
                </div>
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 hover:bg-[var(--color-surface-alt)] transition-colors">
                    <span class="text-[var(--color-ink-muted)]">WordPress Core:</span>
                    <span class="font-mono font-medium text-[var(--color-ink-strong)]">
                        {{ $env['wp_version'] ?? ($site->is_wordpress ? 'WordPress' : 'Static') }}
                    </span>
                </div>
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 hover:bg-[var(--color-surface-alt)] transition-colors">
                    <span class="text-[var(--color-ink-muted)]">SSL Certificate:</span>
                    <span class="font-medium text-[var(--color-ink-strong)]">
                        @if ($site->cert_expires_at)
                            Expires {{ $site->cert_expires_at->diffForHumans() }}
                        @else
                            Not tracked
                        @endif
                    </span>
                </div>
            </div>
        @endif
    </div>

    <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
        <span class="text-[11px] text-[var(--color-ink-muted)]">
            @if ($hasForms && $latestFormRun?->ran_at)
                Ran {{ $latestFormRun->ran_at->diffForHumans() }}
            @else
                Server &amp; Core Stack
            @endif
        </span>
        @if ($hasForms)
            <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'forms']) }}"
               class="btn-pill-nav text-xs font-medium text-indigo-600 hover:underline">
                Manage Forms <i class="fa-solid fa-chevron-right text-[10px] ml-0.5"></i>
            </a>
        @else
            <button type="button"
                    @click="envModalOpen = true"
                    class="btn-pill-nav text-xs font-medium text-[var(--color-brand)] hover:underline flex items-center gap-1 cursor-pointer">
                View Details <i class="fa-solid fa-chevron-right text-[10px] ml-0.5"></i>
            </button>
        @endif
    </div>

    @if (! $hasForms)
        {{-- Environment & SSL Details Modal --}}
        <template x-teleport="body">
            <div x-show="envModalOpen"
                 x-cloak
                 @keydown.escape.window="envModalOpen = false"
                 class="fixed inset-0 z-50 overflow-y-auto"
                 role="dialog"
                 aria-modal="true"
                 style="display: none;">
                <div class="fixed inset-0 bg-black/50 backdrop-blur-xs transition-opacity"
                     @click="envModalOpen = false"></div>

                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="relative w-full max-w-xl rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-2xl p-6 transition-all"
                         @click.stop>
                        {{-- Modal Header --}}
                        <div class="flex items-center justify-between pb-4 border-b border-[var(--color-border-light)] mb-4">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] flex items-center justify-center shrink-0">
                                    <i class="fa-solid fa-server text-base"></i>
                                </div>
                                <div>
                                    <h3 class="font-display font-semibold text-base text-[var(--color-ink-strong)]">
                                        Environment &amp; Server Details
                                    </h3>
                                    <p class="text-xs text-[var(--color-ink-muted)] font-data">
                                        {{ $site->domain }}
                                    </p>
                                </div>
                            </div>
                            <button type="button"
                                    @click="envModalOpen = false"
                                    class="w-8 h-8 rounded-full flex items-center justify-center text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] cursor-pointer"
                                    aria-label="Close modal">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        {{-- Modal Body --}}
                        <div class="space-y-4">
                            {{-- Section 1: Runtime & Web Stack --}}
                            <div>
                                <h4 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-2 flex items-center gap-1.5">
                                    <i class="fa-solid fa-microchip text-[11px] text-[var(--color-ink-muted)]"></i> Runtime &amp; Web Stack
                                </h4>
                                <div class="p-3 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/40 space-y-2 text-xs">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">PHP Version:</span>
                                        <span class="font-mono font-medium text-[var(--color-ink-strong)] flex items-center gap-1.5">
                                            {{ $rawPhpVersion ?? 'Standard' }}
                                            @if ($phpEolInfo && $phpEolInfo['status'] === 'eol')
                                                <span class="status-pill status-red text-[10px]" title="{{ $phpEolInfo['detail'] }}">EOL</span>
                                            @elseif ($phpEolInfo && $phpEolInfo['status'] === 'security_only')
                                                <span class="status-pill status-yellow text-[10px]" title="{{ $phpEolInfo['detail'] }}">Security only</span>
                                            @elseif ($rawPhpVersion)
                                                <span class="status-pill status-green text-[10px]">Active</span>
                                            @endif
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">WordPress Core:</span>
                                        <span class="font-mono font-medium text-[var(--color-ink-strong)]">
                                            {{ $env['wp_version'] ?? ($site->is_wordpress ? 'WordPress' : 'Static') }}
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">Web Server:</span>
                                        <span class="font-medium text-[var(--color-ink-strong)] uppercase">
                                            {{ $env['web_server'] ?? ($site->isPressable() ? 'Pressable Edge' : 'nginx') }}
                                        </span>
                                    </div>
                                    @if (! empty($env['memory_limit']))
                                        <div class="flex items-center justify-between">
                                            <span class="text-[var(--color-ink-muted)]">Memory Limit:</span>
                                            <span class="font-mono font-medium text-[var(--color-ink-strong)]">
                                                {{ $env['memory_limit'] }}
                                            </span>
                                        </div>
                                    @endif
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">WP-Cron:</span>
                                        <span class="font-medium {{ ! empty($env['wp_cron_disabled']) ? 'text-blue-600' : 'text-[var(--color-ink-strong)]' }}">
                                            {{ ! empty($env['wp_cron_disabled']) ? 'System Cron (WP-Cron Disabled)' : 'Default WP-Cron' }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {{-- Section 2: Database & Caching --}}
                            <div>
                                <h4 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-2 flex items-center gap-1.5">
                                    <i class="fa-solid fa-database text-[11px] text-[var(--color-ink-muted)]"></i> Database &amp; Caching
                                </h4>
                                <div class="p-3 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/40 space-y-2 text-xs">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">Database Version:</span>
                                        <span class="font-mono font-medium text-[var(--color-ink-strong)]">
                                            {{ $env['db_version'] ?? 'MySQL / MariaDB' }}
                                        </span>
                                    </div>
                                    @if (! empty($env['db_size_bytes']))
                                        <div class="flex items-center justify-between">
                                            <span class="text-[var(--color-ink-muted)]">Database Size:</span>
                                            <span class="font-mono font-medium text-[var(--color-ink-strong)]">
                                                {{ \Illuminate\Support\Number::fileSize($env['db_size_bytes'], precision: 1) }}
                                            </span>
                                        </div>
                                    @endif
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">Object Cache:</span>
                                        <span class="font-medium {{ ! empty($env['object_cache']) ? 'text-emerald-600' : 'text-[var(--color-ink-soft)]' }}">
                                            {{ ! empty($env['object_cache']) ? 'Active (Redis/Memcached)' : 'Inactive' }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {{-- Section 3: SSL / HTTPS Certificate --}}
                            <div>
                                <h4 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-2 flex items-center gap-1.5">
                                    <i class="fa-solid fa-shield-halved text-[11px] text-[var(--color-ink-muted)]"></i> SSL Certificate
                                </h4>
                                <div class="p-3 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/40 space-y-2 text-xs">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">Status:</span>
                                        <span class="status-pill {{ $certStatusClass }} text-[10px]">
                                            <span class="status-dot"></span> {{ $certStatusText }}
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">Source:</span>
                                        <span class="font-mono font-medium text-[var(--color-ink-strong)]">
                                            @if ($site->cert_source === 'spinupwp_le')
                                                SpinupWP / Let's Encrypt
                                            @elseif ($site->cert_source === 'external')
                                                External (3rd-party)
                                            @elseif ($site->cert_source === 'redirect_only')
                                                Redirect-only / parked
                                            @elseif ($site->isPressable())
                                                Pressable Managed SSL
                                            @else
                                                {{ $site->cert_source ?: 'None' }}
                                            @endif
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">Expires:</span>
                                        <span class="font-medium text-[var(--color-ink-strong)]">
                                            @if ($site->cert_expires_at)
                                                {{ $site->cert_expires_at->format('M j, Y H:i') }}
                                                <span class="text-[var(--color-ink-muted)]">({{ $site->cert_expires_at->diffForHumans() }})</span>
                                            @else
                                                —
                                            @endif
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">Renews:</span>
                                        <span class="font-medium text-[var(--color-ink-strong)]">
                                            @if ($site->cert_renews_at)
                                                {{ $site->cert_renews_at->format('M j, Y H:i') }}
                                                <span class="text-[var(--color-ink-muted)]">({{ $site->cert_renews_at->diffForHumans() }})</span>
                                            @else
                                                —
                                            @endif
                                        </span>
                                    </div>
                                    @if ($site->cert_notes)
                                        <div class="pt-1.5 border-t border-[var(--color-border-light)] text-[11px] text-[var(--color-ink-muted)]">
                                            <i class="fa-regular fa-note-sticky text-gray-400 mr-1"></i> {{ $site->cert_notes }}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Section 4: Host & Infrastructure --}}
                            <div>
                                <h4 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-2 flex items-center gap-1.5">
                                    <i class="fa-solid fa-network-wired text-[11px] text-[var(--color-ink-muted)]"></i> Host &amp; Infrastructure
                                </h4>
                                <div class="p-3 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/40 space-y-2 text-xs">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">Host Provider:</span>
                                        <span class="font-medium text-[var(--color-ink-strong)]">
                                            {{ $site->host()->label() }}
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">Server / Hostname:</span>
                                        <span class="font-mono font-medium text-[var(--color-ink-strong)]">
                                            {{ $site->server?->name ?? $site->custom_ip_address ?? '—' }}
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-[var(--color-ink-muted)]">Last Snapshot Sync:</span>
                                        <span class="text-[var(--color-ink-strong)]">
                                            {{ $site->companion_snapshot_at?->diffForHumans() ?? 'No snapshot recorded' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Modal Footer --}}
                        <div class="mt-6 pt-4 border-t border-[var(--color-border-light)] flex items-center justify-between">
                            <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'settings']) }}#cert-detail"
                               class="text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-brand)] flex items-center gap-1.5 transition-colors">
                                <i class="fa-solid fa-gear text-[10px]"></i> Open SSL Settings &rarr;
                            </a>
                            <button type="button"
                                    @click="envModalOpen = false"
                                    class="btn-primary text-xs py-1.5 px-4 cursor-pointer">
                                Done
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    @endif
</div>
