@extends('layouts.app')

@section('title', 'White Label & Styling Hub · Clockwork Control')

@section('content')
    <div x-data="{
        activeTab: '{{ $activeTab }}',

        {{-- Companion state --}}
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

        {{-- Reports state --}}
        reportsEnabled: {{ $reportsBranding['enabled'] ? 'true' : 'false' }},
        reportsCompanyName: '{{ addslashes($reportsBranding['company_name']) }}',
        reportsSupportEmail: '{{ addslashes($reportsBranding['support_email']) }}',
        reportsSupportUrl: '{{ addslashes($reportsBranding['support_url']) }}',
        reportsPrimaryColor: '{{ addslashes($reportsBranding['primary_color']) }}',
        reportsAccentColor: '{{ addslashes($reportsBranding['accent_color']) }}',
        reportsFooterText: '{{ addslashes($reportsBranding['footer_text']) }}',

        {{-- Email notification state --}}
        emailEnabled: {{ $emailBranding['enabled'] ? 'true' : 'false' }},
        emailCompanyName: '{{ addslashes($emailBranding['company_name']) }}',
        emailSenderName: '{{ addslashes($emailBranding['sender_name']) }}',
        emailReplyTo: '{{ addslashes($emailBranding['reply_to']) }}',
        emailHeaderBg: '{{ addslashes($emailBranding['header_bg']) }}',
        emailAccentColor: '{{ addslashes($emailBranding['accent_color']) }}',
        emailBadgeText: '{{ addslashes($emailBranding['badge_text']) }}',
        emailFooterText: '{{ addslashes($emailBranding['footer_text']) }}',
        emailUseLogo: {{ $emailBranding['use_logo'] ? 'true' : 'false' }},

        {{-- Test email sender state --}}
        testRecipient: '{{ auth()->user()->email ?? 'admin@example.com' }}',
        testSending: false,
        testResult: null,

        async sendTestEmail() {
            if (this.testSending || !this.testRecipient) return;
            this.testSending = true;
            this.testResult = null;
            try {
                const res = await fetch('{{ route('settings.companion.test-email') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ recipient: this.testRecipient }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    this.testResult = { ok: false, message: data.error || ('HTTP ' + res.status) };
                } else {
                    this.testResult = {
                        ok: true,
                        message: data.message + (data.mailer === 'log' ? ' (Driver is log — see storage/logs/laravel.log)' : '')
                    };
                }
            } catch (e) {
                this.testResult = { ok: false, message: 'Request failed: ' + e.message };
            } finally {
                this.testSending = false;
            }
        }
    }">
        <x-page-header title="White Label & Styling Hub"
            subtitle="Centralize all client-facing agency branding, WordPress Companion mu-plugin styling, automated Client Reports, and vulnerability notification emails in one place.">
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

        @include('settings._tabs')

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
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Receiving sync updates</div>
            </div>
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Active Channels</div>
                <div class="text-lg font-semibold mt-1 flex items-center gap-1.5 flex-wrap">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium"
                          :class="enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">
                        <i class="fa-brands fa-wordpress text-[10px]"></i> Companion
                    </span>
                    @if ($reportsModuleEnabled)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium"
                              :class="reportsEnabled ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">
                            <i class="fa-solid fa-file-lines text-[10px]"></i> Reports
                        </span>
                    @endif
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium"
                          :class="emailEnabled ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">
                        <i class="fa-solid fa-envelope text-[10px]"></i> Email
                    </span>
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Client-facing surfaces</div>
            </div>
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Agency Logo</div>
                <div class="flex items-center gap-2 mt-1">
                    <template x-if="logoUrl">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Uploaded
                            </span>
                        </div>
                    </template>
                    <template x-if="!logoUrl">
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> Wordmark Fallback
                        </span>
                    </template>
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Shared across all surfaces</div>
            </div>
        </div>

        {{-- White Labeling Surface Selector (Segmented Pill Control) --}}
        <div class="inline-flex items-center p-1 rounded-xl bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] mb-6 overflow-x-auto max-w-full">
            <button type="button" @click="activeTab = 'companion'"
                class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all whitespace-nowrap cursor-pointer"
                :class="activeTab === 'companion'
                    ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-xs'
                    : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'">
                <i class="fa-brands fa-wordpress text-xs"></i>
                <span>Companion (wp-admin)</span>
                <span class="w-1.5 h-1.5 rounded-full" :class="enabled ? 'bg-emerald-500' : 'bg-slate-300'"></span>
            </button>
            <button type="button" @click="activeTab = 'reports'"
                class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all whitespace-nowrap cursor-pointer"
                :class="activeTab === 'reports'
                    ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-xs'
                    : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'">
                <i class="fa-solid fa-file-lines text-xs"></i>
                <span>Client Reports</span>
                @if ($reportsModuleEnabled)
                    <span class="w-1.5 h-1.5 rounded-full" :class="reportsEnabled ? 'bg-emerald-500' : 'bg-slate-300'"></span>
                @else
                    <span class="text-[10px] px-1.5 py-0.2 rounded bg-slate-100 text-slate-500 border border-slate-200">Disabled</span>
                @endif
            </button>
            <button type="button" @click="activeTab = 'email'"
                class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all whitespace-nowrap cursor-pointer"
                :class="activeTab === 'email'
                    ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-xs'
                    : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'">
                <i class="fa-solid fa-envelope text-xs"></i>
                <span>Plugin Notification Email</span>
                <span class="w-1.5 h-1.5 rounded-full" :class="emailEnabled ? 'bg-emerald-500' : 'bg-slate-300'"></span>
            </button>
        </div>

        {{-- ========================================================================= --}}
        {{-- TAB 1: COMPANION (WP-ADMIN MU-PLUGIN)                                     --}}
        {{-- ========================================================================= --}}
        <div x-show="activeTab === 'companion'" class="grid grid-cols-1 lg:grid-cols-12 gap-6 max-w-6xl">
            {{-- Left column: Companion Form (7 cols) --}}
            <div class="lg:col-span-7 space-y-6">
                <form method="POST" action="{{ route('settings.companion.update') }}" class="card p-6 space-y-6">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="tab" value="companion">

                    {{-- Master Switch --}}
                    <div class="flex items-center justify-between pb-5 border-b border-[var(--color-border-light)]">
                        <div>
                            <div class="font-display text-base font-semibold text-[var(--color-ink-strong)]">Enable Companion White-Labeling</div>
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
                            <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Replaces "Clockwork Web Dev" as the plugin author, report provider, and notification sender.</p>
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
                                <i class="fa-solid fa-floppy-disk mr-1.5"></i> Save Companion Settings
                            </button>
                        </div>
                    </div>
                </form>

                {{-- Logo Upload Card --}}
                <div class="card p-6">
                    <h3 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-1">Agency Brand Logo</h3>
                    <p class="text-xs text-[var(--color-ink-soft)] mb-4">Upload your agency logo to display in the WordPress Companion dashboard, Client Reports, and notification emails. Recommended format: SVG or transparent PNG, max height 60px.</p>

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
                    <div class="card p-5 border-slate-300 shadow-sm bg-slate-900 text-white overflow-hidden rounded-xl">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-700 mb-4 flex-wrap gap-2">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-rose-500 inline-block"></span>
                                <span class="w-3 h-3 rounded-full bg-amber-500 inline-block"></span>
                                <span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span>
                                <span class="text-xs font-mono text-slate-400 ml-1">WordPress Admin Preview</span>
                            </div>
                            
                            {{-- View Switcher Pills --}}
                            <div class="flex items-center gap-1 bg-slate-800 p-0.5 rounded-lg border border-slate-700">
                                <button type="button" @click="previewTab = 'screen'"
                                    class="px-2.5 py-1 rounded text-[11px] font-medium transition-colors cursor-pointer"
                                    :class="previewTab === 'screen' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200'">
                                    <i class="fa-solid fa-desktop mr-1"></i> Dashboard
                                </button>
                                <button type="button" @click="previewTab = 'surfaces'"
                                    class="px-2.5 py-1 rounded text-[11px] font-medium transition-colors cursor-pointer"
                                    :class="previewTab === 'surfaces' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200'">
                                    <i class="fa-brands fa-wordpress mr-1"></i> Surfaces
                                </button>
                            </div>
                        </div>

                        {{-- VIEW 1: Full Companion Plugin Screen Mockup --}}
                        <div x-show="previewTab === 'screen'" class="space-y-4">
                            <div class="bg-white text-slate-900 rounded-lg overflow-hidden border border-slate-200 shadow-md">
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
                                        <span class="text-[10px] font-mono text-white/50">v1.34.0</span>
                                    </div>
                                </div>

                                <div class="bg-white border-b border-slate-200 px-3 flex gap-3 text-xs overflow-x-auto">
                                    <span class="py-2 font-semibold text-indigo-700 border-b-2 border-indigo-600 whitespace-nowrap">Activity</span>
                                    <span class="py-2 text-slate-500 whitespace-nowrap">Uptime</span>
                                    <span class="py-2 text-slate-500 whitespace-nowrap">Security</span>
                                    <span class="py-2 text-slate-500 whitespace-nowrap">Backups</span>
                                </div>

                                <div class="p-3.5 space-y-3 bg-slate-50">
                                    <div class="bg-white p-3 rounded-lg border border-slate-200 flex items-center justify-between">
                                        <div>
                                            <div class="text-xs font-bold text-slate-900" x-text="'Managed by ' + (companyName || 'Clockwork Web Dev')"></div>
                                            <div class="text-[11px] text-slate-500 mt-0.5">24/7 site monitoring &amp; maintenance active</div>
                                        </div>
                                        <span class="text-[10px] px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-semibold">Care Active</span>
                                    </div>

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

                                    <div class="text-[10px] text-slate-500 pt-1 border-t border-slate-200 flex items-center justify-between">
                                        <span x-text="footerText || ('Maintained by ' + (companyName || 'Clockwork Web Dev, LLC'))"></span>
                                        <span class="text-slate-400">WP 6.7</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- VIEW 2: WordPress Admin Surfaces (Sidebar & Plugins list) --}}
                        <div x-show="previewTab === 'surfaces'" class="space-y-4">
                            <div>
                                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold mb-2 flex items-center gap-1.5">
                                    <i class="fa-solid fa-bars"></i> Sidebar Menu
                                </div>
                                <div class="bg-slate-800 rounded-lg p-2 font-sans border border-slate-700">
                                    <div class="flex items-center gap-2.5 px-3 py-2 rounded text-slate-400 text-xs">
                                        <i class="fa-solid fa-gauge w-4"></i> <span>Dashboard</span>
                                    </div>
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

                            <div>
                                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold mb-2 flex items-center justify-between">
                                    <span><i class="fa-brands fa-wordpress mr-1"></i> Plugins Table (Must-Use)</span>
                                    <span x-show="hidePluginRow" class="text-amber-400 text-[10px]"><i class="fa-solid fa-eye-slash mr-1"></i> Hidden for Clients</span>
                                </div>
                                <div class="bg-white text-slate-900 rounded-lg p-3 text-xs border border-slate-200" :class="hidePluginRow ? 'opacity-50' : ''">
                                    <div class="font-bold text-slate-900 text-sm flex items-center justify-between">
                                        <span x-text="pluginName || 'Clockwork Companion'"></span>
                                        <span class="text-[10px] text-slate-400 font-normal">v1.34.0</span>
                                    </div>
                                    <p class="text-slate-600 text-xs mt-1 leading-relaxed" x-text="pluginDescription"></p>
                                    <div class="mt-2 text-[11px] text-slate-500 pt-2 border-t border-slate-100 flex items-center gap-2">
                                        <span>By <a href="#" class="text-blue-600 hover:underline font-medium" x-text="companyName || 'Clockwork Web Dev'"></a></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card p-5 bg-indigo-50/70 border-indigo-200">
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-arrows-split-up-and-left text-sm"></i>
                            </div>
                            <div class="text-xs text-indigo-950 space-y-1 leading-relaxed">
                                <div class="font-semibold text-sm text-indigo-900">How White-Labeling Syncs</div>
                                <p>When you click <strong>Save Companion Settings</strong>, Clockwork pushes options directly to the WordPress database via HMAC REST. Client sites execute all filtering locally.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ========================================================================= --}}
        {{-- TAB 2: CLIENT REPORTS (REPORTS WHITE-LABELING)                            --}}
        {{-- ========================================================================= --}}
        <div x-show="activeTab === 'reports'" class="space-y-6 max-w-6xl">
            @if ($reportsModuleEnabled)
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    {{-- Left column: Reports Styling Form (7 cols) --}}
                    <div class="lg:col-span-7 space-y-6">
                        <form method="POST" action="{{ route('settings.companion.update') }}" class="card p-6 space-y-6">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="tab" value="reports">

                            {{-- Master Switch --}}
                            <div class="flex items-center justify-between pb-5 border-b border-[var(--color-border-light)]">
                                <div>
                                    <div class="font-display text-base font-semibold text-[var(--color-ink-strong)]">Enable Custom Reports Styling</div>
                                    <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">Apply custom agency headers, branding palette, and disclaimers to all compiled client reports.</div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="enabled" value="1" x-model="reportsEnabled" class="sr-only peer">
                                    <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--color-brand)]"></div>
                                </label>
                            </div>

                            {{-- Section 1: Report Identity --}}
                            <div class="space-y-4">
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">1. Report Agency Identity</h3>

                                <div>
                                    <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Company / Agency Name for Reports</label>
                                    <input type="text" name="company_name" x-model="reportsCompanyName"
                                        class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data"
                                        :placeholder="companyName || 'e.g. Acme Web Services'">
                                    <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Leave empty to use your shared Agency Name (<span x-text="companyName || 'Clockwork Web Dev'"></span>).</p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Support Email on Reports</label>
                                        <input type="email" name="support_email" x-model="reportsSupportEmail"
                                            class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data"
                                            :placeholder="supportEmail || 'support@youragency.com'">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Client Portal / Support URL</label>
                                        <input type="url" name="support_url" x-model="reportsSupportUrl"
                                            class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data"
                                            :placeholder="supportUrl || companyUrl || 'https://youragency.com'">
                                    </div>
                                </div>
                            </div>

                            {{-- Section 2: Report Color Palette --}}
                            <div class="border-t border-[var(--color-border-light)] pt-5 space-y-4">
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">2. Brand Palette &amp; Accents</h3>

                                <div>
                                    <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Primary Brand Color (Header &amp; Card Accents)</label>
                                    <div class="flex items-center gap-3">
                                        <input type="color" x-model="reportsPrimaryColor" class="w-9 h-9 p-0.5 rounded border border-slate-300 cursor-pointer">
                                        <input type="text" name="primary_color" x-model="reportsPrimaryColor"
                                            class="w-36 px-3 py-2 text-sm border border-[var(--color-border)] rounded-md font-mono focus:outline-none focus:border-[var(--color-brand)]">
                                        
                                        {{-- Color Presets --}}
                                        <div class="flex items-center gap-1.5 ml-2">
                                            <button type="button" @click="reportsPrimaryColor = '#2D2062'" title="Clockwork Purple" class="w-6 h-6 rounded-full bg-[#2D2062] border border-white shadow-xs cursor-pointer"></button>
                                            <button type="button" @click="reportsPrimaryColor = '#4F46E5'" title="Indigo" class="w-6 h-6 rounded-full bg-[#4F46E5] border border-white shadow-xs cursor-pointer"></button>
                                            <button type="button" @click="reportsPrimaryColor = '#0F172A'" title="Slate Navy" class="w-6 h-6 rounded-full bg-[#0F172A] border border-white shadow-xs cursor-pointer"></button>
                                            <button type="button" @click="reportsPrimaryColor = '#065F46'" title="Forest Green" class="w-6 h-6 rounded-full bg-[#065F46] border border-white shadow-xs cursor-pointer"></button>
                                            <button type="button" @click="reportsPrimaryColor = '#334155'" title="Slate Gray" class="w-6 h-6 rounded-full bg-[#334155] border border-white shadow-xs cursor-pointer"></button>
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Used for cover banner backgrounds, major titles, and metric cards.</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Accent Strip &amp; Highlight Color</label>
                                    <div class="flex items-center gap-3">
                                        <input type="color" x-model="reportsAccentColor" class="w-9 h-9 p-0.5 rounded border border-slate-300 cursor-pointer">
                                        <input type="text" name="accent_color" x-model="reportsAccentColor"
                                            class="w-36 px-3 py-2 text-sm border border-[var(--color-border)] rounded-md font-mono focus:outline-none focus:border-[var(--color-brand)]">
                                        
                                        {{-- Color Presets --}}
                                        <div class="flex items-center gap-1.5 ml-2">
                                            <button type="button" @click="reportsAccentColor = '#7EFF83'" title="Mint Accent" class="w-6 h-6 rounded-full bg-[#7EFF83] border border-white shadow-xs cursor-pointer"></button>
                                            <button type="button" @click="reportsAccentColor = '#10B981'" title="Emerald" class="w-6 h-6 rounded-full bg-[#10B981] border border-white shadow-xs cursor-pointer"></button>
                                            <button type="button" @click="reportsAccentColor = '#F59E0B'" title="Amber" class="w-6 h-6 rounded-full bg-[#F59E0B] border border-white shadow-xs cursor-pointer"></button>
                                            <button type="button" @click="reportsAccentColor = '#0EA5E9'" title="Sky Blue" class="w-6 h-6 rounded-full bg-[#0EA5E9] border border-white shadow-xs cursor-pointer"></button>
                                            <button type="button" @click="reportsAccentColor = '#F43F5E'" title="Rose" class="w-6 h-6 rounded-full bg-[#F43F5E] border border-white shadow-xs cursor-pointer"></button>
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Used for decorative accent bars, badges, and status highlights.</p>
                                </div>
                            </div>

                            {{-- Section 3: Footer & Disclaimer --}}
                            <div class="border-t border-[var(--color-border-light)] pt-5 space-y-4">
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">3. Report Footer &amp; Disclaimers</h3>

                                <div>
                                    <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Custom Report Footer Notice / SLA Credit</label>
                                    <textarea name="footer_text" x-model="reportsFooterText" rows="3"
                                        class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)]"
                                        placeholder="e.g. This report is prepared exclusively for your organization as part of your active Website Care Plan. Contact your account manager with questions."></textarea>
                                    <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Appears at the bottom of every generated report document and shared client PDF.</p>
                                </div>
                            </div>

                            <div class="border-t border-[var(--color-border-light)] pt-5 flex items-center justify-end">
                                <button type="submit" class="btn-pill-primary px-5 py-2 text-sm font-semibold">
                                    <i class="fa-solid fa-floppy-disk mr-1.5"></i> Save Reports Styling
                                </button>
                            </div>
                        </form>

                        {{-- Reset Reports Styling Card --}}
                        <div class="card p-6 flex items-center justify-between bg-slate-50 border-dashed">
                            <div>
                                <div class="text-sm font-medium text-[var(--color-ink-strong)]">Reset Reports to Defaults</div>
                                <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">Revert report styling and colors back to default Clockwork theme values.</div>
                            </div>
                            <form method="POST" action="{{ route('settings.companion.reset-reports') }}" onsubmit="return confirm('Reset Client Reports styling back to shared defaults?');">
                                @csrf
                                <button type="submit" class="btn-pill-secondary text-xs text-rose-600 hover:text-rose-700 hover:bg-rose-50 border-rose-200">
                                    <i class="fa-solid fa-rotate-left mr-1"></i> Reset Reports Styling
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- Right column: Live Client Report Document Preview (5 cols) --}}
                    <div class="lg:col-span-5 space-y-6">
                        <div class="sticky top-6 space-y-6">
                            <div class="card p-5 border-slate-300 shadow-sm bg-slate-900 text-white overflow-hidden rounded-xl">
                                <div class="flex items-center justify-between pb-3 border-b border-slate-700 mb-4">
                                    <div class="flex items-center gap-2">
                                        <span class="w-3 h-3 rounded-full bg-rose-500 inline-block"></span>
                                        <span class="w-3 h-3 rounded-full bg-amber-500 inline-block"></span>
                                        <span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span>
                                        <span class="text-xs font-mono text-slate-400 ml-1">Report Document Preview</span>
                                    </div>
                                    <span class="text-[10px] text-emerald-400 font-mono"><i class="fa-solid fa-eye mr-1"></i> Interactive</span>
                                </div>

                                {{-- Styled Report Document Paper Mockup --}}
                                <div class="bg-white text-slate-900 rounded-lg overflow-hidden border border-slate-200 shadow-md">
                                    {{-- Accent Top Bar --}}
                                    <div :style="'height: 6px; background:' + (reportsPrimaryColor || '#2D2062')"></div>
                                    <div :style="'height: 3px; background:' + (reportsAccentColor || '#7EFF83')"></div>

                                    <div class="p-4 space-y-4">
                                        {{-- Header with Brand Logo/Title --}}
                                        <div class="border-b border-slate-200 pb-3 flex items-start justify-between">
                                            <div>
                                                <template x-if="logoUrl">
                                                    <img :src="logoUrl" alt="Report Logo" class="h-6 object-contain mb-1.5">
                                                </template>
                                                <template x-if="!logoUrl">
                                                    <div class="font-extrabold text-sm text-slate-900 mb-1" x-text="reportsCompanyName || companyName || 'Clockwork Web Dev'"></div>
                                                </template>
                                                <h4 class="text-base font-bold text-slate-900">Website Care &amp; Maintenance Report</h4>
                                                <p class="text-[11px] text-slate-500 mt-0.5">Prepared for <span class="font-semibold text-slate-800">acme-store.com</span></p>
                                            </div>
                                            <div class="text-right text-[10px] text-slate-400">
                                                <div>Monthly Executive Summary</div>
                                                <div class="font-semibold text-slate-700">August 2026</div>
                                            </div>
                                        </div>

                                        {{-- Metric Highlights --}}
                                        <div class="grid grid-cols-3 gap-2">
                                            <div class="p-2 rounded bg-slate-50 border border-slate-100 text-center">
                                                <div class="text-[9px] uppercase font-semibold text-slate-400">Updates</div>
                                                <div class="text-xs font-bold text-slate-900 mt-0.5" :style="'color:' + (reportsPrimaryColor || '#2D2062')">18 Applied</div>
                                            </div>
                                            <div class="p-2 rounded bg-slate-50 border border-slate-100 text-center">
                                                <div class="text-[9px] uppercase font-semibold text-slate-400">Uptime</div>
                                                <div class="text-xs font-bold text-emerald-600 mt-0.5">99.98%</div>
                                            </div>
                                            <div class="p-2 rounded bg-slate-50 border border-slate-100 text-center">
                                                <div class="text-[9px] uppercase font-semibold text-slate-400">Security</div>
                                                <div class="text-xs font-bold text-indigo-600 mt-0.5">Protected</div>
                                            </div>
                                        </div>

                                        {{-- Sample Summary Callout --}}
                                        <div class="p-3 rounded-lg border text-xs" :style="'border-color:' + (reportsAccentColor || '#7EFF83') + '40; background:' + (reportsAccentColor || '#7EFF83') + '10'">
                                            <div class="font-semibold text-[11px] flex items-center gap-1.5" :style="'color:' + (reportsPrimaryColor || '#2D2062')">
                                                <i class="fa-solid fa-circle-check text-emerald-600"></i> All Core &amp; Plugin Updates Applied
                                            </div>
                                            <p class="text-[10px] text-slate-600 mt-1 leading-relaxed">All active plugins were updated and verified against known CVE vulnerability advisories.</p>
                                        </div>

                                        {{-- Footer note if set --}}
                                        <template x-if="reportsFooterText">
                                            <div class="p-2.5 rounded bg-slate-50 border border-slate-200 text-[10px] text-slate-600 italic" x-text="reportsFooterText"></div>
                                        </template>

                                        {{-- Report Doc Footer --}}
                                        <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-[9px] text-slate-400">
                                            <span>Generated by <strong class="text-slate-700" x-text="reportsCompanyName || companyName || 'Clockwork Control'"></strong></span>
                                            <span x-text="reportsSupportEmail || supportEmail || 'support@youragency.com'"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card p-5 bg-slate-50 border-slate-200 text-xs text-slate-600 space-y-1">
                                <div class="font-semibold text-slate-900 flex items-center gap-1.5">
                                    <i class="fa-solid fa-file-pdf text-[var(--color-brand)]"></i>
                                    PDF &amp; Web Link Ready
                                </div>
                                <p>These styling options apply to all monthly reports compiled by <code>Modules\ClientReports</code>, both on web view links and downloaded PDF summaries.</p>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                {{-- Client Reports Module is disabled alert card --}}
                <div class="card p-8 text-center max-w-2xl mx-auto space-y-4">
                    <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-500 mx-auto flex items-center justify-center text-xl">
                        <i class="fa-solid fa-file-lines"></i>
                    </div>
                    <div>
                        <h3 class="font-display text-lg font-bold text-[var(--color-ink-strong)]">Client Reports Module Is Not Enabled</h3>
                        <p class="text-xs text-[var(--color-ink-soft)] mt-1.5 max-w-md mx-auto">
                            The <code>Modules\ClientReports</code> module is currently inactive in your environment. Enable it in the Module Directory to generate and white-label automated executive maintenance reports.
                        </p>
                    </div>
                    <div class="pt-2">
                        <a href="{{ route('settings.modules.index') }}" class="btn-pill-primary text-xs px-4 py-2 inline-flex items-center gap-1.5">
                            <i class="fa-solid fa-cubes"></i>
                            <span>Go to Module Directory</span>
                        </a>
                    </div>
                </div>
            @endif
        </div>

        {{-- ========================================================================= --}}
        {{-- TAB 3: PLUGIN NOTIFICATION EMAIL (VULNERABILITY REPORT EMAIL)             --}}
        {{-- ========================================================================= --}}
        <div x-show="activeTab === 'email'" class="grid grid-cols-1 lg:grid-cols-12 gap-6 max-w-6xl">
            {{-- Left column: Email Styling Form (7 cols) --}}
            <div class="lg:col-span-7 space-y-6">
                <form method="POST" action="{{ route('settings.companion.update') }}" class="card p-6 space-y-6">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="tab" value="email">

                    {{-- Master Switch --}}
                    <div class="flex items-center justify-between pb-5 border-b border-[var(--color-border-light)]">
                        <div>
                            <div class="font-display text-base font-semibold text-[var(--color-ink-strong)]">Enable Custom Email Notification Branding</div>
                            <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">Customize the email header, colors, wordmark, and footer credits on vulnerability reports sent to clients.</div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="enabled" value="1" x-model="emailEnabled" class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--color-brand)]"></div>
                        </label>
                    </div>

                    {{-- Section 1: Email Brand Identity --}}
                    <div class="space-y-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">1. Email Sender &amp; Identity</h3>

                        <div>
                            <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Company / Agency Name</label>
                            <input type="text" name="company_name" x-model="emailCompanyName"
                                class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data"
                                :placeholder="companyName || 'e.g. Acme Web Services'">
                            <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Defaults to your shared Agency Name (<span x-text="companyName || 'Clockwork Web Dev'"></span>).</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Sender Display Name</label>
                                <input type="text" name="sender_name" x-model="emailSenderName"
                                    class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data"
                                    :placeholder="companyName ? companyName + ' Security' : 'Clockwork Security'">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Reply-To Email Address</label>
                                <input type="email" name="reply_to" x-model="emailReplyTo"
                                    class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data"
                                    :placeholder="supportEmail || 'support@youragency.com'">
                            </div>
                        </div>

                        <label class="flex items-start gap-3 cursor-pointer pt-1">
                            <input type="checkbox" name="use_logo" value="1" x-model="emailUseLogo"
                                class="mt-0.5 rounded border-slate-300 text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                            <div>
                                <div class="text-xs font-medium text-[var(--color-ink-strong)]">Display Uploaded Brand Logo in Header</div>
                                <div class="text-[11px] text-[var(--color-ink-soft)]">When enabled and a logo is uploaded, renders your logo image in the top email bar; otherwise renders a styled agency wordmark.</div>
                            </div>
                        </label>
                    </div>

                    {{-- Section 2: Header Styling & Color Palette --}}
                    <div class="border-t border-[var(--color-border-light)] pt-5 space-y-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">2. Header Styling &amp; Colors</h3>

                        <div>
                            <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Header Banner Background Color</label>
                            <div class="flex items-center gap-3">
                                <input type="color" x-model="emailHeaderBg" class="w-9 h-9 p-0.5 rounded border border-slate-300 cursor-pointer">
                                <input type="text" name="header_bg" x-model="emailHeaderBg"
                                    class="w-36 px-3 py-2 text-sm border border-[var(--color-border)] rounded-md font-mono focus:outline-none focus:border-[var(--color-brand)]">
                                
                                {{-- Color Presets --}}
                                <div class="flex items-center gap-1.5 ml-2">
                                    <button type="button" @click="emailHeaderBg = '#2D2062'" title="Clockwork Purple" class="w-6 h-6 rounded-full bg-[#2D2062] border border-white shadow-xs cursor-pointer"></button>
                                    <button type="button" @click="emailHeaderBg = '#0F172A'" title="Slate Navy" class="w-6 h-6 rounded-full bg-[#0F172A] border border-white shadow-xs cursor-pointer"></button>
                                    <button type="button" @click="emailHeaderBg = '#1E1B4B'" title="Midnight Indigo" class="w-6 h-6 rounded-full bg-[#1E1B4B] border border-white shadow-xs cursor-pointer"></button>
                                    <button type="button" @click="emailHeaderBg = '#042F2E'" title="Dark Teal" class="w-6 h-6 rounded-full bg-[#042F2E] border border-white shadow-xs cursor-pointer"></button>
                                    <button type="button" @click="emailHeaderBg = '#18181B'" title="Zinc Charcoal" class="w-6 h-6 rounded-full bg-[#18181B] border border-white shadow-xs cursor-pointer"></button>
                                </div>
                            </div>
                            <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Replaces the default deep purple header band on outgoing HTML security emails.</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Accent Strip Color</label>
                            <div class="flex items-center gap-3">
                                <input type="color" x-model="emailAccentColor" class="w-9 h-9 p-0.5 rounded border border-slate-300 cursor-pointer">
                                <input type="text" name="accent_color" x-model="emailAccentColor"
                                    class="w-36 px-3 py-2 text-sm border border-[var(--color-border)] rounded-md font-mono focus:outline-none focus:border-[var(--color-brand)]">
                                
                                {{-- Color Presets --}}
                                <div class="flex items-center gap-1.5 ml-2">
                                    <button type="button" @click="emailAccentColor = '#7EFF83'" title="Mint / Lime" class="w-6 h-6 rounded-full bg-[#7EFF83] border border-white shadow-xs cursor-pointer"></button>
                                    <button type="button" @click="emailAccentColor = '#38BDF8'" title="Sky Blue" class="w-6 h-6 rounded-full bg-[#38BDF8] border border-white shadow-xs cursor-pointer"></button>
                                    <button type="button" @click="emailAccentColor = '#34D399'" title="Emerald" class="w-6 h-6 rounded-full bg-[#34D399] border border-white shadow-xs cursor-pointer"></button>
                                    <button type="button" @click="emailAccentColor = '#FBBF24'" title="Amber" class="w-6 h-6 rounded-full bg-[#FBBF24] border border-white shadow-xs cursor-pointer"></button>
                                    <button type="button" @click="emailAccentColor = '#F43F5E'" title="Rose" class="w-6 h-6 rounded-full bg-[#F43F5E] border border-white shadow-xs cursor-pointer"></button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Header Badge Label</label>
                            <input type="text" name="badge_text" x-model="emailBadgeText"
                                class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data"
                                placeholder="Security Alert">
                            <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Appears in the upper-right corner of the email banner.</p>
                        </div>
                    </div>

                    {{-- Section 3: Content & Footer --}}
                    <div class="border-t border-[var(--color-border-light)] pt-5 space-y-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">3. Footer Message &amp; Support Credit</h3>

                        <div>
                            <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1">Custom Care Plan Call-to-Action / Disclaimer</label>
                            <textarea name="footer_text" x-model="emailFooterText" rows="3"
                                class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)]"
                                placeholder="e.g. As part of your Active Site Care Plan, our engineers can test and apply these security patches on staging for you. Reply to schedule."></textarea>
                        </div>
                    </div>

                    <div class="border-t border-[var(--color-border-light)] pt-5 flex items-center justify-end">
                        <button type="submit" class="btn-pill-primary px-5 py-2 text-sm font-semibold">
                            <i class="fa-solid fa-floppy-disk mr-1.5"></i> Save Email Branding
                        </button>
                    </div>
                </form>

                {{-- Send Test Email Card --}}
                <div class="card p-6 space-y-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="font-display text-base font-semibold text-[var(--color-ink-strong)]">Send Test Vulnerability Email</h3>
                            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Send a test vulnerability report to your inbox to review the live HTML rendering and mail transport.</p>
                        </div>
                        <span class="text-xs px-2.5 py-1 rounded bg-[var(--color-surface-alt)] font-mono text-[var(--color-ink-soft)] border border-[var(--color-border-light)]">
                            mailer: {{ config('mail.default') }}
                        </span>
                    </div>

                    <div class="flex flex-col sm:flex-row items-center gap-3">
                        <input type="email" x-model="testRecipient" placeholder="recipient@youragency.com"
                            class="w-full px-3 py-2 text-sm border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] font-data">
                        <button type="button" @click="sendTestEmail()" :disabled="testSending || !testRecipient"
                            class="btn-pill-secondary text-xs whitespace-nowrap px-4 py-2 disabled:opacity-50 cursor-pointer">
                            <i class="fa-solid" :class="testSending ? 'fa-spinner fa-spin' : 'fa-paper-plane'"></i>
                            <span x-text="testSending ? 'Sending...' : 'Send Test Email'"></span>
                        </button>
                    </div>

                    <template x-if="testResult">
                        <div class="p-3 rounded text-xs border"
                             :class="testResult.ok ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-rose-50 text-rose-800 border-rose-200'">
                            <i class="fa-solid mr-1" :class="testResult.ok ? 'fa-circle-check' : 'fa-triangle-exclamation'"></i>
                            <span x-text="testResult.message"></span>
                        </div>
                    </template>
                </div>

                {{-- Reset Email Defaults Card --}}
                <div class="card p-6 flex items-center justify-between bg-slate-50 border-dashed">
                    <div>
                        <div class="text-sm font-medium text-[var(--color-ink-strong)]">Reset Email Branding to Defaults</div>
                        <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">Revert email colors, wordmark, and footer copy back to Clockwork Control defaults.</div>
                    </div>
                    <form method="POST" action="{{ route('settings.companion.reset-email') }}" onsubmit="return confirm('Reset email notification styling back to defaults?');">
                        @csrf
                        <button type="submit" class="btn-pill-secondary text-xs text-rose-600 hover:text-rose-700 hover:bg-rose-50 border-rose-200">
                            <i class="fa-solid fa-rotate-left mr-1"></i> Reset Email Defaults
                        </button>
                    </form>
                </div>
            </div>

            {{-- Right column: Live Interactive Email Preview (5 cols) --}}
            <div class="lg:col-span-5 space-y-6">
                <div class="sticky top-6 space-y-6">
                    <div class="card p-5 border-slate-300 shadow-sm bg-slate-900 text-white overflow-hidden rounded-xl">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-700 mb-4">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-rose-500 inline-block"></span>
                                <span class="w-3 h-3 rounded-full bg-amber-500 inline-block"></span>
                                <span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span>
                                <span class="text-xs font-mono text-slate-400 ml-1">HTML Email Live Preview</span>
                            </div>
                            <span class="text-[10px] text-emerald-400 font-mono"><i class="fa-solid fa-circle-check mr-1"></i> Live</span>
                        </div>

                        {{-- Email Client Shell Mockup --}}
                        <div class="bg-slate-100 rounded-lg p-3 text-slate-900 border border-slate-300 shadow-inner">
                            {{-- Fake Email Header Details --}}
                            <div class="bg-white rounded-t-lg p-3 border-b border-slate-200 text-xs space-y-1">
                                <div class="flex items-center justify-between text-[11px] text-slate-500">
                                    <span>From: <strong class="text-slate-800" x-text="(emailSenderName || emailCompanyName || companyName || 'Clockwork Security')"></strong> &lt;<span x-text="emailReplyTo || supportEmail || 'support@youragency.com'"></span>&gt;</span>
                                    <span class="text-[10px]">Just now</span>
                                </div>
                                <div class="text-xs font-semibold text-slate-900 truncate">
                                    Security update needed on glisi.org — 2 known vulnerabilities
                                </div>
                            </div>

                            {{-- Real Email Canvas --}}
                            <div class="p-3 bg-[#F1EEFF] rounded-b-lg overflow-hidden">
                                <div class="bg-white rounded-lg shadow-sm overflow-hidden text-left border border-slate-200">
                                    {{-- Brand Header Banner --}}
                                    <div class="p-3.5 flex items-center justify-between text-white" :style="'background:' + (emailHeaderBg || '#2D2062')">
                                        <div class="flex items-center gap-2">
                                            <template x-if="emailUseLogo && logoUrl">
                                                <img :src="logoUrl" alt="Logo" class="h-6 max-w-[120px] object-contain">
                                            </template>
                                            <template x-if="!(emailUseLogo && logoUrl)">
                                                <div class="font-extrabold text-sm tracking-tight text-white" x-text="emailCompanyName || companyName || 'Clockwork Web Dev'"></div>
                                            </template>
                                        </div>
                                        <span class="text-[9px] uppercase tracking-widest font-semibold text-white/80" x-text="emailBadgeText || 'SECURITY ALERT'"></span>
                                    </div>
                                    {{-- Accent Strip --}}
                                    <div :style="'height: 3px; background:' + (emailAccentColor || '#7EFF83')"></div>

                                    {{-- Email Body --}}
                                    <div class="p-4 space-y-3 text-xs">
                                        <div class="inline-block bg-rose-50 text-rose-700 text-[9px] uppercase tracking-wider font-bold px-2 py-0.5 rounded-full border border-rose-200">
                                            ⚠ Security update needed
                                        </div>
                                        <div class="font-bold text-slate-900 text-sm">
                                            2 known vulnerabilities found on <a href="#" class="text-indigo-600 hover:underline">glisi.org</a>
                                        </div>
                                        <p class="text-[11px] text-slate-600 leading-relaxed">
                                            Our security scan compared installed plugins on your site against known CVE vulnerability advisories.
                                        </p>

                                        {{-- CVE Alert Card --}}
                                        <div class="p-2.5 rounded bg-slate-50 border-l-4 border-l-amber-500 border border-slate-200 space-y-1">
                                            <div class="font-semibold text-slate-900 text-xs">Elementor Website Builder <span class="text-slate-400 font-normal font-mono text-[10px]">(elementor)</span></div>
                                            <div class="text-[11px] text-slate-600">Contributor+ Stored Cross-Site Scripting via Template Import</div>
                                            <div class="text-[10px] text-slate-500">
                                                Installed: <span class="text-rose-600 font-mono font-semibold">3.18.0</span> &rarr; Update to: <span class="text-emerald-700 font-mono font-semibold">3.18.2</span>
                                            </div>
                                        </div>

                                        {{-- Custom Footer Note if given --}}
                                        <template x-if="emailFooterText">
                                            <div class="p-2 rounded bg-indigo-50/70 border border-indigo-100 text-[10px] text-indigo-900" x-text="emailFooterText"></div>
                                        </template>

                                        {{-- Footer Credit --}}
                                        <div class="pt-2 border-t border-slate-100 text-[10px] text-slate-400">
                                            <div>Sent by <strong class="text-slate-700" x-text="emailCompanyName || companyName || 'Clockwork Web Dev'"></strong> — your care plan.</div>
                                            <div class="mt-0.5" x-text="(emailCompanyName || companyName || 'Clockwork Web Dev') + ' · ' + (companyUrl || 'https://youragency.com')"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card p-5 bg-slate-50 border-slate-200 text-xs text-slate-600 space-y-1">
                        <div class="font-semibold text-slate-900 flex items-center gap-1.5">
                            <i class="fa-solid fa-envelope-open-text text-[var(--color-brand)]"></i>
                            Triggered from Issues Page
                        </div>
                        <p>This email template is sent when clicking the <strong>Email vulnerability report</strong> action in the WP-plugins-out-of-date table on <code>/issues</code>.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
