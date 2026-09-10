@extends('layouts.app')

@section('title', 'Settings & Operations · Clockwork Control')

@section('content')
<div x-data="{
    search: '',
    matches(text) {
        if (!this.search.trim()) return true;
        return text.toLowerCase().includes(this.search.toLowerCase().trim());
    }
}">
    @include('settings._tabs')

    <x-page-header title="Settings & Operations"
        subtitle="Manage fleet configuration, API integrations, operational tools, and system administration.">
        <x-slot:actions>
            <div class="relative w-72 sm:w-80">
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

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- 1. Fleet & Branding --}}
        <div class="card p-5 flex flex-col justify-between"
             x-show="matches('fleet branding companion white label tags wordpress plugins ingest scheduling security scans backup relay')">
            <div>
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-[var(--color-border-light)]">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 border border-blue-100/80 dark:border-blue-900/40 flex items-center justify-center text-sm shadow-2xs">
                            <i class="fa-solid fa-sliders"></i>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-[var(--color-ink-strong)]">Fleet &amp; Branding</h2>
                            <p class="text-xs text-[var(--color-ink-muted)]">Customization and policies applied across monitored sites</p>
                        </div>
                    </div>
                    <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] border border-[var(--color-border-light)]">Fleet</span>
                </div>

                <div class="divide-y divide-[var(--color-border-light)]">
                    <a href="{{ route('settings.companion.index') }}"
                       x-show="matches('white labeling companion white label mu plugin branding logo reports email')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-paintbrush text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">White Labeling</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Brand WordPress admin, Client Reports, and notification emails</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
                            <span class="text-[10px] px-2 py-0.5 rounded-full font-medium {{ ($branding['enabled'] ?? false) ? 'bg-[var(--color-status-green-bg)] text-[var(--color-status-green)]' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]' }}">
                                {{ ($branding['enabled'] ?? false) ? 'Branded' : 'Default' }}
                            </span>
                            <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform"></i>
                        </div>
                    </a>

                    <a href="{{ route('settings.tags.index') }}"
                       x-show="matches('server tags labels production staging environment')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-tags text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Server Tags</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Color-coded environment categories (Production, Staging, Shared)</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
                            <span class="text-[10px] px-2 py-0.5 rounded-full font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]">
                                {{ $tagsCount }} {{ Str::plural('tag', $tagsCount) }}
                            </span>
                            <i class="fa-solid fa-chevron-right text-[10px] text-[var(--color-ink-muted)] group-hover:translate-x-0.5 transition-transform"></i>
                        </div>
                    </a>

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

                    <a href="{{ route('settings.ingest.index') }}"
                       x-show="matches('ingest scheduling cron llar pulls cadence background')"
                       class="group py-2.5 px-2 -mx-2 rounded-lg flex items-center justify-between hover:bg-[var(--color-surface-alt)] transition-colors">
                        <div class="flex items-start gap-3 min-w-0">
                            <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)] group-hover:text-[var(--color-brand)] text-xs mt-1 w-4 transition-colors"></i>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] transition-colors">Ingest &amp; Scheduling</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)] truncate">Automated LLAR security log pulls, uptime checks, and background tasks</div>
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
                </div>
            </div>
        </div>

        {{-- 2. Integrations & Alerts --}}
        <div class="card p-5 flex flex-col justify-between"
             x-show="matches('integrations alerts api credentials modules twilio sms slack mattermost bill.com')">
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
                    <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] border border-[var(--color-border-light)]">Connected</span>
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
                       x-show="matches('modules directory ecosystem marketplace catalog')"
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

        {{-- 3. Operations & Tools --}}
        <div class="card p-5 flex flex-col justify-between"
             x-show="matches('operations tools capacity server updates maintenance history ssh credentials weird stats')">
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
                    <a href="{{ route('capacity.index') }}" class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-brand)] hover:bg-[var(--color-surface)] border border-[var(--color-border-light)] transition-colors">Operations Hub &rarr;</a>
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

        {{-- 4. System & Workspace --}}
        <div class="card p-5 flex flex-col justify-between"
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
                    <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] border border-[var(--color-border-light)]">Core</span>
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
</div>
@endsection
