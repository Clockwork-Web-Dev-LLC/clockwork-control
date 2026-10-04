@extends('layouts.app')

@section('title', 'Settings & Operations · Clockwork Control')

@section('content')
<div x-data="settingsHubManager()">
    @include('settings._tabs')

    <x-page-header title="Settings & Operations"
        subtitle="Manage fleet configuration, API integrations, operational tools, and system administration.">
        <x-slot:actions>
            <div class="relative w-full sm:w-72 md:w-80 max-w-full">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[var(--color-ink-muted)]"></i>
                <input type="text"
                       x-model="search"
                       placeholder="Filter settings & tools... (e.g. twilio, backup)"
                       class="w-full pl-8 pr-8 py-1.5 text-xs rounded-full border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] placeholder:text-[var(--color-ink-muted)] focus:outline-hidden focus:ring-2 focus:ring-[var(--color-brand)]/20 focus:border-[var(--color-brand)] transition-all">
                <button type="button"
                        x-show="search.length > 0"
                        @click="search = ''"
                        class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-circle-xmark"></i>
                </button>
            </div>
        </x-slot:actions>
    </x-page-header>

    {{-- Reorder helper and controls bar --}}
    <div class="flex items-center justify-between flex-wrap gap-2 mb-4 px-1 text-xs text-[var(--color-ink-muted)]">
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 font-medium text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-grip-vertical text-gray-400"></i> Settings Modules
            </span>
            <span class="text-[var(--color-border)]">|</span>
            <span class="text-[11px] text-[var(--color-ink-soft)] flex items-center gap-1">
                <i class="fa-solid fa-arrows-up-down-left-right text-[10px]"></i> Drag handle or use arrows on any card to reorder
            </span>
        </div>

        <div class="flex items-center gap-3">
            <span x-show="savedToast" x-cloak class="text-emerald-600 dark:text-emerald-400 font-medium text-xs flex items-center gap-1.5 transition-opacity">
                <i class="fa-solid fa-circle-check text-[11px]"></i> <span x-text="savedMessage || 'Order saved'"></span>
            </span>
            <button type="button"
                    x-show="isCustom"
                    x-cloak
                    @click="resetOrder()"
                    class="text-[11px] text-[var(--color-ink-muted)] hover:text-rose-600 dark:hover:text-rose-400 transition-colors flex items-center gap-1.5 cursor-pointer">
                <i class="fa-solid fa-arrow-rotate-left text-[10px]"></i> Reset default order
            </button>
        </div>
    </div>

    <div id="settings-cards-grid" class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- 1. Agency Branding --}}
        <div class="card p-5 flex flex-col justify-between transition-all duration-200"
             data-card-id="branding"
             @dragover.prevent="onDragOver($event, 'branding')"
             @dragleave="onDragLeave($event)"
             @drop="onDrop($event, 'branding')"
             :class="{
                 'opacity-40 scale-[0.99] border-dashed border-2 border-[var(--color-brand)] shadow-lg': draggedCard === 'branding',
                 'ring-2 ring-[var(--color-brand)] ring-offset-2 ring-offset-[var(--color-surface)]': dragOverCard === 'branding' && draggedCard !== 'branding'
             }"
             x-show="matches('agency branding companion white label mu plugin logo palette colors reports email')">
            <div>
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-[var(--color-border-light)]">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 border border-purple-100/80 dark:border-purple-900/40 flex items-center justify-center text-sm shadow-2xs">
                            <i class="fa-solid fa-paintbrush"></i>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-[var(--color-ink-strong)]">Agency Branding</h2>
                            <p class="text-xs text-[var(--color-ink-muted)]">White-labeling, brand colors, and client-facing styling</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] border border-[var(--color-border-light)]">Branding</span>
                        <div class="flex items-center gap-0.5 pl-2 border-l border-[var(--color-border-light)] text-[var(--color-ink-muted)]">
                            <button type="button"
                                    @click="moveCard('branding', -1)"
                                    :disabled="order.indexOf('branding') === 0"
                                    class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] disabled:opacity-20 disabled:cursor-not-allowed transition-colors"
                                    title="Move module earlier"
                                    aria-label="Move module earlier">
                                <i class="fa-solid fa-arrow-up text-[10px]"></i>
                            </button>
                            <button type="button"
                                    @click="moveCard('branding', 1)"
                                    :disabled="order.indexOf('branding') === order.length - 1"
                                    class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] disabled:opacity-20 disabled:cursor-not-allowed transition-colors"
                                    title="Move module later"
                                    aria-label="Move module later">
                                <i class="fa-solid fa-arrow-down text-[10px]"></i>
                            </button>
                            <div draggable="true"
                                 @dragstart.stop="onDragStart($event, 'branding')"
                                 @dragend="onDragEnd()"
                                 class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] cursor-grab active:cursor-grabbing transition-colors"
                                 title="Drag to reorder module">
                                <i class="fa-solid fa-grip-vertical text-[11px]"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="divide-y divide-[var(--color-border-light)]">
                    <a href="{{ route('settings.companion.index') }}"
                       x-show="matches('white labeling companion white label mu plugin branding logo palette reports email')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-palette text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">White Label &amp; Styling Hub</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Master Agency Palette, WordPress admin Companion, Client Reports, and notification emails</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
                            <span class="text-[10px] px-2 py-0.5 rounded-full font-medium {{ ($branding['enabled'] ?? false) ? 'bg-[var(--color-status-green-bg)] text-[var(--color-status-green)]' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]' }}">
                                {{ ($branding['enabled'] ?? false) ? 'Branded' : 'Default' }}
                            </span>
                            <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform"></i>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        {{-- 2. Fleet Policies --}}
        <div class="card p-5 flex flex-col justify-between transition-all duration-200"
             data-card-id="fleet_policies"
             @dragover.prevent="onDragOver($event, 'fleet_policies')"
             @dragleave="onDragLeave($event)"
             @drop="onDrop($event, 'fleet_policies')"
             :class="{
                 'opacity-40 scale-[0.99] border-dashed border-2 border-[var(--color-brand)] shadow-lg': draggedCard === 'fleet_policies',
                 'ring-2 ring-[var(--color-brand)] ring-offset-2 ring-offset-[var(--color-surface)]': dragOverCard === 'fleet_policies' && draggedCard !== 'fleet_policies'
             }"
             x-show="matches('fleet policies wordpress plugins ingest scheduling security scans backup relay tags labels firewall ban retention gatekeeper lockouts login')">
            <div>
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-[var(--color-border-light)]">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 border border-blue-100/80 dark:border-blue-900/40 flex items-center justify-center text-sm shadow-2xs">
                            <i class="fa-solid fa-sliders"></i>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-[var(--color-ink-strong)]">Fleet Policies</h2>
                            <p class="text-xs text-[var(--color-ink-muted)]">Automated policies and remote site operations across the fleet</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] border border-[var(--color-border-light)]">Fleet</span>
                        <div class="flex items-center gap-0.5 pl-2 border-l border-[var(--color-border-light)] text-[var(--color-ink-muted)]">
                            <button type="button"
                                    @click="moveCard('fleet_policies', -1)"
                                    :disabled="order.indexOf('fleet_policies') === 0"
                                    class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] disabled:opacity-20 disabled:cursor-not-allowed transition-colors"
                                    title="Move module earlier"
                                    aria-label="Move module earlier">
                                <i class="fa-solid fa-arrow-up text-[10px]"></i>
                            </button>
                            <button type="button"
                                    @click="moveCard('fleet_policies', 1)"
                                    :disabled="order.indexOf('fleet_policies') === order.length - 1"
                                    class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] disabled:opacity-20 disabled:cursor-not-allowed transition-colors"
                                    title="Move module later"
                                    aria-label="Move module later">
                                <i class="fa-solid fa-arrow-down text-[10px]"></i>
                            </button>
                            <div draggable="true"
                                 @dragstart.stop="onDragStart($event, 'fleet_policies')"
                                 @dragend="onDragEnd()"
                                 class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] cursor-grab active:cursor-grabbing transition-colors"
                                 title="Drag to reorder module">
                                <i class="fa-solid fa-grip-vertical text-[11px]"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="divide-y divide-[var(--color-border-light)]">
                    <a href="{{ route('settings.wordpress-plugins.index') }}"
                       x-show="matches('wordpress plugins curated mu-plugins catalog')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-brands fa-wordpress text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">WordPress Plugins</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Curated plugin directory, required plugins, and version tracking</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('downloads.index') }}"
                       x-show="matches('plugins download zip companion renegade package release')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-download text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Plugin Downloads</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Download latest Companion &amp; Renegade release packages</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('settings.ingest.index') }}"
                       x-show="matches('ingest scheduling cron gatekeeper lockouts wordfence pulls cadence background threat logs retention prune nginx')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Ingest &amp; Scheduling</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Gatekeeper/Wordfence pulls and raw nginx log retention</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('settings.security-scans.index') }}"
                       x-show="matches('security scans sucuri core checksums malware verification')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-shield-halved text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Security Scans</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Sucuri SiteCheck &amp; WP Core verification schedule and auto-scans</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('settings.backup-relay.index') }}"
                       x-show="matches('backup relay glacier s3 aws cold storage retention')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-cloud-arrow-up text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Backup Relay</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">AWS S3 Glacier Instant Retrieval cold backup sync &amp; retention</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('settings.care-plans.index') }}"
                       x-show="matches('care plans policy tiered maintenance updates scans')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-shield-heart text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Care Plans Policy</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Tiered care plan enrollment or fleet-wide automated maintenance</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('settings.gatekeeper.index') }}"
                       x-show="matches('login lockouts gatekeeper brute force security failed login attempts')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-lock text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Login Lockouts (Gatekeeper)</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Native login brute force throttling, threshold, and custom lockout copy</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('bans.active') }}"
                       x-show="matches('firewall ban retention fail2ban ip bans cleanup purge')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-ban text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Firewall &amp; Ban Retention</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">IP ban retention period, automated cleanup schedule, and bulk pruning</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('settings.tags.index') }}"
                       x-show="matches('server tags labels production staging environment')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-tags text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Server Tags</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Color-coded environment categories for the dashboard</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
                            <span class="text-[10px] px-2 py-0.5 rounded-full font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]">
                                {{ $tagsCount }} {{ Str::plural('tag', $tagsCount) }}
                            </span>
                            <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform"></i>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        {{-- 3. Integrations & Alerts --}}
        <div class="card p-5 flex flex-col justify-between transition-all duration-200"
             data-card-id="integrations_alerts"
             @dragover.prevent="onDragOver($event, 'integrations_alerts')"
             @dragleave="onDragLeave($event)"
             @drop="onDrop($event, 'integrations_alerts')"
             :class="{
                 'opacity-40 scale-[0.99] border-dashed border-2 border-[var(--color-brand)] shadow-lg': draggedCard === 'integrations_alerts',
                 'ring-2 ring-[var(--color-brand)] ring-offset-2 ring-offset-[var(--color-surface)]': dragOverCard === 'integrations_alerts' && draggedCard !== 'integrations_alerts'
             }"
             x-show="matches('integrations alerts api credentials modules twilio sms slack mattermost bill.com feedback notes bug report atarim claude')">
            <div>
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-[var(--color-border-light)]">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 border border-blue-100/80 dark:border-blue-900/40 flex items-center justify-center text-sm shadow-2xs">
                            <i class="fa-solid fa-plug"></i>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-[var(--color-ink-strong)]">Integrations &amp; Alerts</h2>
                            <p class="text-xs text-[var(--color-ink-muted)]">API credentials, communication channels, and modular add-ons</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] border border-[var(--color-border-light)]">Connected</span>
                        <div class="flex items-center gap-0.5 pl-2 border-l border-[var(--color-border-light)] text-[var(--color-ink-muted)]">
                            <button type="button"
                                    @click="moveCard('integrations_alerts', -1)"
                                    :disabled="order.indexOf('integrations_alerts') === 0"
                                    class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] disabled:opacity-20 disabled:cursor-not-allowed transition-colors"
                                    title="Move module earlier"
                                    aria-label="Move module earlier">
                                <i class="fa-solid fa-arrow-up text-[10px]"></i>
                            </button>
                            <button type="button"
                                    @click="moveCard('integrations_alerts', 1)"
                                    :disabled="order.indexOf('integrations_alerts') === order.length - 1"
                                    class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] disabled:opacity-20 disabled:cursor-not-allowed transition-colors"
                                    title="Move module later"
                                    aria-label="Move module later">
                                <i class="fa-solid fa-arrow-down text-[10px]"></i>
                            </button>
                            <div draggable="true"
                                 @dragstart.stop="onDragStart($event, 'integrations_alerts')"
                                 @dragend="onDragEnd()"
                                 class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] cursor-grab active:cursor-grabbing transition-colors"
                                 title="Drag to reorder module">
                                <i class="fa-solid fa-grip-vertical text-[11px]"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="divide-y divide-[var(--color-border-light)]">
                    <a href="{{ route('settings.integrations.index') }}"
                       x-show="matches('api credentials keys cloudflare digitalocean aws hetzner vultr kinsta gridpane')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-key text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">API Credentials</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Cloudflare, AWS, DigitalOcean, Hetzner, Vultr, Kinsta &amp; GridPane tokens</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('settings.modules.index') }}"
                       x-show="matches('modules directory ecosystem marketplace catalog feedback')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-boxes-stacked text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Module Directory</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Discover, enable, and manage official and community modules</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    @if (Route::has('feedback.index'))
                    <a href="{{ route('feedback.index') }}"
                       x-show="matches('feedback notes bug report pins claude atarim working list module comments')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-comment-dots text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Feedback &amp; Bug Notes</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">In-app visual right-click notes, live pin overlays, team discussions &amp; Claude prompts</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>
                    @endif

                    <a href="{{ route('settings.notifications.index') }}"
                       x-show="matches('sms twilio phone notifications alerts quiet hours')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-comment-sms text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">SMS Notifications (Twilio)</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Site downtime SMS alerts, on-call recipients, and quiet-hours schedule</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    @if (Route::has('settings.slack.index'))
                    <a href="{{ route('settings.slack.index') }}"
                       x-show="matches('slack notifications channels webhooks alerts')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-brands fa-slack text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Slack Notifications</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Channel alerts for site outages, security findings, and updates</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>
                    @endif

                    @if (Route::has('settings.mattermost.index'))
                    <a href="{{ route('settings.mattermost.index') }}"
                       x-show="matches('mattermost notifications webhooks team chat')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-comment-dots text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Mattermost Notifications</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Self-hosted Mattermost channels and alert webhooks</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>
                    @endif

                    @if (Route::has('settings.bill-com.index'))
                    <a href="{{ route('settings.bill-com.index') }}"
                       x-show="matches('bill.com billing sync invoices customers care plan')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-file-invoice-dollar text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Bill.com Sync</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Customer matching and care-plan recurring subscription synchronization</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>
                    @endif
                </div>
            </div>
        </div>

        {{-- 4. Operations & Tools --}}
        <div class="card p-5 flex flex-col justify-between transition-all duration-200"
             data-card-id="operations_tools"
             @dragover.prevent="onDragOver($event, 'operations_tools')"
             @dragleave="onDragLeave($event)"
             @drop="onDrop($event, 'operations_tools')"
             :class="{
                 'opacity-40 scale-[0.99] border-dashed border-2 border-[var(--color-brand)] shadow-lg': draggedCard === 'operations_tools',
                 'ring-2 ring-[var(--color-brand)] ring-offset-2 ring-offset-[var(--color-surface)]': dragOverCard === 'operations_tools' && draggedCard !== 'operations_tools'
             }"
             x-show="matches('operations tools capacity server updates maintenance history ssh credentials weird stats ai remedy airemedy')">
            <div>
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-[var(--color-border-light)]">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 border border-blue-100/80 dark:border-blue-900/40 flex items-center justify-center text-sm shadow-2xs">
                            <i class="fa-solid fa-toolbox"></i>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-[var(--color-ink-strong)]">Operations &amp; Tools</h2>
                            <p class="text-xs text-[var(--color-ink-muted)]">Active infrastructure utilities, capacity tracking, and run histories</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <a href="{{ route('capacity.index') }}" class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-brand)] hover:bg-[var(--color-surface)] border border-[var(--color-border-light)] transition-colors">Operations Hub &rarr;</a>
                        <div class="flex items-center gap-0.5 pl-2 border-l border-[var(--color-border-light)] text-[var(--color-ink-muted)]">
                            <button type="button"
                                    @click="moveCard('operations_tools', -1)"
                                    :disabled="order.indexOf('operations_tools') === 0"
                                    class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] disabled:opacity-20 disabled:cursor-not-allowed transition-colors"
                                    title="Move module earlier"
                                    aria-label="Move module earlier">
                                <i class="fa-solid fa-arrow-up text-[10px]"></i>
                            </button>
                            <button type="button"
                                    @click="moveCard('operations_tools', 1)"
                                    :disabled="order.indexOf('operations_tools') === order.length - 1"
                                    class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] disabled:opacity-20 disabled:cursor-not-allowed transition-colors"
                                    title="Move module later"
                                    aria-label="Move module later">
                                <i class="fa-solid fa-arrow-down text-[10px]"></i>
                            </button>
                            <div draggable="true"
                                 @dragstart.stop="onDragStart($event, 'operations_tools')"
                                 @dragend="onDragEnd()"
                                 class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] cursor-grab active:cursor-grabbing transition-colors"
                                 title="Drag to reorder module">
                                <i class="fa-solid fa-grip-vertical text-[11px]"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="divide-y divide-[var(--color-border-light)]">
                    <a href="{{ route('capacity.index') }}"
                       x-show="matches('capacity servers disk memory cpu load headroom')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-gauge-high text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Capacity Dashboard</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Fleet-wide server memory, disk utilization, and resource headroom</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
                            <span class="text-[10px] px-2 py-0.5 rounded-full font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]">
                                {{ $serversCount }} {{ Str::plural('server', $serversCount) }}
                            </span>
                            <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform"></i>
                        </div>
                    </a>

                    <a href="{{ route('capacity.settings') }}"
                       x-show="matches('capacity settings thresholds limits alerts overcommit')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-sliders text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Capacity Settings</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Configure alert thresholds, storage limits, and overcommit parameters</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('operations.server-updates.index') }}"
                       x-show="matches('server updates apt yum system packages patches linux')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-cube text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Server Updates</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">OS-level package management, security patches, and reboots across Linux nodes</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('maintenance-history.index') }}"
                       x-show="matches('maintenance history audit log automation timeline backup runs')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Maintenance History</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Complete audit trail of automated maintenance, script runs, and backups</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('servers.credentials.bulk') }}"
                       x-show="matches('ssh credentials bulk test keys passwords server access')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-key text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">SSH Credentials (Bulk)</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Test SSH connectivity fleet-wide and update server keys or credentials</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    @if (app(\Modules\Core\ModuleStateResolver::class)->isEnabled('ai-remedy'))
                        <a href="{{ route('ai-remedy.index') }}"
                           x-show="matches('airemedy ai remedy operations triage forensics self healing spikes diagnosis openrouter')"
                           class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                            <div class="flex items-start gap-3 min-w-0">
                                <i class="fa-solid fa-wand-magic-sparkles text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">AiRemedy Triage &amp; Self-Healing</div>
                                    <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Autonomous incident diagnosis, root-cause forensics, and staged SSH remediation</div>
                                </div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                        </a>
                    @endif

                    <a href="{{ route('settings.weird-stats.index') }}"
                       x-show="matches('weird stats fleet analytics records anomalies numbers')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-chart-pie text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Weird Stats</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Fleet anomalies, distribution metrics, oldest plugins, and platform records</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>
                </div>
            </div>
        </div>

        {{-- 5. System & Workspace --}}
        <div class="card p-5 flex flex-col justify-between transition-all duration-200"
             data-card-id="system_workspace"
             @dragover.prevent="onDragOver($event, 'system_workspace')"
             @dragleave="onDragLeave($event)"
             @drop="onDrop($event, 'system_workspace')"
             :class="{
                 'opacity-40 scale-[0.99] border-dashed border-2 border-[var(--color-brand)] shadow-lg': draggedCard === 'system_workspace',
                 'ring-2 ring-[var(--color-brand)] ring-offset-2 ring-offset-[var(--color-surface)]': dragOverCard === 'system_workspace' && draggedCard !== 'system_workspace'
             }"
             x-show="matches('system workspace team users updates maintenance backup diagnostics setup docs')">
            <div>
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-[var(--color-border-light)]">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 border border-blue-100/80 dark:border-blue-900/40 flex items-center justify-center text-sm shadow-2xs">
                            <i class="fa-solid fa-server"></i>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-[var(--color-ink-strong)]">System &amp; Workspace</h2>
                            <p class="text-xs text-[var(--color-ink-muted)]">Instance administration, operator accounts, database maintenance, and docs</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] border border-[var(--color-border-light)]">Core</span>
                        <div class="flex items-center gap-0.5 pl-2 border-l border-[var(--color-border-light)] text-[var(--color-ink-muted)]">
                            <button type="button"
                                    @click="moveCard('system_workspace', -1)"
                                    :disabled="order.indexOf('system_workspace') === 0"
                                    class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] disabled:opacity-20 disabled:cursor-not-allowed transition-colors"
                                    title="Move module earlier"
                                    aria-label="Move module earlier">
                                <i class="fa-solid fa-arrow-up text-[10px]"></i>
                            </button>
                            <button type="button"
                                    @click="moveCard('system_workspace', 1)"
                                    :disabled="order.indexOf('system_workspace') === order.length - 1"
                                    class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] disabled:opacity-20 disabled:cursor-not-allowed transition-colors"
                                    title="Move module later"
                                    aria-label="Move module later">
                                <i class="fa-solid fa-arrow-down text-[10px]"></i>
                            </button>
                            <div draggable="true"
                                 @dragstart.stop="onDragStart($event, 'system_workspace')"
                                 @dragend="onDragEnd()"
                                 class="w-6 h-6 rounded flex items-center justify-center text-xs hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] cursor-grab active:cursor-grabbing transition-colors"
                                 title="Drag to reorder module">
                                <i class="fa-solid fa-grip-vertical text-[11px]"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="divide-y divide-[var(--color-border-light)]">
                    <a href="{{ route('settings.users.index') }}"
                       x-show="matches('team users accounts operators access roles security')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-people-group text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Team &amp; Users</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Invite operators, manage user accounts, and configure permissions</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
                            <span class="text-[10px] px-2 py-0.5 rounded-full font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]">
                                {{ $userCount }} {{ Str::plural('user', $userCount) }}
                            </span>
                            <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform"></i>
                        </div>
                    </a>

                    <a href="{{ route('settings.updates.index') }}"
                       x-show="matches('system updates core releases clockwork control upgrade version')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-arrows-rotate text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">System Updates</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Clockwork Control core updates, release notes, and version status</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('settings.maintenance.index') }}"
                       x-show="matches('db backup database mysqldump telemetry data maintenance')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-database text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">DB Backup &amp; Telemetry</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Instant database SQL dump download and anonymous telemetry toggles</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('settings.diagnostics.index') }}"
                       x-show="matches('diagnostics health check redis database network status')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-stethoscope text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">System Diagnostics</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Connectivity checks across database, Redis cache, filesystem, and external APIs</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('setup.index') }}"
                       x-show="matches('setup wizard onboarding checklist credentials initial')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-list-check text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Setup Wizard</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Revisit initial onboarding wizard, hosting setups, and provider checklists</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>

                    <a href="{{ route('docs.index') }}"
                       x-show="matches('documentation docs help guides knowledge base runbooks')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-book text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Documentation</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Integrated operator runbooks, API documentation, and architecture guides</div>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform ml-3"></i>
                    </a>
                </div>
            </div>
        </div>

    </div>

    {{-- Zero-FOUC instant reorder before first paint --}}
    <script>
        (function() {
            try {
                var saved = JSON.parse(localStorage.getItem('cw_settings_cards_order') || 'null');
                if (Array.isArray(saved) && saved.length > 0) {
                    var grid = document.getElementById('settings-cards-grid');
                    if (grid) {
                        saved.forEach(function(id) {
                            var el = grid.querySelector('[data-card-id="' + id + '"]');
                            if (el) grid.appendChild(el);
                        });
                    }
                }
            } catch (e) {}
        })();
    </script>
