@extends('layouts.app')

@section('title', 'Servers · Modern Studio')

@php
    use App\Models\Server;

    $statusMeta = [
        Server::STATUS_GREEN => ['label' => 'Healthy', 'class' => 'status-green', 'card' => '', 'icon' => 'fa-circle-check'],
        Server::STATUS_YELLOW => ['label' => 'Watch', 'class' => 'status-yellow', 'card' => 'card-status-yellow', 'icon' => 'fa-triangle-exclamation'],
        Server::STATUS_RED => ['label' => 'Alert', 'class' => 'status-red', 'card' => 'card-status-red', 'icon' => 'fa-circle-exclamation'],
        Server::STATUS_UNKNOWN => ['label' => 'Unknown', 'class' => 'status-unknown', 'card' => 'card-status-unknown', 'icon' => 'fa-circle-question'],
    ];

    $pressureColor = function (?float $pct): string {
        if ($pct === null) return 'var(--color-primary-500)';
        if ($pct > 90) return 'var(--color-status-red)';
        if ($pct > 80) return 'var(--color-status-yellow)';
        return 'var(--color-primary-500)';
    };

    $healthyCount = $statusCounts[Server::STATUS_GREEN] ?? 0;
    $watchCount = $statusCounts[Server::STATUS_YELLOW] ?? 0;
    $alertCount = $statusCounts[Server::STATUS_RED] ?? 0;
    $totalCount = $servers->count();
    $healthPct = $totalCount > 0 ? round(($healthyCount / $totalCount) * 100, 1) : 100;
@endphp

