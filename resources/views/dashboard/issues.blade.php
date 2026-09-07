@extends('layouts.app')

@section('title', 'Issues · Clockwork')

@section('content')
    @php
        $issuesSubtitle = $totals['all'] === 0
            ? 'All clear across the fleet.'
            : $totals['all'] . ' ' . Str::plural('item', $totals['all']) . ' need attention.';

        $chips = [
            ['key' => 'seo-indexability', 'label' => 'SEO blocked', 'class' => 'status-red'],
            ['key' => 'malware', 'label' => 'Malware', 'class' => 'status-red'],
            ['key' => 'companion_malware', 'label' => 'Malware findings', 'class' => 'status-red'],
            ['key' => 'tampering', 'label' => 'Core tampering', 'class' => 'status-red'],
            ['key' => 'health', 'label' => 'Health', 'class' => 'status-red'],
            ['key' => 'forms_failing', 'label' => 'Form tests', 'class' => 'status-red'],
            ['key' => 'hot', 'label' => 'Hot servers', 'class' => 'status-yellow'],
            ['key' => 'ssl', 'label' => 'SSL', 'class' => 'status-yellow'],
            ['key' => 'domain-expiration', 'label' => 'Domain', 'class' => 'status-yellow'],
            ['key' => 'cf', 'label' => 'Cloudflare', 'class' => 'status-yellow'],
            ['key' => 'patches', 'label' => 'Patches', 'class' => 'status-yellow'],
            ['key' => 'reboot', 'label' => 'Reboot', 'class' => 'status-yellow'],
            ['key' => 'no_ssh', 'label' => 'SSH', 'class' => 'status-yellow'],
            ['key' => 'no_jail', 'label' => 'Jail', 'class' => 'status-yellow'],
            ['key' => 'no_companion', 'label' => 'Companion', 'class' => 'status-yellow'],
            ['key' => 'plugins_outdated', 'label' => 'WP plugins', 'class' => 'status-yellow'],
            ['key' => 'two_factor', 'label' => '2FA', 'class' => 'status-yellow'],
            ['key' => 'orphans', 'label' => 'Orphans', 'class' => 'status-yellow'],
            ['key' => 'no_db', 'label' => 'DB creds', 'class' => 'status-yellow'],
        ];
    @endphp

    <x-page-header :title="'Issues'" :subtitle="$issuesSubtitle">
        <x-slot:actions>
            <div class="flex items-center gap-2 flex-wrap text-sm">
                @foreach ($chips as $chip)
                    @if ($totals[$chip['key']] > 0)
                        <a href="#section-{{ $chip['key'] }}" class="status-pill {{ $chip['class'] }}">
                            <span class="status-dot"></span>
                            {{ $chip['label'] }} {{ $totals[$chip['key']] }}
                        </a>
                    @endif
                @endforeach
                <a href="{{ route('issues.index') }}"
                   class="btn-pill-nav inline-flex items-center gap-1.5"
                   title="Loaded {{ now()->format('g:i:s a') }}"
                   onclick="this.querySelector('i').classList.add('fa-spin'); this.querySelector('span').textContent = 'Refreshing…';">
                    <i class="fa-solid fa-rotate"></i> <span>Refresh</span>
                </a>
            </div>
        </x-slot:actions>
    </x-page-header>

    @if ($totals['all'] === 0)
        <div class="card p-10 text-center">
            <i class="fa-solid fa-circle-check text-5xl text-[var(--color-status-green)] mb-3"></i>
            <p class="text-lg font-medium text-[var(--color-ink-strong)]">All clear</p>
            <p class="text-sm text-[var(--color-ink-muted)] mt-1">
                No SSL issues, no unhealthy servers, no provisioning gaps, no missing DB creds.
            </p>
        </div>
    @endif

    {{-- MALWARE / BLACKLIST (Sucuri SiteCheck) --}}
    @if ($malwareHits->isNotEmpty())
        <section id="section-malware" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-bug text-[var(--color-status-red)] mr-2"></i>
                        Malware or blacklist hit
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Latest Sucuri SiteCheck flagged the site as compromised or on a public blacklist.</p>
                </div>
                <span class="status-pill status-red">{{ $malwareHits->count() }}</span>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-5 py-2">Site</th>
                        <th class="text-left px-5 py-2">Server</th>
                        <th class="text-left px-5 py-2">Flag</th>
                        <th class="text-left px-5 py-2">Summary</th>
                        <th class="text-left px-5 py-2">Scanned</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($malwareHits as $row)
                        <tr>
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', [$row->site, 'security']) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $row->site->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs">
                                @if ($row->site->server)
                                    <a href="{{ route('servers.show', $row->site->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $row->site->server->name }}">{{ $row->site->server->display_name }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs">
                                @if ($row->has_malware_hit)<span class="status-pill status-red">malware</span>@endif
                                @if ($row->blacklist_hit)<span class="status-pill status-red">blacklist</span>@endif
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] truncate max-w-md">{{ $row->summary }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]" title="{{ $row->scanned_at }}">{{ $row->scanned_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- COMPANION MALWARE FINDINGS (PHP-in-uploads + obfuscation signatures, scanned on-site) --}}
    @if ($companionMalwareFindings->isNotEmpty())
        <section id="section-companion_malware" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-file-circle-exclamation text-[var(--color-status-red)] mr-2"></i>
                        Companion malware findings
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Latest Companion file scan flagged PHP in uploads or obfuscation/webshell signatures. Clients see the same result on their wp-admin Security page — get there first.</p>
                </div>
                <span class="status-pill status-red">{{ $companionMalwareFindings->count() }}</span>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-5 py-2">Site</th>
                        <th class="text-left px-5 py-2">Server</th>
                        <th class="text-right px-5 py-2">Findings</th>
                        <th class="text-left px-5 py-2">Summary</th>
                        <th class="text-left px-5 py-2">Scanned</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($companionMalwareFindings as $row)
                        <tr>
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', [$row->site, 'security']) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $row->site->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs">
                                @if ($row->site->server)
                                    <a href="{{ route('servers.show', $row->site->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $row->site->server->name }}">{{ $row->site->server->display_name }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs text-right font-data">{{ number_format($row->modified_files_count) }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] truncate max-w-md">{{ $row->summary }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]" title="{{ $row->scanned_at }}">{{ $row->scanned_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- CORE FILE TAMPERING (wp core verify-checksums) --}}
    @if ($checksumTampering->isNotEmpty())
        <section id="section-tampering" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-shield-halved text-[var(--color-status-red)] mr-2"></i>
                        Core file tampering
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Latest <code class="font-data">wp core verify-checksums</code> flagged modified, missing, or unexpected core files.</p>
                </div>
                <span class="status-pill status-red">{{ $checksumTampering->count() }}</span>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-5 py-2">Site</th>
                        <th class="text-left px-5 py-2">Server</th>
                        <th class="text-right px-5 py-2">Files flagged</th>
                        <th class="text-left px-5 py-2">Summary</th>
                        <th class="text-left px-5 py-2">Scanned</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($checksumTampering as $row)
                        <tr>
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', [$row->site, 'security']) }}#core-integrity" class="text-[var(--color-primary-600)] hover:underline">{{ $row->site->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs">
                                @if ($row->site->server)
                                    <a href="{{ route('servers.show', $row->site->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $row->site->server->name }}">{{ $row->site->server->display_name }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs text-right font-data">{{ number_format($row->modified_files_count) }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] truncate max-w-md">{{ $row->summary }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]" title="{{ $row->scanned_at }}">{{ $row->scanned_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- SITES CURRENTLY DOWN — real outages outrank everything below --}}
    @if ($downSites->isNotEmpty())
        <section id="section-down-sites" class="card overflow-hidden mb-6 ring-1 ring-[var(--color-status-red)]/30">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-circle-exclamation text-[var(--color-status-red)] mr-2"></i>
                        Sites currently down
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">HTTP probe failed 2+ times in a row. Mattermost was alerted at the transition.</p>
                </div>
                <span class="status-pill status-red">{{ $downSites->count() }}</span>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="px-5 py-2 text-left">Site</th>
                        <th class="px-5 py-2 text-left">Server</th>
                        <th class="px-5 py-2 text-left">Failure</th>
                        <th class="px-5 py-2 text-left">Down for</th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($downSites as $site)
                        <tr>
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $site) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $site->domain }}</a>
                            </td>
                            <td class="px-5 py-2 font-data text-[var(--color-ink-muted)]">{{ $site->server?->name ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-strong)] cell-failure">
                                @if ($site->uptime_last_status_code)
                                    HTTP {{ $site->uptime_last_status_code }}
                                @else
                                    <span class="text-[var(--color-ink-muted)]">unreachable</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-status-red)] font-data tabular-nums">
                                {{ $site->uptime_down_since?->diffForHumans(['parts' => 2, 'short' => true]) ?? '—' }}
                            </td>
                            <td class="px-5 py-2 text-right">
                                <button type="button"
                                        class="uptime-recheck-btn text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50"
                                        data-url="{{ route('sites.uptime.recheck', $site) }}">
                                    <i class="fa-solid fa-rotate"></i> Recheck
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <script>
                (function () {
                    const csrf = '{{ csrf_token() }}';
                    document.querySelectorAll('#section-down-sites .uptime-recheck-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const row = btn.closest('tr');
                            const original = btn.innerHTML;
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                            try {
                                const r = await fetch(btn.dataset.url, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                });
                                const data = await r.json();
                                if (data.ok) {
                                    if (data.state === 'up') {
                                        row.style.transition = 'opacity 400ms';
                                        row.style.opacity = '0';
                                        setTimeout(() => row.remove(), 450);
                                    } else {
                                        const failureCell = row.querySelector('.cell-failure');
                                        if (failureCell) {
                                            failureCell.innerHTML = data.status_code
                                                ? 'HTTP ' + data.status_code
                                                : '<span class="text-[var(--color-ink-muted)]">unreachable</span>';
                                        }
                                        btn.innerHTML = original;
                                        btn.disabled = false;
                                    }
                                } else {
                                    btn.innerHTML = '<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> ' + (data.message || 'Failed');
                                    setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                                }
                            } catch (e) {
                                btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
                                setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                            }
                        });
                    });
                })();
            </script>
        </section>
    @endif

    {{-- SEO INDEXABILITY --}}
    @if ($seoIssues->isNotEmpty() || $ignoredSeoIssues->isNotEmpty())
        <section id="section-seo-indexability" class="card overflow-hidden mb-6" x-data="{
            activeTab: 'active',
            ignoreModalOpen: false,
            targetSiteId: null,
            targetDomain: '',
            ignoreReason: '',
            openIgnore(id, domain) {
                this.targetSiteId = id;
                this.targetDomain = domain;
                this.ignoreReason = '';
                this.ignoreModalOpen = true;
            }
        }">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-magnifying-glass text-[var(--color-status-red)] mr-2"></i>
                        SEO indexability
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                        Red = production site blocking search engines · Gray = staging environment protected from search.
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="inline-flex items-center p-0.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-xs">
                        <button type="button"
                                @click="activeTab = 'active'"
                                :class="activeTab === 'active' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]'"
                                class="px-2.5 py-1 rounded-md transition-all inline-flex items-center gap-1.5 cursor-pointer">
                            <span>Active Issues</span>
                            @if ($totals['seo_blocked'] > 0)
                                <span class="status-pill status-red text-[10px] py-0 px-1.5 leading-tight">{{ $totals['seo_blocked'] }}</span>
                            @else
                                <span class="status-pill status-green text-[10px] py-0 px-1.5 leading-tight">0</span>
                            @endif
                        </button>
                        <button type="button"
                                @click="activeTab = 'ignored'"
                                :class="activeTab === 'ignored' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]'"
                                class="px-2.5 py-1 rounded-md transition-all inline-flex items-center gap-1.5 cursor-pointer">
                            <span>Ignored / Suppressed</span>
                            <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono font-medium {{ $ignoredSeoIssues->isNotEmpty() ? 'bg-[var(--color-surface-subtle)] text-[var(--color-ink-strong)] border border-[var(--color-border-light)]' : 'text-[var(--color-ink-muted)]' }}">
                                {{ $ignoredSeoIssues->count() }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- ACTIVE SEO ISSUES TABLE --}}
            <div x-show="activeTab === 'active'">
                @if ($seoIssues->isEmpty())
                    <div class="p-8 text-center text-xs text-[var(--color-ink-muted)]">
                        <i class="fa-solid fa-circle-check text-emerald-500 text-lg mb-2 block"></i>
                        No active SEO indexability issues across the fleet.
                        @if ($ignoredSeoIssues->isNotEmpty())
                            <span class="block mt-1">
                                ({{ $ignoredSeoIssues->count() }} {{ Str::plural('site', $ignoredSeoIssues->count()) }} currently suppressed in <button type="button" @click="activeTab = 'ignored'" class="text-[var(--color-brand)] underline hover:text-[var(--color-brand-dark)] cursor-pointer">Ignored</button>)
                            </span>
                        @endif
                    </div>
                @else
                    <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                                <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                                <x-sort-th key="status" class="px-5 py-2">Status</x-sort-th>
                                <x-sort-th key="reason" class="px-5 py-2">Blocked vector</x-sort-th>
                                <th class="px-5 py-2">Snippet</th>
                                <x-sort-th key="checked" class="px-5 py-2">Checked</x-sort-th>
                                <th class="px-5 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($seoIssues as $s)
                                @php
                                    $isStaging = $s->server?->isStaging() ?? false;
                                    $pillClass = $isStaging ? 'status-gray' : 'status-red';
                                    $statusLabel = $s->seoStatusLabel();
                                    $reasonLabel = match ($s->seo_blocked_reason) {
                                        'meta_noindex' => 'Meta noindex',
                                        'header_noindex' => 'X-Robots-Tag',
                                        'robots_disallow_all' => 'robots.txt Disallow',
                                        default => $s->seo_blocked_reason ?? 'Blocked',
                                    };
                                @endphp
                                <tr data-site-row="{{ $s->id }}"
                                    data-sort-site="{{ $s->domain }}"
                                    data-sort-server="{{ $s->server?->name ?? '' }}"
                                    data-sort-status="{{ $isStaging ? 'staging' : 'production' }}"
                                    data-sort-reason="{{ $s->seo_blocked_reason }}"
                                    data-sort-checked="{{ $s->seo_checked_at?->getTimestamp() ?? '' }}">
                                    <td class="px-5 py-2 font-data">
                                        <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                                    </td>
                                    <td class="px-5 py-2 text-xs font-data">
                                        <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server?->name }}</a>
                                    </td>
                                    <td class="px-5 py-2 cell-status">
                                        <span class="status-pill {{ $pillClass }} text-[10px]">{{ $statusLabel }}</span>
                                    </td>
                                    <td class="px-5 py-2 text-xs font-medium text-[var(--color-ink)] cell-reason">
                                        {{ $reasonLabel }}
                                    </td>
                                    <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] font-mono cell-snippet">
                                        @if ($s->seo_blocked_snippet)
                                            <code class="bg-[var(--color-surface-subtle)] px-1.5 py-0.5 rounded text-[11px]">{{ Str::limit($s->seo_blocked_snippet, 55) }}</code>
                                        @else
                                            <span class="text-[var(--color-ink-soft)]">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] cell-checked">
                                        {{ $s->seo_checked_at?->diffForHumans() ?? 'never' }}
                                    </td>
                                    <td class="px-5 py-2 text-right">
                                        <div class="flex items-center justify-end gap-2.5">
                                            <button type="button"
                                                    class="text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] inline-flex items-center gap-1 cursor-pointer"
                                                    title="Ignore this alert if noindex is intentional"
                                                    @click="openIgnore({{ $s->id }}, '{{ $s->domain }}')">
                                                <i class="fa-solid fa-eye-slash text-[11px]"></i>
                                                <span>Ignore</span>
                                            </button>
                                            <button type="button"
                                                    class="preflight-btn text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50 inline-flex items-center gap-1 cursor-pointer"
                                                    data-url="{{ route('sites.seo.preflight', $s) }}">
                                                <i class="fa-solid fa-rotate"></i>
                                                <span>Pre-flight check</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- IGNORED SEO ISSUES TABLE --}}
            <div x-show="activeTab === 'ignored'" x-cloak>
                @if ($ignoredSeoIssues->isEmpty())
                    <div class="p-8 text-center text-xs text-[var(--color-ink-muted)]">
                        <i class="fa-solid fa-info-circle text-[var(--color-ink-soft)] text-lg mb-2 block"></i>
                        No SEO indexability issues are currently ignored.
                    </div>
                @else
                    <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                                <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                                <th class="px-5 py-2">Blocked Vector</th>
                                <th class="px-5 py-2">Ignore Reason</th>
                                <x-sort-th key="ignored_at" class="px-5 py-2">Ignored Date</x-sort-th>
                                <th class="px-5 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($ignoredSeoIssues as $item)
                                @php
                                    $site = $item->site;
                                    $reasonLabel = match ($site?->seo_blocked_reason) {
                                        'meta_noindex' => 'Meta noindex',
                                        'header_noindex' => 'X-Robots-Tag',
                                        'robots_disallow_all' => 'robots.txt Disallow',
                                        default => $site?->seo_blocked_reason ?? 'Blocked',
                                    };
                                @endphp
                                <tr data-sort-site="{{ $site?->domain ?? '' }}"
                                    data-sort-server="{{ $site?->server?->name ?? '' }}"
                                    data-sort-ignored_at="{{ $item->created_at?->getTimestamp() ?? '' }}">
                                    <td class="px-5 py-2 font-data">
                                        @if ($site)
                                            <a href="{{ route('sites.show', $site) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $site->domain }}</a>
                                        @else
                                            <span class="text-[var(--color-ink-muted)]">Deleted Site #{{ $item->site_id }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-xs font-data">
                                        @if ($site?->server)
                                            <a href="{{ route('servers.show', $site->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $site->server->name }}</a>
                                        @else
                                            <span class="text-[var(--color-ink-soft)]">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-xs text-[var(--color-ink)] font-medium">
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-[var(--color-surface-subtle)] text-[11px] border border-[var(--color-border-light)]">
                                            <i class="fa-solid fa-ban text-[var(--color-ink-muted)] text-[10px]"></i>
                                            {{ $reasonLabel }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-2 text-xs text-[var(--color-ink-strong)]">
                                        @if ($item->reason)
                                            <span class="font-medium text-[var(--color-ink-strong)]">{{ $item->reason }}</span>
                                        @else
                                            <span class="text-[var(--color-ink-soft)] italic">No reason provided</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] font-data">
                                        {{ $item->created_at?->format('M j, Y') }}
                                        @if ($item->user)
                                            <span class="text-[11px] text-[var(--color-ink-muted)]">by {{ $item->user->name }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-right">
                                        <div class="flex items-center justify-end gap-2.5">
                                            <form method="POST" action="{{ route('issues.unignore', $item) }}" class="inline">
                                                @csrf
                                                <button type="submit"
                                                        class="btn-pill-nav text-xs cursor-pointer"
                                                        title="Resume monitoring and alerting for this site">
                                                    <i class="fa-solid fa-play text-emerald-600 text-[10px]"></i>
                                                    <span>Resume monitoring</span>
                                                </button>
                                            </form>
                                            @if ($site)
                                                <button type="button"
                                                        class="preflight-btn text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50 inline-flex items-center gap-1 cursor-pointer"
                                                        data-url="{{ route('sites.seo.preflight', $site) }}">
                                                    <i class="fa-solid fa-rotate"></i>
                                                    <span>Pre-flight</span>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- IGNORE MODAL --}}
            <div x-show="ignoreModalOpen"
                 x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-xs"
                 @keydown.escape.window="ignoreModalOpen = false">
                <div class="card p-5 max-w-md w-full bg-[var(--color-surface)] shadow-xl border border-[var(--color-border-light)] rounded-xl"
                     @click.outside="ignoreModalOpen = false">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-8 h-8 rounded-full bg-amber-500/10 text-amber-600 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-eye-slash"></i>
                            </span>
                            <div>
                                <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)]">Ignore SEO Indexability Alert</h3>
                                <p class="text-xs text-[var(--color-ink-muted)] font-data" x-text="targetDomain"></p>
                            </div>
                        </div>
                        <button type="button" @click="ignoreModalOpen = false" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] cursor-pointer">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <p class="text-xs text-[var(--color-ink-muted)] mb-4 leading-relaxed">
                        Suppressing this alert hides the site from <code class="text-[11px] px-1 py-0.5 rounded bg-[var(--color-surface-subtle)]">/issues</code> and decrements the navigation badge. You can review all ignored sites and resume monitoring anytime.
                    </p>

                    <form method="POST" action="{{ route('issues.ignore') }}">
                        @csrf
                        <input type="hidden" name="issue_type" value="seo_indexability">
                        <input type="hidden" name="site_id" :value="targetSiteId">

                        <div class="mb-4">
                            <label class="block text-[11px] uppercase tracking-wider text-[var(--color-ink-soft)] font-semibold mb-1.5">
                                Reason for ignoring (optional)
                            </label>
                            <input type="text"
                                   name="reason"
                                   x-model="ignoreReason"
                                   placeholder="e.g. Internal employee intranet, volunteer portal, deliberate noindex"
                                   class="w-full text-xs px-3 py-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] placeholder:text-[var(--color-ink-muted)] focus:outline-hidden focus:ring-2 focus:ring-[var(--color-brand)]/20 focus:border-[var(--color-brand)]">
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-[var(--color-border-light)]">
                            <button type="button"
                                    @click="ignoreModalOpen = false"
                                    class="btn-pill-nav text-xs cursor-pointer">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="btn-primary text-xs font-medium cursor-pointer">
                                <i class="fa-solid fa-eye-slash text-[11px]"></i>
                                <span>Ignore Alert</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                (function () {
                    const csrf = '{{ csrf_token() }}';
                    document.querySelectorAll('#section-seo-indexability .preflight-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const row = btn.closest('tr');
                            const original = btn.innerHTML;
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking…';
                            try {
                                const r = await fetch(btn.dataset.url, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                });
                                const data = await r.json();
                                if (data.ok) {
                                    if (data.indexable) {
                                        row.style.transition = 'opacity 400ms';
                                        row.style.opacity = '0';
                                        setTimeout(() => row.remove(), 450);
                                    } else {
                                        const pillClass = data.is_staging ? 'status-gray' : 'status-red';
                                        const pill = document.createElement('span');
                                        pill.className = `status-pill ${pillClass} text-[10px]`;
                                        pill.textContent = data.status_label;
                                        const statusCell = row.querySelector('.cell-status');
                                        statusCell.textContent = '';
                                        statusCell.appendChild(pill);

                                        if (data.reason) {
                                            row.querySelector('.cell-reason').textContent = data.reason;
                                        }
                                        if (data.snippet) {
                                            const codeEl = document.createElement('code');
                                            codeEl.className = 'bg-[var(--color-surface-subtle)] px-1.5 py-0.5 rounded text-[11px]';
                                            codeEl.textContent = data.snippet.substring(0, 55);
                                            const snippetCell = row.querySelector('.cell-snippet');
                                            snippetCell.textContent = '';
                                            snippetCell.appendChild(codeEl);
                                        }
                                        row.querySelector('.cell-checked').textContent = 'just now';
                                        btn.innerHTML = original;
                                        btn.disabled = false;
                                    }
                                } else {
                                    btn.innerHTML = '<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> Failed';
                                    setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                                }
                            } catch (e) {
                                btn.innerHTML = '<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> Error';
                                setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                            }
                        });
                    });
                })();
            </script>
        </section>
    @endif

    {{-- HEALTH --}}
    @if ($unhealthyServers->isNotEmpty())
        <section id="section-health" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-heart-pulse text-[var(--color-status-red)] mr-2"></i>
                        Server health
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Servers reporting status=red from the DigitalOcean poller.</p>
                </div>
                <span class="status-pill status-red">{{ $unhealthyServers->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'server', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="polled" class="px-5 py-2">Last polled</x-sort-th>
                        <x-sort-th key="alert" class="px-5 py-2">Last alert</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($unhealthyServers as $s)
                        <tr
                            data-sort-server="{{ $s->name }}"
                            data-sort-polled="{{ $s->last_polled_at?->getTimestamp() ?? '' }}"
                            data-sort-alert="{{ $s->last_alert_at?->getTimestamp() ?? '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('servers.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] cell-polled">{{ $s->last_polled_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $s->last_alert_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-5 py-2 text-right">
                                @if ($s->provider_id)
                                    <button type="button"
                                            class="health-recheck-btn text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50"
                                            data-url="{{ route('servers.recheck-health', $s) }}">
                                        <i class="fa-solid fa-rotate"></i> Recheck
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <script>
                (function () {
                    const csrf = '{{ csrf_token() }}';
                    document.querySelectorAll('#section-health .health-recheck-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const row = btn.closest('tr');
                            const original = btn.innerHTML;
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                            try {
                                const r = await fetch(btn.dataset.url, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                });
                                const data = await r.json();
                                if (data.ok) {
                                    if (data.status === 'green' || data.status === 'yellow') {
                                        row.style.transition = 'opacity 400ms';
                                        row.style.opacity = '0';
                                        setTimeout(() => row.remove(), 450);
                                    } else {
                                        row.querySelector('.cell-polled').textContent = data.last_polled ?? 'just now';
                                        btn.innerHTML = original;
                                        btn.disabled = false;
                                    }
                                } else {
                                    btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-[var(--color-status-red)]"></i>';
                                    btn.title = data.error ?? 'Poll failed';
                                    setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                                }
                            } catch (e) {
                                btn.innerHTML = original;
                                btn.disabled = false;
                            }
                        });
                    });
                })();
            </script>
        </section>
    @endif

    {{-- HOT --}}
    @if ($hotServers->isNotEmpty())
        <section id="section-hot" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-temperature-three-quarters text-[var(--color-status-yellow)] mr-2"></i>
                        Hot servers
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">24-hour average exceeds CPU/memory/disk yellow thresholds (sustained, not spikes).</p>
                </div>
                <span class="status-pill status-yellow">{{ $hotServers->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'cpu', defaultDir: 'desc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="reasons" class="px-5 py-2">Reasons (24h avg)</x-sort-th>
                        <x-sort-th key="cpu" align="right" class="px-5 py-2">CPU avg</x-sort-th>
                        <x-sort-th key="mem" align="right" class="px-5 py-2">Mem avg</x-sort-th>
                        <x-sort-th key="disk" align="right" class="px-5 py-2">Disk avg</x-sort-th>
                        <x-sort-th key="load" align="right" class="px-5 py-2">Load avg</x-sort-th>
                        <x-sort-th key="samples" align="right" class="px-5 py-2">Samples</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($hotServers as $s)
                        @php $a = $s->_avg; @endphp
                        <tr
                            data-sort-server="{{ $s->name }}"
                            data-sort-reasons="{{ implode(' · ', $s->_reasons) }}"
                            data-sort-cpu="{{ $a->avg_cpu ?? '' }}"
                            data-sort-mem="{{ $a->avg_memory ?? '' }}"
                            data-sort-disk="{{ $a->avg_disk ?? '' }}"
                            data-sort-load="{{ $a->avg_load ?? '' }}"
                            data-sort-samples="{{ $a->samples }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('servers.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ implode(' · ', $s->_reasons) }}</td>
                            <td class="px-5 py-2 text-right text-xs">{{ $a->avg_cpu !== null ? number_format((float) $a->avg_cpu, 0) . '%' : '—' }}</td>
                            <td class="px-5 py-2 text-right text-xs">{{ $a->avg_memory !== null ? number_format((float) $a->avg_memory, 0) . '%' : '—' }}</td>
                            <td class="px-5 py-2 text-right text-xs">{{ $a->avg_disk !== null ? number_format((float) $a->avg_disk, 0) . '%' : '—' }}</td>
                            <td class="px-5 py-2 text-right text-xs">{{ $a->avg_load !== null ? number_format((float) $a->avg_load, 2) : '—' }}</td>
                            <td class="px-5 py-2 text-right text-xs text-[var(--color-ink-soft)]">{{ $a->samples }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- SSL --}}
    @if ($sslIssues->isNotEmpty())
        @php
            // Orange cloud = CF proxy on (origin cert may be cosmetic — visitors see CF's edge cert).
            // Gray cloud = CF DNS only, proxy off (origin cert is what users see, matters fully).
            // No icon = not on Cloudflare at all (origin cert matters fully).
            $renderCfIcon = function (?string $state) {
                return match ($state) {
                    'proxied' => '<i class="fa-solid fa-cloud" style="color: #F38020" title="Cloudflare proxy active — visitors see CF edge cert, not origin"></i>',
                    'dns_only' => '<i class="fa-solid fa-cloud text-[var(--color-ink-soft)]" title="On Cloudflare DNS but proxy is OFF — origin cert is user-facing"></i>',
                    default => '',
                };
            };
        @endphp
        <section id="section-ssl" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-lock-open text-[var(--color-ink-muted)] mr-2"></i>
                        SSL certificates
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Yellow = renewal window passed without rollover · Red = already expired. <i class="fa-solid fa-cloud" style="color: #F38020"></i> = on Cloudflare with proxy active · <i class="fa-solid fa-cloud text-[var(--color-ink-soft)]"></i> = on Cloudflare DNS only.</p>
                </div>
                <span class="status-pill status-yellow">{{ $sslIssues->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'expires', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="w-8 px-3 py-2"></th>
                        <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="state" class="px-5 py-2">State</x-sort-th>
                        <x-sort-th key="expires" class="px-5 py-2">Expires</x-sort-th>
                        <x-sort-th key="source" class="px-5 py-2">Source</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($sslIssues as $s)
                        @php
                            $state = $s->sslState();
                            $stateClass = $state === 'red' ? 'status-red' : 'status-yellow';
                            $stateLabel = $state === 'red' ? 'Expired' : 'Renewal needed';
                        @endphp
                        <tr data-site-row="{{ $s->id }}"
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name ?? '' }}"
                            data-sort-state="{{ $state }}"
                            data-sort-expires="{{ $s->cert_expires_at?->getTimestamp() ?? '' }}"
                            data-sort-source="{{ $s->cert_source }}">
                            <td class="px-3 py-2 text-center">
                                {!! $renderCfIcon($s->cloudflare_state) !!}
                            </td>
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server?->name }}</a>
                            </td>
                            <td class="px-5 py-2 cell-state">
                                <span class="status-pill {{ $stateClass }} text-[10px]">{{ $stateLabel }}</span>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] cell-expires">
                                {{ $s->cert_expires_at?->format('M j, Y') }}
                                <span class="text-[var(--color-ink-soft)]">({{ $s->cert_expires_at?->diffForHumans() }})</span>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] font-data">{{ $s->cert_source }}</td>
                            <td class="px-5 py-2 text-right">
                                <button type="button"
                                        class="recheck-btn text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50"
                                        data-url="{{ route('sites.cert.recheck', $s) }}">
                                    <i class="fa-solid fa-rotate"></i> Recheck
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <script>
                (function () {
                    const csrf = '{{ csrf_token() }}';
                    document.querySelectorAll('#section-ssl .recheck-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const row = btn.closest('tr');
                            const original = btn.innerHTML;
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                            try {
                                const r = await fetch(btn.dataset.url, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                });
                                const data = await r.json();
                                if (data.ok) {
                                    if (data.state === 'green' || data.state === 'none') {
                                        row.style.transition = 'opacity 400ms';
                                        row.style.opacity = '0';
                                        setTimeout(() => row.remove(), 450);
                                    } else {
                                        const cls = data.state === 'red' ? 'status-red' : 'status-yellow';
                                        const label = data.state === 'red' ? 'Expired' : 'Renewal needed';
                                        const pill = document.createElement('span');
                                        pill.className = `status-pill ${cls} text-[10px]`;
                                        pill.textContent = label;
                                        const stateCell = row.querySelector('.cell-state');
                                        stateCell.textContent = '';
                                        stateCell.appendChild(pill);
                                        if (data.expires_at) {
                                            row.querySelector('.cell-expires').textContent =
                                                new Date(data.expires_at).toLocaleString();
                                        }
                                        btn.innerHTML = original;
                                        btn.disabled = false;
                                    }
                                } else {
                                    btn.innerHTML = '<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> ' + (data.message || 'Failed');
                                    setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                                }
                            } catch (e) {
                                btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
                                setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                            }
                        });
                    });
                })();
            </script>
        </section>
    @endif

    {{-- DOMAIN EXPIRATION --}}
    @if ($domainExpirationIssues->isNotEmpty())
        <section id="section-domain-expiration" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-globe text-[var(--color-ink-muted)] mr-2"></i>
                        Domain expiration
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                        Yellow = renewal window (≤30 days) · Red = expiring soon (≤7 days) or redemption/pending delete.
                    </p>
                </div>
                <span class="status-pill status-yellow">{{ $domainExpirationIssues->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'expires', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="state" class="px-5 py-2">State</x-sort-th>
                        <x-sort-th key="expires" class="px-5 py-2">Expires</x-sort-th>
                        <x-sort-th key="registrar" class="px-5 py-2">Registrar</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($domainExpirationIssues as $s)
                        @php
                            $state = $s->domainExpirationState();
                            $stateClass = $state === 'red' ? 'status-red' : 'status-yellow';
                            $stateLabel = $s->domainExpirationStateLabel();
                        @endphp
                        <tr data-site-row="{{ $s->id }}"
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name ?? '' }}"
                            data-sort-state="{{ $state }}"
                            data-sort-expires="{{ $s->domain_expires_at?->getTimestamp() ?? '' }}"
                            data-sort-registrar="{{ $s->domain_registrar ?? '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server?->name }}</a>
                            </td>
                            <td class="px-5 py-2 cell-state">
                                <span class="status-pill {{ $stateClass }} text-[10px]">{{ $stateLabel }}</span>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] cell-expires">
                                {{ $s->domain_expires_at?->format('M j, Y') }}
                                <span class="text-[var(--color-ink-soft)]">({{ $s->domain_expires_at?->diffForHumans() }})</span>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] font-data cell-registrar">
                                {{ $s->domain_registrar ?? 'Unknown' }}
                            </td>
                            <td class="px-5 py-2 text-right">
                                <button type="button"
                                        class="recheck-btn text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50"
                                        data-url="{{ route('sites.domain.recheck', $s) }}">
                                    <i class="fa-solid fa-rotate"></i> Recheck
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <script>
                (function () {
                    const csrf = '{{ csrf_token() }}';
                    document.querySelectorAll('#section-domain-expiration .recheck-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const row = btn.closest('tr');
                            const original = btn.innerHTML;
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                            try {
                                const r = await fetch(btn.dataset.url, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                });
                                const data = await r.json();
                                if (data.ok) {
                                    if (data.state === 'green' || data.state === 'none') {
                                        row.style.transition = 'opacity 400ms';
                                        row.style.opacity = '0';
                                        setTimeout(() => row.remove(), 450);
                                    } else {
                                        const cls = data.state === 'red' ? 'status-red' : 'status-yellow';
                                        const pill = document.createElement('span');
                                        pill.className = `status-pill ${cls} text-[10px]`;
                                        pill.textContent = data.state_label;
                                        const stateCell = row.querySelector('.cell-state');
                                        stateCell.textContent = '';
                                        stateCell.appendChild(pill);
                                        if (data.expires_formatted) {
                                            row.querySelector('.cell-expires').textContent =
                                                data.expires_formatted + (data.days_remaining !== null ? ` (${data.days_remaining}d left)` : '');
                                        }
                                        if (data.registrar) {
                                            row.querySelector('.cell-registrar').textContent = data.registrar;
                                        }
                                        btn.innerHTML = original;
                                        btn.disabled = false;
                                    }
                                } else {
                                    btn.innerHTML = '<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> Failed';
                                    setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                                }
                            } catch (e) {
                                btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
                                setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                            }
                        });
                    });
                })();
            </script>
        </section>
    @endif

    {{-- CLOUDFLARE MISCONFIG --}}
    @if ($cfMisconfigured->isNotEmpty())
        <section id="section-cf" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-cloud text-yellow-500 mr-2"></i>
                        Cloudflare misconfigured
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                        These domains use Cloudflare DNS but the proxy (orange cloud) is off — traffic is hitting the origin directly. Toggle the proxy on in Cloudflare → DNS to fix.
                    </p>
                </div>
                <span class="status-pill status-yellow">{{ $cfMisconfigured->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="arecord" class="px-5 py-2 font-data">A record (origin)</x-sort-th>
                        <x-sort-th key="ns" class="px-5 py-2 font-data">NS</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($cfMisconfigured as $s)
                        <tr
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name ?? '' }}"
                            data-sort-arecord="{{ $s->resolved_a_record ?? '' }}"
                            data-sort-ns="{{ $s->resolved_ns_record ?? '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server?->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data text-[var(--color-ink-soft)]">{{ $s->resolved_a_record ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs font-data text-[var(--color-ink-soft)]">{{ $s->resolved_ns_record ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- Shared banner for any in-page server-reboot button — sits above both
         Patches Available and Reboot Required so the JS handler below can
         flash status without depending on which card is rendered. --}}
    @if ($patchesAvailable->isNotEmpty() || $rebootRequired->isNotEmpty())
        <div id="reboot-action-banner" class="hidden card px-5 py-3 mb-4 text-sm"></div>
    @endif

    {{-- PATCHES (mirrors SpinupWP's upgrade_required) --}}
    @if ($patchesAvailable->isNotEmpty())
        <section id="section-patches" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-cube text-[var(--color-ink-muted)] mr-2"></i>
                        Patches available
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">SpinupWP reports non-security apt updates are pending. Security updates auto-install via unattended-upgrades.</p>
                </div>
                <span class="status-pill status-yellow">{{ $patchesAvailable->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'server', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="ubuntu" class="px-5 py-2">Ubuntu</x-sort-th>
                        <x-sort-th key="reboot" class="px-5 py-2">Reboot</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($patchesAvailable as $s)
                        <tr
                            data-sort-server="{{ $s->name }}"
                            data-sort-ubuntu="{{ $s->ubuntu_version ?: '' }}"
                            data-sort-reboot="{{ $s->reboot_required ? '1' : '0' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('servers.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] font-data">ubuntu {{ $s->ubuntu_version ?: '?' }}</td>
                            <td class="px-5 py-2 text-xs text-right">
                                @if ($s->reboot_required)
                                    <span class="text-[var(--color-ink-soft)] mr-2">reboot pending</span>
                                @endif
                                <button type="button"
                                        class="reboot-now text-xs text-[var(--color-status-yellow)] hover:opacity-80 disabled:opacity-50 font-medium"
                                        data-url="{{ route('servers.reboot', $s) }}"
                                        data-server-name="{{ $s->name }}">
                                    <i class="fa-solid fa-power-off"></i> Reboot now
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- REBOOT (mirrors SpinupWP's reboot_required — typically a kernel patch was applied via unattended-upgrades) --}}
    @if ($rebootRequired->isNotEmpty())
        <section id="section-reboot" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-power-off text-[var(--color-ink-muted)] mr-2"></i>
                        Reboot required
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">A kernel or system update has been applied; the server is running an old image until rebooted.</p>
                </div>
                <span class="status-pill status-yellow">{{ $rebootRequired->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'server', defaultDir: 'asc' })" id="reboot-list">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="ubuntu" class="px-5 py-2">Ubuntu</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($rebootRequired as $s)
                        <tr data-server-id="{{ $s->id }}"
                            data-sort-server="{{ $s->name }}"
                            data-sort-ubuntu="{{ $s->ubuntu_version ?: '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('servers.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] font-data">ubuntu {{ $s->ubuntu_version ?: '?' }}</td>
                            <td class="px-5 py-2 text-right">
                                <div class="inline-flex items-center gap-3 justify-end">
                                    <button type="button"
                                            class="reboot-recheck text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50"
                                            data-url="{{ route('servers.reboot.probe', $s) }}"
                                            data-server-name="{{ $s->name }}">
                                        <i class="fa-solid fa-rotate"></i> Recheck
                                    </button>
                                    <button type="button"
                                            class="reboot-now text-xs text-[var(--color-status-yellow)] hover:opacity-80 disabled:opacity-50 font-medium"
                                            data-url="{{ route('servers.reboot', $s) }}"
                                            data-server-name="{{ $s->name }}">
                                        <i class="fa-solid fa-power-off"></i> Reboot now
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- Reboot-button JS handler — wired for any .reboot-now / .reboot-recheck
         button on this page. Renders whenever either the Patches Available or
         Reboot Required section is shown so the shared #reboot-action-banner
         it talks to is always in the DOM. --}}
    @if ($patchesAvailable->isNotEmpty() || $rebootRequired->isNotEmpty())
        <script>
            (function () {
                const result = document.getElementById('reboot-action-banner');
                const setBanner = (cls, html) => {
                    // Preserve the card framing the blade markup sets up; just
                    // swap the color modifier.
                    result.className = `card px-5 py-3 mb-4 text-sm ${cls}`;
                    result.innerHTML = html;
                    result.classList.remove('hidden');
                };
                const fadeOut = (row) => {
                    row.style.transition = 'opacity 0.5s';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 500);
                };

                document.querySelectorAll('.reboot-recheck').forEach((btn) => {
                    btn.addEventListener('click', async () => {
                        const row = btn.closest('tr');
                        btn.disabled = true;
                        const original = btn.innerHTML;
                        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Probing…';
                        setBanner('text-[var(--color-ink-muted)]', `Probing ${btn.dataset.serverName}…`);
                        try {
                            const r = await fetch(btn.dataset.url, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                            });
                            const data = await r.json();
                            if (data.ok) {
                                if (data.reboot_required) {
                                    setBanner('text-[var(--color-status-yellow)]',
                                        `<i class="fa-solid fa-triangle-exclamation"></i> ${btn.dataset.serverName}: ${data.message}`);
                                } else {
                                    setBanner('text-[var(--color-status-green)]',
                                        `<i class="fa-solid fa-circle-check"></i> ${btn.dataset.serverName}: ${data.message} Removing from list.`);
                                    fadeOut(row);
                                }
                            } else {
                                setBanner('text-[var(--color-status-red)]',
                                    `<i class="fa-solid fa-circle-xmark"></i> ${btn.dataset.serverName}: ${data.message || 'Failed.'}`);
                            }
                        } catch (e) {
                            setBanner('text-[var(--color-status-red)]', 'Network error: ' + e.message);
                        } finally {
                            btn.disabled = false;
                            btn.innerHTML = original;
                        }
                    });
                });

                // Reboot now — confirm, POST, fade row. Posts no reboot_at, so
                // ServerUpdateController::reboot schedules a +1-minute reboot.
                document.querySelectorAll('.reboot-now').forEach((btn) => {
                    btn.addEventListener('click', async () => {
                        const row = btn.closest('tr');
                        const name = btn.dataset.serverName;
                        if (! confirm(`Reboot ${name} now? The box will go down momentarily and come back in ~30–90 seconds.`)) {
                            return;
                        }
                        btn.disabled = true;
                        const original = btn.innerHTML;
                        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Issuing…';
                        setBanner('text-[var(--color-ink-muted)]', `Issuing reboot on ${name}…`);
                        try {
                            const r = await fetch(btn.dataset.url, {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({}),
                            });
                            const data = await r.json();
                            if (data.ok) {
                                setBanner('text-[var(--color-status-green)]',
                                    `<i class="fa-solid fa-circle-check"></i> ${name}: ${data.message}`);
                                fadeOut(row);
                            } else {
                                setBanner('text-[var(--color-status-red)]',
                                    `<i class="fa-solid fa-circle-xmark"></i> ${name}: ${data.message || 'Reboot failed.'}`);
                            }
                        } catch (e) {
                            setBanner('text-[var(--color-status-red)]', 'Network error: ' + e.message);
                        } finally {
                            btn.disabled = false;
                            btn.innerHTML = original;
                        }
                    });
                });
            })();
        </script>
    @endif

    {{-- SSH --}}
    @if ($missingSsh->isNotEmpty())
        <section id="section-no_ssh" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-key text-[var(--color-ink-muted)] mr-2"></i>
                        SSH not verified
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Until SSH is verified, the server can't pull logs or ban IPs.</p>
                </div>
                <span class="status-pill status-yellow">{{ $missingSsh->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'server', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="status" class="px-5 py-2">Status</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($missingSsh as $s)
                        @php $sshStatus = $s->ssh_password ? 'Password stored — needs Test SSH' : 'No credentials yet'; @endphp
                        <tr
                            data-sort-server="{{ $s->name }}"
                            data-sort-status="{{ $sshStatus }}"
                            x-data="{ testing: false, result: null }"
                        >
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('servers.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs">
                                <span x-show="result === null" class="text-[var(--color-ink-soft)]">{{ $sshStatus }}</span>
                                <span x-show="result !== null && result.ok" x-cloak class="text-green-600 font-medium" x-text="result?.message"></span>
                                <span x-show="result !== null && !result.ok" x-cloak class="text-red-500" x-text="result?.message"></span>
                            </td>
                            <td class="px-5 py-2 text-right">
                                @if ($s->ssh_password)
                                    <button
                                        @click="
                                            testing = true;
                                            fetch('{{ route('servers.test', $s) }}', {
                                                method: 'POST',
                                                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
                                            })
                                            .then(r => r.json())
                                            .then(d => { result = d; testing = false; })
                                            .catch(() => { result = { ok: false, message: 'Request failed' }; testing = false; })
                                        "
                                        :disabled="testing"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] hover:bg-gray-200 transition-colors disabled:opacity-50"
                                    >
                                        <span x-show="!testing"><i class="fa-solid fa-plug mr-1"></i>Test SSH</span>
                                        <span x-show="testing" x-cloak><i class="fa-solid fa-spinner fa-spin mr-1"></i>Testing…</span>
                                    </button>
                                @else
                                    <a href="{{ route('servers.credentials.edit', $s) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] hover:bg-gray-200 transition-colors">
                                        <i class="fa-solid fa-key mr-1"></i>Add password
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- JAIL --}}
    @if ($missingJail->isNotEmpty())
        <section id="section-no_jail" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-shield-halved text-[var(--color-ink-muted)] mr-2"></i>
                        Fail2ban not provisioned
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">SSH works but the clockwork jail isn't installed yet — banning IPs won't work on these.</p>
                </div>
                <span class="status-pill status-yellow">{{ $missingJail->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'server', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($missingJail as $s)
                        <tr data-sort-server="{{ $s->name }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('servers.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->name }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- FORM TESTING FAILING --}}
    @if ($failedFormTests->isNotEmpty())
        <section id="section-forms_failing" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-envelope-circle-check text-[var(--color-status-red)] mr-2"></i>
                        Contact form failing
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Care-plan sites with contact-form testing on whose daily test has failed two or more runs in a row. Mattermost was pinged on the second failure.</p>
                </div>
                <span class="status-pill status-red">{{ $failedFormTests->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'streak', defaultDir: 'desc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="plugin" class="px-5 py-2">Plugin</x-sort-th>
                        <x-sort-th key="streak" align="right" class="px-5 py-2">Streak</x-sort-th>
                        <x-sort-th key="last_test" class="px-5 py-2">Last test</x-sort-th>
                        <x-sort-th key="error" class="px-5 py-2">Error</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($failedFormTests as $cft)
                        @php $s = $cft->site; @endphp
                        <tr
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name ?? '' }}"
                            data-sort-plugin="{{ $cft->form_plugin }}"
                            data-sort-streak="{{ $cft->failure_streak }}"
                            data-sort-last_test="{{ $cft->last_test_at?->getTimestamp() ?? '' }}"
                            data-sort-error="{{ $cft->last_test_error }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', ['site' => $s, 'tab' => 'forms']) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                                <span class="text-[10px] text-[var(--color-ink-soft)] ml-1">{{ $cft->form_id }}</span>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server?->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs">{{ $cft->form_plugin }}</td>
                            <td class="px-5 py-2 text-right text-xs font-data">{{ $cft->failure_streak }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $cft->last_test_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] truncate max-w-xs" title="{{ $cft->last_test_error }}">{{ \Illuminate\Support\Str::limit($cft->last_test_error, 80) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- COMPANION MISSING / STALE --}}
    @if ($companionMissing->isNotEmpty())
        <section id="section-no_companion" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-plug-circle-xmark text-[var(--color-ink-muted)] mr-2"></i>
                        Companion plugin not reachable
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Form testing is enabled for these sites but the Companion mu-plugin hasn't checked in within 48h (or was never installed). Click into each site and hit <strong>Install Companion</strong> on the Settings tab.</p>
                </div>
                <span class="status-pill status-yellow">{{ $companionMissing->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="installed" class="px-5 py-2">Installed</x-sort-th>
                        <x-sort-th key="version" class="px-5 py-2">Version</x-sort-th>
                        <x-sort-th key="last_seen" class="px-5 py-2">Last seen</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($companionMissing as $s)
                        <tr
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name ?? '' }}"
                            data-sort-installed="{{ $s->companion_installed ? '1' : '0' }}"
                            data-sort-version="{{ $s->companion_version }}"
                            data-sort-last_seen="{{ $s->companion_last_seen_at?->getTimestamp() ?? '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', ['site' => $s, 'tab' => 'forms']) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server?->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs">{{ $s->companion_installed ? 'yes' : 'no' }}</td>
                            <td class="px-5 py-2 text-xs font-data text-[var(--color-ink-soft)]">{{ $s->companion_version ?: '—' }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $s->companion_last_seen_at?->diffForHumans() ?? 'never' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- WP PLUGINS OUTDATED --}}
    @if ($pluginsOutdated->isNotEmpty())
        <section id="section-plugins_outdated" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-cube text-[var(--color-ink-muted)] mr-2"></i>
                        WordPress plugins out of date
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Pulled from each site's Companion snapshot (refreshed nightly at 03:00). Counts reflect the cached <code class="bg-[var(--color-surface-alt)] px-1.5 py-0.5 rounded">update_plugins</code> transient on the site.</p>
                </div>
                <span class="status-pill status-yellow">{{ $pluginsOutdated->count() }}</span>
            </div>
            <div class="max-h-[32rem] overflow-y-auto">
                <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'security', defaultDir: 'desc' })">
                    <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                        <tr>
                            <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                            <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                            <x-sort-th key="careplan" class="px-5 py-2 text-center" title="Sort to group care-plan sites at the top — they're the ones we update routinely. Non-care-plan sites only get touched for security-driven updates.">Care plan</x-sort-th>
                            <x-sort-th key="security" class="px-5 py-2 text-center" title="Number of installed plugins matching a known CVE in the wpvulnerability.net mirror. Default sort to surface security-driven updates first regardless of care-plan status.">Security</x-sort-th>
                            <x-sort-th key="updates" class="px-5 py-2 text-right">Updates</x-sort-th>
                            <x-sort-th key="active" class="px-5 py-2 text-right">Active</x-sort-th>
                            <x-sort-th key="total" class="px-5 py-2 text-right">Total</x-sort-th>
                            <x-sort-th key="snapshot" class="px-5 py-2">Snapshot</x-sort-th>
                            <th class="px-5 py-2 text-center text-xs uppercase tracking-wide" title="Email a vulnerability report to a chosen recipient. Only enabled when CVEs are present.">Email</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($pluginsOutdated as $s)
                            @php
                                $counts = $s->companion_snapshot['plugins']['counts'] ?? [];
                                $updates = (int) ($counts['updates_available'] ?? 0);
                                $active = (int) ($counts['active'] ?? 0);
                                $total = (int) ($counts['total'] ?? 0);
                                $onCarePlan = (bool) $s->care_plan_enabled;
                                $vulns = $vulnsBySiteId[$s->id] ?? [];
                                $vulnCount = count($vulns);
                                $serverShortName = $s->server?->display_name ?? '';
                            @endphp
                            <tr
                                x-data="{
                                    vulnModalOpen: false,
                                    emailModalOpen: false,
                                    emailRecipient: '{{ Auth::user()?->email }}',
                                    emailSending: false,
                                    emailResult: null,
                                    refreshing: false,
                                    refreshError: null,
                                    snapshotAge: '{{ $s->companion_snapshot_at?->diffForHumans() ?? 'never' }}',
                                    updatesCount: {{ $updates }},
                                    async doRefresh() {
                                        if (this.refreshing) return;
                                        this.refreshing = true;
                                        this.refreshError = null;
                                        try {
                                            const res = await fetch('{{ route('sites.companion.refresh-snapshot', $s) }}', {
                                                method: 'POST',
                                                headers: {
                                                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                                    'Accept': 'application/json',
                                                },
                                            });
                                            const data = await res.json().catch(() => ({}));
                                            if (!res.ok || !data.ok) {
                                                this.refreshError = data.error || ('HTTP ' + res.status);
                                            } else {
                                                this.updatesCount = data.updates;
                                                this.snapshotAge = 'just now';
                                            }
                                        } catch (e) {
                                            this.refreshError = e.message;
                                        } finally {
                                            this.refreshing = false;
                                        }
                                    },
                                    async sendVulnEmail() {
                                        if (this.emailSending) return;
                                        this.emailSending = true;
                                        this.emailResult = null;
                                        try {
                                            const res = await fetch('{{ route('sites.email-vuln-report', $s) }}', {
                                                method: 'POST',
                                                headers: {
                                                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                                    'Content-Type': 'application/json',
                                                    'Accept': 'application/json',
                                                },
                                                body: JSON.stringify({ recipient: this.emailRecipient }),
                                            });
                                            const data = await res.json().catch(() => ({}));
                                            if (!res.ok || !data.ok) {
                                                this.emailResult = { ok: false, message: data.error || ('HTTP ' + res.status) };
                                            } else {
                                                this.emailResult = {
                                                    ok: true,
                                                    message: 'Sent to ' + data.recipient + ' (' + data.count + ' CVE' + (data.count === 1 ? '' : 's') + ')'
                                                        + (data.mailer === 'log' ? ' — driver=log, check storage/logs/laravel.log' : '')
                                                };
                                            }
                                        } catch (e) {
                                            this.emailResult = { ok: false, message: 'Request failed: ' + e.message };
                                        } finally {
                                            this.emailSending = false;
                                        }
                                    }
                                }"
                                data-sort-site="{{ $s->domain }}"
                                data-sort-server="{{ $serverShortName }}"
                                data-sort-careplan="{{ $onCarePlan ? 1 : 0 }}"
                                data-sort-security="{{ $vulnCount }}"
                                data-sort-updates="{{ $updates }}"
                                data-sort-active="{{ $active }}"
                                data-sort-total="{{ $total }}"
                                data-sort-snapshot="{{ $s->companion_snapshot_at?->getTimestamp() ?? '' }}">
                                <td class="px-5 py-2 font-data">
                                    <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                                </td>
                                <td class="px-5 py-2 text-xs font-data">
                                    <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $s->server?->name }}">{{ $serverShortName }}</a>
                                </td>
                                <td class="px-5 py-2 text-center text-xs">
                                    @if ($onCarePlan)
                                        <i class="fa-solid fa-shield-halved text-[var(--color-status-green)]" title="On care plan — updates and routine maintenance are included."></i>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]" title="Not on a care plan — only update for security reasons.">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-center text-xs">
                                    @if ($vulnCount > 0)
                                        <button type="button"
                                                class="status-pill status-red cursor-pointer hover:opacity-80"
                                                title="Click for CVE details"
                                                @click="vulnModalOpen = true">
                                            <i class="fa-solid fa-triangle-exclamation"></i> {{ $vulnCount }} CVE{{ $vulnCount === 1 ? '' : 's' }}
                                        </button>

                                        {{-- Modal: CVE details for this site. Click-outside / Esc to close. --}}
                                        <div x-show="vulnModalOpen"
                                             x-cloak
                                             @keydown.escape.window="vulnModalOpen = false"
                                             class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 bg-black/40"
                                             @click.self="vulnModalOpen = false"
                                             role="dialog"
                                             aria-modal="true">
                                            <div class="bg-[var(--color-surface)] rounded-[var(--radius-card)] shadow-2xl max-w-3xl w-full max-h-[85vh] overflow-y-auto border border-[var(--color-border)]"
                                                 @click.stop>
                                                <div class="px-6 py-4 border-b border-[var(--color-border-light)] flex items-start justify-between gap-4 sticky top-0 bg-[var(--color-surface)] z-10">
                                                    <div class="min-w-0">
                                                        <h3 class="font-display text-lg text-[var(--color-ink-strong)]">
                                                            <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-red)] mr-1"></i>
                                                            {{ $s->domain }}
                                                        </h3>
                                                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">{{ $vulnCount }} known CVE{{ $vulnCount === 1 ? '' : 's' }} matching installed plugin version{{ $vulnCount === 1 ? '' : 's' }} · sorted by severity</p>
                                                    </div>
                                                    <button type="button" @click="vulnModalOpen = false" class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] text-xl leading-none -mt-1" aria-label="Close">×</button>
                                                </div>
                                                <div class="px-6 py-4 space-y-4 text-left">
                                                    @foreach ($vulns as $v)
                                                        @php
                                                            $vuln = $v['vulnerability'];
                                                            $sev = $vuln->cvss_severity ?: ($vuln->cvss_score ? null : null);
                                                            $sevClass = match (strtolower((string) $sev)) {
                                                                'critical' => 'status-red',
                                                                'high' => 'status-red',
                                                                'medium' => 'status-yellow',
                                                                'low' => 'status-unknown',
                                                                default => 'status-unknown',
                                                            };
                                                        @endphp
                                                        <div class="border-l-2 {{ $v['patch_available'] ? 'border-[var(--color-status-yellow)]' : 'border-[var(--color-status-red)]' }} pl-3">
                                                            <div class="flex items-start justify-between gap-2 flex-wrap">
                                                                <div class="font-medium text-sm text-[var(--color-ink-strong)]">
                                                                    {{ $v['plugin_name'] }}
                                                                    <span class="text-xs text-[var(--color-ink-muted)] font-data">({{ $v['plugin_slug'] }})</span>
                                                                </div>
                                                                @if ($vuln->cve)
                                                                    <a href="https://www.cve.org/CVERecord?id={{ urlencode($vuln->cve) }}"
                                                                       target="_blank" rel="noopener"
                                                                       class="text-xs font-data text-[var(--color-primary-600)] hover:underline">
                                                                        {{ $vuln->cve }} <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                                                    </a>
                                                                @endif
                                                            </div>
                                                            <div class="text-xs text-[var(--color-ink-muted)] mt-1">{{ $vuln->title }}</div>
                                                            <div class="flex items-center gap-3 text-xs mt-2 flex-wrap">
                                                                <span class="font-data">
                                                                    <span class="text-[var(--color-ink-soft)]">installed:</span>
                                                                    <span class="text-[var(--color-status-red)] font-medium">{{ $v['current_version'] }}</span>
                                                                </span>
                                                                @if ($vuln->patched_in)
                                                                    <span class="font-data">
                                                                        <span class="text-[var(--color-ink-soft)]">→ patched in:</span>
                                                                        <span class="text-[var(--color-status-green)] font-medium">{{ $vuln->patched_in }}</span>
                                                                    </span>
                                                                @else
                                                                    <span class="status-pill status-red text-[10px]" title="No fixed version published yet — only mitigation is removing the plugin.">no patch yet</span>
                                                                @endif
                                                                @if (! $v['active'])
                                                                    <span class="status-pill status-unknown text-[10px]" title="Plugin is installed but not activated on this site.">inactive</span>
                                                                @endif
                                                                @if ($vuln->cvss_score)
                                                                    <span class="status-pill {{ $sevClass }} text-[10px]">CVSS {{ number_format($vuln->cvss_score, 1) }}{{ $sev ? ' · '.ucfirst($sev) : '' }}</span>
                                                                @endif
                                                                @if ($vuln->url)
                                                                    <a href="{{ $vuln->url }}" target="_blank" rel="noopener" class="text-[var(--color-primary-600)] hover:underline text-xs">
                                                                        Source <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                                                    </a>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]" title="No installed plugin versions match a known CVE.">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-right font-data text-xs"><span class="status-pill status-yellow" x-text="updatesCount"></span></td>
                                <td class="px-5 py-2 text-right text-xs font-data text-[var(--color-ink-soft)]">{{ $active }}</td>
                                <td class="px-5 py-2 text-right text-xs font-data text-[var(--color-ink-soft)]">{{ $total }}</td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]" x-text="snapshotAge"></td>
                                <td class="px-5 py-2 text-center text-xs">
                                    @if ($vulnCount > 0)
                                        <button type="button"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-full text-[var(--color-primary-600)] hover:bg-[var(--color-surface-alt)] cursor-pointer transition"
                                                title="Email this vulnerability list to a recipient"
                                                @click="emailModalOpen = true; emailResult = null">
                                            <i class="fa-regular fa-envelope"></i>
                                        </button>

                                        {{-- Modal: send vulnerability report. Click-outside / Esc to close. --}}
                                        <div x-show="emailModalOpen"
                                             x-cloak
                                             @keydown.escape.window="emailModalOpen = false"
                                             class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 bg-black/40"
                                             @click.self="emailModalOpen = false"
                                             role="dialog"
                                             aria-modal="true">
                                            <div class="bg-[var(--color-surface)] rounded-[var(--radius-card)] shadow-2xl max-w-lg w-full border border-[var(--color-border)]"
                                                 @click.stop>
                                                <div class="px-6 py-4 border-b border-[var(--color-border-light)] flex items-start justify-between gap-4">
                                                    <div class="min-w-0">
                                                        <h3 class="font-display text-lg text-[var(--color-ink-strong)]">
                                                            <i class="fa-regular fa-envelope text-[var(--color-primary-600)] mr-1"></i>
                                                            Email vulnerability report
                                                        </h3>
                                                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">{{ $s->domain }} · {{ $vulnCount }} CVE{{ $vulnCount === 1 ? '' : 's' }} will be included</p>
                                                    </div>
                                                    <button type="button" @click="emailModalOpen = false" class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] text-xl leading-none -mt-1" aria-label="Close">×</button>
                                                </div>
                                                <div class="px-6 py-5 text-left space-y-3">
                                                    <label class="block text-sm font-medium text-[var(--color-ink-strong)]">
                                                        Send to
                                                        <input type="email"
                                                               x-model="emailRecipient"
                                                               required
                                                               placeholder="recipient@example.com"
                                                               class="mt-1 block w-full px-3 py-2 border border-[var(--color-border)] rounded-md font-data text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)]"
                                                               @keydown.enter="sendVulnEmail()">
                                                    </label>
                                                    <p class="text-xs text-[var(--color-ink-soft)]">
                                                        @if (config('mail.default') === 'log')
                                                            <strong class="text-[var(--color-status-yellow)]">Note:</strong> mail driver is <code class="bg-[var(--color-surface-alt)] px-1 rounded">log</code> — the rendered email will be written to <code class="bg-[var(--color-surface-alt)] px-1 rounded">storage/logs/laravel.log</code> instead of being delivered. Switch <code class="bg-[var(--color-surface-alt)] px-1 rounded">MAIL_MAILER=mailgun</code> in <code class="bg-[var(--color-surface-alt)] px-1 rounded">.env</code> and add Mailgun credentials to send for real.
                                                        @else
                                                            Sent immediately via the <code class="bg-[var(--color-surface-alt)] px-1 rounded">{{ config('mail.default') }}</code> driver. The action is logged in audit history.
                                                        @endif
                                                    </p>
                                                    <template x-if="emailResult">
                                                        <div x-show="emailResult"
                                                             :class="emailResult.ok ? 'bg-[var(--color-status-green-bg)] text-[var(--color-status-green)] border-[var(--color-status-green)]' : 'bg-[var(--color-status-red-bg)] text-[var(--color-status-red)] border-[var(--color-status-red)]'"
                                                             class="text-xs px-3 py-2 rounded border">
                                                            <i class="fa-solid" :class="emailResult.ok ? 'fa-circle-check' : 'fa-circle-exclamation'"></i>
                                                            <span x-text="emailResult.message"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                                <div class="px-6 py-3 border-t border-[var(--color-border-light)] flex items-center justify-end gap-2 bg-[var(--color-border-light)]/40">
                                                    <button type="button"
                                                            @click="emailModalOpen = false"
                                                            class="px-3 py-1.5 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                                                        Close
                                                    </button>
                                                    <button type="button"
                                                            @click="sendVulnEmail()"
                                                            :disabled="emailSending || !emailRecipient"
                                                            class="btn-primary text-sm disabled:opacity-50 disabled:cursor-not-allowed">
                                                        <i class="fa-regular fa-paper-plane mr-1"></i>
                                                        <span x-text="emailSending ? 'Sending…' : 'Send report'"></span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]" title="No CVEs to report.">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <button type="button"
                                            @click="doRefresh()"
                                            :disabled="refreshing"
                                            :title="refreshError ? 'Error: ' + refreshError : 'Refresh Companion snapshot for this site'"
                                            class="inline-flex items-center justify-center w-7 h-7 rounded text-[var(--color-ink-soft)] hover:text-[var(--color-primary-600)] hover:bg-[var(--color-surface-alt)] transition disabled:opacity-40">
                                        <i class="fa-solid text-xs" :class="refreshing ? 'fa-spinner fa-spin' : (refreshError ? 'fa-triangle-exclamation text-[var(--color-status-amber)]' : 'fa-rotate-right')"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- 2FA AT RISK (Wordfence Login Security migration) --}}
    @if ($twoFactorAtRisk->isNotEmpty())
        <section id="section-two_factor" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-shield-halved text-[var(--color-status-yellow)] mr-2"></i>
                        Two-factor at risk
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Admins/editors whose 2FA still lives in Wordfence Login Security (being discontinued). If WFLS is inactive their login gate is already OFF. Migrate via wp-admin → Clockwork → Login Security on each site.</p>
                </div>
                <span class="status-pill status-yellow">{{ $twoFactorAtRisk->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="wfls" class="px-5 py-2">WFLS status</x-sort-th>
                        <x-sort-th key="migrate" align="right" class="px-5 py-2">Needs migration</x-sort-th>
                        <x-sort-th key="enrolled" align="right" class="px-5 py-2">On Clockwork 2FA</x-sort-th>
                        <x-sort-th key="none" align="right" class="px-5 py-2">No 2FA</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($twoFactorAtRisk as $s)
                        @php
                            $tf = $s->companion_snapshot['two_factor'];
                            $wflsActive = (bool) ($tf['wfls_active'] ?? false);
                            $gateDisabled = ! empty($tf['gate_disabled']);
                            $readyToRemove = ! empty($tf['wfls_ready_to_remove']);
                            $unmigrated = (int) ($tf['wfls_unmigrated_total'] ?? $tf['counts']['wfls_only'] ?? 0);
                        @endphp
                        <tr
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name }}"
                            data-sort-wfls="{{ $gateDisabled ? 0 : ($readyToRemove ? 3 : ($wflsActive ? 2 : 1)) }}"
                            data-sort-migrate="{{ $unmigrated }}"
                            data-sort-enrolled="{{ (int) ($tf['counts']['enrolled'] ?? 0) }}"
                            data-sort-none="{{ (int) ($tf['counts']['unprotected'] ?? 0) }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server?->name }}</a>
                            </td>
                            <td class="px-5 py-2">
                                @if ($gateDisabled)
                                    <span class="status-pill status-red text-[10px]" title="CLOCKWORK_2FA_DISABLE is set — the Companion 2FA gate is bypassed on this site.">gate disabled</span>
                                @elseif ($readyToRemove)
                                    <span class="status-pill status-green text-[10px]" title="Every WFLS 2FA setup has migrated to Companion — remove the plugin via wp-admin → Clockwork → Login Security.">migrated — remove WFLS</span>
                                @elseif ($wflsActive)
                                    <span class="status-pill status-yellow text-[10px]">active — migrate before removal</span>
                                @else
                                    <span class="status-pill status-red text-[10px]" title="WFLS data exists but the plugin is inactive — these users have no working login gate.">removed — gate is OFF</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-right font-data text-xs">
                                @if ($unmigrated > 0)
                                    <span class="status-pill status-yellow">{{ $unmigrated }}</span>
                                @else
                                    <span class="text-[var(--color-status-green)]">0</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-right font-data text-xs text-[var(--color-status-green)]">{{ (int) ($tf['counts']['enrolled'] ?? 0) }}</td>
                            <td class="px-5 py-2 text-right font-data text-xs text-[var(--color-ink-soft)]">{{ (int) ($tf['counts']['unprotected'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- ORPHAN SITES --}}
    @if ($orphanSites->isNotEmpty())
        <section id="section-orphans" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-link-slash text-[var(--color-ink-muted)] mr-2"></i>
                        Orphaned sites
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Local Site rows whose SpinupWP linkage is gone. Either consolidated under another site (safe to archive) or unknown (needs review). Refresh with <code class="bg-[var(--color-surface-alt)] px-1.5 py-0.5 rounded">php artisan clockwork:find-orphan-sites --archive-consolidated</code>.</p>
                </div>
                <span class="status-pill status-yellow">{{ $orphanSites->count() }}</span>
            </div>
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-5 py-2">Domain</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Was on server</x-sort-th>
                        <x-sort-th key="status" class="px-5 py-2">Status</x-sort-th>
                        <x-sort-th key="parent" class="px-5 py-2">Consolidated under</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($orphanSites as $s)
                        @php
                            $parent = $s->consolidated_into_site_id ? ($orphanParents[$s->consolidated_into_site_id] ?? null) : null;
                            $statusLabel = $parent ? 'Consolidated' : 'Unknown';
                        @endphp
                        <tr
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name ?? '' }}"
                            data-sort-status="{{ $statusLabel }}"
                            data-sort-parent="{{ $parent?->domain ?? '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                @if ($s->server)
                                    <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $s->server->name }}">{{ $s->server->display_name }}</a>
                                @else
                                    <span class="text-[var(--color-ink-soft)]">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-2">
                                @if ($parent)
                                    <span class="status-pill status-yellow text-[10px]">Consolidated</span>
                                @else
                                    <span class="status-pill status-red text-[10px]">Unknown</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs">
                                @if ($parent)
                                    <a href="{{ route('sites.show', $parent) }}" class="text-[var(--color-primary-600)] hover:underline font-data">{{ $parent->domain }}</a>
                                @else
                                    <span class="text-[var(--color-ink-soft)]">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-right">
                                <form method="POST" action="{{ route('issues.orphans.destroy', $s->id) }}"
                                    onsubmit="return confirm('Remove {{ $s->domain }} from monitoring? This archives the site row and removes it from all listings.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded text-xs font-medium bg-red-50 text-red-700 hover:bg-red-100 transition-colors">
                                        <i class="fa-solid fa-trash"></i> Remove
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- DB CREDS --}}
    @if ($missingDbCreds->isNotEmpty())
        <section id="section-no_db" class="card overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-database text-[var(--color-ink-muted)] mr-2"></i>
                        WordPress DB credentials missing
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Wordfence + LLAR ingest needs these. Fetched over SSH from wp-config.php.</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="status-pill status-yellow">{{ $missingDbCreds->count() }}</span>
                    <form method="POST" action="{{ route('issues.fetch-all-db-creds') }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] hover:bg-gray-200 transition-colors">
                            <i class="fa-solid fa-rotate"></i> Fetch all
                        </button>
                    </form>
                </div>
            </div>
            <div class="max-h-96 overflow-y-auto">
                <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                    <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                        <tr>
                            <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                            <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($missingDbCreds as $s)
                            <tr
                                data-sort-site="{{ $s->domain }}"
                                data-sort-server="{{ $s->server?->name ?? '' }}">
                                <td class="px-5 py-2 font-data">
                                    <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                                </td>
                                <td class="px-5 py-2 text-xs font-data">
                                    <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server?->name }}</a>
                                </td>
                                <td class="px-5 py-2 text-right">
                                    <form method="POST" action="{{ route('sites.fetch-db-creds', $s) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded text-xs font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] hover:bg-gray-200 transition-colors">
                                            <i class="fa-solid fa-rotate"></i> Fetch
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