</div>

<script>
function settingsHubManager() {
    return {
        search: '',
        defaultOrder: ['branding', 'fleet_policies', 'integrations_alerts', 'operations_tools', 'system_workspace'],
        order: ['branding', 'fleet_policies', 'integrations_alerts', 'operations_tools', 'system_workspace'],
        isCustom: false,
        draggedCard: null,
        dragOverCard: null,
        savedToast: false,
        savedMessage: 'Order saved',
        toastTimeout: null,

        init() {
            try {
                var raw = localStorage.getItem('cw_settings_cards_order');
                if (raw) {
                    var saved = JSON.parse(raw);
                    if (Array.isArray(saved) && saved.length > 0) {
                        var validSaved = saved.filter(function(id) {
                            return this.defaultOrder.indexOf(id) !== -1;
                        }.bind(this));
                        var missing = this.defaultOrder.filter(function(id) {
                            return validSaved.indexOf(id) === -1;
                        });
                        this.order = validSaved.concat(missing);
                        this.isCustom = JSON.stringify(this.order) !== JSON.stringify(this.defaultOrder);
                        this.applyOrderToDom();
                    }
                }
            } catch (e) {}
        },

        matches(text) {
            if (!this.search.trim()) return true;
            return text.toLowerCase().includes(this.search.toLowerCase().trim());
        },

        applyOrderToDom() {
            var grid = document.getElementById('settings-cards-grid');
            if (!grid) return;
            this.order.forEach(function(id) {
                var el = grid.querySelector('[data-card-id="' + id + '"]');
                if (el) {
                    grid.appendChild(el);
                }
            });
        },

        saveOrder() {
            this.isCustom = JSON.stringify(this.order) !== JSON.stringify(this.defaultOrder);
            try {
                localStorage.setItem('cw_settings_cards_order', JSON.stringify(this.order));
            } catch (e) {}
            this.showToast('Order saved');
        },

        resetOrder() {
            this.order = this.defaultOrder.slice();
            this.isCustom = false;
            try {
                localStorage.removeItem('cw_settings_cards_order');
            } catch (e) {}
            this.applyOrderToDom();
            this.showToast('Reset to default order');
        },

        moveCard(cardId, direction) {
            var fromIndex = this.order.indexOf(cardId);
            if (fromIndex === -1) return;
            var toIndex = fromIndex + direction;
            if (toIndex < 0 || toIndex >= this.order.length) return;

            var newOrder = this.order.slice();
            var moved = newOrder.splice(fromIndex, 1)[0];
            newOrder.splice(toIndex, 0, moved);
            this.order = newOrder;

            this.applyOrderToDom();
            this.saveOrder();
        },

        onDragStart(event, cardId) {
            this.draggedCard = cardId;
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', cardId);
            }
        },

        onDragEnd() {
            this.draggedCard = null;
            this.dragOverCard = null;
        },

        onDragOver(event, cardId) {
            if (this.draggedCard && this.draggedCard !== cardId) {
                this.dragOverCard = cardId;
            }
        },

        onDragLeave(event) {
            // Handled dynamically
        },

        onDrop(event, targetCardId) {
            var sourceCardId = this.draggedCard || (event.dataTransfer ? event.dataTransfer.getData('text/plain') : null);
            if (!sourceCardId || sourceCardId === targetCardId) {
                this.draggedCard = null;
                this.dragOverCard = null;
                return;
            }

            var fromIndex = this.order.indexOf(sourceCardId);
            var toIndex = this.order.indexOf(targetCardId);
            if (fromIndex !== -1 && toIndex !== -1) {
                var newOrder = this.order.slice();
                var moved = newOrder.splice(fromIndex, 1)[0];
                newOrder.splice(toIndex, 0, moved);
                this.order = newOrder;

                this.applyOrderToDom();
                this.saveOrder();
            }

            this.draggedCard = null;
            this.dragOverCard = null;
        },

        showToast(msg) {
            this.savedMessage = msg;
            this.savedToast = true;
            if (this.toastTimeout) clearTimeout(this.toastTimeout);
            this.toastTimeout = setTimeout(function() {
                this.savedToast = false;
            }.bind(this), 2500);
        }
    };
}
</script>
@endsection
