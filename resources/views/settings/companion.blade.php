@extends('layouts.app')

@section('title', 'Companion & White Label · Clockwork Control')

@section('content')
    <div x-data="{
        enabled: {{ $branding['enabled'] ? 'true' : 'false' }},
        companyName: '{{ addslashes($branding['company_name']) }}',
        companyUrl: '{{ addslashes($branding['company_url']) }}',
        supportEmail: '{{ addslashes($branding['support_email']) }}',
        supportUrl: '{{ addslashes($branding['support_url']) }}',
        pluginName: '{{ addslashes($branding['plugin_name']) }}',
        pluginDescription: '{{ addslashes($branding['plugin_description']) }}',
        menuTitle: '{{ addslashes($branding['menu_title']) }}',
        menuIcon: '{{ addslashes($branding['menu_icon']) }}',
        logoUrl: '{{ addslashes($branding['logo_url']) }}',
        hidePluginRow: {{ $branding['hide_plugin_row'] ? 'true' : 'false' }},
        hideHelpLinks: {{ $branding['hide_help_links'] ? 'true' : 'false' }},
        footerText: '{{ addslashes($branding['footer_text']) }}',
        previewTab: 'screen',
        syncing: false,
        syncMessage: ''
    }">
        <x-page-header title="Companion & White Label"
            subtitle="Customize and white-label the WordPress Companion mu-plugin across your fleet. Change the company author, plugin name, dashboard logo, support email, and admin menus.">
            <x-slot:actions>
                <a href="{{ route('settings.companion.preview') }}" target="_blank" class="btn-pill-nav text-sm inline-flex items-center gap-2">
                    <i class="fa-solid fa-arrow-up-right-from-square text-[var(--color-ink-muted)]"></i>
                    <span>Full Screen Preview</span>
                </a>
                <form method="POST" action="{{ route('settings.companion.sync') }}" class="inline" @submit="syncing = true">
                    @csrf
                    <button type="submit" class="btn-pill-nav text-sm inline-flex items-center gap-2" :disabled="syncing">
                        <i class="fa-solid fa-arrows-rotate text-[var(--color-ink-muted)]" :class="syncing ? 'fa-spin' : ''"></i>
                        <span x-text="syncing ? 'Syncing Fleet...' : 'Sync Fleet Now'"></span>
                    </button>
                </form>
            </x-slot:actions>
        </x-page-header>

        @if (session('status'))
            <div class="card p-4 mb-6 status-green flex items-center gap-2">
                <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
            </div>
        @endif
        @if (session('warning'))
            <div class="card p-4 mb-6 status-yellow flex items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation"></i> {{ session('warning') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="card p-4 mb-6 status-red">
                <div class="font-semibold mb-1">Please fix the following errors:</div>
                <ul class="list-disc list-inside text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Metrics bar --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 max-w-6xl">
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Monitored Sites</div>
                <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ $totalSites }}</div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Active in fleet</div>
            </div>
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Companion Installed</div>
                <div class="text-2xl font-display text-[var(--color-status-green)] font-data">{{ $totalInstalled }}</div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Receiving updates</div>
            </div>
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">White-Label Status</div>
                <div class="text-lg font-semibold mt-1">
                    <span x-show="enabled" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                    </span>
                    <span x-show="!enabled" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-700">
                        <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> Default Clockwork
                    </span>
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-1">In WordPress admin</div>
            </div>
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Fleet Rollout</div>
                <div class="text-2xl font-display text-[var(--color-brand)] font-data">HMAC REST</div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Push on save &amp; install</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 max-w-6xl">
            {{-- Left column: Form configuration (7 cols) --}}
            <div class="lg:col-span-7 space-y-6">
                <form method="POST" action="{{ route('settings.companion.update') }}" class="card p-6 space-y-6">
                    @csrf
                    @method('PATCH')

                    {{-- Master Switch --}}
                    <div class="flex items-center justify-between pb-5 border-b border-[var(--color-border-light)]">
                        <div>
                            <div class="font-display text-base font-semibold text-[var(--color-ink-strong)]">Enable White-Labeling</div>
                            <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">Override Clockwork Web Dev authoring and branding in client WordPress admin dashboards.</div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="enabled" value="1" x-model="enabled" class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--color-brand)]"></div>
                        </label>
                    </div>

                    {{-- Agency Identity Section --}}
                    <div class="space-y-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">1. Agency &amp; Company Identity</h3>
                        
                        <div>
                            <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Company / Agency Name</label>
                            <input type="text" name="company_name" x-model="companyName" required
                                class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data"
                                placeholder="e.g. Acme Web Services">
                            <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Replaces "Clockwork Web Dev" as the plugin author and company name.</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Agency Website</label>
                                <input type="url" name="company_url" x-model="companyUrl"
                                    class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data"
                                    placeholder="https://youragency.com">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Support &amp; Help Desk Email</label>
                                <input type="email" name="support_email" x-model="supportEmail"
                                    class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data"
                                    placeholder="support@youragency.com">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Support Portal / Client Portal URL <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="url" name="support_url" x-model="supportUrl"
                                class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data"
                                placeholder="https://clients.youragency.com/tickets">
                        </div>
                    </div>

                    <div class="border-t border-[var(--color-border-light)] pt-5 space-y-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">2. WordPress Plugin Listing Metadata</h3>

                        <div>
                            <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Plugin Name</label>
                            <input type="text" name="plugin_name" x-model="pluginName" required
                                class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data"
                                placeholder="e.g. Acme Site Care Companion">
                            <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Displayed in the WordPress Plugins &amp; Must-Use list.</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Plugin Description</label>
                            <textarea name="plugin_description" x-model="pluginDescription" rows="2"
                                class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)]"
                                placeholder="Short description for the WordPress plugins table"></textarea>
                        </div>
                    </div>

                    <div class="border-t border-[var(--color-border-light)] pt-5 space-y-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">3. WordPress Admin Menu &amp; Visuals</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Sidebar Menu Label</label>
                                <input type="text" name="menu_title" x-model="menuTitle" required
                                    class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)]"
                                    placeholder="e.g. Site Care or Care Plan">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Menu Dashicon</label>
                                <select name="menu_icon" x-model="menuIcon"
                                    class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data">
                                    <option value="dashicons-clock">dashicons-clock (Default)</option>
                                    <option value="dashicons-shield">dashicons-shield (Security)</option>
                                    <option value="dashicons-hammer">dashicons-hammer (Maintenance)</option>
                                    <option value="dashicons-admin-tools">dashicons-admin-tools (Tools)</option>
                                    <option value="dashicons-heart">dashicons-heart (Care)</option>
                                    <option value="dashicons-cloud">dashicons-cloud (Cloud)</option>
                                    <option value="dashicons-chart-area">dashicons-chart-area (Telemetry)</option>
                                    <option value="dashicons-superhero">dashicons-superhero (Agency)</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Admin Footer Notice / Custom Credit <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="text" name="footer_text" x-model="footerText"
                                class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)]"
                                placeholder="e.g. Maintained with care by Acme Web Services">
                        </div>
                    </div>

                    <div class="border-t border-[var(--color-border-light)] pt-5 space-y-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">4. Client Access &amp; Visibility Controls</h3>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="hide_plugin_row" value="1" x-model="hidePluginRow"
                                class="mt-0.5 rounded border-slate-300 text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                            <div>
                                <div class="text-xs font-medium text-[var(--color-ink-strong)]">Hide from Plugins List</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)]">Hide the mu-plugin from <code>/wp-admin/plugins.php</code> for non-super-admins to avoid client confusion.</div>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="hide_help_links" value="1" x-model="hideHelpLinks"
                                class="mt-0.5 rounded border-slate-300 text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                            <div>
                                <div class="text-xs font-medium text-[var(--color-ink-strong)]">Suppress Upstream Documentation Links</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)]">Hide external Clockwork Control docs links in wp-admin, routing clients to your agency support email instead.</div>
                            </div>
                        </label>
                    </div>

                    <div class="border-t border-[var(--color-border-light)] pt-5 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <label class="inline-flex items-center gap-2 text-xs text-[var(--color-ink-strong)] cursor-pointer">
                                <input type="checkbox" name="sync_fleet" value="1" class="rounded border-slate-300 text-[var(--color-brand)]" checked>
                                <span>Push to all connected sites upon saving</span>
                            </label>
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="submit" class="btn-pill-primary px-5 py-2 text-sm font-semibold">
                                <i class="fa-solid fa-floppy-disk mr-1.5"></i> Save Settings
                            </button>
                        </div>
                    </div>
                </form>

                {{-- Logo Upload Card --}}
                <div class="card p-6">
                    <h3 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-1">Agency Brand Logo</h3>
                    <p class="text-xs text-[var(--color-ink-soft)] mb-4">Upload your agency logo to display at the top of the WordPress Companion dashboard. Recommended format: SVG or transparent PNG, max height 60px.</p>

                    <form method="POST" action="{{ route('settings.companion.logo') }}" enctype="multipart/form-data" class="flex flex-col sm:flex-row items-center gap-4">
                        @csrf
                        <div class="w-full">
                            <input type="file" name="logo" accept="image/png,image/svg+xml,image/jpeg,image/webp" required
                                class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-[var(--color-surface-alt)] file:text-[var(--color-ink-strong)] hover:file:bg-slate-200">
                        </div>
                        <button type="submit" class="btn-pill-secondary text-xs whitespace-nowrap px-4 py-2">
                            <i class="fa-solid fa-upload mr-1"></i> Upload Logo
                        </button>
                    </form>

                    @if ($branding['logo_url'])
                        <div class="mt-4 pt-4 border-t border-[var(--color-border-light)] flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 bg-slate-100 rounded border border-slate-200 p-1 flex items-center justify-center">
                                    <img src="{{ $branding['logo_url'] }}" alt="Logo" class="max-h-full max-w-full object-contain">
                                </div>
                                <div class="text-xs font-mono text-[var(--color-ink-soft)] truncate max-w-xs">{{ $branding['logo_url'] }}</div>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Reset to Defaults Card --}}
                <div class="card p-6 flex items-center justify-between bg-slate-50 border-dashed">
                    <div>
                        <div class="text-sm font-medium text-[var(--color-ink-strong)]">Reset to Clockwork Defaults</div>
                        <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">Revert company name, plugin details, and support links back to Clockwork Control defaults.</div>
                    </div>
                    <form method="POST" action="{{ route('settings.companion.reset') }}" onsubmit="return confirm('Reset all companion branding back to Clockwork Control defaults?');">
                        @csrf
                        <button type="submit" class="btn-pill-secondary text-xs text-rose-600 hover:text-rose-700 hover:bg-rose-50 border-rose-200">
                            <i class="fa-solid fa-rotate-left mr-1"></i> Reset Defaults
                        </button>
                    </form>
                </div>
            </div>

            {{-- Right column: Live WordPress Admin Preview (5 cols) --}}
            <div class="lg:col-span-5 space-y-6">
                <div class="sticky top-6 space-y-6">
                    {{-- Preview Mockup Container --}}
                    <div class="card p-5 border-slate-300 shadow-sm bg-slate-900 text-white overflow-hidden rounded-xl">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-700 mb-4 flex-wrap gap-2">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-rose-500 inline-block"></span>
                                <span class="w-3 h-3 rounded-full bg-amber-500 inline-block"></span>
                                <span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span>
                                <span class="text-xs font-mono text-slate-400 ml-1">Live Preview</span>
                            </div>
                            
                            {{-- View Switcher Pills --}}
                            <div class="flex items-center gap-1 bg-slate-800 p-0.5 rounded-lg border border-slate-700">
                                <button type="button" @click="previewTab = 'screen'"
                                    class="px-2.5 py-1 rounded text-[11px] font-medium transition-colors"
                                    :class="previewTab === 'screen' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200'">
                                    <i class="fa-solid fa-desktop mr-1"></i> Companion Screen
                                </button>
                                <button type="button" @click="previewTab = 'surfaces'"
                                    class="px-2.5 py-1 rounded text-[11px] font-medium transition-colors"
                                    :class="previewTab === 'surfaces' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200'">
                                    <i class="fa-brands fa-wordpress mr-1"></i> Admin Surfaces
                                </button>
                            </div>

                            <a href="{{ route('settings.companion.preview') }}" target="_blank"
                                class="text-[11px] text-sky-400 hover:text-sky-300 hover:underline inline-flex items-center gap-1 font-medium">
                                <i class="fa-solid fa-expand"></i> Full
                            </a>
                        </div>

                        {{-- VIEW 1: Full Companion Plugin Screen Mockup --}}
                        <div x-show="previewTab === 'screen'" class="space-y-4">
                            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold flex items-center justify-between">
                                <span><i class="fa-solid fa-window-maximize mr-1"></i> Client WP-Admin: Companion Dashboard</span>
                                <span class="text-emerald-400 text-[10px]"><i class="fa-solid fa-circle-check mr-1"></i> Live Render</span>
                            </div>

                            {{-- Mockup Window Shell --}}
                            <div class="bg-white text-slate-900 rounded-lg overflow-hidden border border-slate-200 shadow-md">
                                {{-- Top Header Band --}}
                                <div class="bg-[#2D2062] text-white p-3.5 flex items-center justify-between">
                                    <div class="flex items-center gap-2.5">
                                        <template x-if="logoUrl">
                                            <img :src="logoUrl" alt="Brand Logo" class="h-6 max-w-[120px] object-contain">
                                        </template>
                                        <template x-if="!logoUrl">
                                            <div class="w-6 h-6 rounded bg-indigo-500 text-white flex items-center justify-center font-bold text-xs" x-text="(companyName || 'C').charAt(0)"></div>
                                        </template>
                                        <span class="text-xs uppercase tracking-wide text-white/80 font-medium" x-text="(menuTitle || 'Clockwork') + ' Companion'"></span>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <template x-if="!hideHelpLinks">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-medium bg-white/10 text-white border border-white/20">
                                                <i class="fa-solid fa-headset mr-1"></i> Get Support
                                            </span>
                                        </template>
                                        <span class="text-[10px] font-mono text-white/50">v1.33.0</span>
                                    </div>
                                </div>

                                {{-- Tab Strip --}}
                                <div class="bg-white border-b border-slate-200 px-3 flex gap-3 text-xs overflow-x-auto">
                                    <span class="py-2 font-semibold text-indigo-700 border-b-2 border-indigo-600 whitespace-nowrap">Activity</span>
                                    <span class="py-2 text-slate-500 hover:text-slate-800 whitespace-nowrap">Uptime</span>
                                    <span class="py-2 text-slate-500 hover:text-slate-800 whitespace-nowrap">Security</span>
                                    <span class="py-2 text-slate-500 hover:text-slate-800 whitespace-nowrap">2FA</span>
                                    <span class="py-2 text-slate-500 hover:text-slate-800 whitespace-nowrap">Backups</span>
                                </div>

                                {{-- Companion Page Body --}}
                                <div class="p-3.5 space-y-3 bg-slate-50">
                                    {{-- Banner --}}
                                    <div class="bg-white p-3 rounded-lg border border-slate-200 flex items-center justify-between">
                                        <div>
                                            <div class="text-xs font-bold text-slate-900" x-text="'Managed by ' + (companyName || 'Clockwork Web Dev')"></div>
                                            <div class="text-[11px] text-slate-500 mt-0.5">24/7 site monitoring &amp; maintenance active</div>
                                        </div>
                                        <span class="text-[10px] px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-semibold">Care Active</span>
                                    </div>

                                    {{-- Mini Metrics --}}
                                    <div class="grid grid-cols-3 gap-2 text-center">
                                        <div class="bg-white p-2 rounded border border-slate-200">
                                            <div class="text-[9px] uppercase tracking-wide text-slate-400 font-semibold">Uptime</div>
                                            <div class="text-xs font-bold text-emerald-600 mt-0.5">99.98%</div>
                                        </div>
                                        <div class="bg-white p-2 rounded border border-slate-200">
                                            <div class="text-[9px] uppercase tracking-wide text-slate-400 font-semibold">Security</div>
                                            <div class="text-xs font-bold text-indigo-600 mt-0.5">Protected</div>
                                        </div>
                                        <div class="bg-white p-2 rounded border border-slate-200">
                                            <div class="text-[9px] uppercase tracking-wide text-slate-400 font-semibold">Backups</div>
                                            <div class="text-xs font-bold text-slate-800 mt-0.5">Daily Offsite</div>
                                        </div>
                                    </div>

                                    {{-- Recent Activity Entry --}}
                                    <div class="bg-white p-2.5 rounded border border-slate-200 text-xs">
                                        <div class="text-[10px] uppercase font-semibold text-slate-400 mb-1">Recent Operation</div>
                                        <div class="flex items-center justify-between text-[11px]">
                                            <span class="text-slate-700 flex items-center gap-1.5 font-medium">
                                                <i class="fa-solid fa-shield-halved text-emerald-600 text-[10px]"></i> Checksum Scan
                                            </span>
                                            <span class="text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded text-[10px] font-semibold">Clean</span>
                                        </div>
                                    </div>

                                    {{-- Footer Credit Note --}}
                                    <div class="text-[10px] text-slate-500 pt-1 border-t border-slate-200 flex items-center justify-between">
                                        <span x-text="footerText || ('Maintained by ' + (companyName || 'Clockwork Web Dev, LLC'))"></span>
                                        <span class="text-slate-400">WP 6.7</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- VIEW 2: WordPress Admin Surfaces (Sidebar, Plugins.php, Header) --}}
                        <div x-show="previewTab === 'surfaces'" class="space-y-4">
                            {{-- 1. WP Sidebar Preview --}}
                            <div>
                                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold mb-2 flex items-center gap-1.5">
                                    <i class="fa-solid fa-bars"></i> WordPress Sidebar Menu
                                </div>
                                <div class="bg-slate-800 rounded-lg p-2 font-sans border border-slate-700">
                                    <div class="flex items-center gap-2.5 px-3 py-2 rounded text-slate-400 text-xs">
                                        <i class="fa-solid fa-gauge w-4"></i> <span>Dashboard</span>
                                    </div>
                                    <div class="flex items-center gap-2.5 px-3 py-2 rounded text-slate-400 text-xs">
                                        <i class="fa-solid fa-thumbtack w-4"></i> <span>Posts</span>
                                    </div>
                                    {{-- Active White-Labeled Menu Item --}}
                                    <div class="flex items-center justify-between px-3 py-2 rounded bg-indigo-600 text-white text-xs font-medium shadow-sm">
                                        <div class="flex items-center gap-2.5">
                                            <i class="fa-solid fa-clock w-4" :class="menuIcon.includes('shield') ? 'fa-shield-halved' : (menuIcon.includes('hammer') ? 'fa-hammer' : (menuIcon.includes('heart') ? 'fa-heart' : 'fa-clock'))"></i>
                                            <span x-text="menuTitle || 'Clockwork'"></span>
                                        </div>
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                    </div>
                                    <div class="flex items-center gap-2.5 px-3 py-2 rounded text-slate-400 text-xs">
                                        <i class="fa-solid fa-gear w-4"></i> <span>Settings</span>
                                    </div>
                                </div>
                            </div>

                            {{-- 2. Plugins List Table Row Preview --}}
                            <div>
                                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold mb-2 flex items-center justify-between">
                                    <span><i class="fa-brands fa-wordpress mr-1"></i> Plugins Table (Must-Use)</span>
                                    <span x-show="hidePluginRow" class="text-amber-400 text-[10px]"><i class="fa-solid fa-eye-slash mr-1"></i> Hidden for Clients</span>
                                </div>
                                <div class="bg-white text-slate-900 rounded-lg p-3 text-xs border border-slate-200" :class="hidePluginRow ? 'opacity-50' : ''">
                                    <div class="font-bold text-slate-900 text-sm flex items-center justify-between">
                                        <span x-text="pluginName || 'Clockwork Companion'"></span>
                                        <span class="text-[10px] text-slate-400 font-normal">v1.33.0</span>
                                    </div>
                                    <p class="text-slate-600 text-xs mt-1 leading-relaxed" x-text="pluginDescription"></p>
                                    <div class="mt-2 text-[11px] text-slate-500 pt-2 border-t border-slate-100 flex items-center gap-2">
                                        <span>By <a href="#" class="text-blue-600 hover:underline font-medium" x-text="companyName || 'Clockwork Web Dev'"></a></span>
                                        <span>|</span>
                                        <a href="#" class="text-blue-600 hover:underline">Visit plugin site</a>
                                    </div>
                                </div>
                            </div>

                            {{-- 3. Companion Dashboard Header Preview --}}
                            <div>
                                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold mb-2 flex items-center gap-1.5">
                                    <i class="fa-solid fa-window-maximize"></i> Dashboard Header &amp; Support
                                </div>
                                <div class="bg-slate-100 text-slate-900 rounded-lg p-3 border border-slate-200">
                                    <div class="flex items-center justify-between pb-2 border-b border-slate-200">
                                        <div class="flex items-center gap-2">
                                            <template x-if="logoUrl">
                                                <img :src="logoUrl" alt="Brand Logo" class="h-6 object-contain">
                                            </template>
                                            <template x-if="!logoUrl">
                                                <div class="w-6 h-6 rounded bg-indigo-600 text-white flex items-center justify-center font-bold text-xs" x-text="(companyName || 'C').charAt(0)"></div>
                                            </template>
                                            <span class="font-bold text-sm text-slate-900" x-text="companyName || 'Clockwork Control'"></span>
                                        </div>
                                        <span class="text-[10px] px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-medium">Care Active</span>
                                    </div>
                                    <div class="mt-2 text-[11px] text-slate-600 flex items-center justify-between">
                                        <span>Support: <a href="#" class="text-blue-600 font-medium" x-text="supportEmail || 'support@youragency.com'"></a></span>
                                        <span class="text-slate-400 text-[10px]" x-text="footerText || 'Maintained with care'"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Fleet Sync Guidance Box --}}
                    <div class="card p-5 bg-indigo-50/70 border-indigo-200">
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-arrows-split-up-and-left text-sm"></i>
                            </div>
                            <div class="text-xs text-indigo-950 space-y-1 leading-relaxed">
                                <div class="font-semibold text-sm text-indigo-900">How White-Labeling Syncs</div>
                                <p>When you click <strong>Save Settings</strong>, Clockwork Control pushes your white-label options directly to the WordPress database via HMAC-signed REST to <code>POST /wp-json/clockwork/v1/branding</code>.</p>
                                <p class="text-indigo-800">Your client's WordPress site executes all brand filtering locally on load without making any outbound requests to Clockwork.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
