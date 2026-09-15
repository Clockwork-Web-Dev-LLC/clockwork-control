@extends('layouts.app')

@section('title', 'Styleguide · Clockwork Control')

@section('content')
    {{-- Page Header --}}
    <x-page-header title="Design System & Styleguide"
                   subtitle="Visual catalog, CSS custom properties, and component standards for Clockwork Control." />

    {{-- System Banner --}}
    <div class="card p-6 mb-6 relative overflow-hidden bg-gradient-to-r from-[var(--color-surface)] via-[var(--color-surface)] to-[var(--color-primary-50)] border-l-4 border-l-[var(--color-brand)]">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-[var(--color-brand)]/10 text-[var(--color-brand)] border border-[var(--color-brand)]/20">
                        Clockwork Design System
                    </span>
                    <span class="text-xs text-[var(--color-ink-soft)] font-mono">v1.0-production</span>
                </div>
                <h2 class="text-xl font-display font-bold text-[var(--color-ink-strong)]">
                    Control Center Design Language
                </h2>
                <p class="text-sm text-[var(--color-ink-muted)] max-w-2xl mt-1">
                    The core visual framework behind Clockwork Control: electric brand blue (<code class="font-mono text-xs">#1456f0</code>), refined slate surfaces, balanced typography scales, high-density server telemetry, and collapsible navigation.
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="#palette" class="btn-pill-nav text-xs">Tokens</a>
                <a href="#typography" class="btn-pill-nav text-xs">Typography</a>
                <a href="#buttons" class="btn-pill-nav text-xs">Buttons</a>
                <a href="#badges" class="btn-pill-nav text-xs">Badges</a>
                <a href="#cards" class="btn-pill-nav text-xs">Cards</a>
                <a href="#tables" class="btn-pill-nav text-xs">Tables</a>
            </div>
        </div>
    </div>

    {{-- 1. COLOR TOKENS & SWATCHES --}}
    <div id="palette" class="mb-10">
        <div class="flex items-center justify-between mb-4 border-b border-[var(--color-border-light)] pb-2">
            <div>
                <h3 class="text-base font-display font-bold text-[var(--color-ink-strong)]">1. Color Palette &amp; Surface Tokens</h3>
                <p class="text-xs text-[var(--color-ink-soft)]">Core brand hues, semantic status colors, and dark/light surface variables</p>
            </div>
            <span class="text-[11px] font-mono text-[var(--color-ink-soft)]">CSS Custom Properties</span>
        </div>

        {{-- Brand Colors --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-3 mb-4">
            <div class="card p-3 flex flex-col justify-between">
                <div class="w-full h-12 rounded-lg bg-[var(--color-brand)] shadow-xs mb-2 flex items-center justify-center text-white font-mono text-xs font-bold">
                    #1456f0
                </div>
                <div>
                    <div class="text-xs font-semibold text-[var(--color-ink-strong)]">Electric Brand Blue</div>
                    <div class="text-[10px] font-mono text-[var(--color-ink-soft)]">--color-brand</div>
                </div>
            </div>

            <div class="card p-3 flex flex-col justify-between">
                <div class="w-full h-12 rounded-lg bg-[var(--color-brand-deep)] shadow-xs mb-2 flex items-center justify-center text-white font-mono text-xs font-bold">
                    #17437d
                </div>
                <div>
                    <div class="text-xs font-semibold text-[var(--color-ink-strong)]">Brand Deep</div>
                    <div class="text-[10px] font-mono text-[var(--color-ink-soft)]">--color-brand-deep</div>
                </div>
            </div>

            <div class="card p-3 flex flex-col justify-between">
                <div class="w-full h-12 rounded-lg bg-[var(--color-brand-pink)] shadow-xs mb-2 flex items-center justify-center text-white font-mono text-xs font-bold">
                    #ea5ec1
                </div>
                <div>
                    <div class="text-xs font-semibold text-[var(--color-ink-strong)]">Brand Pink</div>
                    <div class="text-[10px] font-mono text-[var(--color-ink-soft)]">--color-brand-pink</div>
                </div>
            </div>

            <div class="card p-3 flex flex-col justify-between">
                <div class="w-full h-12 rounded-lg bg-[var(--color-brand-sky)] shadow-xs mb-2 flex items-center justify-center text-white font-mono text-xs font-bold">
                    #3daeff
                </div>
                <div>
                    <div class="text-xs font-semibold text-[var(--color-ink-strong)]">Brand Sky</div>
                    <div class="text-[10px] font-mono text-[var(--color-ink-soft)]">--color-brand-sky</div>
                </div>
            </div>

            <div class="card p-3 flex flex-col justify-between">
                <div class="w-full h-12 rounded-lg bg-[var(--color-surface)] border border-[var(--color-border)] shadow-xs mb-2 flex items-center justify-center text-[var(--color-ink-strong)] font-mono text-xs font-bold">
                    Surface
                </div>
                <div>
                    <div class="text-xs font-semibold text-[var(--color-ink-strong)]">Base Surface</div>
                    <div class="text-[10px] font-mono text-[var(--color-ink-soft)]">--color-surface</div>
                </div>
            </div>

            <div class="card p-3 flex flex-col justify-between">
                <div class="w-full h-12 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border)] shadow-xs mb-2 flex items-center justify-center text-[var(--color-ink-strong)] font-mono text-xs font-bold">
                    Alt
                </div>
                <div>
                    <div class="text-xs font-semibold text-[var(--color-ink-strong)]">Alt Surface</div>
                    <div class="text-[10px] font-mono text-[var(--color-ink-soft)]">--color-surface-alt</div>
                </div>
            </div>
        </div>

        {{-- Status Tokens --}}
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div class="card p-3">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-3 h-3 rounded-full bg-[var(--color-status-green)]"></span>
                    <span class="text-xs font-semibold text-[var(--color-status-green)]">Operational</span>
                </div>
                <div class="text-[11px] text-[var(--color-ink-soft)]">--color-status-green</div>
            </div>

            <div class="card p-3">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-3 h-3 rounded-full bg-[var(--color-status-yellow)]"></span>
                    <span class="text-xs font-semibold text-[var(--color-status-yellow)]">Warning / Load</span>
                </div>
                <div class="text-[11px] text-[var(--color-ink-soft)]">--color-status-yellow</div>
            </div>

            <div class="card p-3">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-3 h-3 rounded-full bg-[var(--color-status-orange)]"></span>
                    <span class="text-xs font-semibold text-[var(--color-status-orange)]">Alert / Queued</span>
                </div>
                <div class="text-[11px] text-[var(--color-ink-soft)]">--color-status-orange</div>
            </div>

            <div class="card p-3">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-3 h-3 rounded-full bg-[var(--color-status-red)]"></span>
                    <span class="text-xs font-semibold text-[var(--color-status-red)]">Critical / Outage</span>
                </div>
                <div class="text-[11px] text-[var(--color-ink-soft)]">--color-status-red</div>
            </div>

            <div class="card p-3">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-3 h-3 rounded-full bg-[var(--color-cf-orange)]"></span>
                    <span class="text-xs font-semibold text-[var(--color-cf-orange)]">Cloudflare Proxy</span>
                </div>
                <div class="text-[11px] text-[var(--color-ink-soft)]">--color-cf-orange</div>
            </div>
        </div>
    </div>

    {{-- 2. TYPOGRAPHY HIERARCHY --}}
    <div id="typography" class="mb-10">
        <div class="flex items-center justify-between mb-4 border-b border-[var(--color-border-light)] pb-2">
            <div>
                <h3 class="text-base font-display font-bold text-[var(--color-ink-strong)]">2. Typography System</h3>
                <p class="text-xs text-[var(--color-ink-soft)]">MiniMax multi-font roles: Outfit display, Poppins section headers, DM Sans body, and Roboto telemetry</p>
            </div>
            <span class="text-[11px] font-mono text-[var(--color-ink-soft)]">Font Roles</span>
        </div>

        <div class="card p-6 divide-y divide-[var(--color-border-light)]">
            <div class="py-3.5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="min-w-48">
                    <span class="text-xs font-mono text-[var(--color-ink-soft)]">Display Font</span>
                    <div class="text-[11px] text-[var(--color-brand)] font-semibold">Outfit 28px / 700</div>
                </div>
                <div class="text-2xl md:text-3xl font-display font-bold text-[var(--color-ink-strong)] tracking-tight">
                    Clockwork Fleet Observability Platform
                </div>
            </div>

            <div class="py-3.5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="min-w-48">
                    <span class="text-xs font-mono text-[var(--color-ink-soft)]">Section Heading</span>
                    <div class="text-[11px] text-[var(--color-brand)] font-semibold">Poppins 18px / 600</div>
                </div>
                <div class="text-lg font-mid font-semibold text-[var(--color-ink-strong)]">
                    Managed WordPress Infrastructure &amp; Gateways
                </div>
            </div>

            <div class="py-3.5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="min-w-48">
                    <span class="text-xs font-mono text-[var(--color-ink-soft)]">Body Font</span>
                    <div class="text-[11px] text-[var(--color-brand)] font-semibold">DM Sans 14px / 400</div>
                </div>
                <div class="text-sm font-sans text-[var(--color-ink-muted)] max-w-xl leading-relaxed">
                    Clockwork Control automatically synchronizes servers, tracks WordPress core and plugin updates, enforces Fail2ban security bans, and probes uptime health fleet-wide.
                </div>
            </div>

            <div class="py-3.5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="min-w-48">
                    <span class="text-xs font-mono text-[var(--color-ink-soft)]">Numeric &amp; Telemetry</span>
                    <div class="text-[11px] text-[var(--color-brand)] font-semibold">Roboto 20px / 500</div>
                </div>
                <div class="flex items-center gap-6 font-data text-[var(--color-ink-strong)]">
                    <span class="text-xl font-bold">99.98%</span>
                    <span class="text-xl font-bold text-[var(--color-brand)]">1.28 GB / 4.00 GB</span>
                    <span class="text-xl font-bold text-amber-500">23ms</span>
                    <span class="text-xs font-mono bg-[var(--color-surface-alt)] px-2 py-1 rounded-md border border-[var(--color-border-light)]">198.51.100.12</span>
                </div>
            </div>
        </div>
    </div>

    {{-- 3. BUTTONS & CONTROLS --}}
    <div id="buttons" class="mb-10">
        <div class="flex items-center justify-between mb-4 border-b border-[var(--color-border-light)] pb-2">
            <div>
                <h3 class="text-base font-display font-bold text-[var(--color-ink-strong)]">3. Buttons &amp; Action Elements</h3>
                <p class="text-xs text-[var(--color-ink-soft)]">Solid primary actions, pill filter navigation, secondary controls, and keyboard shortcuts</p>
            </div>
            <span class="text-[11px] font-mono text-[var(--color-ink-soft)]">Interactive Components</span>
        </div>

        <div class="card p-6 space-y-6">
            {{-- Primary Buttons --}}
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-3">Primary Action Buttons</div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="btn-primary">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Primary Action
                    </button>
                    <button type="button" class="btn-primary">
                        <i class="fa-solid fa-plus"></i> Add Server
                    </button>
                    <button type="button" class="btn-primary opacity-60 cursor-not-allowed">
                        <i class="fa-solid fa-spinner fa-spin"></i> Processing…
                    </button>
                </div>
            </div>

            {{-- Pill Navigation & Tabs --}}
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-3">Pill Navigation &amp; Filter Tabs</div>
                <div class="flex flex-wrap items-center gap-2 p-1.5 rounded-full bg-[var(--color-surface-alt)] w-fit border border-[var(--color-border-light)]">
                    <button type="button" class="btn-pill-nav is-active text-xs">
                        <i class="fa-solid fa-server"></i> All Servers
                        <span class="px-2 py-0.5 rounded-full text-[10px] bg-[var(--color-brand)] text-white">42</span>
                    </button>
                    <button type="button" class="btn-pill-nav text-xs">
                        <i class="fa-solid fa-triangle-exclamation"></i> High Pressure
                        <span class="px-2 py-0.5 rounded-full text-[10px] bg-amber-500/20 text-amber-600">3</span>
                    </button>
                    <button type="button" class="btn-pill-nav text-xs">
                        <i class="fa-solid fa-shield-halved"></i> Fail2ban
                    </button>
                    <button type="button" class="btn-pill-nav text-xs">
                        <i class="fa-solid fa-sliders"></i> Settings
                    </button>
                </div>
            </div>

            {{-- Action & Danger Buttons --}}
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-3">Secondary &amp; Danger Controls</div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="px-3.5 py-2 text-xs rounded-xl border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] font-medium transition-colors">
                        <i class="fa-solid fa-rotate-left mr-1.5 text-[var(--color-ink-soft)]"></i> Reset Filters
                    </button>
                    <button type="button" class="px-3.5 py-2 text-xs rounded-xl border border-rose-200 dark:border-rose-900/50 bg-rose-50 dark:bg-rose-950/30 text-rose-600 dark:text-rose-400 hover:bg-rose-100 font-medium transition-colors">
                        <i class="fa-solid fa-trash-can mr-1.5"></i> Delete Server
                    </button>
                    <button type="button" class="px-3.5 py-2 text-xs rounded-xl border border-[var(--color-brand)]/30 bg-[var(--color-brand)]/10 text-[var(--color-brand)] hover:bg-[var(--color-brand)]/20 font-medium transition-colors">
                        <i class="fa-solid fa-broom mr-1.5"></i> Bulk Clear Bans
                    </button>
                    <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)]">
                        <span>Toggle Sidebar</span>
                        <kbd class="cmd-kbd">⌘</kbd>
                        <kbd class="cmd-kbd">B</kbd>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 4. STATUS PILLS & BADGES --}}
    <div id="badges" class="mb-10">
        <div class="flex items-center justify-between mb-4 border-b border-[var(--color-border-light)] pb-2">
            <div>
                <h3 class="text-base font-display font-bold text-[var(--color-ink-strong)]">4. Status Pills &amp; Micro-Indicators</h3>
                <p class="text-xs text-[var(--color-ink-soft)]">Semantic state badges with matching dot indicators for instant fleet recognition</p>
            </div>
            <span class="text-[11px] font-mono text-[var(--color-ink-soft)]">Semantic Badges</span>
        </div>

        <div class="card p-6 flex flex-wrap items-center gap-3">
            <span class="status-pill status-green">
                <span class="status-dot"></span> Healthy · 100%
            </span>
            <span class="status-pill status-yellow">
                <span class="status-dot"></span> Warning · 74% Memory
            </span>
            <span class="status-pill status-orange">
                <span class="status-dot"></span> Pressure · 88% CPU
            </span>
            <span class="status-pill status-red">
                <span class="status-dot"></span> Critical Outage
            </span>
            <span class="status-pill status-unknown">
                <span class="status-dot"></span> Standby / Unknown
            </span>
            <span class="status-pill status-cf">
                <i class="fa-solid fa-cloud text-[10px]"></i> Cloudflare Proxy
            </span>
            <span class="px-2.5 py-0.5 rounded-md text-[11px] font-mono bg-[var(--color-surface-alt)] border border-[var(--color-border)] text-[var(--color-ink-strong)] font-semibold">
                PHP 8.3
            </span>
            <span class="px-2.5 py-0.5 rounded-md text-[11px] font-mono bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 font-semibold">
                WP 6.7.2
            </span>
        </div>
    </div>

    {{-- 5. KPI TILES & HUD CARDS --}}
    <div id="cards" class="mb-10">
        <div class="flex items-center justify-between mb-4 border-b border-[var(--color-border-light)] pb-2">
            <div>
                <h3 class="text-base font-display font-bold text-[var(--color-ink-strong)]">5. KPI Telemetry Tiles &amp; HUD Cards</h3>
                <p class="text-xs text-[var(--color-ink-soft)]">Clean elevated cards with progress meters and key operational numbers</p>
            </div>
            <span class="text-[11px] font-mono text-[var(--color-ink-soft)]">Metric HUD</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="card p-5 flex flex-col justify-between">
                <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] uppercase tracking-wider font-semibold">
                    <span>Active Sites</span>
                    <i class="fa-solid fa-globe text-[var(--color-brand)]"></i>
                </div>
                <div class="my-3">
                    <div class="text-2xl font-display font-bold text-[var(--color-ink-strong)]">312</div>
                    <div class="text-xs text-[var(--color-status-green)] flex items-center gap-1 mt-1">
                        <i class="fa-solid fa-circle-check text-[10px]"></i> 100% Probed in 24h
                    </div>
                </div>
                <div class="w-full bg-[var(--color-surface-alt)] h-2 rounded-full overflow-hidden">
                    <div class="bg-[var(--color-brand)] h-full rounded-full" style="width: 85%"></div>
                </div>
            </div>

            <div class="card p-5 flex flex-col justify-between">
                <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] uppercase tracking-wider font-semibold">
                    <span>Memory Pressure</span>
                    <i class="fa-solid fa-microchip text-amber-500"></i>
                </div>
                <div class="my-3">
                    <div class="text-2xl font-display font-bold text-amber-500">76.4%</div>
                    <div class="text-xs text-[var(--color-ink-soft)] mt-1">
                        12.2 GB of 16.0 GB used
                    </div>
                </div>
                <div class="w-full bg-[var(--color-surface-alt)] h-2 rounded-full overflow-hidden">
                    <div class="bg-amber-500 h-full rounded-full" style="width: 76%"></div>
                </div>
            </div>

            <div class="card p-5 flex flex-col justify-between">
                <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] uppercase tracking-wider font-semibold">
                    <span>Fail2ban Blocks</span>
                    <i class="fa-solid fa-shield-halved text-[var(--color-status-green)]"></i>
                </div>
                <div class="my-3">
                    <div class="text-2xl font-display font-bold text-[var(--color-ink-strong)]">55,351</div>
                    <div class="text-xs text-[var(--color-ink-soft)] mt-1">
                        Retention: 12 Mo (Auto-pruned)
                    </div>
                </div>
                <div class="w-full bg-[var(--color-surface-alt)] h-2 rounded-full overflow-hidden">
                    <div class="bg-emerald-500 h-full rounded-full" style="width: 100%"></div>
                </div>
            </div>

            <div class="card p-5 flex flex-col justify-between">
                <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] uppercase tracking-wider font-semibold">
                    <span>Pending Issues</span>
                    <i class="fa-solid fa-triangle-exclamation text-rose-500"></i>
                </div>
                <div class="my-3">
                    <div class="text-2xl font-display font-bold text-rose-500">14</div>
                    <div class="text-xs text-[var(--color-ink-soft)] mt-1">
                        3 Outdated core · 11 Plugins
                    </div>
                </div>
                <div class="w-full bg-[var(--color-surface-alt)] h-2 rounded-full overflow-hidden">
                    <div class="bg-rose-500 h-full rounded-full" style="width: 28%"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- 6. DATA TABLE PREVIEW --}}
    <div id="tables" class="mb-10" x-data="{
        sortCol: 'hostname',
        sortAsc: true,
        density: 'comfortable',
        sortBy(col) {
            if (this.sortCol === col) {
                this.sortAsc = !this.sortAsc;
            } else {
                this.sortCol = col;
                this.sortAsc = true;
            }
        }
    }">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-4 border-b border-[var(--color-border-light)] pb-2 gap-2">
            <div>
                <h3 class="text-base font-display font-bold text-[var(--color-ink-strong)]">6. High-Density Data Tables</h3>
                <p class="text-xs text-[var(--color-ink-soft)]">Sortable column headers with indicator arrows, clean row boundaries, and compact densities</p>
            </div>
            <div class="flex items-center gap-2">
                <div class="flex items-center bg-[var(--color-surface-alt)] p-0.5 rounded-lg border border-[var(--color-border)] text-xs">
                    <button type="button" @click="density = 'comfortable'" :class="density === 'comfortable' ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-xs font-semibold' : 'text-[var(--color-ink-soft)]'" class="px-2.5 py-1 rounded-md transition">Comfortable</button>
                    <button type="button" @click="density = 'compact'" :class="density === 'compact' ? 'bg-[var(--color-surface)] text-[var(--color-brand)] shadow-xs font-semibold' : 'text-[var(--color-ink-soft)]'" class="px-2.5 py-1 rounded-md transition">Compact</button>
                </div>
                <span class="text-[11px] font-mono text-[var(--color-ink-soft)]">Table System</span>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="px-6 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <span class="font-display font-semibold text-sm text-[var(--color-ink-strong)]">Sample Server Telemetry Table</span>
                <span class="text-xs font-mono text-[var(--color-ink-soft)]">Showing 3 of 42 servers</span>
            </div>
            <table class="w-full text-sm text-left">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] text-xs uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5 font-semibold cursor-pointer select-none hover:text-[var(--color-ink-strong)]" @click="sortBy('hostname')">
                            <div class="flex items-center gap-1">
                                <span>Server Name</span>
                                <i class="fa-solid text-[10px]" :class="sortCol === 'hostname' ? (sortAsc ? 'fa-arrow-up text-[var(--color-brand)]' : 'fa-arrow-down text-[var(--color-brand)]') : 'fa-sort opacity-40'"></i>
                            </div>
                        </th>
                        <th class="px-6 py-3.5 font-semibold cursor-pointer select-none hover:text-[var(--color-ink-strong)]" @click="sortBy('status')">
                            <div class="flex items-center gap-1">
                                <span>Status</span>
                                <i class="fa-solid text-[10px]" :class="sortCol === 'status' ? (sortAsc ? 'fa-arrow-up text-[var(--color-brand)]' : 'fa-arrow-down text-[var(--color-brand)]') : 'fa-sort opacity-40'"></i>
                            </div>
                        </th>
                        <th class="px-6 py-3.5 font-semibold cursor-pointer select-none hover:text-[var(--color-ink-strong)]" @click="sortBy('ip')">
                            <div class="flex items-center gap-1">
                                <span>IP Address</span>
                                <i class="fa-solid text-[10px]" :class="sortCol === 'ip' ? (sortAsc ? 'fa-arrow-up text-[var(--color-brand)]' : 'fa-arrow-down text-[var(--color-brand)]') : 'fa-sort opacity-40'"></i>
                            </div>
                        </th>
                        <th class="px-6 py-3.5 font-semibold">Load (1m)</th>
                        <th class="px-6 py-3.5 font-semibold">Memory</th>
                        <th class="px-6 py-3.5 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)] font-sans">
                    <tr class="hover:bg-[var(--color-surface-alt)]/60 transition-colors">
                        <td class="px-6" :class="density === 'compact' ? 'py-2.5' : 'py-4'">
                            <div class="font-semibold text-[var(--color-ink-strong)]">app01.clockworkwp.com</div>
                            <div class="text-xs text-[var(--color-ink-soft)]">SpinupWP · Ubuntu 22.04</div>
                        </td>
                        <td class="px-6" :class="density === 'compact' ? 'py-2.5' : 'py-4'">
                            <span class="status-pill status-green"><span class="status-dot"></span> Online</span>
                        </td>
                        <td class="px-6 font-mono text-xs text-[var(--color-brand)]" :class="density === 'compact' ? 'py-2.5' : 'py-4'">198.51.100.12</td>
                        <td class="px-6 font-mono text-xs text-[var(--color-ink-strong)]" :class="density === 'compact' ? 'py-2.5' : 'py-4'">0.42</td>
                        <td class="px-6" :class="density === 'compact' ? 'py-2.5' : 'py-4'">
                            <div class="text-xs font-mono text-[var(--color-ink-strong)]">42%</div>
                            <div class="w-24 bg-[var(--color-surface-alt)] h-1.5 rounded-full overflow-hidden mt-1.5">
                                <div class="bg-emerald-500 h-full" style="width: 42%"></div>
                            </div>
                        </td>
                        <td class="px-6 text-right" :class="density === 'compact' ? 'py-2.5' : 'py-4'">
                            <button type="button" class="btn-pill-nav text-xs">Inspect</button>
                        </td>
                    </tr>

                    <tr class="hover:bg-[var(--color-surface-alt)]/60 transition-colors">
                        <td class="px-6" :class="density === 'compact' ? 'py-2.5' : 'py-4'">
                            <div class="font-semibold text-[var(--color-ink-strong)]">web02.clockworkwp.com</div>
                            <div class="text-xs text-[var(--color-ink-soft)]">Vultr High Frequency · Debian 12</div>
                        </td>
                        <td class="px-6" :class="density === 'compact' ? 'py-2.5' : 'py-4'">
                            <span class="status-pill status-yellow"><span class="status-dot"></span> Pressure</span>
                        </td>
                        <td class="px-6 font-mono text-xs text-[var(--color-brand)]" :class="density === 'compact' ? 'py-2.5' : 'py-4'">198.51.100.82</td>
                        <td class="px-6 font-mono text-xs text-amber-500" :class="density === 'compact' ? 'py-2.5' : 'py-4'">2.88</td>
                        <td class="px-6" :class="density === 'compact' ? 'py-2.5' : 'py-4'">
                            <div class="text-xs font-mono text-amber-500">79%</div>
                            <div class="w-24 bg-[var(--color-surface-alt)] h-1.5 rounded-full overflow-hidden mt-1.5">
                                <div class="bg-amber-500 h-full" style="width: 79%"></div>
                            </div>
                        </td>
                        <td class="px-6 text-right" :class="density === 'compact' ? 'py-2.5' : 'py-4'">
                            <button type="button" class="btn-pill-nav text-xs">Inspect</button>
                        </td>
                    </tr>

                    <tr class="hover:bg-[var(--color-surface-alt)]/60 transition-colors">
                        <td class="px-6" :class="density === 'compact' ? 'py-2.5' : 'py-4'">
                            <div class="font-semibold text-[var(--color-ink-strong)]">pressable-agency-01</div>
                            <div class="text-xs text-[var(--color-ink-soft)]">Pressable Cloud · 80 Site Pool</div>
                        </td>
                        <td class="px-6" :class="density === 'compact' ? 'py-2.5' : 'py-4'">
                            <span class="status-pill status-green"><span class="status-dot"></span> Optimal</span>
                        </td>
                        <td class="px-6 font-mono text-xs text-[var(--color-ink-soft)]" :class="density === 'compact' ? 'py-2.5' : 'py-4'">—</td>
                        <td class="px-6 font-mono text-xs text-[var(--color-ink-soft)]" :class="density === 'compact' ? 'py-2.5' : 'py-4'">—</td>
                        <td class="px-6" :class="density === 'compact' ? 'py-2.5' : 'py-4'">
                            <div class="text-xs font-mono text-[var(--color-ink-strong)]">65 / 80 Sites</div>
                            <div class="w-24 bg-[var(--color-surface-alt)] h-1.5 rounded-full overflow-hidden mt-1.5">
                                <div class="bg-[var(--color-brand)] h-full" style="width: 81%"></div>
                            </div>
                        </td>
                        <td class="px-6 text-right" :class="density === 'compact' ? 'py-2.5' : 'py-4'">
                            <button type="button" class="btn-pill-nav text-xs">Inspect</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection
