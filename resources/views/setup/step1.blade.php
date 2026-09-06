@extends('layouts.app')

@section('title', 'Which services are you using? · Setup · Clockwork Control')

@section('content')
    <div class="max-w-7xl mx-auto pb-28" x-data="serviceLimitsModal()">
        <!-- Progress & Header -->
        <div class="mb-3">
            <span class="status-pill status-green text-xs font-semibold uppercase tracking-wider">
                <span class="status-dot"></span>
                <span>Fleet Integrations Setup</span>
            </span>
        </div>
        <x-page-header title="Which services are you using?"
            subtitle="Select the hosting providers, server panels, and tools your fleet depends on. Click the settings cog on any service to paste API keys directly into .env and tune rate limits." />

        <form method="POST" action="{{ route('setup.step1.save') }}" id="services-form">
            @csrf

            @foreach ($categories as $categoryKey => $cat)
                @if (count($cat['services']) > 0)
                    @if (!$loop->first)
                        <div style="padding-top: 1.25rem; padding-bottom: 1.25rem;">
                            <hr class="border-t border-[var(--color-border)] opacity-60">
                        </div>
                    @endif

                    <section>
                        <div class="mb-6 flex items-baseline justify-between flex-wrap gap-3">
                            <div class="max-w-4xl">
                                <div class="flex items-center gap-2.5 flex-wrap">
                                    <h2 class="text-2xl font-display font-bold text-[var(--color-ink-strong)] tracking-tight">
                                        {{ $cat['title'] }}
                                    </h2>
                                    @if (!empty($cat['badge']))
                                        @php
                                            $badgeStyles = [
                                                'emerald' => 'status-pill status-green',
                                                'blue' => 'status-pill status-cf',
                                                'indigo' => 'status-pill status-cf',
                                                'cyan' => 'status-pill status-cf',
                                                'rose' => 'status-pill status-red',
                                                'purple' => 'status-pill status-cf',
                                                'amber' => 'status-pill status-yellow',
                                                'slate' => 'status-pill status-unknown',
                                            ];
                                            $badgeClass = $badgeStyles[$cat['badge_color'] ?? 'blue'] ?? $badgeStyles['blue'];
                                        @endphp
                                        <span class="{{ $badgeClass }} text-[11px]">
                                            <span class="status-dot"></span>
                                            {{ $cat['badge'] }}
                                        </span>
                                    @elseif (!empty($cat['is_fleet_source']))
                                        <span class="status-pill status-green text-[11px]">
                                            <span class="status-dot"></span>
                                            Fleet Source &middot; Required
                                        </span>
                                    @endif
                                </div>
                                <p class="text-xs sm:text-sm text-[var(--color-ink-muted)] mt-1.5 leading-relaxed">
                                    {{ $cat['description'] }}
                                </p>
                            </div>
                            <span class="text-xs font-data text-[var(--color-ink-soft)] bg-[var(--color-surface-alt)] px-3 py-1 rounded-full border border-[var(--color-border-light)] self-start sm:self-auto">
                                {{ count($cat['services']) }} {{ count($cat['services']) === 1 ? 'integration' : 'integrations' }}
                            </span>
                        </div>

                        @if (!empty($cat['is_fleet_source']) || $categoryKey === 'hosting')
                            <!-- Fleet Source Prerequisite Banner (Styled consistently with guidance cards) -->
                            <div id="fleet-source-card" class="mb-6 p-4 rounded-xl border transition-all duration-200 bg-emerald-50/90 border-emerald-300 text-slate-800 shadow-xs">
                                <div class="flex items-start gap-3.5">
                                    <div id="fleet-source-icon-box" class="w-9 h-9 rounded-lg bg-emerald-500/15 text-emerald-700 flex items-center justify-center flex-shrink-0 text-base mt-0.5 border border-emerald-300/50">
                                        <i id="fleet-source-icon" class="fa-solid fa-circle-check"></i>
                                    </div>
                                    <div class="text-xs sm:text-sm leading-relaxed flex-1">
                                        <span id="fleet-source-title" class="font-bold block mb-0.5 text-emerald-950 text-sm">Fleet Source Active:</span>
                                        <div id="fleet-source-text" class="text-slate-800 leading-normal">
                                            To monitor your fleet, Clockwork Control needs at least one <strong>Managed WordPress Host</strong> (Pressable, WP Engine, Kinsta) or <strong>Server Management Panel</strong> (SpinupWP, Cloudways, GridPane) to import sites and servers. If you manage servers via SpinupWP, Cloudways, or GridPane, pairing with your <strong>Cloud VPS</strong> provider unlocks 5-minute CPU, RAM, and droplet telemetry.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if ($categoryKey === 'cloud_vps')
                            <div id="vps-guidance-card" class="mb-6 p-4 rounded-xl border transition-all duration-200 bg-blue-50/80 border-blue-200/90 text-slate-800 shadow-xs">
                                <div class="flex items-start gap-3.5">
                                    <div id="vps-guidance-icon-box" class="w-9 h-9 rounded-lg bg-blue-500/15 text-blue-700 flex items-center justify-center flex-shrink-0 text-base mt-0.5 border border-blue-300/40">
                                        <i id="vps-guidance-icon" class="fa-solid fa-microchip"></i>
                                    </div>
                                    <div class="text-xs sm:text-sm leading-relaxed flex-1">
                                        <span id="vps-guidance-title" class="font-bold block mb-0.5 text-blue-950 text-sm">Hardware Telemetry Pairing:</span>
                                        <div id="vps-guidance-text" class="text-slate-800 leading-normal">
                                            If you use <strong>SpinupWP</strong>, <strong>Cloudways</strong>, or <strong>GridPane</strong>, enable your server's cloud provider (e.g. DigitalOcean, Hetzner, Azure) to unlock 5-minute CPU, RAM, and droplet health monitoring.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-5" style="display: grid; gap: 1.25rem;">
                            @foreach ($cat['services'] as $service)
                                @php
                                    $isActive = (bool) $service['enabled'];
                                    $isConfigured = (bool) ($service['is_configured'] ?? false);

                                    if ($isActive) {
                                        if ($isConfigured) {
                                            // Turned ON + Integrated properly: soft green gradient with lighter green 1px border
                                            $cardStateClasses = 'border border-emerald-500 bg-gradient-to-r from-emerald-50/75 via-emerald-50/30 to-teal-50/40 shadow-xs';
                                        } else {
                                            // Turned ON + NOT configured: slightly red background/gradient with solid red 1px border
                                            $cardStateClasses = 'border border-rose-600 bg-gradient-to-r from-rose-50/90 via-rose-50/40 to-red-50/60 shadow-xs';
                                        }
                                    } else {
                                        // Turned OFF: clean theme-aware surface with subtle border
                                        $cardStateClasses = 'border border-[var(--color-border-light)] bg-[var(--color-surface)] shadow-xs';
                                    }
                                @endphp
                                <div class="service-row flex items-center justify-between transition-all duration-200 {{ $cardStateClasses }}"
                                     style="padding: 1.125rem 1.25rem; border-radius: 0.75rem;"
                                     data-service-id="{{ $service['id'] }}"
                                     data-configured="{{ $isConfigured ? 'true' : 'false' }}">

                                    <!-- Left: Logo & Info (No grey background on logo) -->
                                    <div class="flex items-center gap-3.5 min-w-0 pr-2">
                                        <!-- Logo with transparent background -->
                                        <div class="w-10 h-10 flex items-center justify-center flex-shrink-0">
                                            <x-service-logo :service="$service['id']" class="w-8 h-8" />
                                        </div>

                                        <!-- Title to the right of logo -->
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <span class="font-display font-bold text-base text-[var(--color-ink-strong)] leading-tight">
                                                    {{ $service['name'] }}
                                                </span>

                                                @if (!empty($service['in_use_reason']) && !str_contains($service['in_use_reason'], 'Credentials configured') && !str_contains($service['in_use_reason'], 'scans recorded') && !str_contains($service['in_use_reason'], 'tests configured'))
                                                    <span class="status-pill status-green text-[10px] font-mono">
                                                        <span class="status-dot"></span>
                                                        {!! $service['in_use_reason'] !!}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Right: Beaker Icon (if testing needed) + Cog Button + Pure Red / Green Toggle -->
                                    <div class="flex items-center gap-2 flex-shrink-0 ml-3">
                                        @if (($service['status'] ?? 'verified') === 'looking_for_testers')
                                            <div x-data="{ showTooltip: false }" class="relative inline-flex items-center">
                                                <span @mouseenter="showTooltip = true"
                                                      @mouseleave="showTooltip = false"
                                                      @focus="showTooltip = true"
                                                      @blur="showTooltip = false"
                                                      class="text-amber-500 hover:text-amber-600 transition-colors cursor-help flex items-center justify-center p-1"
                                                      title="Looking for Testers"
                                                      aria-label="Looking for Testers">
                                                    <i class="fa-solid fa-flask text-sm"></i>
                                                </span>
                                                <div x-show="showTooltip"
                                                     x-cloak
                                                     x-transition:enter="transition ease-out duration-150"
                                                     x-transition:enter-start="opacity-0 translate-y-1"
                                                     x-transition:enter-end="opacity-100 translate-y-0"
                                                     x-transition:leave="transition ease-in duration-100"
                                                     x-transition:leave-start="opacity-100 translate-y-0"
                                                     x-transition:leave-end="opacity-0 translate-y-1"
                                                     class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-2.5 py-1 bg-neutral-900 text-white text-[11px] font-medium rounded-md shadow-xl whitespace-nowrap z-30 pointer-events-none tracking-normal">
                                                    Looking for Testers
                                                    <div class="absolute top-full left-1/2 -translate-x-1/2 -mt-0.5 border-4 border-transparent border-t-neutral-900"></div>
                                                </div>
                                            </div>
                                        @endif

                                        <button type="button"
                                                @click="openModal('{{ $service['id'] }}', '{{ addslashes($service['name']) }}')"
                                                class="w-8 h-8 rounded-lg flex items-center justify-center text-[var(--color-ink-soft)] hover:text-[var(--color-primary-600)] hover:bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] transition-colors cursor-pointer shadow-2xs"
                                                title="{{ $service['name'] }} API Limits &amp; Settings"
                                                aria-label="{{ $service['name'] }} API Limits &amp; Settings">
                                            <i class="fa-solid fa-gear text-sm"></i>
                                        </button>

                                        <label for="toggle-{{ $service['id'] }}" class="relative inline-flex items-center cursor-pointer select-none">
                                            <input type="checkbox"
                                                   name="services[]"
                                                   id="toggle-{{ $service['id'] }}"
                                                   value="{{ $service['id'] }}"
                                                   class="sr-only service-toggle"
                                                   @checked($isActive)>

                                            <!-- Toggle Track (Green when active, Red when inactive) -->
                                            <div class="toggle-track w-14 h-7 rounded-full transition-colors duration-200 ease-in-out p-1 flex items-center {{ $isActive ? 'bg-emerald-600 justify-end' : 'bg-rose-600 justify-start' }}">
                                                <!-- Toggle Knob with icon -->
                                                <div class="toggle-knob w-5 h-5 bg-white rounded-full shadow-md transition-transform flex items-center justify-center text-[10px] font-bold {{ $isActive ? 'text-emerald-600' : 'text-rose-600' }}">
                                                    <i class="fa-solid {{ $isActive ? 'fa-check' : 'fa-xmark' }}"></i>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            @endforeach

            <div style="padding-top: 1.25rem; padding-bottom: 1.25rem;">
                <hr class="border-t border-[var(--color-border)] opacity-60">
            </div>

            <!-- Action Bar -->
            <div class="sticky bottom-4 z-20 card p-4 flex items-center justify-between flex-wrap gap-4 shadow-xl border-2 border-[var(--color-primary-500)]/40 bg-[var(--color-surface)]">
                <div class="flex items-center gap-3 flex-wrap">
                    <span class="status-pill status-green text-xs font-semibold">
                        <span id="active-counter">0</span> active services
                    </span>
                    <span id="fleet-source-pill" class="status-pill status-yellow text-xs font-semibold">
                        <span id="fleet-source-pill-content">0 fleet sources</span>
                    </span>
                    <span id="action-hint-text" class="text-xs text-[var(--color-ink-muted)] hidden md:inline">
                        Click any service cog to configure API keys &amp; limits.
                    </span>
                </div>
                <div class="flex items-center gap-3">
                    <button type="submit" id="btn-continue-step1" class="btn-primary flex items-center gap-2 text-sm px-6 py-2.5">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Finish Setup &amp; Go to Dashboard</span>
                    </button>
                </div>
            </div>
        </form>

        {{-- Modal: Service API Rate Limits & Settings --}}
        <div x-show="showLimitsModal"
             x-cloak
             @keydown.escape.window="showLimitsModal = false"
             class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-6 bg-black/50 backdrop-blur-xs overflow-y-auto"
             @click.self="showLimitsModal = false"
             role="dialog"
             aria-modal="true">
            <div class="bg-[var(--color-surface)] rounded-[var(--radius-card)] shadow-2xl max-w-2xl w-full my-8 border border-[var(--color-border)] overflow-hidden"
                 @click.stop>
                
                <!-- Modal Header -->
                <div class="px-6 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between gap-4 bg-[var(--color-surface)] sticky top-0 z-10">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-[var(--color-surface-alt)] flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-gear text-[var(--color-primary-600)] text-base"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="font-display font-bold text-base sm:text-lg text-[var(--color-ink-strong)]" x-text="serviceName + ' API Limits & Settings'"></h3>
                                <template x-if="service">
                                    <span class="status-pill status-blue text-[10px] font-mono" x-text="service.category"></span>
                                </template>
                            </div>
                            <p class="text-xs text-[var(--color-ink-muted)]">Official vendor rate limits, headers, and client connection tuning</p>
                        </div>
                    </div>
                    <button type="button" @click="showLimitsModal = false" class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] text-2xl leading-none p-1 cursor-pointer" aria-label="Close">×</button>
                </div>

                <!-- Modal Body -->
                <div class="p-6 space-y-5 text-sm text-[var(--color-ink-muted)] leading-relaxed text-left">
                    
                    <!-- Loading state -->
                    <div x-show="loading" class="py-12 text-center text-[var(--color-ink-muted)]">
                        <i class="fa-solid fa-circle-notch fa-spin text-2xl text-[var(--color-primary-600)] mb-2"></i>
                        <p class="text-xs">Loading rate limits and documentation...</p>
                    </div>

                    <!-- Error state -->
                    <div x-show="errorMessage && !loading" class="p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2">
                        <i class="fa-solid fa-circle-exclamation text-rose-600"></i>
                        <span x-text="errorMessage"></span>
                    </div>

                    <!-- Success banner -->
                    <div x-show="saveSuccess" x-transition class="p-3.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center gap-2">
                        <i class="fa-solid fa-circle-check text-emerald-600"></i>
                        <span x-text="successMessage || 'Settings updated successfully! Credentials saved directly to your .env file.'"></span>
                    </div>

                    <div x-show="!loading && service" class="space-y-5">
                        <!-- API Credentials (.env file) Box -->
                        <div class="p-4 rounded-xl bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] space-y-3">
                            <div class="flex items-center justify-between flex-wrap gap-2">
                                <div class="text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider flex items-center gap-1.5">
                                    <i class="fa-solid fa-key text-[var(--color-primary-600)]"></i>
                                    <span>API Credentials (.env file)</span>
                                </div>
                                <span class="text-[11px] text-[var(--color-ink-soft)]">Saved directly into root <code class="font-data text-[10px]">.env</code> &bull; No DB storage</span>
                            </div>

                            <template x-if="credentials && credentials.length > 0">
                                <div class="space-y-3 pt-1">
                                    <template x-for="cred in credentials" :key="cred.field">
                                        <div class="p-3.5 bg-[var(--color-surface-alt)]/60 rounded-lg border border-[var(--color-border-light)] space-y-2">
                                            <div class="flex items-center justify-between flex-wrap gap-2">
                                                <div>
                                                    <span class="text-xs font-bold text-[var(--color-ink-strong)]" x-text="cred.label"></span>
                                                    <code class="font-data text-[10px] text-[var(--color-ink-soft)] ml-1.5 px-1.5 py-0.5 rounded bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]" x-text="cred.env_var"></code>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <template x-if="cred.configured">
                                                        <span class="status-pill status-green text-[10px] font-mono">
                                                            <i class="fa-solid fa-check"></i> Set in .env
                                                        </span>
                                                    </template>
                                                    <template x-if="!cred.configured">
                                                        <span class="status-pill status-unknown text-[10px] font-mono">
                                                            Not set
                                                        </span>
                                                    </template>

                                                    <template x-if="cred.configured">
                                                        <button type="button"
                                                                @click="removeCredential(cred.field, cred.label)"
                                                                class="text-rose-600 hover:text-rose-700 hover:bg-rose-50 px-2 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer border border-rose-200 flex items-center gap-1"
                                                                title="Remove this key from your .env file">
                                                            <i class="fa-solid fa-trash-can text-[10px]"></i>
                                                            <span>Remove from .env</span>
                                                        </button>
                                                    </template>
                                                </div>
                                            </div>

                                            <div class="relative">
                                                <input :type="cred.showPlain ? 'text' : (cred.secret ? 'password' : 'text')"
                                                       x-model="credentialsPayload[cred.field]"
                                                       :placeholder="cred.configured ? 'Currently set in .env (' + (cred.preview || 'configured') + ') — paste new value to replace' : 'Paste ' + cred.label + ' here'"
                                                       class="w-full font-data text-xs text-[var(--color-ink-strong)] border border-[var(--color-border)] rounded-md px-3 py-2 bg-[var(--color-surface)] focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)] pr-10">
                                                <template x-if="cred.secret">
                                                    <button type="button"
                                                            @click="cred.showPlain = !cred.showPlain"
                                                            class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] p-1 text-xs cursor-pointer"
                                                            :title="cred.showPlain ? 'Hide secret' : 'Show plain text'">
                                                        <i class="fa-solid" :class="cred.showPlain ? 'fa-eye-slash' : 'fa-eye'"></i>
                                                    </button>
                                                </template>
                                            </div>

                                            <div class="flex items-center justify-between text-[11px] text-[var(--color-ink-soft)] flex-wrap gap-1">
                                                <span x-text="cred.guide"></span>
                                                <template x-if="cred.url">
                                                    <a :href="cred.url" target="_blank" rel="noopener noreferrer" class="text-[var(--color-primary-600)] hover:underline flex items-center gap-1 font-medium">
                                                        <span>Get API Key</span>
                                                        <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
                                                    </a>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Test Connection Bar with Live Results -->
                                    <template x-if="testable">
                                        <div class="pt-2.5 border-t border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-2">
                                            <div class="text-xs flex-1 min-w-0 pr-2">
                                                <template x-if="testResult">
                                                    <div class="p-2.5 rounded-lg text-xs flex items-start gap-2.5 transition-all shadow-2xs"
                                                         :class="{
                                                             'bg-emerald-50/90 border border-emerald-300/80 text-emerald-900': testResult.status === 'ok',
                                                             'bg-rose-50/90 border border-rose-300/80 text-rose-900': testResult.status === 'fail',
                                                             'bg-amber-50/90 border border-amber-300/80 text-amber-900': testResult.status === 'skipped'
                                                         }">
                                                        <i class="fa-solid mt-0.5 flex-shrink-0 text-sm"
                                                           :class="{
                                                               'fa-circle-check text-emerald-600': testResult.status === 'ok',
                                                               'fa-circle-xmark text-rose-600': testResult.status === 'fail',
                                                               'fa-triangle-exclamation text-amber-600': testResult.status === 'skipped'
                                                           }"></i>
                                                        <div class="min-w-0 flex-1">
                                                            <div class="font-semibold flex items-center gap-2 flex-wrap text-xs">
                                                                <span x-text="testResult.status === 'ok' ? 'API Connected &amp; Verified' : (testResult.status === 'fail' ? 'Connection Test Failed' : 'Notice')"></span>
                                                                <template x-if="testResult.duration_ms !== null && testResult.duration_ms !== undefined">
                                                                    <span class="font-mono text-[10px] px-1.5 py-0.2 rounded bg-black/5 font-normal" x-text="testResult.duration_ms + 'ms'"></span>
                                                                </template>
                                                            </div>
                                                            <p class="text-[11px] leading-relaxed mt-0.5 opacity-90 break-words" x-text="testResult.summary"></p>
                                                            <template x-if="testResult.detail">
                                                                <p class="font-mono text-[10px] mt-1.5 p-1.5 bg-black/5 rounded text-left overflow-x-auto leading-normal" x-text="testResult.detail"></p>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </template>
                                                <template x-if="!testResult && !testing">
                                                    <span class="text-[11px] text-[var(--color-ink-soft)]">
                                                        Test live connection with credentials to verify vendor API access.
                                                    </span>
                                                </template>
                                                <template x-if="testing">
                                                    <span class="text-[11px] text-[var(--color-primary-600)] flex items-center gap-1.5 font-medium">
                                                        <i class="fa-solid fa-circle-notch fa-spin"></i>
                                                        <span>Connecting &amp; testing <span x-text="serviceName"></span> API...</span>
                                                    </span>
                                                </template>
                                            </div>

                                            <button type="button"
                                                    @click="testConnection()"
                                                    :disabled="testing || saving || loading"
                                                    class="btn-pill-nav text-xs font-semibold text-[var(--color-ink-strong)] hover:text-[var(--color-primary-600)] hover:bg-[var(--color-surface-alt)] border border-[var(--color-border)] shadow-2xs flex items-center gap-1.5 px-3 py-1.5 cursor-pointer disabled:opacity-50">
                                                <template x-if="testing">
                                                    <i class="fa-solid fa-circle-notch fa-spin text-[var(--color-primary-600)]"></i>
                                                </template>
                                                <template x-if="!testing">
                                                    <i class="fa-solid fa-bolt text-amber-500"></i>
                                                </template>
                                                <span x-text="testing ? 'Testing...' : 'Test Connection'"></span>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <template x-if="!credentials || credentials.length === 0">
                                <div class="p-3 bg-[var(--color-surface-alt)]/60 rounded-lg border border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)] flex items-center gap-2">
                                    <i class="fa-solid fa-circle-info text-[var(--color-primary-600)]"></i>
                                    <span>Zero credentials required — this service operates publicly without an API key.</span>
                                </div>
                            </template>
                        </div>

                        <!-- Official Rate Limit Specifications Box -->
                        <div class="p-4 rounded-xl bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] space-y-3">
                            <div class="flex items-center justify-between flex-wrap gap-2">
                                <div class="text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider flex items-center gap-1.5">
                                    <i class="fa-solid fa-gauge-high text-[var(--color-primary-600)]"></i>
                                    <span>Official Vendor Rate Limits</span>
                                </div>
                                <span class="status-pill status-unknown text-[10px] font-mono" x-text="'HTTP ' + (service?.official_limits?.exceeded_code || '429')"></span>
                            </div>

                            <div>
                                <div class="font-display font-bold text-base text-[var(--color-ink-strong)]" x-text="service?.official_limits?.standard"></div>
                                <p class="text-xs text-[var(--color-ink-muted)] mt-0.5" x-text="service?.official_limits?.window"></p>
                            </div>

                            <div class="pt-2 border-t border-[var(--color-border-light)]">
                                <span class="text-[11px] font-semibold text-[var(--color-ink-strong)] block mb-1">Response Headers Monitored:</span>
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="h in (service?.official_limits?.headers || [])" :key="h">
                                        <span class="font-data text-[11px] px-2 py-0.5 rounded bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-[var(--color-ink-strong)] font-semibold" x-text="h"></span>
                                    </template>
                                </div>
                            </div>

                            <div class="pt-2 border-t border-[var(--color-border-light)] text-xs">
                                <span class="text-[11px] font-semibold text-[var(--color-ink-strong)] block mb-0.5">Burst Notes:</span>
                                <p class="text-[11px] text-[var(--color-ink-muted)] leading-normal" x-text="service?.official_limits?.burst_notes"></p>
                            </div>
                        </div>

                        <!-- Fleet Impact Card -->
                        <div class="p-4 rounded-xl border border-blue-200/80 bg-blue-50/60 dark:border-blue-800/60 dark:bg-blue-950/30 text-slate-800 dark:text-slate-200 space-y-2">
                            <div class="text-xs font-bold text-blue-950 dark:text-blue-300 flex items-center gap-1.5">
                                <i class="fa-solid fa-network-wired text-blue-600 dark:text-blue-400"></i>
                                <span>Fleet Polling Impact &amp; Pacing</span>
                            </div>
                            <div class="text-xs leading-relaxed text-slate-800 dark:text-slate-200">
                                <p class="mb-1" x-text="service?.fleet_impact?.calls_per_server"></p>
                                <p class="text-[11px] text-slate-600 dark:text-slate-400" x-text="service?.fleet_impact?.fleet_projection"></p>
                            </div>
                            <div class="pt-2 border-t border-blue-200/60 dark:border-blue-800/60 text-[11px] text-emerald-800 dark:text-emerald-300 font-medium flex items-start gap-1">
                                <i class="fa-solid fa-lightbulb text-emerald-600 dark:text-emerald-400 mt-0.5"></i>
                                <span x-text="service?.fleet_impact?.recommendation"></span>
                            </div>
                        </div>

                        <!-- Tunables Form Controls -->
                        <div class="space-y-4 pt-1">
                            <div class="flex items-center justify-between">
                                <h4 class="font-display font-bold text-sm text-[var(--color-ink-strong)]">Operator Overrides</h4>
                                <span class="text-[11px] text-[var(--color-ink-soft)]" x-text="tunables.is_custom ? 'Custom settings saved' : 'Vendor defaults active'"></span>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <!-- Timeout -->
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">Timeout (sec)</label>
                                        <span class="text-[10px] text-[var(--color-ink-soft)]" x-text="'Default: ' + (service?.defaults?.timeout || 15) + 's'"></span>
                                    </div>
                                    <input type="number" x-model.number="tunables.timeout" min="1" max="300"
                                           class="w-full font-data text-sm text-[var(--color-ink-strong)] border border-[var(--color-border)] rounded-md px-3 py-2 bg-[var(--color-surface)] focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                                    <span class="text-[10px] text-[var(--color-ink-soft)] mt-0.5 block">HTTP call timeout</span>
                                </div>

                                <!-- Concurrency -->
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">Concurrency</label>
                                        <span class="text-[10px] text-[var(--color-ink-soft)]" x-text="'Default: ' + (service?.defaults?.concurrency || 3)"></span>
                                    </div>
                                    <input type="number" x-model.number="tunables.concurrency" min="1" max="10"
                                           class="w-full font-data text-sm text-[var(--color-ink-strong)] border border-[var(--color-border)] rounded-md px-3 py-2 bg-[var(--color-surface)] focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                                    <span class="text-[10px] text-[var(--color-ink-soft)] mt-0.5 block">Parallel workers</span>
                                </div>

                                <!-- Inter-request Delay -->
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">Pacing Delay (ms)</label>
                                        <span class="text-[10px] text-[var(--color-ink-soft)]" x-text="'Default: ' + (service?.defaults?.delay_ms || 0) + 'ms'"></span>
                                    </div>
                                    <input type="number" x-model.number="tunables.delay_ms" min="0" max="5000" step="10"
                                           class="w-full font-data text-sm text-[var(--color-ink-strong)] border border-[var(--color-border)] rounded-md px-3 py-2 bg-[var(--color-surface)] focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                                    <span class="text-[10px] text-[var(--color-ink-soft)] mt-0.5 block">Sleep between requests</span>
                                </div>

                                <!-- Retries -->
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">Auto Retries</label>
                                        <span class="text-[10px] text-[var(--color-ink-soft)]" x-text="'Default: ' + (service?.defaults?.retry_attempts || 2)"></span>
                                    </div>
                                    <input type="number" x-model.number="tunables.retry_attempts" min="0" max="5"
                                           class="w-full font-data text-sm text-[var(--color-ink-strong)] border border-[var(--color-border)] rounded-md px-3 py-2 bg-[var(--color-surface)] focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                                    <span class="text-[10px] text-[var(--color-ink-soft)] mt-0.5 block">Retry on 429/503</span>
                                </div>
                            </div>
                        </div>

                        <!-- Documentation Links & Full Page Link -->
                        <div class="pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-2 text-xs">
                            <a :href="service?.rate_limit_docs_url" target="_blank" rel="noopener noreferrer" class="text-[var(--color-primary-600)] hover:underline flex items-center gap-1">
                                <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                <span>Official <span x-text="serviceName"></span> Rate Limit Docs</span>
                            </a>
                            <a :href="'/settings/integrations/' + serviceId + '/limits'" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] underline flex items-center gap-1">
                                <span>Open Full Dedicated Page &rarr;</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="px-6 py-3.5 bg-[var(--color-surface-alt)]/60 border-t border-[var(--color-border-light)] flex items-center justify-between gap-3 sticky bottom-0">
                    <button type="button" @click="resetDefaults()" :disabled="saving || testing" class="btn-pill-nav text-xs text-[var(--color-ink-muted)] hover:text-rose-600 cursor-pointer">
                        <i class="fa-solid fa-arrow-rotate-left mr-1"></i> Reset Defaults
                    </button>
                    <div class="flex items-center gap-2">
                        <template x-if="testable">
                            <button type="button"
                                    @click="testConnection()"
                                    :disabled="testing || saving || loading"
                                    class="btn-pill-nav text-xs font-semibold text-[var(--color-ink-strong)] hover:text-[var(--color-primary-600)] hover:bg-[var(--color-surface-alt)] border border-[var(--color-border)] flex items-center gap-1.5 px-3 py-2 cursor-pointer disabled:opacity-50"
                                    title="Test connection to the vendor API">
                                <template x-if="testing">
                                    <i class="fa-solid fa-circle-notch fa-spin text-[var(--color-primary-600)]"></i>
                                </template>
                                <template x-if="!testing">
                                    <i class="fa-solid fa-bolt text-amber-500"></i>
                                </template>
                                <span x-text="testing ? 'Testing...' : 'Test Connection'"></span>
                            </button>
                        </template>
                        <button type="button" @click="showLimitsModal = false" class="btn-pill-nav text-xs cursor-pointer">
                            Close
                        </button>
                        <button type="button" @click="saveTunables()" :disabled="saving || loading || testing" class="btn btn-primary text-xs px-4 py-2 cursor-pointer flex items-center gap-1.5">
                            <template x-if="saving">
                                <i class="fa-solid fa-circle-notch fa-spin"></i>
                            </template>
                            <template x-if="!saving">
                                <i class="fa-solid fa-floppy-disk"></i>
                            </template>
                            <span x-text="saving ? 'Saving...' : 'Save Settings'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function serviceLimitsModal() {
            return {
                showLimitsModal: false,
                serviceId: '',
                serviceName: '',
                testable: false,
                loading: false,
                saving: false,
                testing: false,
                saveSuccess: false,
                testResult: null,
                successMessage: '',
                errorMessage: '',
                service: null,
                credentials: [],
                credentialsPayload: {},
                tunables: {
                    rate_limit: '',
                    timeout: 15,
                    concurrency: 3,
                    delay_ms: 0,
                    retry_attempts: 2,
                    is_custom: false
                },
                openModal(id, name) {
                    this.serviceId = id;
                    this.serviceName = name;
                    this.showLimitsModal = true;
                    this.loading = true;
                    this.errorMessage = '';
                    this.saveSuccess = false;
                    this.testResult = null;
                    this.testing = false;
                    this.credentialsPayload = {};
                    fetch('/settings/integrations/' + id + '/limits', {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(res => {
                        if (!res.ok) throw new Error('Service configuration not found');
                        return res.json();
                    })
                    .then(data => {
                        this.service = data.service;
                        this.tunables = data.tunables;
                        this.credentials = (data.credentials || []).map(c => ({ ...c, showPlain: false }));
                        this.testable = !!data.testable;
                        this.loading = false;
                    })
                    .catch(err => {
                        this.errorMessage = err.message || 'Error loading limits';
                        this.loading = false;
                    });
                },
                saveTunables() {
                    this.saving = true;
                    this.errorMessage = '';
                    this.saveSuccess = false;
                    const token = document.querySelector('input[name=_token]')?.value;
                    const payload = {
                        ...this.tunables,
                        credentials: this.credentialsPayload
                    };
                    fetch('/settings/integrations/' + this.serviceId + '/limits', {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': token
                        },
                        body: JSON.stringify(payload)
                    })
                    .then(res => res.json())
                    .then(data => {
                        this.saving = false;
                        if (data.success) {
                            this.tunables = data.tunables;
                            this.credentials = (data.credentials || []).map(c => ({ ...c, showPlain: false }));
                            this.credentialsPayload = {};
                            this.successMessage = data.message || 'Settings and credentials saved to .env!';
                            this.saveSuccess = true;
                            setTimeout(() => { this.saveSuccess = false; }, 3500);

                            // Live update the card configured state
                            const anyConfigured = (data.credentials || []).some(c => c.configured);
                            const row = document.querySelector(`.service-row[data-service-id="${this.serviceId}"]`);
                            if (row) {
                                row.dataset.configured = anyConfigured ? 'true' : 'false';
                                const toggle = row.querySelector('.service-toggle');
                                if (toggle && typeof window.clockworkApplyToggleState === 'function') {
                                    window.clockworkApplyToggleState(row, toggle);
                                }
                            }
                        } else {
                            this.errorMessage = data.message || 'Error saving settings';
                        }
                    })
                    .catch(err => {
                        this.saving = false;
                        this.errorMessage = 'Network error saving settings';
                    });
                },
                removeCredential(field, label) {
                    if (!confirm('Remove ' + label + ' from your .env file?')) return;
                    this.saving = true;
                    this.errorMessage = '';
                    const token = document.querySelector('input[name=_token]')?.value;
                    fetch('/settings/integrations/' + this.serviceId + '/credentials/' + field + '/remove', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': token
                        }
                    })
                    .then(res => res.json())
                    .then(data => {
                        this.saving = false;
                        if (data.success) {
                            this.credentials = (data.credentials || []).map(c => ({ ...c, showPlain: false }));
                            delete this.credentialsPayload[field];
                            this.successMessage = data.message || (label + ' removed from .env file.');
                            this.saveSuccess = true;
                            setTimeout(() => { this.saveSuccess = false; }, 3500);

                            // Live update the card configured state
                            const anyConfigured = (data.credentials || []).some(c => c.configured);
                            const row = document.querySelector(`.service-row[data-service-id="${this.serviceId}"]`);
                            if (row) {
                                row.dataset.configured = anyConfigured ? 'true' : 'false';
                                const toggle = row.querySelector('.service-toggle');
                                if (toggle && typeof window.clockworkApplyToggleState === 'function') {
                                    window.clockworkApplyToggleState(row, toggle);
                                }
                            }
                        } else {
                            this.errorMessage = data.message || 'Error removing credential';
                        }
                    })
                    .catch(err => {
                        this.saving = false;
                        this.errorMessage = 'Network error removing credential';
                    });
                },
                resetDefaults() {
                    if (!confirm('Reset ' + this.serviceName + ' API limits to recommended defaults?')) return;
                    this.saving = true;
                    this.errorMessage = '';
                    const token = document.querySelector('input[name=_token]')?.value;
                    fetch('/settings/integrations/' + this.serviceId + '/limits/reset', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': token
                        }
                    })
                    .then(res => res.json())
                    .then(data => {
                        this.saving = false;
                        if (data.success) {
                            this.tunables = data.tunables;
                            this.credentials = (data.credentials || []).map(c => ({ ...c, showPlain: false }));
                            this.successMessage = 'Reset rate limit tunables back to factory defaults.';
                            this.saveSuccess = true;
                            setTimeout(() => { this.saveSuccess = false; }, 3500);
                        }
                    })
                    .catch(err => {
                        this.saving = false;
                        this.errorMessage = 'Network error resetting defaults';
                    });
                },
                async testConnection() {
                    this.testing = true;
                    this.testResult = null;
                    this.errorMessage = '';

                    const hasUnsavedKeys = Object.keys(this.credentialsPayload).some(k => this.credentialsPayload[k] && this.credentialsPayload[k].trim() !== '');
                    const hasExistingKeys = (this.credentials || []).some(c => c.configured);

                    if (!hasExistingKeys && !hasUnsavedKeys) {
                        this.testing = false;
                        this.testResult = {
                            success: false,
                            status: 'skipped',
                            summary: 'No credentials configured yet. Please paste your API key above first.',
                            duration_ms: null
                        };
                        return;
                    }

                    const token = document.querySelector('input[name=_token]')?.value;

                    // If operator entered a new key, save it to .env first before running the test!
                    if (hasUnsavedKeys) {
                        try {
                            const payload = {
                                ...this.tunables,
                                credentials: this.credentialsPayload
                            };
                            const saveRes = await fetch('/settings/integrations/' + this.serviceId + '/limits', {
                                method: 'PATCH',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': token
                                },
                                body: JSON.stringify(payload)
                            });
                            const saveData = await saveRes.json();
                            if (saveData.success) {
                                this.credentials = (saveData.credentials || []).map(c => ({ ...c, showPlain: false }));
                                this.credentialsPayload = {};

                                const anyConfigured = (saveData.credentials || []).some(c => c.configured);
                                const row = document.querySelector(`.service-row[data-service-id="${this.serviceId}"]`);
                                if (row) {
                                    row.dataset.configured = anyConfigured ? 'true' : 'false';
                                    const toggle = row.querySelector('.service-toggle');
                                    if (toggle && typeof window.clockworkApplyToggleState === 'function') {
                                        window.clockworkApplyToggleState(row, toggle);
                                    }
                                }
                            }
                        } catch (err) {
                            console.error('Auto-save prior to test failed:', err);
                        }
                    }

                    try {
                        const res = await fetch('/settings/integrations/' + this.serviceId + '/test', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': token
                            }
                        });
                        const data = await res.json();
                        this.testing = false;
                        this.testResult = {
                            success: data.success,
                            status: data.status || (data.success ? 'ok' : 'fail'),
                            summary: data.summary || data.message || (data.success ? 'Connection test succeeded.' : 'Connection test failed.'),
                            detail: data.detail || null,
                            duration_ms: data.duration_ms
                        };
                    } catch (err) {
                        this.testing = false;
                        this.testResult = {
                            success: false,
                            status: 'fail',
                            summary: 'Network error contacting server: ' + (err.message || 'Unknown error'),
                            duration_ms: null
                        };
                    }
                }
            };
        }

        document.addEventListener('DOMContentLoaded', function () {
            const FLEET_SOURCE_IDS = ['pressable', 'wpengine', 'kinsta', 'spinupwp', 'cloudways', 'gridpane'];
            const PANEL_IDS = ['spinupwp', 'cloudways', 'gridpane'];
            const VPS_IDS = ['digitalocean', 'hetzner', 'vultr', 'linode', 'azure'];

            const counter = document.getElementById('active-counter');
            const toggles = document.querySelectorAll('.service-toggle');
            const fleetCard = document.getElementById('fleet-source-card');
            const fleetIconBox = document.getElementById('fleet-source-icon-box');
            const fleetIcon = document.getElementById('fleet-source-icon');
            const fleetTitle = document.getElementById('fleet-source-title');
            const fleetText = document.getElementById('fleet-source-text');
            const fleetPill = document.getElementById('fleet-source-pill');
            const fleetPillContent = document.getElementById('fleet-source-pill-content');
            const hintText = document.getElementById('action-hint-text');
            const vpsText = document.getElementById('vps-guidance-text');
            const vpsCard = document.getElementById('vps-guidance-card');
            const vpsIconBox = document.getElementById('vps-guidance-icon-box');
            const vpsIcon = document.getElementById('vps-guidance-icon');
            const vpsTitle = document.getElementById('vps-guidance-title');
            const servicesForm = document.getElementById('services-form');

            const GREEN_CARD_CLASSES = [
                'border', 'border-emerald-500',
                'bg-gradient-to-r', 'from-emerald-50/75', 'via-emerald-50/30', 'to-teal-50/40',
                'shadow-xs'
            ];
            const RED_CARD_CLASSES = [
                'border', 'border-rose-600',
                'bg-gradient-to-r', 'from-rose-50/90', 'via-rose-50/40', 'to-red-50/60',
                'shadow-xs'
            ];
            const WHITE_CARD_CLASSES = [
                'border', 'border-[var(--color-border-light)]',
                'bg-[var(--color-surface)]', 'shadow-xs'
            ];
            const ALL_CARD_CLASSES = [
                'border-2', 'border',
                'border-emerald-600', 'border-emerald-500', 'border-rose-600', 'border-emerald-500/60', 'border-[var(--color-border)]', 'border-[var(--color-border-light)]',
                'bg-gradient-to-r', 'from-emerald-50/90', 'via-emerald-50/40', 'to-teal-50/60',
                'from-emerald-50/75', 'via-emerald-50/30', 'to-teal-50/40',
                'from-rose-50/90', 'via-rose-50/40', 'to-red-50/60',
                'bg-white', 'bg-[var(--color-surface)]', 'bg-[var(--color-surface-alt)]/20',
                'opacity-75', 'shadow-xs', 'shadow-sm'
            ];

            function applyToggleState(row, checkbox) {
                if (!row) return;

                const track = row.querySelector('.toggle-track');
                const knob = row.querySelector('.toggle-knob');
                const icon = knob?.querySelector('i');
                const isConfigured = row.dataset.configured === 'true';

                // Strip prior state classes
                row.classList.remove(...ALL_CARD_CLASSES);

                if (checkbox.checked) {
                    if (isConfigured) {
                        // Integrated properly: light green gradient with darker green border
                        row.classList.add(...GREEN_CARD_CLASSES);
                    } else {
                        // Turned on but NOT configured: slightly red background/gradient with solid red border
                        row.classList.add(...RED_CARD_CLASSES);
                    }

                    track.classList.remove('bg-rose-600', 'justify-start');
                    track.classList.add('bg-emerald-600', 'justify-end');

                    knob.classList.remove('text-rose-600');
                    knob.classList.add('text-emerald-600');

                    icon.classList.remove('fa-xmark');
                    icon.classList.add('fa-check');
                } else {
                    // Not turned on: just clean white
                    row.classList.add(...WHITE_CARD_CLASSES);

                    track.classList.remove('bg-emerald-600', 'justify-end');
                    track.classList.add('bg-rose-600', 'justify-start');

                    knob.classList.remove('text-emerald-600');
                    knob.classList.add('text-rose-600');

                    icon.classList.remove('fa-check');
                    icon.classList.add('fa-xmark');
                }
                updateDashboardState();
            }

            window.clockworkApplyToggleState = applyToggleState;

            function updateDashboardState() {
                let totalActive = 0;
                const activeIds = [];

                toggles.forEach(t => {
                    if (t.checked) {
                        totalActive++;
                        activeIds.push(t.value);
                    }
                });

                if (counter) counter.textContent = totalActive;

                const fleetCount = activeIds.filter(id => FLEET_SOURCE_IDS.includes(id)).length;
                const panelActive = activeIds.some(id => PANEL_IDS.includes(id));
                const vpsActive = activeIds.some(id => VPS_IDS.includes(id));

                // Update Top Fleet Source Card dynamically
                if (fleetCard) {
                    if (fleetCount > 0) {
                        fleetCard.className = 'mb-6 p-4 rounded-xl border transition-all duration-200 bg-emerald-50/90 border-emerald-300 text-slate-800 shadow-xs';
                        if (fleetIconBox) fleetIconBox.className = 'w-9 h-9 rounded-lg bg-emerald-500/15 text-emerald-700 flex items-center justify-center flex-shrink-0 text-base mt-0.5 border border-emerald-300/50';
                        if (fleetIcon) fleetIcon.className = 'fa-solid fa-circle-check';
                        if (fleetTitle) {
                            fleetTitle.className = 'font-bold block mb-0.5 text-emerald-950 text-sm';
                            fleetTitle.textContent = `Fleet Source Connected (${fleetCount} active):`;
                        }
                        if (fleetText) {
                            fleetText.innerHTML = `You have <strong>${fleetCount} fleet source${fleetCount === 1 ? '' : 's'}</strong> active. Clockwork Control connects directly to your hosting provider APIs to automatically discover and synchronize your WordPress sites and servers without manual data entry.`;
                        }
                    } else {
                        fleetCard.className = 'mb-6 p-4 rounded-xl border transition-all duration-200 bg-amber-50/90 border-amber-300 text-slate-800 shadow-xs';
                        if (fleetIconBox) fleetIconBox.className = 'w-9 h-9 rounded-lg bg-amber-500/15 text-amber-700 flex items-center justify-center flex-shrink-0 text-base mt-0.5 border border-amber-300/50';
                        if (fleetIcon) fleetIcon.className = 'fa-solid fa-triangle-exclamation';
                        if (fleetTitle) {
                            fleetTitle.className = 'font-bold block mb-0.5 text-amber-950 text-sm';
                            fleetTitle.textContent = 'Fleet Source Prerequisite Required:';
                        }
                        if (fleetText) {
                            fleetText.innerHTML = `To monitor your fleet, Clockwork Control needs at least one <strong>Managed WordPress Host</strong> (Pressable, WP Engine, Kinsta) or <strong>Server Management Panel</strong> (SpinupWP, Cloudways, GridPane) to import sites and servers. Please toggle on at least one below.`;
                        }
                    }
                }

                // Update Fleet Source Pill in Sticky Action Bar
                if (fleetPill && fleetPillContent) {
                    if (fleetCount > 0) {
                        fleetPill.className = 'status-pill status-green text-xs font-semibold';
                        fleetPillContent.innerHTML = `<i class="fa-solid fa-check"></i> ${fleetCount} fleet source${fleetCount === 1 ? '' : 's'}`;
                        if (hintText) {
                            hintText.textContent = 'Ready to configure API keys for active integrations.';
                            hintText.className = 'text-xs text-[var(--color-ink-muted)] hidden md:inline';
                        }
                    } else {
                        fleetPill.className = 'status-pill status-yellow text-xs font-semibold';
                        fleetPillContent.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> No fleet source selected`;
                        if (hintText) {
                            hintText.textContent = 'Select at least 1 host or panel to discover sites.';
                            hintText.className = 'text-xs text-amber-700 font-medium hidden md:inline';
                        }
                    }
                }

                // Update VPS Guidance banner dynamically
                if (vpsText && vpsCard) {
                    if (panelActive && !vpsActive) {
                        vpsCard.className = 'mb-6 p-4 rounded-xl border transition-all duration-200 bg-amber-50/90 border-amber-200 text-slate-800 shadow-xs';
                        if (vpsIconBox) vpsIconBox.className = 'w-9 h-9 rounded-lg bg-amber-500/15 text-amber-700 flex items-center justify-center flex-shrink-0 text-base mt-0.5 border border-amber-300/50';
                        if (vpsIcon) vpsIcon.className = 'fa-solid fa-triangle-exclamation';
                        if (vpsTitle) {
                            vpsTitle.className = 'font-bold block mb-0.5 text-amber-950 text-sm';
                            vpsTitle.textContent = 'Hardware Telemetry Recommended:';
                        }
                        vpsText.innerHTML = `<span class="text-slate-800 leading-relaxed">You have a Server Panel enabled (SpinupWP / Cloudways / GridPane). Be sure to enable your <strong>Cloud VPS provider</strong> below (e.g. DigitalOcean, Hetzner, Vultr) to monitor CPU, RAM, and droplet telemetry!</span>`;
                    } else if (panelActive && vpsActive) {
                        vpsCard.className = 'mb-6 p-4 rounded-xl border transition-all duration-200 bg-emerald-50/90 border-emerald-300 text-slate-800 shadow-xs';
                        if (vpsIconBox) vpsIconBox.className = 'w-9 h-9 rounded-lg bg-emerald-500/15 text-emerald-700 flex items-center justify-center flex-shrink-0 text-base mt-0.5 border border-emerald-300/50';
                        if (vpsIcon) vpsIcon.className = 'fa-solid fa-circle-check';
                        if (vpsTitle) {
                            vpsTitle.className = 'font-bold block mb-0.5 text-emerald-950 text-sm';
                            vpsTitle.textContent = 'Panel & VPS Paired:';
                        }
                        vpsText.innerHTML = `<span class="text-slate-800 leading-relaxed">Cloud VPS provider enabled to poll server hardware telemetry alongside WordPress sites.</span>`;
                    } else if (!panelActive && fleetCount > 0) {
                        vpsCard.className = 'mb-6 p-4 rounded-xl border transition-all duration-200 bg-blue-50/80 border-blue-200/90 text-slate-800 shadow-xs';
                        if (vpsIconBox) vpsIconBox.className = 'w-9 h-9 rounded-lg bg-blue-500/15 text-blue-700 flex items-center justify-center flex-shrink-0 text-base mt-0.5 border border-blue-300/40';
                        if (vpsIcon) vpsIcon.className = 'fa-solid fa-circle-info';
                        if (vpsTitle) {
                            vpsTitle.className = 'font-bold block mb-0.5 text-blue-950 text-sm';
                            vpsTitle.textContent = 'Managed Host Fleet:';
                        }
                        vpsText.innerHTML = `<span class="text-slate-800 leading-relaxed">Managed hosts (Pressable, WP Engine, Kinsta) manage infrastructure directly &mdash; Cloud VPS credentials are not required for those sites.</span>`;
                    } else {
                        vpsCard.className = 'mb-6 p-4 rounded-xl border transition-all duration-200 bg-blue-50/80 border-blue-200/90 text-slate-800 shadow-xs';
                        if (vpsIconBox) vpsIconBox.className = 'w-9 h-9 rounded-lg bg-blue-500/15 text-blue-700 flex items-center justify-center flex-shrink-0 text-base mt-0.5 border border-blue-300/40';
                        if (vpsIcon) vpsIcon.className = 'fa-solid fa-microchip';
                        if (vpsTitle) {
                            vpsTitle.className = 'font-bold block mb-0.5 text-blue-950 text-sm';
                            vpsTitle.textContent = 'Hardware Telemetry Pairing:';
                        }
                        vpsText.innerHTML = `<span class="text-slate-800 leading-relaxed">If you use <strong>SpinupWP</strong> or <strong>Cloudways</strong>, enable your server's cloud provider (e.g. DigitalOcean, Hetzner, Azure) to unlock 5-minute CPU, RAM, and droplet health monitoring.</span>`;
                    }
                }
            }

            // Confirm on submit if 0 fleet sources are selected
            servicesForm?.addEventListener('submit', function (e) {
                let fleetCount = 0;
                toggles.forEach(t => {
                    if (t.checked && FLEET_SOURCE_IDS.includes(t.value)) {
                        fleetCount++;
                    }
                });

                if (fleetCount === 0) {
                    const confirmed = confirm("You have not selected any Managed WordPress Hosts or Server Management Panels.\n\nWithout a fleet source, Clockwork Control will have no sites or servers to monitor.\n\nDo you want to continue anyway?");
                    if (!confirmed) {
                        e.preventDefault();
                        servicesForm.scrollIntoView({ behavior: 'smooth' });
                    }
                }
            });

            function saveToggle(serviceId, enabled) {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                    || '{{ csrf_token() }}';

                fetch('{{ route('setup.toggle') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        service: serviceId,
                        enabled: enabled,
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        console.error('Failed to save toggle state:', data.message);
                    }
                })
                .catch(err => {
                    console.error('Network error saving toggle state:', err);
                });
            }

            toggles.forEach(toggle => {
                const row = toggle.closest('.service-row');
                toggle.addEventListener('change', () => {
                    applyToggleState(row, toggle);
                    saveToggle(toggle.value, toggle.checked);
                });
            });

            updateDashboardState();
        });
    </script>
@endsection
