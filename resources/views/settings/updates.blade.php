@extends('layouts.app')

@section('title', 'System updates · Clockwork')

@section('content')
    <div x-data="{
        showConfirmModal: false,
        isUpdating: false,
        isChecking: false,
        copiedSnippet: false,
        copyText(text) {
            navigator.clipboard.writeText(text);
            this.copiedSnippet = true;
            setTimeout(() => this.copiedSnippet = false, 2000);
        }
    }">
        @include('settings._tabs')

        {{-- Standard Clockwork Page Header --}}
        <x-page-header title="Clockwork Updates"
            subtitle="Operator utilities for Clockwork Control Core, bundled modules, and Companion plugin fleet rollout. Updates are operator-triggered — nothing installs automatically.">
            <x-slot:actions>
                <form method="POST" action="{{ route('settings.updates.check') }}" @submit="isChecking = true" class="inline">
                    @csrf
                    <button type="submit"
                            :disabled="isChecking"
                            class="btn-pill-nav text-sm disabled:opacity-50"
                            title="Query upstream release channel for new versions">
                        <i class="fa-solid fa-rotate" :class="{ 'fa-spin': isChecking }"></i>
                        <span x-text="isChecking ? 'Checking…' : 'Check Again'">Check Again</span>
                    </button>
                </form>
                <a href="{{ route('settings.maintenance.backup') }}"
                   class="btn-pill-nav text-sm"
                   title="Download database snapshot before applying updates">
                    <i class="fa-solid fa-database text-[var(--color-ink-muted)]"></i>
                    <span>DB backup</span>
                </a>
            </x-slot:actions>
        </x-page-header>

        {{-- Status Flash Notifications --}}
        @if (session('status_update_ok'))
            <div class="mb-4 px-4 py-2.5 rounded-md bg-[var(--color-status-green)]/10 text-[var(--color-status-green)] text-sm flex items-center gap-2">
                <i class="fa-solid fa-circle-check"></i>
                <span>{{ session('status_update_ok') }}</span>
            </div>
        @endif
        @if (session('status_update_available'))
            <div class="mb-4 px-4 py-2.5 rounded-md bg-[var(--color-status-yellow)]/10 text-[var(--color-status-yellow)] text-sm flex items-center gap-2">
                <i class="fa-solid fa-arrow-up-from-bracket"></i>
                <span>{{ session('status_update_available') }}</span>
            </div>
        @endif
        @if (session('status_update_error'))
            <div class="mb-4 px-4 py-2.5 rounded-md bg-[var(--color-status-red)]/10 text-[var(--color-status-red)] text-sm flex items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>{{ session('status_update_error') }}</span>
            </div>
        @endif

        {{-- Log Output from Just-Completed or Last-Recorded Update --}}
        @php
            $displaySteps = session('update_steps') ?? ($lastApplyResult['steps'] ?? null);
            $isHistorical = ! session('update_steps') && ! empty($lastApplyResult);
        @endphp

        @if (! empty($displaySteps))
            <div class="card p-4 mb-6 border-l-4 {{ (! empty($lastApplyResult['success']) || session('status_update_ok')) ? 'border-[var(--color-primary-600)]' : 'border-[var(--color-status-red)]' }}">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] font-semibold">
                        {{ $isHistorical ? 'Last Update Execution Log' : 'Update Execution Summary' }}
                        @if ($isHistorical && ! empty($lastApplyResult['applied_at']))
                            <span class="text-[var(--color-ink-muted)] font-normal normal-case ml-2">
                                ({{ \Illuminate\Support\Carbon::parse($lastApplyResult['applied_at'])->diffForHumans() }} · v{{ $lastApplyResult['version'] ?? '' }})
                            </span>
                        @endif
                    </div>
                    @if ($isHistorical)
                        <span class="status-pill text-[10px] {{ (! empty($lastApplyResult['success'])) ? 'status-green' : 'status-red' }}">
                            {{ (! empty($lastApplyResult['success'])) ? 'succeeded' : 'failed' }}
                        </span>
                    @endif
                </div>
                @if (! empty($lastApplyResult['error']) && $isHistorical && empty($lastApplyResult['success']))
                    <div class="mb-3 px-3 py-2 rounded bg-[var(--color-status-red)]/10 text-[var(--color-status-red)] text-xs font-data">
                        {{ $lastApplyResult['error'] }}
                    </div>
                @endif
                <div class="space-y-2 text-xs font-data">
                    @foreach ($displaySteps as $step)
                        <div class="flex items-start gap-2">
                            <span class="{{ $step['success'] ? 'text-[var(--color-status-green)]' : 'text-[var(--color-status-red)]' }} font-bold">
                                {{ $step['success'] ? '✓' : '✗' }}
                            </span>
                            <div>
                                <span class="font-medium text-[var(--color-ink-strong)]">{{ $step['step'] }}</span>
                                @if (! empty($step['output']))
                                    <div class="text-[var(--color-ink-muted)] text-[11px] mt-0.5 whitespace-pre-wrap">{{ $step['output'] }}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Standard Roll-up Tiles --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">App Version</div>
                <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">v{{ $updateInfo['current_version'] }}</div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">
                    @if ($updateInfo['has_update'])
                        <span class="text-[var(--color-status-yellow)] font-semibold">v{{ $updateInfo['latest_version'] }} available</span>
                    @else
                        <span class="text-[var(--color-status-green)] font-semibold">Up to date</span>
                    @endif
                </div>
            </div>

            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Bundled Companion</div>
                <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">v{{ $companionStatus['bundled_version'] }}</div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">For WordPress fleet</div>
            </div>

            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Older Version Pending</div>
                <div class="text-2xl font-display {{ $companionStatus['needs_update'] > 0 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-status-green)]' }} font-data">
                    {{ $companionStatus['needs_update'] }}
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">
                    @if ($companionStatus['needs_update'] > 0)
                        Sites need companion update
                    @else
                        All monitored sites current
                    @endif
                </div>
            </div>

            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Last Checked</div>
                <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">
                    @if ($lastCheckedAt)
                        {{ $lastCheckedAt->diffForHumans(null, true) }}
                    @else
                        Never
                    @endif
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">
                    @if ($lastCheckedAt)
                        {{ $lastCheckedAt->format('M j, Y H:i') }}
                    @else
                        Click check for updates
                    @endif
                </div>
            </div>
        </div>

        {{-- Section 1: Clockwork Control Core --}}
        <div class="card p-6 mb-6 max-w-5xl">
            <div class="flex items-start justify-between gap-4 mb-4 flex-wrap">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1 flex items-center gap-2">
                        <i class="fa-solid fa-cube text-[var(--color-ink-muted)]"></i>
                        Clockwork Control Core
                    </h2>
                    <div class="text-xs text-[var(--color-ink-muted)] font-data">
                        Installed: <strong>v{{ $updateInfo['current_version'] }}</strong>
                        @if ($updateInfo['git']['is_git'])
                            · git <code class="font-data">{{ $updateInfo['git']['branch'] }}@<span class="font-semibold">{{ $updateInfo['git']['commit'] }}</span></code>
                            @if (! $updateInfo['git']['is_clean'])
                                · <span class="text-[var(--color-status-yellow)] font-semibold"><i class="fa-solid fa-triangle-exclamation"></i> working copy modified</span>
                            @endif
                        @endif
                        · channel: {{ $updateInfo['channel'] }}
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    @if ($updateInfo['has_update'] && auth()->user()?->isAdmin())
                        <button type="button"
                                @click="showConfirmModal = true"
                                class="px-4 py-2 rounded-md bg-[var(--color-primary-600)] text-white text-sm font-medium hover:bg-[var(--color-primary-700)] inline-flex items-center gap-2">
                            <i class="fa-solid fa-download"></i> Update to v{{ $updateInfo['latest_version'] }}
                        </button>
                    @elseif (! $updateInfo['has_update'])
                        <span class="status-pill status-green">
                            <span class="status-dot"></span> Up to date (v{{ $updateInfo['current_version'] }})
                        </span>
                    @endif
                </div>
            </div>

            @if ($updateInfo['has_update'])
                <div class="mb-4 p-4 rounded-lg bg-[var(--color-status-yellow-bg)] border border-[var(--color-status-yellow)]/30 text-sm">
                    <div class="font-semibold text-[var(--color-ink-strong)] mb-1 flex items-center justify-between flex-wrap gap-2">
                        <span>An updated version of Clockwork Control is available! (<strong>v{{ $updateInfo['latest_version'] }}</strong>)</span>
                        @if ($updateInfo['html_url'])
                            <a href="{{ $updateInfo['html_url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs text-[var(--color-primary-600)] underline font-normal">
                                View release on GitHub &rarr;
                            </a>
                        @endif
                    </div>
                    @if (! empty($updateInfo['release_name']))
                        <div class="text-xs text-[var(--color-ink-muted)] mb-2 font-medium">
                            {{ $updateInfo['release_name'] }}
                            @if ($updateInfo['published_at'])
                                · Released {{ $updateInfo['published_at']->format('M j, Y') }}
                            @endif
                        </div>
                    @endif
                    @if (! empty($updateInfo['release_notes']))
                        <div class="mt-2 text-xs text-[var(--color-ink-muted)] font-sans max-h-48 overflow-y-auto whitespace-pre-wrap leading-relaxed border-t border-[var(--color-status-yellow)]/20 pt-2">
                            {{ $updateInfo['release_notes'] }}
                        </div>
                    @endif
                </div>
            @else
                <div class="text-sm text-[var(--color-ink-muted)] space-y-2 mb-4">
                    <p>
                        You have the latest version of Clockwork Control. All core modules, database schemas, and background tasks are running the latest release.
                    </p>
                </div>
            @endif

            <div class="text-xs text-[var(--color-ink-muted)] space-y-1 pt-3 border-t border-[var(--color-border-light)]">
                <div class="flex items-center justify-between gap-2 flex-wrap">
                    <span class="text-[var(--color-ink-soft)]">
                        Manual upgrade command:
                        <code class="font-data bg-[var(--color-surface-alt)] px-1.5 py-0.5 rounded text-[var(--color-ink-strong)] select-all ml-1">git pull origin main && composer install --no-dev && php artisan migrate --force && php artisan optimize:clear</code>
                    </span>
                    <button type="button"
                            @click="copyText('git pull origin main && composer install --no-dev && php artisan migrate --force && php artisan optimize:clear')"
                            class="text-xs text-[var(--color-primary-600)] hover:underline font-medium inline-flex items-center gap-1">
                        <i class="fa-regular fa-copy"></i>
                        <span x-text="copiedSnippet ? 'Copied!' : 'Copy command'">Copy command</span>
                    </button>
                </div>
            </div>

            <div class="mt-4 pt-4 border-t border-[var(--color-border-light)] text-xs text-[var(--color-ink-soft)] flex items-start gap-2">
                <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-yellow)] mt-0.5 flex-shrink-0"></i>
                <div>
                    <strong class="text-[var(--color-ink-strong)]">Pre-update recommendation.</strong>
                    Before updating, please ensure you have downloaded a database backup. Encrypted SSH keys, site tokens, and configuration live in your database.
                    @if (auth()->user()?->isAdmin())
                    <a href="{{ route('settings.maintenance.backup') }}" class="text-[var(--color-primary-600)] underline ml-1 font-medium">Download backup snapshot &rarr;</a>
                    @endif
                </div>
            </div>
        </div>

        {{-- Section 2: Clockwork Companion Plugin (Fleet) --}}
        <div class="card p-6 mb-6 max-w-5xl">
            <div class="flex items-start justify-between gap-4 mb-4 flex-wrap">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1 flex items-center gap-2">
                        <i class="fa-brands fa-wordpress text-[var(--color-ink-muted)]"></i>
                        Clockwork Companion Plugin (Fleet)
                    </h2>
                    <div class="text-xs text-[var(--color-ink-muted)] font-data">
                        Bundled version: <strong>v{{ $companionStatus['bundled_version'] }}</strong>
                        · {{ $companionStatus['installed_sites'] }} of {{ $companionStatus['total_sites'] }} monitored sites have Companion installed
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    @if ($companionStatus['needs_update'] > 0)
                        <span class="status-pill status-yellow">
                            <span class="status-dot"></span> {{ $companionStatus['needs_update'] }} sites need update
                        </span>
                    @else
                        <span class="status-pill status-green">
                            <span class="status-dot"></span> Fleet up to date
                        </span>
                    @endif
                </div>
            </div>

            <div class="text-sm text-[var(--color-ink-muted)] space-y-2 mb-4">
                <p>
                    The client WordPress mu-plugin runs on each monitored site, providing signed REST endpoints for remote health, security probes, and update checks.
                </p>
                <p>
                    Deploying updates to client sites is always operator-triggered. Run canary deployments first on non-production sites before rolling out to the full fleet.
                </p>
            </div>

            <div class="flex items-center justify-between gap-4 pt-3 border-t border-[var(--color-border-light)] flex-wrap text-xs">
                <div class="text-[var(--color-ink-soft)]">
                    CLI deploy command:
                    <code class="font-data bg-[var(--color-surface-alt)] px-1.5 py-0.5 rounded text-[var(--color-ink-strong)] select-all ml-1">php artisan clockwork:companion-fleet-deploy</code>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('updates.index') }}" class="btn-pill-nav text-xs">
                        <i class="fa-solid fa-list-check"></i> Fleet WordPress updates
                    </a>
                    <a href="{{ route('docs.index') }}" class="btn-pill-nav text-xs">
                        <i class="fa-solid fa-book"></i> Deploy documentation
                    </a>
                </div>
            </div>
        </div>

        {{-- Section 3: Module Directory Catalog --}}
        <div class="card p-6 mb-6 max-w-5xl">
            <div class="flex items-start justify-between gap-4 mb-4 flex-wrap">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1 flex items-center gap-2">
                        <i class="fa-solid fa-puzzle-piece text-[var(--color-ink-muted)]"></i>
                        Module Directory Catalog
                    </h2>
                    <div class="text-xs text-[var(--color-ink-muted)] font-data">
                        Third-party and community integrations catalog indexed from clockworkcontrol.com
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <form method="POST" action="{{ route('settings.modules.refresh') }}" class="inline">
                        @csrf
                        <button type="submit" class="btn-pill-nav text-xs">
                            <i class="fa-solid fa-rotate"></i> Check catalog updates
                        </button>
                    </form>
                    <a href="{{ route('settings.modules.index') }}" class="px-4 py-2 rounded-md bg-[var(--color-primary-600)] text-white text-xs font-medium hover:bg-[var(--color-primary-700)] inline-flex items-center gap-1.5">
                        <span>Browse directory</span> &rarr;
                    </a>
                </div>
            </div>

            <div class="text-sm text-[var(--color-ink-muted)] space-y-2">
                <p>
                    Official bundled modules (SpinupWP, Pressable, Hetzner, Azure, Cloudways, etc.) update together with Clockwork Control Core. Third-party modules follow independent Semantic Versioning.
                </p>
            </div>
        </div>

        {{-- Operator Confirmation Modal for Core Self-Update --}}
        @if (auth()->user()?->isAdmin())
        <div x-show="showConfirmModal"
             x-cloak
             class="fixed inset-0 z-50 overflow-y-auto bg-black/40 flex items-center justify-center p-4"
             @keydown.escape.window="showConfirmModal = false">
            <div class="card max-w-lg w-full p-6 shadow-xl relative"
                 @click.away="showConfirmModal = false">
                <div class="flex items-start gap-3 mb-4">
                    <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-yellow)] text-xl mt-0.5"></i>
                    <div>
                        <h3 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                            Confirm System Update
                        </h3>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5 font-data">
                            Update Clockwork Control to <strong>v{{ $updateInfo['latest_version'] }}</strong>
                        </p>
                    </div>
                </div>

                <div class="text-xs text-[var(--color-ink-muted)] space-y-2 mb-6 p-3 rounded bg-[var(--color-surface-alt)] font-data leading-relaxed">
                    <div class="font-semibold text-[var(--color-ink-strong)]">This operator action will:</div>
                    <ul class="list-disc pl-4 space-y-1 text-[var(--color-ink-muted)]">
                        <li>Pull latest release from git</li>
                        <li>Update application files & dependencies</li>
                        <li>Run database migrations (<code class="font-data text-[10px]">php artisan migrate --force</code>)</li>
                        <li>Clear and rebuild system caches</li>
                    </ul>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <button type="button"
                            @click="showConfirmModal = false"
                            class="px-4 py-2 rounded-md border border-[var(--color-border)] text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                        Cancel
                    </button>

                    <form method="POST" action="{{ route('settings.updates.apply') }}" @submit="isUpdating = true">
                        @csrf
                        <button type="submit"
                                :disabled="isUpdating"
                                class="px-4 py-2 rounded-md bg-[var(--color-primary-600)] text-white text-sm font-medium hover:bg-[var(--color-primary-700)] inline-flex items-center gap-2 disabled:opacity-50">
                            <i class="fa-solid fa-rotate" :class="{ 'fa-spin': isUpdating }"></i>
                            <span x-text="isUpdating ? 'Updating…' : 'Update now'">Update now</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @endif
    </div>
@endsection