@section('content')
<div x-data="{
    activeTab: 'all',
    filterProvider: 'all'
}">
    <!-- ================================================================= -->
    <!-- MODERN SAAS KPI OVERVIEW                                          -->
    <!-- ================================================================= -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
        <!-- KPI 1 -->
        <div class="studio-kpi-card">
            <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] font-medium mb-1">
                <span>Fleet Availability</span>
                <span class="inline-flex items-center gap-1 text-[var(--color-status-green)] font-semibold">
                    <span class="w-1.5 h-1.5 rounded-full bg-[var(--color-status-green)]"></span>
                    {{ $healthPct }}%
                </span>
            </div>
            <div class="text-2xl font-display font-bold text-[var(--color-ink-strong)] font-data">
                {{ $healthyCount }} / {{ $totalCount }}
            </div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                Servers operating normally
            </div>
        </div>

        <!-- KPI 2 -->
        <div class="studio-kpi-card">
            <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] font-medium mb-1">
                <span>Managed Websites</span>
                <i class="fa-brands fa-wordpress text-sky-500 text-sm"></i>
            </div>
            <div class="text-2xl font-display font-bold text-[var(--color-ink-strong)] font-data">
                {{ $totalSites }}
            </div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                Active WordPress client sites
            </div>
        </div>

        <!-- KPI 3 -->
        <div class="studio-kpi-card">
            <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] font-medium mb-1">
                <span>Infrastructure Nodes</span>
                <i class="fa-solid fa-server text-[var(--color-brand)] text-sm"></i>
            </div>
            <div class="text-2xl font-display font-bold text-[var(--color-ink-strong)] font-data">
                {{ $totalCount }}
            </div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                Monitored host machines
            </div>
        </div>

        <!-- KPI 4 -->
        <div class="studio-kpi-card">
            <div class="flex items-center justify-between text-xs text-[var(--color-ink-soft)] font-medium mb-1">
                <span>Open Attention Items</span>
                @if ($alertCount > 0)
                    <span class="w-2 h-2 rounded-full bg-[var(--color-status-red)] animate-ping"></span>
                @else
                    <i class="fa-solid fa-circle-check text-[var(--color-status-green)]"></i>
                @endif
            </div>
            <div class="text-2xl font-display font-bold font-data {{ $alertCount > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-strong)]' }}">
                {{ $alertCount + $watchCount }}
            </div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                {{ $alertCount > 0 ? $alertCount . ' critical alerts requiring triage' : 'All systems nominal' }}
            </div>
        </div>
    </div>

    <!-- Status Flashes -->
    @if (session('status'))
        <div class="p-4 mb-6 rounded-xl bg-[var(--color-status-green)]/10 border border-[var(--color-status-green)]/20 text-[var(--color-status-green)] flex items-center gap-2 text-xs font-semibold">
            <i class="fa-solid fa-circle-check"></i>
            {{ session('status') }}
        </div>
    @endif
    @if (session('status_error'))
        <div class="p-4 mb-6 rounded-xl bg-[var(--color-status-red)]/10 border border-[var(--color-status-red)]/20 text-[var(--color-status-red)] flex items-center gap-2 text-xs font-semibold">
            <i class="fa-solid fa-triangle-exclamation"></i>
            {{ session('status_error') }}
        </div>
    @endif

    <!-- Missing Credentials Notification -->
    @if ($missingCreds > 0)
        <div class="studio-card p-4 mb-6 flex items-center justify-between gap-4 border-l-4 border-l-[var(--color-status-yellow)]">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-[var(--color-status-yellow)]/10 text-[var(--color-status-yellow)] flex items-center justify-center font-bold">
                    <i class="fa-solid fa-key text-xs"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-[var(--color-ink-strong)]">{{ $missingCreds }} {{ Str::plural('server', $missingCreds) }} missing SSH credentials</p>
                    <p class="text-[11px] text-[var(--color-ink-muted)]">Configure credentials to unlock real-time metric polling and automated fail2ban protections.</p>
                </div>
            </div>
            <a href="{{ route('servers.credentials.bulk') }}" class="px-3 py-1.5 rounded-lg border border-[var(--color-border)] text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                Setup Credentials
            </a>
        </div>
    @endif

    <!-- ================================================================= -->
    <!-- FILTER RIBBON & PROVIDER TABS                                     -->
    <!-- ================================================================= -->
    <div class="flex items-center justify-between flex-wrap gap-4 mb-6">
        <!-- Search bar -->
        <div class="relative flex-1 max-w-sm" id="fleet-search-wrap">
            <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] text-xs"></i>
            <input
                type="search"
                id="fleet-search"
                placeholder="Search servers or sites…"
                autocomplete="off"
                class="w-full pl-9 pr-8 py-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] focus:border-[var(--color-brand)] focus:outline-none text-xs text-[var(--color-ink-strong)] shadow-2xs"
            >
            <button type="button" id="fleet-search-clear" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] w-5 h-5 rounded-full flex items-center justify-center text-xs">
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

        <!-- Tag filter pills -->
        @if ($tags->isNotEmpty())
            <div class="flex items-center gap-1.5 flex-wrap text-xs">
                @foreach ($tags as $tag)
                    @php $isActive = $activeTag && $activeTag->id === $tag->id; @endphp
                    <a href="{{ route('dashboard', $isActive ? [] : ['tag' => $tag->slug]) }}"
                       class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border transition-colors {{ $isActive ? 'border-transparent text-white font-semibold' : 'border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]' }}"
                       style="{{ $isActive ? 'background: ' . $tag->color : '' }}">
                        <span class="w-1.5 h-1.5 rounded-full" style="background: {{ $tag->color }}"></span>
                        {{ $tag->name }}
                    </a>
                @endforeach
            </div>
        @endif

        <!-- Quick Sync Buttons -->
        <div class="flex items-center gap-2">
            @if (app(\Modules\Core\ModuleStateResolver::class)->isEnabled('spinupwp'))
                <form method="POST" action="{{ route('servers.refreshFromSpinupWp') }}" class="inline">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 rounded-lg border border-[var(--color-border)] text-xs font-medium hover:bg-[var(--color-surface-alt)] transition-colors text-[var(--color-ink-strong)]"
                            title="Sync SpinupWP inventory">
                        <i class="fa-solid fa-rotate text-xs mr-1 text-[var(--color-ink-muted)]"></i> SpinupWP Sync
                    </button>
                </form>
            @endif
            @if (app(\Modules\Core\ModuleStateResolver::class)->isEnabled('gridpane'))
                <form method="POST" action="{{ route('servers.refreshFromGridPane') }}" class="inline">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 rounded-lg border border-[var(--color-border)] text-xs font-medium hover:bg-[var(--color-surface-alt)] transition-colors text-[var(--color-ink-strong)]"
                            title="Sync GridPane inventory">
                        <i class="fa-solid fa-rotate text-xs mr-1 text-[var(--color-ink-muted)]"></i> GridPane Sync
                    </button>
                </form>
            @endif
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- SERVER CARDS GRID                                                 -->
    <!-- ================================================================= -->
    @if ($servers->isEmpty())
        <div class="studio-card p-12 text-center">
            <i class="fa-solid fa-server text-4xl text-[var(--color-ink-soft)] mb-3"></i>
            <h3 class="text-base font-semibold text-[var(--color-ink-strong)]">No servers registered</h3>
            <p class="text-xs text-[var(--color-ink-muted)] mt-1">Start by adding a server or syncing from your hosting control panel.</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($servers as $server)
                @php
                    $meta = $statusMeta[$server->status] ?? $statusMeta[Server::STATUS_UNKNOWN];
                    $spark = $sparklines[$server->id] ?? null;
                    $latest = $spark['latest'] ?? null;
                    $cpuVal = $latest['cpu_usage'] ?? null;
                    $memVal = $latest['memory_usage'] ?? null;
                    $dskVal = $latest['disk_usage'] ?? null;
                @endphp
                <div class="studio-card p-6 flex flex-col justify-between server-card"
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
                                    Updates Available
                                </span>
                            @endif
                            @if ($server->reboot_required)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-[var(--color-status-yellow)] text-white">
                                    Reboot Required
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- Resource Progress Bars -->
                    <div class="space-y-3 pt-4 border-t border-[var(--color-border-light)]">
                        <!-- CPU -->
                        <div>
                            <div class="flex justify-between text-xs mb-1 font-medium">
                                <span class="text-[var(--color-ink-muted)]">CPU Usage</span>
                                <span class="font-data font-semibold text-[var(--color-ink-strong)]">{{ $cpuVal !== null ? round($cpuVal) . '%' : '—' }}</span>
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

                        <!-- Footer Link -->
                        <div class="flex items-center justify-between pt-3 border-t border-[var(--color-border-light)]/60 text-xs">
                            <span class="text-[var(--color-ink-soft)] font-medium">
                                <i class="fa-solid fa-globe text-xs mr-1 text-[var(--color-brand)]"></i>
                                {{ $server->sites_count ?? $server->sites->count() }} managed sites
                            </span>
                            <a href="{{ route('servers.show', $server) }}" class="font-semibold text-[var(--color-brand)] hover:underline inline-flex items-center gap-1">
                                Manage &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const input = document.getElementById('fleet-search');
        const clearBtn = document.getElementById('fleet-search-clear');
        const emptyMsg = document.getElementById('fleet-search-empty');
        if (!input) return;

        input.addEventListener('input', () => {
            const query = input.value.trim().toLowerCase();
            if (clearBtn) clearBtn.classList.toggle('hidden', query === '');

            let matchCount = 0;
            document.querySelectorAll('.server-card').forEach(el => {
                const searchData = el.getAttribute('data-search') || '';
                const matches = query === '' || searchData.includes(query);
                el.classList.toggle('hidden', !matches);
                if (matches) matchCount++;
            });

            if (emptyMsg) emptyMsg.classList.toggle('hidden', matchCount > 0 || query === '');
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                input.value = '';
                input.dispatchEvent(new Event('input'));
                input.focus();
            });
        }
    });
</script>
@endsection
