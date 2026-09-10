@extends('layouts.app')

@section('title', 'Servers · Clockwork Control')

@php
    use App\Models\Server;

    $statusMeta = [
        Server::STATUS_GREEN => ['label' => 'Healthy', 'class' => 'status-green', 'card' => ''],
        Server::STATUS_YELLOW => ['label' => 'Watch', 'class' => 'status-yellow', 'card' => 'card-status-yellow'],
        Server::STATUS_RED => ['label' => 'Alert', 'class' => 'status-red', 'card' => 'card-status-red'],
        Server::STATUS_UNKNOWN => ['label' => 'Unknown', 'class' => 'status-unknown', 'card' => 'card-status-unknown'],
    ];

    // Pressure tier for CPU/MEM/DSK readouts & meters:
    //   < 70%  → normal (brand primary / default ink)
    //   70-79% → yellow (watch)
    //   80-89% → orange (elevated pressure)
    //   >= 90% → red (alert)
    $pressureColor = function (?float $pct): ?string {
        if ($pct === null) return null;
        if ($pct >= 90) return 'var(--color-status-red)';
        if ($pct >= 80) return 'var(--color-status-orange)';
        if ($pct >= 70) return 'var(--color-status-yellow)';
        return null;
    };

    $sparkline = function (array $samples, int $width = 80, int $height = 18): string {
        if ($samples === []) return '';
        $count = count($samples);
        if ($count === 1) {
            $samples[] = $samples[0];
            $count = 2;
        }
        $max = 100;
        $stepX = $width / max(1, $count - 1);
        $points = [];
        foreach ($samples as $i => $v) {
            $x = number_format($i * $stepX, 2, '.', '');
            $y = number_format($height - (($v / $max) * $height), 2, '.', '');
            $points[] = "{$x},{$y}";
        }
        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '" preserveAspectRatio="none" class="text-[var(--color-primary-light)] shrink-0">'
            . '<polyline fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $points) . '"/>'
            . '</svg>';
    };

    // Calculate Fleet-wide stats
    $healthyCount = $statusCounts[Server::STATUS_GREEN] ?? 0;
    $watchCount = $statusCounts[Server::STATUS_YELLOW] ?? 0;
    $alertCount = $statusCounts[Server::STATUS_RED] ?? 0;
    $totalCount = $servers->count();
    $healthPct = $totalCount > 0 ? round(($healthyCount / $totalCount) * 100, 1) : 100;
    $missingCreds = $servers->whereNull('ssh_password')->count();
@endphp

@section('content')
<div x-data="{
    viewMode: localStorage.getItem('cw_view_mode') || 'cards',
    inspectOpen: false,
    inspectServer: null,
    setView(v) {
        this.viewMode = v;
        localStorage.setItem('cw_view_mode', v);
    },
    openInspect(server) {
        this.inspectServer = server;
        this.inspectOpen = true;
    }
}">

    <!-- ================================================================= -->
    <!-- TOP PAGE HEADER & GLOBAL FLEET ACTIONS                            -->
    <!-- ================================================================= -->
    <x-page-header title="Servers" subtitle="Live infrastructure fleet monitor across provisioned clouds and host nodes.">
        <x-slot:actions>
            @if (app(\Modules\Core\ModuleStateResolver::class)->isEnabled('spinupwp'))
                <form method="POST" action="{{ route('servers.refreshFromSpinupWp') }}" class="inline">
                    @csrf
                    <button type="submit" class="btn-pill-nav text-xs"
                            title="Re-pull servers + sites from SpinupWP API"
                            onclick="this.disabled=true; this.querySelector('i').classList.add('fa-spin'); this.querySelector('span').textContent = 'Refreshing…';">
                        <i class="fa-solid fa-rotate"></i> <span>Refresh from SpinupWP</span>
                    </button>
                </form>
            @endif
            @if (app(\Modules\Core\ModuleStateResolver::class)->isEnabled('gridpane'))
                <form method="POST" action="{{ route('servers.refreshFromGridPane') }}" class="inline ml-1 sm:ml-2">
                    @csrf
                    <button type="submit" class="btn-pill-nav text-xs"
                            title="Re-pull servers + sites from GridPane API"
                            onclick="this.disabled=true; this.querySelector('i').classList.add('fa-spin'); this.querySelector('span').textContent = 'Refreshing…';">
                        <i class="fa-solid fa-rotate"></i> <span>Refresh from GridPane</span>
                    </button>
                </form>
            @endif
            <a href="{{ route('servers.create') }}" class="btn-primary text-xs ml-1 sm:ml-2">
                <i class="fa-solid fa-plus"></i> <span>Add Server</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <!-- ================================================================= -->
    <!-- TELEMETRY & FLEET KPI METRICS                                     -->
    <!-- ================================================================= -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Metric 1: Fleet Health Score -->
        <div class="cw-kpi-card flex flex-col justify-between">
            <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] font-medium mb-1">
                <span class="font-mono uppercase tracking-wider text-[11px] font-semibold">Fleet Health</span>
                <i class="fa-solid fa-heart-pulse text-xs text-emerald-500"></i>
            </div>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-3xl font-display font-bold text-[var(--color-ink-strong)] font-data">{{ $healthPct }}%</span>
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">Optimal</span>
            </div>
            <div class="flex items-center gap-1.5 mt-2 text-xs font-data">
                <span class="inline-flex items-center gap-1 text-[var(--color-status-green)] font-semibold">
                    <span class="w-1.5 h-1.5 rounded-full bg-[var(--color-status-green)]"></span>
                    {{ $healthyCount }} Healthy
                </span>
                @if ($watchCount > 0)
                    <span class="text-[var(--color-ink-soft)]">·</span>
                    <span class="inline-flex items-center gap-1 text-[var(--color-status-yellow)] font-semibold">
                        <span class="w-1.5 h-1.5 rounded-full bg-[var(--color-status-yellow)]"></span>
                        {{ $watchCount }} Watch
                    </span>
                @endif
                @if ($alertCount > 0)
                    <span class="text-[var(--color-ink-soft)]">·</span>
                    <span class="inline-flex items-center gap-1 text-[var(--color-status-red)] font-semibold">
                        <span class="w-1.5 h-1.5 rounded-full bg-[var(--color-status-red)]"></span>
                        {{ $alertCount }} Alert
                    </span>
                @endif
            </div>
        </div>

        <!-- Metric 2: Active Hosted Sites -->
        <div class="cw-kpi-card flex flex-col justify-between">
            <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] font-medium mb-1">
                <span class="font-mono uppercase tracking-wider text-[11px] font-semibold">Managed Sites</span>
                <i class="fa-solid fa-globe text-sky-500 text-xs"></i>
            </div>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-3xl font-display font-bold text-[var(--color-ink-strong)] font-data">{{ $totalSites }}</span>
                <span class="text-xs text-[var(--color-ink-soft)]">Active Domains</span>
            </div>
            <div class="flex items-center justify-between text-xs text-[var(--color-ink-muted)] mt-2">
                <a href="{{ route('sites.index') }}" class="text-[var(--color-brand)] font-medium hover:underline flex items-center gap-1">
                    <span>Sites Directory</span>
                    <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </a>
                <span class="font-data text-[11px] text-[var(--color-ink-soft)]">{{ $totalCount > 0 ? round($totalSites / $totalCount, 1) : 0 }} / node</span>
            </div>
        </div>

        <!-- Metric 3: Total Monitored Nodes -->
        <div class="cw-kpi-card flex flex-col justify-between">
            <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] font-medium mb-1">
                <span class="font-mono uppercase tracking-wider text-[11px] font-semibold">Total Nodes</span>
                <i class="fa-solid fa-server text-[var(--color-brand)] text-sm"></i>
            </div>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-3xl font-display font-bold text-[var(--color-ink-strong)] font-data">{{ $totalCount }}</span>
                <span class="text-xs text-[var(--color-ink-soft)]">Monitored Hosts</span>
            </div>
            <div class="flex items-center gap-2 mt-1 text-xs text-[var(--color-ink-muted)] font-data">
                <span><i class="fa-solid fa-microchip text-[var(--color-brand)] text-xs mr-1"></i>SSH Polled</span>
            </div>
        </div>

        <!-- Metric 4: Operations & Sync -->
        <div class="cw-kpi-card flex flex-col justify-between">
            <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] font-medium mb-1">
                <span class="font-mono uppercase tracking-wider text-[11px] font-semibold">Operations &amp; Sync</span>
                <i class="fa-solid fa-rotate text-xs text-[var(--color-ink-muted)]"></i>
            </div>
            <div class="flex items-center gap-2 mt-2 flex-wrap">
                @if (app(\Modules\Core\ModuleStateResolver::class)->isEnabled('spinupwp'))
                    <form method="POST" action="{{ route('servers.refreshFromSpinupWp') }}" class="inline flex-1">
                        @csrf
                        <button type="submit" class="w-full text-center px-2.5 py-1.5 rounded-lg border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)] text-xs font-medium text-[var(--color-ink-strong)] transition-all cursor-pointer"
                                title="Re-pull from SpinupWP API"
                                onclick="this.disabled=true; this.querySelector('i').classList.add('fa-spin'); this.querySelector('span').textContent = 'Refreshing…';">
                            <i class="fa-solid fa-rotate text-[10px] mr-1"></i> <span>SpinupWP</span>
                        </button>
                    </form>
                @endif
                @if (app(\Modules\Core\ModuleStateResolver::class)->isEnabled('gridpane'))
                    <form method="POST" action="{{ route('servers.refreshFromGridPane') }}" class="inline flex-1">
                        @csrf
                        <button type="submit" class="w-full text-center px-2.5 py-1.5 rounded-lg border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)] text-xs font-medium text-[var(--color-ink-strong)] transition-all cursor-pointer"
                                title="Re-pull from GridPane API"
                                onclick="this.disabled=true; this.querySelector('i').classList.add('fa-spin'); this.querySelector('span').textContent = 'Refreshing…';">
                            <i class="fa-solid fa-rotate text-[10px] mr-1"></i> <span>GridPane</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <!-- Status Flashes -->
    @if (session('status'))
        <div class="card p-3 mb-6 status-green flex items-center gap-2 text-xs font-medium">
            <i class="fa-solid fa-circle-check"></i>
            {{ session('status') }}
        </div>
    @endif
    @if (session('status_error'))
        <div class="card p-3 mb-6 status-red flex items-center gap-2 text-xs font-medium">
            <i class="fa-solid fa-triangle-exclamation"></i>
            {{ session('status_error') }}
        </div>
    @endif

    <!-- Missing Credentials Warning Banner -->
    @if ($missingCreds > 0)
        <div class="card p-4 mb-6 border-l-4 border-[var(--color-status-yellow)] flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 text-xs sm:text-sm">
                <i class="fa-solid fa-key text-[var(--color-status-yellow)] text-base"></i>
                <div>
                    <span class="font-semibold text-[var(--color-ink-strong)]">{{ $missingCreds }} {{ Str::plural('server', $missingCreds) }}</span>
                    <span class="text-[var(--color-ink-muted)]"> missing SSH credentials. Telemetry and IP banning are offline for these.</span>
                </div>
            </div>
            <a href="{{ route('servers.credentials.bulk') }}" class="btn-pill-nav text-xs font-semibold">
                Set Credentials
            </a>
        </div>
    @endif

    <!-- ================================================================= -->
    <!-- FILTER BAR & VIEW TOGGLE                                          -->
    <!-- ================================================================= -->
    <div class="flex items-center justify-between flex-wrap gap-4 mb-6">
        <!-- Live Client Search -->
        <div class="relative flex-1 max-w-md min-w-[240px]" id="fleet-search-wrap">
            <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] text-xs"></i>
            <input
                type="search"
                id="fleet-search"
                placeholder="Search servers or sites… (Press / to focus)"
                autocomplete="off"
                class="w-full pl-9 pr-8 py-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] focus:border-[var(--color-brand)] focus:outline-none text-xs text-[var(--color-ink-strong)] shadow-2xs font-sans"
            >
            <button type="button" id="fleet-search-clear" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] w-5 h-5 rounded-full flex items-center justify-center text-xs cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <div id="fleet-search-sites" class="hidden absolute left-0 right-0 top-full mt-1 card p-3 z-30 shadow-xl max-h-60 overflow-y-auto">
                <div class="text-[10px] uppercase font-mono tracking-wide text-[var(--color-ink-soft)] mb-2 px-1">Matching sites</div>
                <div id="fleet-search-sites-list" class="flex flex-col text-xs"></div>
            </div>
            <div id="fleet-search-empty" class="hidden absolute left-0 right-0 top-full mt-1 card p-3 z-30 shadow-xl text-xs text-[var(--color-ink-soft)] text-center">
                Nothing matches.
            </div>
        </div>

        <!-- Tag Filters -->
        @if ($tags->isNotEmpty())
            <div class="flex items-center gap-1.5 flex-wrap text-xs">
                @foreach ($tags as $tag)
                    @php $isActive = $activeTag && $activeTag->id === $tag->id; @endphp
                    <a href="{{ route('dashboard', $isActive ? [] : ['tag' => $tag->slug]) }}"
                       class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border transition-colors {{ $isActive ? 'border-transparent text-white font-semibold' : 'border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}"
                       style="{{ $isActive ? 'background: ' . $tag->color : '' }}">
                        <span class="w-1.5 h-1.5 rounded-full" style="background: {{ $tag->color }}"></span>
                        {{ $tag->name }}
                        <span class="opacity-60 text-[10px]">({{ $tag->servers_count }})</span>
                    </a>
                @endforeach
            </div>
        @endif

        <!-- View Switcher (Cards vs Data Grid Table) -->
        <div class="inline-flex items-center p-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-alt)]/60 text-xs ml-auto">
            <button type="button"
                    @click="setView('cards')"
                    class="px-2.5 py-1 rounded-md flex items-center gap-1.5 transition-all text-xs font-medium cursor-pointer"
                    :class="viewMode === 'cards' ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-2xs font-semibold' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'">
                <i class="fa-solid fa-table-cells-large text-xs"></i>
                <span class="hidden sm:inline">Cards</span>
            </button>
            <button type="button"
                    @click="setView('table')"
                    class="px-2.5 py-1 rounded-md flex items-center gap-1.5 transition-all text-xs font-medium cursor-pointer"
                    :class="viewMode === 'table' ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-2xs font-semibold' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'">
                <i class="fa-solid fa-list text-xs"></i>
                <span class="hidden sm:inline">Data Grid</span>
            </button>
        </div>
    </div>

    <!-- Empty Fleet Notice -->
    @if ($servers->isEmpty())
        <div class="card p-12 text-center">
            <i class="fa-solid fa-server text-4xl text-[var(--color-ink-soft)] mb-3"></i>
            <h3 class="text-base font-semibold text-[var(--color-ink-strong)]">No servers registered</h3>
            <p class="text-xs text-[var(--color-ink-muted)] mt-1">Start by adding a server or syncing from your hosting control panel.</p>
        </div>
    @else
        <!-- ============================================================= -->
        <!-- VIEW 1: CARDS GRID (Polymorphic: Modern SaaS or Dense HUD)    -->
        <!-- ============================================================= -->
        <div x-show="viewMode === 'cards'" x-cloak class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($servers as $server)
                @php
                    $meta = $statusMeta[$server->status] ?? $statusMeta[Server::STATUS_UNKNOWN];
                    $spark = $sparklines[$server->id] ?? null;
                    $latest = $spark['latest'] ?? null;
                    $cpuVal = $latest?->cpu_pct !== null ? (float) $latest->cpu_pct : null;
                    $memVal = $latest?->memory_pct !== null ? (float) $latest->memory_pct : null;
                    $dskVal = $latest?->disk_pct !== null ? (float) $latest->disk_pct : null;
                    $sparkCpu = $spark['cpu'] ?? [];
                    $telemetryStale = $latest?->recorded_at?->lt(now()->subDay()) ?? false;

                    $cpuColor = $pressureColor($cpuVal);
                    $memColor = $pressureColor($memVal);
                    $dskColor = $pressureColor($dskVal);

                    $sshOk = (bool) ($server->last_ssh_ok_at ?? $server->clockwork_jail_provisioned_at);
                    $jailOk = (bool) $server->clockwork_jail_provisioned_at;
                    $sshTitle = $server->last_ssh_ok_at
                        ? 'SSH verified ' . $server->last_ssh_ok_at->diffForHumans()
                        : ($server->clockwork_jail_provisioned_at
                            ? 'SSH verified at provisioning ' . $server->clockwork_jail_provisioned_at->diffForHumans()
                            : 'SSH not verified — set credentials and Test');
                    $jailTitle = $jailOk
                        ? 'fail2ban provisioned ' . $server->clockwork_jail_provisioned_at->diffForHumans()
                        : 'fail2ban not provisioned';

                    $cloudProvider = $server->provider ? app(\App\Services\CloudProvider\CloudProviderRegistry::class)->resolve($server->provider) : null;
                    $providerName = match(true) {
                        str_starts_with($server->provider ?? '', 'digitalocean') => 'DigitalOcean',
                        str_starts_with($server->provider ?? '', 'hetzner') => 'Hetzner',
                        str_starts_with($server->provider ?? '', 'vultr') => 'Vultr',
                        str_starts_with($server->provider ?? '', 'azure') => 'Azure',
                        str_starts_with($server->provider ?? '', 'linode') => 'Linode',
                        str_starts_with($server->provider ?? '', 'aws') => 'AWS',
                        default => $cloudProvider && $cloudProvider->id() !== 'null' ? $cloudProvider->label() : null,
                    };

                    $maxMetric = max((float) ($cpuVal ?? 0), (float) ($memVal ?? 0), (float) ($dskVal ?? 0));
                    $cardStatusClass = match(true) {
                        $server->status === Server::STATUS_RED || $maxMetric >= 90 => 'card-status-red',
                        $maxMetric >= 80 => 'card-status-orange',
                        $server->status === Server::STATUS_YELLOW || $maxMetric >= 70 => 'card-status-yellow',
                        $server->status === Server::STATUS_UNKNOWN => 'card-status-unknown',
                        default => '',
                    };
                    $cardPill = match(true) {
                        $server->status === Server::STATUS_RED || $maxMetric >= 90 => ['label' => 'Alert', 'class' => 'status-red'],
                        $maxMetric >= 80 => ['label' => 'Watch', 'class' => 'status-orange'],
                        $server->status === Server::STATUS_YELLOW || $maxMetric >= 70 => ['label' => 'Watch', 'class' => 'status-yellow'],
                        $server->status === Server::STATUS_UNKNOWN => ['label' => 'Unknown', 'class' => 'status-unknown'],
                        default => ['label' => 'Healthy', 'class' => 'status-green'],
                    };
                @endphp
                <div class="cw-server-card flex flex-col justify-between server-card relative group {{ $cardStatusClass }}"
                     data-server-id="{{ $server->id }}"
                     data-search="{{ strtolower($server->name . ' ' . $server->hostname) }}">

                    <!-- Card Header -->
                    <div>
                        <div class="flex items-start justify-between gap-3 mb-2.5">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('servers.show', $server) }}" class="font-display font-bold text-base text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] truncate block transition-colors" title="{{ $server->name }}">
                                    {{ $server->display_name }}
                                </a>
                                <p class="text-xs text-[var(--color-ink-soft)] font-mono truncate mt-0.5">{{ $server->hostname }}</p>
                            </div>
                            <span class="status-pill {{ $cardPill['class'] }} text-xs shrink-0">
                                <span class="status-dot"></span>
                                {{ $cardPill['label'] }}
                            </span>
                        </div>

                        <!-- Staging, Patches, SpinupWP/GridPane & Tags -->
                        <div class="flex items-center gap-1.5 flex-wrap mb-3">
                            @if ($server->spinupwp_id)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400" title="SpinupWP server #{{ $server->spinupwp_id }}">
                                    <i class="fa-solid fa-bolt text-[10px] text-[#00C2A8]"></i>
                                    <span>SpinupWP</span>
                                </span>
                            @endif
                            @if ($server->isGridPane())
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400" title="GridPane server{{ $server->provider_id ? ' #' . $server->provider_id : '' }}">
                                    <i class="fa-solid fa-table-cells text-[10px] text-emerald-500"></i>
                                    <span>GridPane</span>
                                </span>
                            @endif

                            {{-- Tags --}}
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

                            @if ($server->upgrade_required)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-yellow)] text-white"
                                      title="Patches available — open Updates tab to run">
                                    <i class="fa-solid fa-cube text-[9px]"></i> Patches
                                </span>
                            @endif
                            @if ($server->reboot_required)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-yellow)] text-white"
                                      title="Reboot required — open Updates tab to schedule">
                                    <i class="fa-solid fa-power-off text-[9px]"></i> Reboot
                                </span>
                            @endif
                        </div>

                        <!-- Connectivity & Health Bar: Sites Count, SSH, and Fail2ban Jail -->
                        <div class="flex items-center justify-between gap-3 text-xs py-2 px-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 border border-[var(--color-border-light)] mb-3">
                            <span class="inline-flex items-center gap-1.5 text-[var(--color-ink-muted)]">
                                <i class="fa-solid fa-globe text-[var(--color-ink-soft)] text-xs"></i>
                                <span class="font-medium">{{ $server->sites_count ?? $server->sites->count() }} {{ Str::plural('site', $server->sites_count ?? $server->sites->count()) }}</span>
                            </span>

                            <div class="flex items-center gap-3">
                                {{-- SSH Status Indicator --}}
                                <span class="inline-flex items-center gap-1 cursor-help"
                                      title="{{ $sshTitle }}"
                                      style="color: {{ $sshOk ? 'var(--color-status-green)' : 'var(--color-ink-soft)' }}">
                                    <i class="fa-solid fa-key text-[10px]"></i>
                                    <span class="font-semibold text-[11px]">SSH</span>
                                </span>

                                {{-- Jail / Fail2ban Status Indicator --}}
                                <span class="inline-flex items-center gap-1 cursor-help"
                                      title="{{ $jailTitle }}"
                                      style="color: {{ $jailOk ? 'var(--color-status-green)' : 'var(--color-ink-soft)' }}">
                                    <i class="fa-solid fa-shield-halved text-[10px]"></i>
                                    <span class="font-semibold text-[11px]">Jail</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Telemetry Meters: Studio (Progress Bars) vs Command Center (Dense HUD) -->
                    @if ($latest)
                        <!-- Modern Studio Meters -->
                        <div class="cw-meter-studio space-y-2.5 pt-3 border-t border-[var(--color-border-light)]">
                            <!-- CPU -->
                            <div>
                                <div class="flex justify-between items-center text-xs mb-1 font-medium">
                                    <span class="text-[var(--color-ink-muted)]">CPU Usage</span>
                                    <div class="flex items-center gap-2">
                                        <span class="font-data font-semibold text-[var(--color-ink-strong)]" @if ($cpuColor) style="color: {{ $cpuColor }}; font-weight: 600;" @endif>
                                            {{ $cpuVal !== null ? number_format($cpuVal, 0) . '%' : '—' }}
                                        </span>
                                        @if (!empty($sparkCpu))
                                            <div title="{{ $telemetryStale ? 'Last sample '.$latest->recorded_at->diffForHumans() : 'CPU last 24h' }}">
                                                {!! $sparkline($sparkCpu, 60, 14) !!}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                <div class="studio-progress-bar">
                                    <div class="studio-progress-fill" style="width: {{ min(100, max(0, $cpuVal ?? 0)) }}%; background-color: {{ $cpuColor ?? 'var(--color-primary-500)' }};"></div>
                                </div>
                            </div>

                            <!-- Memory -->
                            <div>
                                <div class="flex justify-between text-xs mb-1 font-medium">
                                    <span class="text-[var(--color-ink-muted)]">Memory (RAM)</span>
                                    <span class="font-data font-semibold text-[var(--color-ink-strong)]" @if ($memColor) style="color: {{ $memColor }}; font-weight: 600;" @endif>
                                        {{ $memVal !== null ? number_format($memVal, 0) . '%' : '—' }}
                                    </span>
                                </div>
                                <div class="studio-progress-bar">
                                    <div class="studio-progress-fill" style="width: {{ min(100, max(0, $memVal ?? 0)) }}%; background-color: {{ $memColor ?? 'var(--color-primary-500)' }};"></div>
                                </div>
                            </div>

                            <!-- Disk -->
                            <div>
                                <div class="flex justify-between text-xs mb-1 font-medium">
                                    <span class="text-[var(--color-ink-muted)]">Disk Allocation</span>
                                    <span class="font-data font-semibold text-[var(--color-ink-strong)]" @if ($dskColor) style="color: {{ $dskColor }}; font-weight: 600;" @endif>
                                        {{ $dskVal !== null ? number_format($dskVal, 0) . '%' : '—' }}
                                    </span>
                                </div>
                                <div class="studio-progress-bar">
                                    <div class="studio-progress-fill" style="width: {{ min(100, max(0, $dskVal ?? 0)) }}%; background-color: {{ $dskColor ?? 'var(--color-primary-500)' }};"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Command Center Compact Telemetry -->
                        <div class="cw-meter-cmd mt-3 pt-2.5 border-t border-[var(--color-border-light)] items-center justify-between gap-3 text-xs font-mono">
                            <div class="grid grid-cols-3 gap-2 flex-1 min-w-0">
                                <div title="CPU"><span class="text-[var(--color-ink-soft)] text-[10px]">CPU</span> <span class="font-bold" @if ($cpuColor) style="color: {{ $cpuColor }}; font-weight: 600;" @endif>{{ $cpuVal !== null ? number_format($cpuVal, 0) . '%' : '—' }}</span></div>
                                <div title="Memory"><span class="text-[var(--color-ink-soft)] text-[10px]">RAM</span> <span class="font-bold" @if ($memColor) style="color: {{ $memColor }}; font-weight: 600;" @endif>{{ $memVal !== null ? number_format($memVal, 0) . '%' : '—' }}</span></div>
                                <div title="Disk"><span class="text-[var(--color-ink-soft)] text-[10px]">DSK</span> <span class="font-bold" @if ($dskColor) style="color: {{ $dskColor }}; font-weight: 600;" @endif>{{ $dskVal !== null ? number_format($dskVal, 0) . '%' : '—' }}</span></div>
                            </div>
                            @if (!empty($sparkCpu))
                                <div class="shrink-0" title="{{ $telemetryStale ? 'Last sample '.$latest->recorded_at->diffForHumans() : 'CPU last 24h' }}">
                                    {!! $sparkline($sparkCpu, 70, 16) !!}
                                </div>
                            @endif
                        </div>
                        @if ($telemetryStale)
                            <p class="text-[10px] text-[var(--color-ink-soft)] mt-2" title="{{ $latest->recorded_at->toDayDateTimeString() }}">
                                <i class="fa-regular fa-clock text-[9px] mr-0.5"></i>
                                Last sample {{ $latest->recorded_at->diffForHumans() }}
                            </p>
                        @endif
                    @else
                        <div class="py-3.5 my-1 text-center text-xs text-[var(--color-ink-soft)] bg-[var(--color-surface-alt)]/40 rounded-lg border border-dashed border-[var(--color-border-light)]">
                            <i class="fa-solid fa-chart-line opacity-40 mr-1 text-xs"></i> No telemetry recorded yet
                        </div>
                    @endif

                    <!-- Footer: Actions -->
                    <div class="flex items-center justify-between pt-3 mt-3 border-t border-[var(--color-border-light)]/60 text-xs">
                        <span class="text-[11px] font-mono text-[var(--color-ink-soft)]" title="{{ $server->hostname }}{{ $server->provider_id ? ' #' . $server->provider_id : '' }}">
                            {{ $server->provider_label ?: 'Server Node' }}
                        </span>
                        <div class="flex items-center gap-2">
                            <button type="button"
                                    @click="openInspect(@js([
                                        'id' => $server->id,
                                        'name' => $server->display_name,
                                        'hostname' => $server->hostname,
                                        'ip' => $server->ip_address,
                                        'status' => $server->status,
                                        'statusLabel' => $cardPill['label'],
                                        'statusClass' => $cardPill['class'],
                                        'sitesCount' => $server->sites_count ?? $server->sites->count(),
                                        'cpu' => $cpuVal !== null ? number_format($cpuVal, 0) . '%' : '—',
                                        'mem' => $memVal !== null ? number_format($memVal, 0) . '%' : '—',
                                        'dsk' => $dskVal !== null ? number_format($dskVal, 0) . '%' : '—',
                                        'sshOk' => $sshOk,
                                        'sshTitle' => $sshTitle,
                                        'jailOk' => $jailOk,
                                        'jailTitle' => $jailTitle,
                                        'provider' => $server->isGridPane() ? 'GridPane' : ($providerName ?? ($cloudProvider && $cloudProvider->id() !== 'null' ? $cloudProvider->label() : ($server->provider ? ucfirst($server->provider) : 'Manual'))),
                                        'showUrl' => route('servers.show', $server),
                                    ]))"
                                    class="hover:text-[var(--color-brand)] text-[var(--color-ink-muted)] font-medium inline-flex items-center gap-1 cursor-pointer">
                                <i class="fa-solid fa-eye text-[10px]"></i> Inspect
                            </button>
                            <span>·</span>
                            <a href="{{ route('servers.show', $server) }}" class="font-semibold text-[var(--color-brand)] hover:underline inline-flex items-center gap-1">
                                Manage &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- ============================================================= -->
        <!-- VIEW 2: HIGH-DENSITY DATA GRID TABLE                          -->
        <!-- ============================================================= -->
        <div x-show="viewMode === 'table'" x-cloak class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="cw-data-table font-mono">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Server Node</th>
                            <th>Hostname</th>
                            <th>Provider</th>
                            <th>Security</th>
                            <th>CPU History</th>
                            <th>RAM</th>
                            <th>Disk</th>
                            <th>Sites</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $server)
                            @php
                                $meta = $statusMeta[$server->status] ?? $statusMeta[Server::STATUS_UNKNOWN];
                                $spark = $sparklines[$server->id] ?? null;
                                $latest = $spark['latest'] ?? null;
                                $cpuVal = $latest?->cpu_pct !== null ? (float) $latest->cpu_pct : null;
                                $memVal = $latest?->memory_pct !== null ? (float) $latest->memory_pct : null;
                                $dskVal = $latest?->disk_pct !== null ? (float) $latest->disk_pct : null;
                                $sparkCpu = $spark['cpu'] ?? [];
                                $telemetryStale = $latest?->recorded_at?->lt(now()->subDay()) ?? false;

                                $cpuColor = $pressureColor($cpuVal);
                                $memColor = $pressureColor($memVal);
                                $dskColor = $pressureColor($dskVal);

                                $sshOk = (bool) ($server->last_ssh_ok_at ?? $server->clockwork_jail_provisioned_at);
                                $jailOk = (bool) $server->clockwork_jail_provisioned_at;
                                $sshTitle = $server->last_ssh_ok_at
                                    ? 'SSH verified ' . $server->last_ssh_ok_at->diffForHumans()
                                    : ($server->clockwork_jail_provisioned_at
                                        ? 'SSH verified at provisioning ' . $server->clockwork_jail_provisioned_at->diffForHumans()
                                        : 'SSH not verified — set credentials and Test');
                                $jailTitle = $jailOk
                                    ? 'fail2ban provisioned ' . $server->clockwork_jail_provisioned_at->diffForHumans()
                                    : 'fail2ban not provisioned';

                                $cloudProvider = $server->provider ? app(\App\Services\CloudProvider\CloudProviderRegistry::class)->resolve($server->provider) : null;
                                $providerName = match(true) {
                                    str_starts_with($server->provider ?? '', 'digitalocean') => 'DigitalOcean',
                                    str_starts_with($server->provider ?? '', 'hetzner') => 'Hetzner',
                                    str_starts_with($server->provider ?? '', 'vultr') => 'Vultr',
                                    str_starts_with($server->provider ?? '', 'azure') => 'Azure',
                                    str_starts_with($server->provider ?? '', 'linode') => 'Linode',
                                    str_starts_with($server->provider ?? '', 'aws') => 'AWS',
                                    default => $cloudProvider && $cloudProvider->id() !== 'null' ? $cloudProvider->label() : null,
                                };

                                $maxMetric = max((float) ($cpuVal ?? 0), (float) ($memVal ?? 0), (float) ($dskVal ?? 0));
                                $cardPill = match(true) {
                                    $server->status === Server::STATUS_RED || $maxMetric >= 90 => ['label' => 'Alert', 'class' => 'status-red'],
                                    $maxMetric >= 80 => ['label' => 'Watch', 'class' => 'status-orange'],
                                    $server->status === Server::STATUS_YELLOW || $maxMetric >= 70 => ['label' => 'Watch', 'class' => 'status-yellow'],
                                    $server->status === Server::STATUS_UNKNOWN => ['label' => 'Unknown', 'class' => 'status-unknown'],
                                    default => ['label' => 'Healthy', 'class' => 'status-green'],
                                };
                            @endphp
                            <tr class="server-card"
                                data-server-id="{{ $server->id }}"
                                data-search="{{ strtolower($server->name . ' ' . $server->hostname) }}">
                                <td>
                                    <span class="status-pill {{ $cardPill['class'] }} text-[10px]">
                                        <span class="status-dot"></span>
                                        {{ $cardPill['label'] }}
                                    </span>
                                </td>
                                <td class="font-sans font-semibold text-[var(--color-ink-strong)]">
                                    <a href="{{ route('servers.show', $server) }}" class="hover:text-[var(--color-brand)]">
                                        {{ $server->display_name }}
                                    </a>
                                </td>
                                <td class="text-[var(--color-ink-soft)] font-mono text-xs">
                                    {{ $server->hostname }}
                                </td>
                                <td>
                                    <div class="flex items-center gap-1.5 text-xs text-[var(--color-ink-muted)]">
                                        @if ($cloudProvider && $cloudProvider->id() !== 'null')
                                            <i class="{{ $cloudProvider->iconClass() }} text-xs shrink-0" style="color: {{ $cloudProvider->iconColor() }}" title="{{ $cloudProvider->label() }}{{ $server->provider_id ? ' #' . $server->provider_id : '' }}"></i>
                                            <span class="font-medium text-[var(--color-ink-strong)]">{{ $providerName ?? $cloudProvider->label() }}</span>
                                        @elseif ($server->provider)
                                            <i class="fa-solid fa-server text-xs text-[var(--color-ink-soft)] shrink-0"></i>
                                            <span class="font-medium text-[var(--color-ink-strong)]">{{ $server->isGridPane() ? 'GridPane' : ucfirst($server->provider) }}</span>
                                        @else
                                            <span class="text-[var(--color-ink-soft)] italic">Manual</span>
                                        @endif
                                        @if ($server->spinupwp_id)
                                            <i class="fa-solid fa-bolt text-[10px] text-[#00C2A8]" title="SpinupWP #{{ $server->spinupwp_id }}"></i>
                                        @endif
                                        @if ($server->isGridPane())
                                            <i class="fa-solid fa-table-cells text-[10px] text-emerald-500" title="GridPane server{{ $server->provider_id ? ' #' . $server->provider_id : '' }}"></i>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="flex items-center gap-2.5 text-xs">
                                        <span class="inline-flex items-center gap-1" title="{{ $sshTitle }}" style="color: {{ $sshOk ? 'var(--color-status-green)' : 'var(--color-ink-soft)' }}">
                                            <i class="fa-solid fa-key text-[10px]"></i>
                                            <span class="font-semibold text-[10px]">SSH</span>
                                        </span>
                                        <span class="inline-flex items-center gap-1" title="{{ $jailTitle }}" style="color: {{ $jailOk ? 'var(--color-status-green)' : 'var(--color-ink-soft)' }}">
                                            <i class="fa-solid fa-shield-halved text-[10px]"></i>
                                            <span class="font-semibold text-[10px]">Jail</span>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-[var(--color-ink-strong)] w-10" @if ($cpuColor) style="color: {{ $cpuColor }}; font-weight: 600;" @endif>{{ $cpuVal !== null ? number_format($cpuVal, 0) . '%' : '—' }}</span>
                                        @if (!empty($sparkCpu))
                                            <span title="{{ $telemetryStale ? 'Last sample '.$latest->recorded_at->diffForHumans() : 'CPU last 24h' }}">
                                                {!! $sparkline($sparkCpu, 70, 16) !!}
                                            </span>
                                        @endif
                                        @if ($telemetryStale)
                                            <span class="text-[10px] text-[var(--color-ink-soft)] whitespace-nowrap" title="{{ $latest->recorded_at->toDayDateTimeString() }}">Last sample {{ $latest->recorded_at->diffForHumans() }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="font-bold text-[var(--color-ink-strong)]" @if ($memColor) style="color: {{ $memColor }}; font-weight: 600;" @endif>
                                    {{ $memVal !== null ? number_format($memVal, 0) . '%' : '—' }}
                                </td>
                                <td class="font-bold text-[var(--color-ink-strong)]" @if ($dskColor) style="color: {{ $dskColor }}; font-weight: 600;" @endif>
                                    {{ $dskVal !== null ? number_format($dskVal, 0) . '%' : '—' }}
                                </td>
                                <td class="text-[var(--color-ink-muted)]">
                                    {{ $server->sites_count ?? $server->sites->count() }} sites
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <button type="button"
                                            @click="openInspect(@js([
                                                'id' => $server->id,
                                                'name' => $server->display_name,
                                                'hostname' => $server->hostname,
                                                'ip' => $server->ip_address,
                                                'status' => $server->status,
                                                'statusLabel' => $cardPill['label'],
                                                'statusClass' => $cardPill['class'],
                                                'sitesCount' => $server->sites_count ?? $server->sites->count(),
                                                'cpu' => $cpuVal !== null ? number_format($cpuVal, 0) . '%' : '—',
                                                'mem' => $memVal !== null ? number_format($memVal, 0) . '%' : '—',
                                                'dsk' => $dskVal !== null ? number_format($dskVal, 0) . '%' : '—',
                                                'sshOk' => $sshOk,
                                                'sshTitle' => $sshTitle,
                                                'jailOk' => $jailOk,
                                                'jailTitle' => $jailTitle,
                                                'provider' => $server->provider_label ?? $server->provider,
                                                'showUrl' => route('servers.show', $server),
                                            ]))"
                                            class="p-1 hover:text-[var(--color-brand)] text-[var(--color-ink-muted)] cursor-pointer"
                                            title="Quick Telemetry Inspector">
                                        <i class="fa-solid fa-eye text-xs"></i>
                                    </button>
                                    <a href="{{ route('servers.show', $server) }}" class="p-1 hover:text-[var(--color-brand)] text-[var(--color-ink-muted)] ml-2" title="Manage Server">
                                        <i class="fa-solid fa-arrow-right text-xs"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- ================================================================= -->
    <!-- IGNORED SERVERS SECTION                                           -->
    <!-- ================================================================= -->
    @if ($ignoredServers->isNotEmpty())
        <section class="mt-14" id="ignored-servers">
            <div class="flex items-end justify-between mb-4">
                <h2 class="font-display text-lg font-bold text-[var(--color-ink-muted)]">Ignored Servers</h2>
                <span class="text-xs text-[var(--color-ink-soft)] font-mono uppercase tracking-wide">
                    {{ $ignoredServers->count() }} excluded from monitoring stats
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 opacity-75">
                @foreach ($ignoredServers as $server)
                    <a href="{{ route('servers.show', $server) }}"
                       class="card p-4 block border-dashed hover:opacity-100 transition-opacity">
                       <div class="flex items-start gap-2.5 mb-2">
                           <i class="fa-solid fa-eye-slash text-[var(--color-ink-soft)] mt-0.5 text-sm"></i>
                           <div class="min-w-0 flex-1">
                               <div class="font-semibold text-[var(--color-ink-strong)] truncate text-sm" title="{{ $server->name }}">{{ $server->display_name }}</div>
                               <div class="text-xs text-[var(--color-ink-soft)] font-mono truncate">{{ $server->hostname }}</div>
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

    <!-- ================================================================= -->
    <!-- SLIDE-OVER QUICK INSPECT TELEMETRY DRAWER                         -->
    <!-- ================================================================= -->
    <div x-show="inspectOpen"
         x-cloak
         class="fixed inset-0 z-50 overflow-hidden flex justify-end"
         role="dialog"
         aria-modal="true">
        <div class="fixed inset-0 bg-black/40 backdrop-blur-xs transition-opacity" @click="inspectOpen = false"></div>

        <div class="relative w-full max-w-md bg-[var(--color-surface)] border-l border-[var(--color-border)] h-full overflow-y-auto p-6 shadow-2xl flex flex-col justify-between z-10 cmd-drawer">
            <template x-if="inspectServer">
                <div class="space-y-6">
                    <!-- Drawer Header -->
                    <div class="flex items-start justify-between gap-4 border-b border-[var(--color-border-light)] pb-4">
                        <div class="min-w-0">
                            <span class="text-[10px] uppercase font-mono tracking-widest text-[var(--color-ink-soft)]">Telemetry Inspector</span>
                            <h2 class="text-xl font-display font-bold text-[var(--color-ink-strong)] truncate" x-text="inspectServer.name"></h2>
                            <p class="text-xs font-mono text-[var(--color-ink-soft)] mt-0.5" x-text="inspectServer.hostname"></p>
                        </div>
                        <button type="button" @click="inspectOpen = false" class="p-2 rounded-lg text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] cursor-pointer">
                            <i class="fa-solid fa-xmark text-sm"></i>
                        </button>
                    </div>

                    <!-- Node State -->
                    <div class="p-3 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] flex items-center justify-between text-xs">
                        <span class="text-[var(--color-ink-muted)] font-medium">Node State:</span>
                        <span class="status-pill text-[10px]" :class="inspectServer.statusClass">
                            <span class="status-dot"></span>
                            <span x-text="inspectServer.statusLabel"></span>
                        </span>
                    </div>

                    <!-- Security & Connectivity State -->
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div class="p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)]">
                            <div class="text-[10px] uppercase font-mono text-[var(--color-ink-soft)] mb-1">SSH Access</div>
                            <div class="flex items-center gap-1.5 font-semibold text-xs" :class="inspectServer.sshOk ? 'text-[var(--color-status-green)]' : 'text-[var(--color-ink-soft)]'">
                                <i class="fa-solid fa-key text-[10px]"></i>
                                <span x-text="inspectServer.sshOk ? 'Verified' : 'Unverified'"></span>
                            </div>
                            <div class="text-[10px] text-[var(--color-ink-soft)] truncate mt-0.5" x-text="inspectServer.sshTitle"></div>
                        </div>
                        <div class="p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)]">
                            <div class="text-[10px] uppercase font-mono text-[var(--color-ink-soft)] mb-1">Fail2ban Jail</div>
                            <div class="flex items-center gap-1.5 font-semibold text-xs" :class="inspectServer.jailOk ? 'text-[var(--color-status-green)]' : 'text-[var(--color-ink-soft)]'">
                                <i class="fa-solid fa-shield-halved text-[10px]"></i>
                                <span x-text="inspectServer.jailOk ? 'Provisioned' : 'Not Active'"></span>
                            </div>
                            <div class="text-[10px] text-[var(--color-ink-soft)] truncate mt-0.5" x-text="inspectServer.jailTitle"></div>
                        </div>
                    </div>

                    <!-- Metrics Grid -->
                    <div>
                        <div class="text-[11px] font-mono uppercase font-semibold text-[var(--color-ink-soft)] mb-2">Live Resource Allocation</div>
                        <div class="grid grid-cols-3 gap-2">
                            <div class="p-3 rounded-lg border border-[var(--color-border-light)] text-center font-mono">
                                <div class="text-[10px] text-[var(--color-ink-soft)]">CPU</div>
                                <div class="text-lg font-bold text-[var(--color-ink-strong)]" x-text="inspectServer.cpu"></div>
                            </div>
                            <div class="p-3 rounded-lg border border-[var(--color-border-light)] text-center font-mono">
                                <div class="text-[10px] text-[var(--color-ink-soft)]">RAM</div>
                                <div class="text-lg font-bold text-[var(--color-ink-strong)]" x-text="inspectServer.mem"></div>
                            </div>
                            <div class="p-3 rounded-lg border border-[var(--color-border-light)] text-center font-mono">
                                <div class="text-[10px] text-[var(--color-ink-soft)]">DISK</div>
                                <div class="text-lg font-bold text-[var(--color-ink-strong)]" x-text="inspectServer.dsk"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Metadata List -->
                    <div class="space-y-2 text-xs border-t border-[var(--color-border-light)] pt-4">
                        <div class="flex justify-between py-1 border-b border-[var(--color-border-light)]/50">
                            <span class="text-[var(--color-ink-soft)]">Hosting Provider:</span>
                            <span class="font-medium text-[var(--color-ink-strong)] capitalize" x-text="inspectServer.provider"></span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-[var(--color-border-light)]/50">
                            <span class="text-[var(--color-ink-soft)]">Hosted Sites:</span>
                            <span class="font-medium text-[var(--color-ink-strong)] font-mono" x-text="inspectServer.sitesCount + ' sites'"></span>
                        </div>
                        <div class="flex justify-between py-1">
                            <span class="text-[var(--color-ink-soft)]">Transport Protocol:</span>
                            <span class="font-medium text-[var(--color-status-green)] font-mono">SSH v2 / Agent</span>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Drawer Footer Actions -->
            <div class="pt-6 border-t border-[var(--color-border-light)] flex gap-2">
                <a :href="inspectServer ? inspectServer.showUrl : '#'" class="flex-1 text-center py-2.5 rounded-lg bg-[var(--color-brand)] text-white text-xs font-semibold hover:opacity-90 transition-opacity">
                    Open Server Console &rarr;
                </a>
                <button type="button" @click="inspectOpen = false" class="px-4 py-2.5 rounded-lg border border-[var(--color-border)] text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] cursor-pointer">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const sites = @json($siteIndex ?? []);
        const input = document.getElementById('fleet-search');
        const clearBtn = document.getElementById('fleet-search-clear');
        const sitesPanel = document.getElementById('fleet-search-sites');
        const sitesList = document.getElementById('fleet-search-sites-list');
        const emptyMsg = document.getElementById('fleet-search-empty');
        const ignored = document.getElementById('ignored-servers');
        const fleetNodes = Array.from(document.querySelectorAll('[data-server-id]'));

        if (!input) return;

        // Pre-index sites by server ID for fast domain-to-server matching
        const domainsByServerId = {};
        sites.forEach(s => {
            const id = String(s.server_id);
            (domainsByServerId[id] = domainsByServerId[id] || []).push(s.domain.toLowerCase());
        });

        function filterFleet() {
            const query = input.value.trim().toLowerCase();
            const hasQuery = query.length > 0;

            if (clearBtn) clearBtn.classList.toggle('hidden', !hasQuery);

            const visibleIds = new Set();
            fleetNodes.forEach(node => {
                const searchData = node.getAttribute('data-search') || '';
                const inServerText = searchData.includes(query);
                const serverId = node.getAttribute('data-server-id');
                const inSiteDomains = (domainsByServerId[serverId] || []).some(d => d.includes(query));
                const matches = !hasQuery || inServerText || inSiteDomains;

                node.style.display = matches ? '' : 'none';
                if (matches && serverId) visibleIds.add(serverId);
            });
            const visibleServers = visibleIds.size;

            if (ignored) ignored.style.display = hasQuery ? 'none' : '';

            if (!hasQuery) {
                if (sitesPanel) sitesPanel.classList.add('hidden');
                if (emptyMsg) emptyMsg.classList.add('hidden');
                if (sitesList) sitesList.innerHTML = '';
                return;
            }

            // Matching site list popup
            const matchingSites = sites
                .filter(s => s.domain.toLowerCase().includes(query))
                .slice(0, 20);

            if (sitesPanel && sitesList) {
                if (matchingSites.length > 0) {
                    sitesList.innerHTML = matchingSites.map(s => `
                        <a href="${s.url}" class="flex items-center justify-between p-2 rounded-lg hover:bg-[var(--color-surface-alt)] transition-colors group">
                            <span class="font-medium text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)] truncate">
                                <i class="fa-brands fa-wordpress text-xs text-sky-500 mr-1.5"></i>
                                ${escapeHtml(s.domain)}
                            </span>
                            <span class="text-[10px] text-[var(--color-ink-soft)] font-mono shrink-0 ml-2">
                                ${escapeHtml(s.server_name || '')}
                            </span>
                        </a>
                    `).join('');
                    sitesPanel.classList.remove('hidden');
                } else {
                    sitesPanel.classList.add('hidden');
                    sitesList.innerHTML = '';
                }
            }

            if (emptyMsg) {
                const totalMatches = visibleServers + matchingSites.length;
                emptyMsg.classList.toggle('hidden', totalMatches > 0);
            }
        }

        function escapeHtml(str) {
            const p = document.createElement('p');
            p.textContent = str;
            return p.innerHTML;
        }

        input.addEventListener('input', filterFleet);

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                input.value = '';
                filterFleet();
                input.focus();
            });
        }

        // Close sites dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (sitesPanel && !sitesPanel.contains(e.target) && e.target !== input) {
                sitesPanel.classList.add('hidden');
            }
        });

        // Keyboard navigation shortcuts: '/' or 'Cmd+K' / 'Ctrl+K' focuses search, 'Esc' closes/clears
        document.addEventListener('keydown', (e) => {
            if ((e.key === '/' && !['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) ||
                ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k')) {
                e.preventDefault();
                input.focus();
                input.select();
            } else if (e.key === 'Escape' && document.activeElement === input) {
                if (sitesPanel) sitesPanel.classList.add('hidden');
                input.blur();
            }
        });
    });
</script>
@endsection
