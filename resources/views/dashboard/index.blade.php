@extends('layouts.app')

@section('title', 'Servers · Clockwork Control')

@php
    use App\Models\Server;

    $statusMeta = [
        Server::STATUS_GREEN => ['label' => 'Healthy', 'class' => 'status-green', 'icon' => 'fa-circle-check'],
        Server::STATUS_YELLOW => ['label' => 'Watch', 'class' => 'status-yellow', 'icon' => 'fa-triangle-exclamation'],
        Server::STATUS_RED => ['label' => 'Alert', 'class' => 'status-red', 'icon' => 'fa-circle-exclamation'],
        Server::STATUS_UNKNOWN => ['label' => 'Unknown', 'class' => 'status-unknown', 'icon' => 'fa-circle-question'],
    ];

    $pressureColor = function (?float $pct): string {
        if ($pct === null) return 'var(--color-primary-500)';
        if ($pct > 90) return 'var(--color-status-red)';
        if ($pct > 80) return 'var(--color-status-yellow)';
        return 'var(--color-primary-500)';
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
        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '" preserveAspectRatio="none" class="text-[var(--color-primary-500)] shrink-0">'
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
    <!-- FLEET KPI & HUD METRICS ROW                                       -->
    <!-- ================================================================= -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5 mb-8">
        <!-- Metric 1: Fleet Availability -->
        <div class="cw-kpi-card flex flex-col justify-between">
            <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] font-medium mb-1">
                <span class="font-mono uppercase tracking-wider text-[11px] font-semibold">Fleet Availability</span>
                <span class="inline-flex items-center gap-1 text-[var(--color-status-green)] font-semibold font-data">
                    <span class="w-1.5 h-1.5 rounded-full bg-[var(--color-status-green)]"></span>
                    {{ $healthPct }}%
                </span>
            </div>
            <div class="flex items-baseline justify-between gap-2 mt-1">
                <span class="text-3xl font-display font-bold text-[var(--color-ink-strong)] font-data">{{ $healthyCount }} / {{ $totalCount }}</span>
                <span class="text-xs text-[var(--color-ink-muted)]">Nodes Online</span>
            </div>
            <div class="flex items-center gap-2 mt-2 text-[11px] text-[var(--color-ink-muted)]">
                <span class="inline-flex items-center gap-1 font-data"><span class="w-2 h-2 rounded-full bg-[var(--color-status-green)]"></span> {{ $healthyCount }} ok</span>
                <span class="inline-flex items-center gap-1 font-data"><span class="w-2 h-2 rounded-full bg-[var(--color-status-yellow)]"></span> {{ $watchCount }} watch</span>
                @if ($alertCount > 0)
                    <span class="inline-flex items-center gap-1 font-data text-[var(--color-status-red)] font-semibold"><span class="w-2 h-2 rounded-full bg-[var(--color-status-red)] animate-pulse"></span> {{ $alertCount }} alert</span>
                @endif
            </div>
        </div>

        <!-- Metric 2: Managed Workloads -->
        <div class="cw-kpi-card flex flex-col justify-between">
            <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] font-medium mb-1">
                <span class="font-mono uppercase tracking-wider text-[11px] font-semibold">Managed Workloads</span>
                <i class="fa-brands fa-wordpress text-sky-500 text-sm"></i>
            </div>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-3xl font-display font-bold text-[var(--color-ink-strong)] font-data">{{ $totalSites }}</span>
                <span class="text-xs text-[var(--color-ink-soft)]">Total Sites</span>
            </div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1 truncate">
                WordPress instances across fleet
            </div>
        </div>

        <!-- Metric 3: Node Infrastructure -->
        <div class="cw-kpi-card flex flex-col justify-between">
            <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] font-medium mb-1">
                <span class="font-mono uppercase tracking-wider text-[11px] font-semibold">Node Infrastructure</span>
                <i class="fa-solid fa-server text-[var(--color-brand)] text-sm"></i>
            </div>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-3xl font-display font-bold text-[var(--color-ink-strong)] font-data">{{ $totalCount }}</span>
                <span class="text-xs text-[var(--color-ink-soft)]">Monitored Hosts</span>
            </div>
            <div class="flex items-center gap-2 mt-1 text-xs text-[var(--color-ink-muted)] font-data">
                <span><i class="fa-solid fa-microchip text-[var(--color-brand)] text-xs mr-1"></i>SSH Polled</span>
                <span>·</span>
                <span><i class="fa-solid fa-network-wired text-emerald-500 text-xs mr-1"></i>LAN Cluster</span>
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
                            <i class="fa-solid fa-rotate text-[10px] mr-1"></i> <span>Refresh from SpinupWP</span>
                        </button>
                    </form>
                @endif
                @if (app(\Modules\Core\ModuleStateResolver::class)->isEnabled('gridpane'))
                    <form method="POST" action="{{ route('servers.refreshFromGridPane') }}" class="inline flex-1">
                        @csrf
                        <button type="submit" class="w-full text-center px-2.5 py-1.5 rounded-lg border border-[var(--color-border)] hover:bg-[var(--color-surface-alt)] text-xs font-medium text-[var(--color-ink-strong)] transition-all cursor-pointer"
                                title="Re-pull from GridPane API"
                                onclick="this.disabled=true; this.querySelector('i').classList.add('fa-spin'); this.querySelector('span').textContent = 'Refreshing…';">
                            <i class="fa-solid fa-rotate text-[10px] mr-1"></i> <span>Refresh from GridPane</span>
                        </button>
                    </form>
                @endif
                <a href="{{ route('servers.create') }}" class="px-2.5 py-1.5 rounded-lg bg-[var(--color-brand)] text-white text-xs font-medium hover:opacity-90 transition-opacity shrink-0">
                    <i class="fa-solid fa-plus text-[10px]"></i> Add server
                </a>
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
        <div x-show="viewMode === 'cards'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($servers as $server)
                @php
                    $meta = $statusMeta[$server->status] ?? $statusMeta[Server::STATUS_UNKNOWN];
                    $spark = $sparklines[$server->id] ?? null;
                    $latest = $spark['latest'] ?? null;
                    $cpuVal = $latest['cpu_usage'] ?? null;
                    $memVal = $latest['memory_usage'] ?? null;
                    $dskVal = $latest['disk_usage'] ?? null;
                @endphp
                <div class="cw-server-card flex flex-col justify-between server-card relative group"
                     data-server-id="{{ $server->id }}"
                     data-search="{{ strtolower($server->name . ' ' . $server->hostname) }}">

                    <!-- Card Header -->
                    <div>
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('servers.show', $server) }}" class="font-display font-bold text-base text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] truncate block transition-colors">
                                    {{ $server->display_name }}
                                </a>
                                <p class="text-xs text-[var(--color-ink-soft)] font-mono truncate mt-0.5">{{ $server->hostname }}</p>
                            </div>
                            <span class="status-pill {{ $meta['class'] }} text-xs shrink-0">
                                <span class="status-dot"></span>
                                {{ $meta['label'] }}
                            </span>
                        </div>

                        <!-- Provider & Tags -->
                        <div class="flex items-center gap-1.5 flex-wrap mb-4">
                            <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold uppercase tracking-wider bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] border border-[var(--color-border-light)]">
                                {{ $server->hosting_provider ?? 'Server Node' }}
                            </span>
                            @foreach ($server->tags as $tag)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]">
                                    <span class="w-1.5 h-1.5 rounded-full" style="background: {{ $tag->color }}"></span>
                                    {{ $tag->name }}
                                </span>
                            @endforeach
                            @if ($server->upgrade_required)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-[var(--color-status-yellow)] text-white">
                                    Updates
                                </span>
                            @endif
                            @if ($server->reboot_required)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-[var(--color-status-yellow)] text-white">
                                    Reboot
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- Telemetry Meters (Progress Bars & Inline Sparkline) -->
                    <div class="space-y-3 pt-4 border-t border-[var(--color-border-light)]">
                        <!-- CPU -->
                        <div>
                            <div class="flex justify-between items-center text-xs mb-1 font-medium">
                                <span class="text-[var(--color-ink-muted)]">CPU Usage</span>
                                <div class="flex items-center gap-2">
                                    <span class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $cpuVal !== null ? round($cpuVal) . '%' : '—' }}</span>
                                    @if ($spark && !empty($spark['samples']))
                                        {!! $sparkline($spark['samples'], 60, 14) !!}
                                    @endif
                                </div>
                            </div>
                            <div class="studio-progress-bar">
                                <div class="studio-progress-fill" style="width: {{ min(100, max(0, $cpuVal ?? 0)) }}%; background-color: {{ $pressureColor($cpuVal) }};"></div>
                            </div>
                        </div>

                        <!-- Memory -->
                        <div>
                            <div class="flex justify-between text-xs mb-1 font-medium">
                                <span class="text-[var(--color-ink-muted)]">Memory (RAM)</span>
                                <span class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $memVal !== null ? round($memVal) . '%' : '—' }}</span>
                            </div>
                            <div class="studio-progress-bar">
                                <div class="studio-progress-fill" style="width: {{ min(100, max(0, $memVal ?? 0)) }}%; background-color: {{ $pressureColor($memVal) }};"></div>
                            </div>
                        </div>

                        <!-- Disk -->
                        <div>
                            <div class="flex justify-between text-xs mb-1 font-medium">
                                <span class="text-[var(--color-ink-muted)]">Disk Allocation</span>
                                <span class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $dskVal !== null ? round($dskVal) . '%' : '—' }}</span>
                            </div>
                            <div class="studio-progress-bar">
                                <div class="studio-progress-fill" style="width: {{ min(100, max(0, $dskVal ?? 0)) }}%; background-color: {{ $pressureColor($dskVal) }};"></div>
                            </div>
                        </div>

                        <!-- Footer: Sites Count + Quick Inspect + Manage -->
                        <div class="flex items-center justify-between pt-3 border-t border-[var(--color-border-light)]/60 text-xs">
                            <span class="text-[var(--color-ink-soft)] font-medium">
                                <i class="fa-solid fa-globe text-xs mr-1 text-[var(--color-brand)]"></i>
                                {{ $server->sites_count ?? $server->sites->count() }} sites
                            </span>
                            <div class="flex items-center gap-2">
                                <button type="button"
                                        @click="openInspect({{ json_encode([
                                            'id' => $server->id,
                                            'name' => $server->display_name,
                                            'hostname' => $server->hostname,
                                            'ip' => $server->ip_address,
                                            'status' => $server->status,
                                            'statusLabel' => $meta['label'],
                                            'statusClass' => $meta['class'],
                                            'sitesCount' => $server->sites_count ?? $server->sites->count(),
                                            'cpu' => $cpuVal !== null ? round($cpuVal) . '%' : '—',
                                            'mem' => $memVal !== null ? round($memVal) . '%' : '—',
                                            'dsk' => $dskVal !== null ? round($dskVal) . '%' : '—',
                                            'provider' => $server->hosting_provider ?? 'manual',
                                            'showUrl' => route('servers.show', $server),
                                        ]) }})"
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
                </div>
            @endforeach
        </div>

        <!-- ============================================================= -->
        <!-- VIEW 2: HIGH-DENSITY DATA GRID TABLE                          -->
        <!-- ============================================================= -->
        <div x-show="viewMode === 'table'" class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="cw-data-table font-mono">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Server Node</th>
                            <th>IP / Hostname</th>
                            <th>Provider</th>
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
                                $cpuVal = $latest['cpu_usage'] ?? null;
                                $memVal = $latest['memory_usage'] ?? null;
                                $dskVal = $latest['disk_usage'] ?? null;
                            @endphp
                            <tr class="server-card"
                                data-server-id="{{ $server->id }}"
                                data-search="{{ strtolower($server->name . ' ' . $server->hostname) }}">
                                <td>
                                    <span class="status-pill {{ $meta['class'] }} text-[10px]">
                                        <span class="status-dot"></span>
                                        {{ $meta['label'] }}
                                    </span>
                                </td>
                                <td class="font-sans font-semibold text-[var(--color-ink-strong)]">
                                    <a href="{{ route('servers.show', $server) }}" class="hover:text-[var(--color-brand)]">
                                        {{ $server->display_name }}
                                    </a>
                                </td>
                                <td class="text-[var(--color-ink-soft)] font-mono">
                                    {{ $server->hostname }}
                                </td>
                                <td class="capitalize text-[var(--color-ink-muted)]">
                                    {{ $server->hosting_provider ?? 'manual' }}
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-[var(--color-ink-strong)] w-8">{{ $cpuVal !== null ? round($cpuVal) . '%' : '—' }}</span>
                                        @if ($spark && !empty($spark['samples']))
                                            {!! $sparkline($spark['samples'], 70, 16) !!}
                                        @endif
                                    </div>
                                </td>
                                <td class="font-bold text-[var(--color-ink-strong)]">
                                    {{ $memVal !== null ? round($memVal) . '%' : '—' }}
                                </td>
                                <td class="font-bold text-[var(--color-ink-strong)]">
                                    {{ $dskVal !== null ? round($dskVal) . '%' : '—' }}
                                </td>
                                <td class="text-[var(--color-ink-muted)]">
                                    {{ $server->sites_count ?? $server->sites->count() }} sites
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <button type="button"
                                            @click="openInspect({{ json_encode([
                                                'id' => $server->id,
                                                'name' => $server->display_name,
                                                'hostname' => $server->hostname,
                                                'ip' => $server->ip_address,
                                                'status' => $server->status,
                                                'statusLabel' => $meta['label'],
                                                'statusClass' => $meta['class'],
                                                'sitesCount' => $server->sites_count ?? $server->sites->count(),
                                                'cpu' => $cpuVal !== null ? round($cpuVal) . '%' : '—',
                                                'mem' => $memVal !== null ? round($memVal) . '%' : '—',
                                                'dsk' => $dskVal !== null ? round($dskVal) . '%' : '—',
                                                'provider' => $server->hosting_provider ?? 'manual',
                                                'showUrl' => route('servers.show', $server),
                                            ]) }})"
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
                        <span class="text-[var(--color-ink-muted)]">Node State:</span>
                        <span class="status-pill text-[10px]" :class="inspectServer.statusClass">
                            <span class="status-dot"></span>
                            <span x-text="inspectServer.statusLabel"></span>
                        </span>
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
        const cards = Array.from(document.querySelectorAll('.server-card'));

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

            let visibleServers = 0;
            cards.forEach(card => {
                const searchData = card.getAttribute('data-search') || '';
                const inServerText = searchData.includes(query);
                const serverId = card.getAttribute('data-server-id');
                const inSiteDomains = (domainsByServerId[serverId] || []).some(d => d.includes(query));
                const matches = !hasQuery || inServerText || inSiteDomains;

                card.style.display = matches ? '' : 'none';
                if (matches) visibleServers++;
            });

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
