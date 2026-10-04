@extends('layouts.app')

@section('title', 'WordPress fleet plugins · Clockwork')

@php
    // Column visibility config — fed to both the toggle dropdown UI and the table's
    // initial data-hidden-cols attribute.
    $columnConfig = [
        ['key' => 'site',       'label' => 'Site',       'default' => true],
        ['key' => 'server',     'label' => 'Server',     'default' => false],
        ['key' => 'tier',       'label' => 'Tier',       'default' => false],
        ['key' => 'companion',  'label' => 'Companion',  'default' => true],
        ['key' => 'gatekeeper', 'label' => 'Gatekeeper', 'default' => true],
        ['key' => 'wordfence',  'label' => 'Wordfence',  'default' => true],
    ];
@endphp

@section('content')
    @include('settings._tabs')

    <div x-data="wpPluginsManager()">
    <x-page-header title="WordPress fleet plugins"
        subtitle="Fleet-wide inventory of WordPress sites and active deployment of the Clockwork Companion agent and security plugins.">
        <x-slot:actions>
            <x-column-toggle id="wp-plugins" :columns="$columnConfig" />
        </x-slot:actions>
    </x-page-header>

    {{-- Primary Metrics Row --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">WordPress sites</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ number_format($totals['sites']) }}</div>
            <div class="text-[11px] text-[var(--color-ink-soft)] mt-0.5">Monitored fleet</div>
        </div>

        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Companion plugin</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">
                {{ number_format($totals['companion_installed']) }}
                <span class="text-sm text-[var(--color-ink-soft)]">/ {{ number_format($totals['sites']) }}</span>
            </div>
            <div class="text-[11px] mt-0.5">
                @if ($totals['companion_missing'] > 0)
                    <span class="inline-flex items-center gap-1 text-[var(--color-status-yellow)] font-medium">
                        <i class="fa-solid fa-triangle-exclamation"></i> {{ number_format($totals['companion_missing']) }} missing
                    </span>
                @else
                    <span class="text-[var(--color-status-green)] font-medium">
                        <i class="fa-solid fa-circle-check"></i> 100% deployed
                    </span>
                @endif
            </div>
        </div>

        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Gatekeeper</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">
                {{ number_format($totals['gatekeeper_enabled']) }}
                <span class="text-sm text-[var(--color-ink-soft)]">/ {{ number_format($totals['sites']) }}</span>
            </div>
            <div class="text-[11px] text-[var(--color-ink-soft)] mt-0.5">Native login lockouts</div>
        </div>

        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Wordfence</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">
                {{ number_format($totals['wordfence_enabled']) }}
                <span class="text-sm text-[var(--color-ink-soft)]">/ {{ number_format($totals['sites']) }}</span>
            </div>
            <div class="text-[11px] text-[var(--color-ink-soft)] mt-0.5">WAF &amp; Login Security</div>
        </div>

        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Unprotected sites</div>
            <div class="text-2xl font-display {{ $totals['no_protection'] > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-status-green)]' }} font-data">
                {{ number_format($totals['no_protection']) }}
            </div>
            <div class="text-[11px] text-[var(--color-ink-soft)] mt-0.5">
                No Wordfence or Gatekeeper
            </div>
        </div>
    </div>

    <div class="card p-5 mb-6">
        <h2 class="font-display text-lg text-[var(--color-ink-strong)] mb-1">Protected plugins</h2>
        <p class="text-xs text-[var(--color-ink-soft)] mb-3">
            Control will refuse to deactivate or delete these slugs from the fleet. Companion and Renegade stay protected even if you omit them.
        </p>
        <form method="POST" action="{{ route('settings.wordpress-plugins.protected.update') }}" class="space-y-3">
            @csrf
            @method('PATCH')
            <textarea name="protected_plugins" rows="8" class="w-full font-data text-sm bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] rounded-md px-3 py-2 text-[var(--color-ink-strong)]">{{ old('protected_plugins', $protectedPlugins) }}</textarea>
            <div class="flex items-center justify-between gap-3">
                <p class="text-[11px] text-[var(--color-ink-muted)]">One <code>directory/file.php</code> slug per line.</p>
                <button type="submit" class="btn-pill-nav text-sm">Save protected list</button>
            </div>
        </form>
    </div>

    @if ($sites->isEmpty())
        <div class="card p-10 text-center text-[var(--color-ink-soft)]">
            No WordPress sites in inventory yet. Run an import for your hosting provider (<code class="bg-[var(--color-surface-alt)] px-1.5 py-0.5 rounded">php artisan clockwork:import-spinupwp</code>, <code class="bg-[var(--color-surface-alt)] px-1.5 py-0.5 rounded">clockwork:import-gridpane</code>, etc.).
        </div>
    @else
        <div class="card overflow-hidden"
             x-data="{
                 filter: 'all',
                 search: '',
                 matches(row) {
                     if (this.filter === 'missing-companion' && row.dataset.companion === '1') return false;
                     if (this.filter === 'companion-installed' && row.dataset.companion !== '1') return false;
                     if (this.filter === 'missing-gatekeeper' && row.dataset.gatekeeper === '1') return false;
                     if (this.search.trim() !== '') {
                         const q = this.search.toLowerCase();
                         const domain = (row.dataset.domain || '').toLowerCase();
                         const server = (row.dataset.server || '').toLowerCase();
                         if (!domain.includes(q) && !server.includes(q)) return false;
                     }
                     return true;
                 },
                 applyFilter() {
                     $el.querySelectorAll('tbody tr[data-site-row]').forEach(tr => {
                         const match = this.matches(tr);
                         tr.style.display = match ? '' : 'none';
                         const res = tr.nextElementSibling;
                         if (res && res.classList.contains('clockwork-result-row')) {
                             res.style.display = match ? '' : 'none';
                         }
                     });
                 }
             }"
             x-init="$watch('filter', () => applyFilter()); $watch('search', () => applyFilter())">

            {{-- Filter Tabs and Quick Search Toolbar --}}
            <div class="p-4 border-b border-[var(--color-border-light)] flex flex-wrap items-center justify-between gap-3 bg-[var(--color-surface-alt)]/50">
                <div class="inline-flex items-center gap-1.5 flex-wrap">
                    <button type="button"
                            @click="filter = 'all'"
                            :class="filter === 'all' ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-sm font-semibold border-[var(--color-border)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] border-transparent'"
                            class="px-3 py-1.5 rounded-md text-xs border transition-colors inline-flex items-center gap-1.5">
                        <span>All Sites</span>
                        <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] font-data">{{ $totals['sites'] }}</span>
                    </button>

                    <button type="button"
                            @click="filter = 'missing-companion'"
                            :class="filter === 'missing-companion' ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-sm font-semibold border-[var(--color-border)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] border-transparent'"
                            class="px-3 py-1.5 rounded-md text-xs border transition-colors inline-flex items-center gap-1.5">
                        <span>Missing Companion</span>
                        <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $totals['companion_missing'] > 0 ? 'bg-[var(--color-status-yellow-bg)] text-[var(--color-status-yellow)] font-semibold' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]' }} font-data">
                            {{ $totals['companion_missing'] }}
                        </span>
                    </button>

                    <button type="button"
                            @click="filter = 'companion-installed'"
                            :class="filter === 'companion-installed' ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-sm font-semibold border-[var(--color-border)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] border-transparent'"
                            class="px-3 py-1.5 rounded-md text-xs border transition-colors inline-flex items-center gap-1.5">
                        <span>Companion Installed</span>
                        <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] font-data">{{ $totals['companion_installed'] }}</span>
                    </button>

                    <button type="button"
                            @click="filter = 'missing-gatekeeper'"
                            :class="filter === 'missing-gatekeeper' ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-sm font-semibold border-[var(--color-border)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] border-transparent'"
                            class="px-3 py-1.5 rounded-md text-xs border transition-colors inline-flex items-center gap-1.5">
                        <span>Missing Gatekeeper</span>
                        <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] font-data">{{ $totals['sites'] - $totals['gatekeeper_enabled'] }}</span>
                    </button>
                </div>

                {{-- Instant search --}}
                <div class="relative w-full sm:w-64">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-[var(--color-ink-soft)] text-xs"></i>
                    <input type="text"
                           x-model="search"
                           placeholder="Filter domain or server…"
                           class="input-text w-full pl-8 pr-3 py-1.5 text-xs rounded-md border border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] placeholder:text-[var(--color-ink-soft)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary-500)]">
                </div>
            </div>

            @php
                $tierFor = function ($server) {
                    if (! $server) return null;
                    foreach ($server->tags ?? [] as $tag) {
                        if (in_array($tag->name, ['Dedicated', 'Shared', 'Staging'], true)) {
                            return $tag->name;
                        }
                    }
                    return null;
                };
                $tierRank = ['Dedicated' => 1, 'Shared' => 2, 'Staging' => 3];
            @endphp

            <div class="overflow-x-auto">
                <table class="w-full text-sm"
                       x-data="sortableTable({ defaultKey: 'companion', defaultDir: 'asc' })"
                       data-column-toggle="wp-plugins"
                       data-hidden-cols="server tier">
                    <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                        <tr>
                            <x-sort-th key="site"       data-col="site"       class="px-5 py-2.5">Site</x-sort-th>
                            <x-sort-th key="server"     data-col="server"     class="px-5 py-2.5">Server</x-sort-th>
                            <x-sort-th key="tier"       data-col="tier"       class="px-5 py-2.5">Tier</x-sort-th>
                            <x-sort-th key="companion"  data-col="companion"  class="px-5 py-2.5" title="Sort to surface sites without Companion">Companion Plugin</x-sort-th>
                            <x-sort-th key="gatekeeper" data-col="gatekeeper" class="px-5 py-2.5" title="Gatekeeper login lockouts">Gatekeeper</x-sort-th>
                            <x-sort-th key="wordfence"  data-col="wordfence"  class="px-5 py-2.5">Wordfence</x-sort-th>
                            <th class="px-5 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($sites as $site)
                            @php
                                $tier = $tierFor($site->server);
                                $probed = $site->wp_plugins_detected_at !== null;
                                $serverName = $site->server?->name ?? ($site->host()?->label() ?? '');
                            @endphp
                            <tr data-site-row="{{ $site->id }}"
                                data-domain="{{ $site->domain }}"
                                data-server="{{ $serverName }}"
                                data-companion="{{ $site->companion_installed ? '1' : '0' }}"
                                data-gatekeeper="{{ $site->gatekeeperEnabled() ? '1' : '0' }}"
                                data-wordfence="{{ $site->wordfence_enabled ? '1' : '0' }}"
                                data-sort-site="{{ $site->domain }}"
                                data-sort-server="{{ $serverName }}"
                                data-sort-tier="{{ $tier !== null ? $tierRank[$tier] : '' }}"
                                data-sort-companion="{{ $site->companion_installed ? 1 : 0 }}"
                                data-sort-gatekeeper="{{ $site->gatekeeperEnabled() ? 1 : 0 }}"
                                data-sort-wordfence="{{ $site->wordfence_enabled ? 1 : 0 }}"
                                class="hover:bg-[var(--color-surface-alt)]/40 transition-colors">
                                
                                {{-- Site domain --}}
                                <td data-col="site" class="px-5 py-3 font-data">
                                    <div class="flex items-center gap-2">
                                        <i class="fa-brands fa-wordpress text-[var(--color-ink-soft)] text-sm"></i>
                                        <a href="{{ route('sites.show', [$site, 'settings']) }}"
                                           class="font-medium text-[var(--color-primary-600)] hover:underline hover:text-[var(--color-primary-700)]">
                                            {{ $site->domain }}
                                        </a>
                                    </div>
                                </td>

                                {{-- Server / Host --}}
                                <td data-col="server" class="px-5 py-3 text-xs">
                                    @if ($site->server)
                                        <a href="{{ route('servers.show', $site->server) }}"
                                           class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]"
                                           title="{{ $site->server->name }}">
                                            {{ $site->server->display_name }}
                                        </a>
                                    @elseif ($site->host())
                                        <span class="text-[var(--color-ink-muted)] font-medium">{{ $site->host()->label() }}</span>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">—</span>
                                    @endif
                                </td>

                                {{-- Tier --}}
                                <td data-col="tier" class="px-5 py-3 text-xs">
                                    @if ($tier)
                                        <span class="status-pill status-unknown text-[10px]">{{ $tier }}</span>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">—</span>
                                    @endif
                                </td>

                                {{-- Companion Plugin Status --}}
                                <td data-col="companion" class="px-5 py-3">
                                    @if ($site->companion_installed)
                                        <span class="status-pill status-green text-xs inline-flex items-center gap-1.5"
                                              title="Companion mu-plugin installed{{ $site->companion_version ? ' (v'.$site->companion_version.')' : '' }}">
                                            <i class="fa-solid fa-circle-check"></i>
                                            <span>Installed{{ $site->companion_version ? ' v'.$site->companion_version : '' }}</span>
                                        </span>
                                    @else
                                        <span class="status-pill status-yellow text-xs inline-flex items-center gap-1.5"
                                              title="Companion mu-plugin is not yet installed on this site">
                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                            <span>Missing</span>
                                        </span>
                                    @endif
                                </td>

                                {{-- Gatekeeper Status --}}
                                <td data-col="gatekeeper" class="px-5 py-3 text-xs">
                                    @if ($site->gatekeeperEnabled())
                                        <span class="status-pill status-green text-xs inline-flex items-center gap-1.5" title="Gatekeeper login lockouts active">
                                            <i class="fa-solid fa-shield-halved"></i> Active
                                        </span>
                                    @else
                                        <span class="text-[var(--color-ink-soft)] inline-flex items-center gap-1" title="Gatekeeper not active">
                                            <i class="fa-solid fa-minus text-[10px]"></i> Inactive
                                        </span>
                                    @endif
                                </td>

                                {{-- Wordfence Status --}}
                                <td data-col="wordfence" class="px-5 py-3 text-xs">
                                    @if ($site->wordfence_enabled)
                                        <span class="status-pill status-green text-xs inline-flex items-center gap-1.5" title="Wordfence active">
                                            <i class="fa-solid fa-shield-halved"></i> Active
                                        </span>
                                    @elseif ($probed)
                                        <span class="text-[var(--color-ink-soft)] inline-flex items-center gap-1" title="Wordfence not active or not installed">
                                            <i class="fa-solid fa-minus text-[10px]"></i> Inactive
                                        </span>
                                    @else
                                        <span class="text-[var(--color-ink-soft)] italic" title="Not yet checked — re-probe to inspect">
                                            Unchecked
                                        </span>
                                    @endif
                                </td>

                                {{-- Actions --}}
                                <td class="px-5 py-3 text-right">
                                    <div class="inline-flex items-center gap-2 justify-end flex-wrap">
                                        {{-- Re-probe SSH state button --}}
                                        <button type="button"
                                                class="wp-refresh-btn btn-pill-nav text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]"
                                                data-url="{{ route('sites.wp-plugins.refresh', $site) }}"
                                                data-site-domain="{{ $site->domain }}"
                                                title="Re-probe security plugins on this site via SSH.">
                                            <i class="fa-solid fa-rotate"></i>
                                        </button>

                                        {{-- Install Companion button --}}
                                        @if (! $site->companion_installed)
                                            <button type="button"
                                                    class="companion-install-btn btn-pill-nav text-xs font-semibold text-[var(--color-primary-600)] border-[var(--color-primary-200)] hover:bg-[var(--color-primary-500)]/10"
                                                    data-url="{{ route('sites.companion.install', $site) }}"
                                                    data-status-url="{{ route('sites.companion.install-status', $site) }}"
                                                    data-site-domain="{{ $site->domain }}"
                                                    title="Install Clockwork Companion mu-plugin. Provides the Backups admin page, security headers, and REST monitoring.">
                                                <i class="fa-solid fa-download"></i> Install Companion
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
    </div>
@endsection
