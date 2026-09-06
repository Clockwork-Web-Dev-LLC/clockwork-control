@extends('layouts.app')

@section('title', 'Servers · Clockwork')

@php
    use App\Models\Server;

    $statusMeta = [
        Server::STATUS_GREEN => ['label' => 'Healthy', 'class' => 'status-green', 'card' => '', 'icon' => 'fa-circle-check'],
        Server::STATUS_YELLOW => ['label' => 'Watch', 'class' => 'status-yellow', 'card' => 'card-status-yellow', 'icon' => 'fa-triangle-exclamation'],
        Server::STATUS_RED => ['label' => 'Alert', 'class' => 'status-red', 'card' => 'card-status-red', 'icon' => 'fa-circle-exclamation'],
        Server::STATUS_UNKNOWN => ['label' => 'Unknown', 'class' => 'status-unknown', 'card' => 'card-status-unknown', 'icon' => 'fa-circle-question'],
    ];

    // Pressure tier for CPU/MEM/DSK readouts. Operator intuition:
    //   <=80%  → no callout (read as gray-muted)
    //   81-90% → yellow (watch)
    //   >90%   → red (alert)
    $pressureColor = function (?float $pct): ?string {
        if ($pct === null) {
            return null;
        }
        if ($pct > 90) {
            return 'var(--color-status-red)';
        }
        if ($pct > 80) {
            return 'var(--color-status-yellow)';
        }
        return null;
    };

    $sparkline = function (array $samples, int $width = 100, int $height = 24): string {
        if ($samples === []) {
            return '';
        }
        $count = count($samples);
        if ($count === 1) {
            $samples[] = $samples[0];
            $count = 2;
        }
        $max = 100; // CPU is 0-100
        $stepX = $width / max(1, $count - 1);
        $points = [];
        foreach ($samples as $i => $v) {
            $x = number_format($i * $stepX, 2, '.', '');
            $y = number_format($height - (($v / $max) * $height), 2, '.', '');
            $points[] = "{$x},{$y}";
        }
        // Background rect anchors 0% (bottom) → 100% (top) so a flat-low line vs a flat-high
        // line look obviously different. Mid-line at 50% gives a quick "is this server north
        // or south of half-utilised?" read.
        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '" preserveAspectRatio="none" class="text-[var(--color-primary-500)] rounded overflow-hidden">'
            . '<rect x="0.5" y="0.5" width="' . ($width - 1) . '" height="' . ($height - 1) . '" rx="3" fill="var(--color-surface-alt)" stroke="var(--color-border-light)" stroke-width="1"/>'
            . '<line x1="0" y1="' . ($height / 2) . '" x2="' . $width . '" y2="' . ($height / 2) . '" stroke="var(--color-border)" stroke-width="0.5" stroke-dasharray="2,2"/>'
            . '<polyline fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $points) . '"/>'
            . '</svg>';
    };
@endphp

@section('content')
    <x-page-header title="Server fleet"
        :subtitle="$servers->count() . ' servers · ' . $totalSites . ' sites'">
        <x-slot:actions>
            @foreach ($statusMeta as $status => $meta)
                <span class="status-pill {{ $meta['class'] }}">
                    <span class="status-dot"></span>
                    {{ $meta['label'] }} {{ $statusCounts[$status] ?? 0 }}
                </span>
            @endforeach
            <form method="POST" action="{{ route('servers.refreshFromSpinupWp') }}" class="ml-2 inline">
                @csrf
                <button type="submit" class="btn-pill-nav"
                        title="Re-pull servers + sites from SpinupWP API. Reflects new servers and site moves immediately."
                        onclick="this.disabled=true; this.querySelector('i').classList.add('fa-spin'); this.querySelector('span').textContent = 'Refreshing…';">
                    <i class="fa-solid fa-rotate"></i> <span>Refresh from SpinupWP</span>
                </button>
            </form>
            <a href="{{ route('servers.create') }}" class="btn-pill-nav ml-2">
                <i class="fa-solid fa-plus"></i> Add server
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            {{ session('status') }}
        </div>
    @endif
    @if (session('status_error'))
        <div class="card p-4 mb-6 status-red flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i>
            {{ session('status_error') }}
        </div>
    @endif

    <div class="mb-8 relative" id="fleet-search-wrap">
        <div class="relative">
            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)]"></i>
            <input
                type="search"
                id="fleet-search"
                placeholder="Search servers (name, IP) or sites (domain)…"
                autocomplete="off"
                class="w-full pl-11 pr-10 py-3 rounded-full border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] focus:bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-brand)] text-base text-[var(--color-ink-strong)]"
            >
            <button type="button" id="fleet-search-clear" class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] w-6 h-6 rounded-full">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div id="fleet-search-sites" class="hidden mt-3 card p-3">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-2 px-2">Matching sites</div>
            <div id="fleet-search-sites-list" class="flex flex-col"></div>
        </div>

        <div id="fleet-search-empty" class="hidden mt-3 text-sm text-[var(--color-ink-soft)] text-center py-4">
            Nothing matches. Try a different name or domain.
        </div>
    </div>

    {{-- Tag filter strip — shows tags that have at least one server. Click to filter; the active tag
         renders solid in its own color and links back to "all". Hidden when no tags exist. --}}
    @if ($tags->isNotEmpty())
        <div class="flex items-center gap-2 flex-wrap mb-6">
            <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mr-1">Filter:</span>
            <a href="{{ route('dashboard') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium transition-colors border
                      {{ ! $activeTag ? 'bg-[var(--color-nav-active-bg)] text-[var(--color-nav-active-ink)] border-[var(--color-nav-active-border)]' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] border-transparent hover:bg-[var(--color-border-light)]' }}">
                All
                <span class="opacity-70">{{ $servers->count() + $ignoredServers->count() }}</span>
            </a>
            @foreach ($tags as $tag)
                @php $isActive = $activeTag && $activeTag->id === $tag->id; @endphp
                <a href="{{ route('dashboard', ['tag' => $tag->slug]) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border transition-colors"
                   style="border-color: {{ $tag->color }}; background: {{ $isActive ? $tag->color : 'transparent' }}; color: {{ $isActive ? 'white' : $tag->color }};"
                   title="{{ $tag->description }}">
                    <span class="inline-flex w-2 h-2 rounded-full" style="background: {{ $isActive ? 'white' : $tag->color }}"></span>
                    {{ $tag->name }}
                    <span class="opacity-70">{{ $tag->servers_count }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @php
        $missingCreds = $servers->whereNull('ssh_password')->count();
    @endphp
    @if ($missingCreds > 0)
        <div class="card p-4 mb-6 flex items-center justify-between gap-4 status-yellow">
            <div class="flex items-center gap-3">
                <i class="fa-solid fa-key"></i>
                <div>
                    <div class="font-medium">{{ $missingCreds }} {{ Str::plural('server', $missingCreds) }} {{ $missingCreds === 1 ? 'is' : 'are' }} missing SSH credentials.</div>
                    <div class="text-xs">Without credentials, log pulling and IP banning won't work on these.</div>
                </div>
            </div>
            <a href="{{ route('servers.credentials.bulk') }}" class="btn-primary">
                <i class="fa-solid fa-key"></i> Manage credentials
            </a>
        </div>
    @endif

    @if ($servers->isEmpty())
        <div class="card p-10 text-center">
            <i class="fa-solid fa-server text-4xl text-[var(--color-ink-soft)] mb-3"></i>
            <p class="text-[var(--color-ink-muted)]">No servers imported yet.</p>
            <p class="text-sm text-[var(--color-ink-soft)] mt-2">
                Run <code class="bg-[var(--color-surface-alt)] px-2 py-0.5 rounded">php artisan clockwork:import-spinupwp</code>.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
            @foreach ($servers as $server)
                @php
                    $meta = $statusMeta[$server->status] ?? $statusMeta[Server::STATUS_UNKNOWN];
                @endphp

                @php
                    $spark = $sparklines[$server->id] ?? null;
                    $latest = $spark['latest'] ?? null;
                @endphp
                <a href="{{ route('servers.show', $server) }}"
                   class="card card-link p-5 block server-card {{ $meta['card'] }}"
                   data-server-id="{{ $server->id }}"
                   data-search="{{ strtolower($server->name . ' ' . $server->hostname) }}">
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div class="min-w-0">
                            <div class="font-display font-semibold text-[var(--color-ink-strong)] truncate" title="{{ $server->name }}">
                                {{ $server->display_name }}
                            </div>
                            <div class="text-xs text-[var(--color-ink-soft)] font-data mt-0.5">
                                {{ $server->hostname }}
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-1.5 shrink-0">
                            <span class="status-pill {{ $meta['class'] }}">
                                <span class="status-dot"></span>
                                {{ $meta['label'] }}
                            </span>
                            {{-- Tags rendered gray on the grid (low visual weight) — the colors live on
                                 the filter strip + the server detail page where they need to pop. The
                                 colored dot is preserved so the user can still cross-reference quickly. --}}
                            @foreach ($server->tags as $tag)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]"
                                      title="{{ $tag->description ?: $tag->name }}">
                                    <span class="inline-flex w-1.5 h-1.5 rounded-full" style="background: {{ $tag->color }}"></span>
                                    {{ $tag->name }}
                                </span>
                            @endforeach
                            @if ($server->isStaging())
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] italic"
                                      title="This server is tagged as staging — its sites are excluded from uptime probes, scans, and alerts.">
                                    <i class="fa-solid fa-eye-slash text-[9px]"></i>
                                    Not monitored
                                </span>
                            @endif
                            {{-- Patch / reboot — small orange dot pills, sit alongside the tag pills. --}}
                            @if ($server->upgrade_required)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium"
                                      style="background: var(--color-status-yellow); color: white;"
                                      title="Patches available — open Updates tab to run">
                                    <i class="fa-solid fa-cube text-[9px]"></i> Patches
                                </span>
                            @endif
                            @if ($server->reboot_required)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium"
                                      style="background: var(--color-status-yellow); color: white;"
                                      title="Reboot required — open Updates tab to schedule">
                                    <i class="fa-solid fa-power-off text-[9px]"></i> Reboot
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-4 text-sm text-[var(--color-ink-muted)]">
                        <span class="inline-flex items-center gap-1.5">
                            <i class="fa-solid fa-globe text-[var(--color-ink-soft)]"></i>
                            {{ $server->sites_count }}
                        </span>

                        @php
                            $sshOk = (bool) ($server->last_ssh_ok_at ?? $server->clockwork_jail_provisioned_at);
                            $jailOk = (bool) $server->clockwork_jail_provisioned_at;
                            $sshTitle = $server->last_ssh_ok_at
                                ? 'SSH verified ' . $server->last_ssh_ok_at->diffForHumans()
                                : ($server->clockwork_jail_provisioned_at
                                    ? 'SSH verified at provisioning ' . $server->clockwork_jail_provisioned_at->diffForHumans()
                                    : 'SSH not verified — set credentials and Test');
                        @endphp
                        <span class="inline-flex items-center gap-1.5"
                              title="{{ $sshTitle }}"
                              style="color: {{ $sshOk ? 'var(--color-status-green)' : 'var(--color-ink-soft)' }}">
                            <i class="fa-solid fa-key"></i>
                            SSH
                        </span>
                        <span class="inline-flex items-center gap-1.5"
                              title="{{ $jailOk ? 'fail2ban provisioned ' . $server->clockwork_jail_provisioned_at->diffForHumans() : 'fail2ban not provisioned' }}"
                              style="color: {{ $jailOk ? 'var(--color-status-green)' : 'var(--color-ink-soft)' }}">
                            <i class="fa-solid fa-shield-halved"></i>
                            Jail
                        </span>

                        @if ($server->provider_id)
                            @php $cloudProvider = app(\App\Services\CloudProvider\CloudProviderRegistry::class)->resolve($server->provider); @endphp
                            <span class="inline-flex items-center" title="{{ $cloudProvider->label() }} #{{ $server->provider_id }}">
                                <i class="{{ $cloudProvider->iconClass() }} text-base" style="color: {{ $cloudProvider->iconColor() }}"></i>
                            </span>
                        @endif

                        @if ($server->spinupwp_id)
                            <span class="inline-flex items-center" title="SpinupWP server #{{ $server->spinupwp_id }}">
                                <i class="fa-solid fa-bolt text-base" style="color: #00C2A8"></i>
                            </span>
                        @endif

                    </div>

                    @if ($latest)
                        <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center gap-3">
                            @php
                                $cpuColor = $pressureColor($latest->cpu_pct === null ? null : (float) $latest->cpu_pct);
                                $memColor = $pressureColor($latest->memory_pct === null ? null : (float) $latest->memory_pct);
                                $dskColor = $pressureColor($latest->disk_pct === null ? null : (float) $latest->disk_pct);
                            @endphp
                            <div class="flex-1 min-w-0 grid grid-cols-3 gap-2 text-[11px] text-[var(--color-ink-muted)]">
                                <div title="CPU"><span class="text-[var(--color-ink-soft)]">CPU</span> <span @if ($cpuColor) style="color: {{ $cpuColor }}; font-weight: 600;" @endif>{{ $latest->cpu_pct !== null ? number_format($latest->cpu_pct, 0) . '%' : '—' }}</span></div>
                                <div title="Memory"><span class="text-[var(--color-ink-soft)]">MEM</span> <span @if ($memColor) style="color: {{ $memColor }}; font-weight: 600;" @endif>{{ $latest->memory_pct !== null ? number_format($latest->memory_pct, 0) . '%' : '—' }}</span></div>
                                <div title="Disk"><span class="text-[var(--color-ink-soft)]">DSK</span> <span @if ($dskColor) style="color: {{ $dskColor }}; font-weight: 600;" @endif>{{ $latest->disk_pct !== null ? number_format($latest->disk_pct, 0) . '%' : '—' }}</span></div>
                            </div>
                            <div class="shrink-0" title="CPU last 24h">
                                {!! $sparkline($spark['cpu'] ?? []) !!}
                            </div>
                        </div>
                    @endif
                </a>
            @endforeach
        </div>
    @endif

    @if ($ignoredServers->isNotEmpty())
        <section class="mt-16" id="ignored-servers">
            <div class="flex items-end justify-between mb-4">
                <h2 class="font-display text-xl text-[var(--color-ink-muted)]">Ignored servers</h2>
                <span class="text-xs text-[var(--color-ink-soft)] uppercase tracking-wide">
                    {{ $ignoredServers->count() }} excluded from monitoring stats
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 opacity-60">
                @foreach ($ignoredServers as $server)
                    <a href="{{ route('servers.show', $server) }}"
                       class="card card-link p-4 block border-dashed">
                        <div class="flex items-start gap-2 mb-2">
                            <i class="fa-solid fa-eye-slash text-[var(--color-ink-soft)] mt-0.5"></i>
                            <div class="min-w-0">
                                <div class="font-medium text-[var(--color-ink-strong)] truncate text-sm" title="{{ $server->name }}">{{ $server->display_name }}</div>
                                <div class="text-xs text-[var(--color-ink-soft)] font-data truncate">{{ $server->hostname }}</div>
                            </div>
                        </div>
                        <div class="text-xs text-[var(--color-ink-soft)]">
                            {{ $server->sites_count }} {{ Str::plural('site', $server->sites_count) }}
                            @if ($server->ignore_reason)
                                · {{ $server->ignore_reason }}
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <script>
        (function () {
            const sites = @json($siteIndex);
            const input = document.getElementById('fleet-search');
            const clearBtn = document.getElementById('fleet-search-clear');
            const sitesPanel = document.getElementById('fleet-search-sites');
            const sitesList = document.getElementById('fleet-search-sites-list');
            const emptyMsg = document.getElementById('fleet-search-empty');
            const ignored = document.getElementById('ignored-servers');
            const cards = Array.from(document.querySelectorAll('.server-card'));

            const serverUrlById = {};
            cards.forEach(c => { serverUrlById[c.dataset.serverId] = c.getAttribute('href'); });

            // Pre-index sites by their server id so we can match a server card via its domains.
            const domainsByServerId = {};
            sites.forEach(s => {
                const id = String(s.server_id);
                (domainsByServerId[id] = domainsByServerId[id] || []).push(s.domain.toLowerCase());
            });

            function applyFilter(rawQ) {
                const q = (rawQ || '').trim().toLowerCase();
                const isQuery = q.length > 0;
                clearBtn.classList.toggle('hidden', !isQuery);

                let visibleServers = 0;
                cards.forEach(card => {
                    const inServerText = card.dataset.search.includes(q);
                    const inSiteDomains = (domainsByServerId[card.dataset.serverId] || [])
                        .some(d => d.includes(q));
                    const matched = !isQuery || inServerText || inSiteDomains;
                    card.style.display = matched ? '' : 'none';
                    if (matched) visibleServers++;
                });

                if (ignored) ignored.style.display = isQuery ? 'none' : '';

                if (!isQuery) {
                    sitesPanel.classList.add('hidden');
                    emptyMsg.classList.add('hidden');
                    sitesList.innerHTML = '';
                    return;
                }

                const matchingSites = sites
                    .filter(s => s.domain.toLowerCase().includes(q))
                    .slice(0, 25);

                if (matchingSites.length === 0) {
                    sitesPanel.classList.add('hidden');
                    sitesList.innerHTML = '';
                    emptyMsg.classList.toggle('hidden', visibleServers > 0);
                    return;
                }

                sitesList.innerHTML = matchingSites.map(s => {
                    return `<a href="${escapeHtml(s.url)}" class="px-2 py-1.5 hover:bg-[var(--color-surface-alt)] rounded flex items-center justify-between gap-3 text-sm">
                        <span class="font-data text-[var(--color-ink-strong)] truncate">${escapeHtml(s.domain)}</span>
                        <span class="text-xs text-[var(--color-ink-soft)] shrink-0">on ${escapeHtml(s.server_name || '?')}</span>
                    </a>`;
                }).join('');
                sitesPanel.classList.remove('hidden');
                emptyMsg.classList.add('hidden');
            }

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, c => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
                })[c]);
            }

            input.addEventListener('input', e => applyFilter(e.target.value));
            clearBtn.addEventListener('click', () => {
                input.value = '';
                applyFilter('');
                input.focus();
            });
            input.addEventListener('keydown', e => {
                if (e.key === 'Escape') {
                    input.value = '';
                    applyFilter('');
                } else if (e.key === 'Enter') {
                    const firstSite = sitesList.querySelector('a');
                    const firstCard = cards.find(c => c.style.display !== 'none');
                    if (firstSite) firstSite.click();
                    else if (firstCard) firstCard.click();
                }
            });

            // "/" focuses search from anywhere on the page.
            document.addEventListener('keydown', e => {
                if (e.key === '/' && document.activeElement !== input
                        && !['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName)) {
                    e.preventDefault();
                    input.focus();
                }
            });
        })();
    </script>
@endsection
