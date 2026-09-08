@php
    $sslState = $site->sslState();
    $snapshotAt = $site->companion_snapshot_at;
    $certCheckedAt = $site->cert_checked_at ?? null;
@endphp

{{-- Operator-triggered refresh: pulls the Companion snapshot (plugins + admins) and
     re-probes the SSL cert and uptime. --}}
<div class="card px-5 py-3 mb-6 flex items-start justify-between flex-wrap gap-3"
     x-data="{
        running: false,
        result: null,
        looksLikePluginGone(combinedMessage) {
            return /rest_no_route|status code 404|Companion is not installed/i.test(combinedMessage);
        },
        looksLikeClockSkew(combinedMessage) {
            return /stale_timestamp|HTTP 401|signature mismatch|invalid_signature/i.test(combinedMessage);
        },
        async refresh() {
            if (this.running) return;
            this.running = true;
            this.result = null;
            const csrf = '{{ csrf_token() }}';
            const tasks = [
                @if ($site->companion_installed)
                fetch('{{ route('sites.companion.push-update', $site) }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                }).then(r => r.json().then(d => ({ name: 'Companion', ...d, status: r.status }))),
                @endif
                fetch('{{ route('sites.cert.recheck', $site) }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                }).then(r => r.json().then(d => ({ name: 'SSL', ...d, status: r.status }))),
                @if ($site->uptime_monitoring_enabled)
                fetch('{{ route('sites.uptime.recheck', $site) }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                }).then(r => r.json().then(d => ({ name: 'Uptime', ...d, status: r.status })))
                @endif
            ];
            try {
                const results = await Promise.all(tasks);
                const allOk = results.every(r => r.status >= 200 && r.status < 300 && r.ok === true);

                const summarise = (r) => {
                    if (r.status >= 200 && r.status < 300 && r.ok === true) return r.name + ': ✓';
                    const raw = r.message || r.error || 'error';
                    if (r.name === 'Companion' && this.looksLikePluginGone(raw)) {
                        return 'Companion: ✗ plugin not responding (mu-plugin appears missing or inactive)';
                    }
                    if (r.name === 'Companion' && this.looksLikeClockSkew(raw)) {
                        return 'Companion: ✗ clock skew or signature mismatch — check the site\'s system clock';
                    }
                    return r.name + ': ✗ ' + raw;
                };

                this.result = {
                    ok: allOk,
                    message: results.map(summarise).join(' · '),
                    needsReinstall: results.some(r => r.name === 'Companion' && !r.ok && this.looksLikePluginGone(r.message || r.error || '')),
                };
                if (allOk) {
                    setTimeout(() => window.location.reload(), 600);
                }
            } catch (e) {
                this.result = { ok: false, message: 'Request failed: ' + e.message };
            } finally {
                this.running = false;
            }
        }
     }">
    <div class="flex-1 min-w-0">
        <div class="text-sm text-[var(--color-ink-strong)] font-medium">Telemetry sync</div>
        <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">
            @php $uptimeSuffix = $site->uptime_monitoring_enabled ? ' and uptime' : ''; @endphp
            @if ($site->companion_installed)
                Pulls fresh snapshot from Companion + re-probes SSL{{ $uptimeSuffix }}.
            @else
                Re-probes SSL{{ $uptimeSuffix }}. Companion isn't installed on this site — install it from the Settings tab for full telemetry.
            @endif
            @if ($snapshotAt)
                Last Companion snapshot: <span title="{{ $snapshotAt }}">{{ $snapshotAt->diffForHumans() }}</span>.
            @elseif ($site->companion_installed)
                Companion snapshot has never been pulled.
            @endif
        </div>
        <template x-if="result">
            <div class="text-xs mt-1"
                 :class="result.ok ? 'text-[var(--color-status-green)]' : 'text-[var(--color-status-red)]'">
                <i class="fa-solid" :class="result.ok ? 'fa-circle-check' : 'fa-circle-exclamation'"></i>
                <span x-text="result.message"></span>
                <span x-show="result.ok" class="text-[var(--color-ink-soft)]">— reloading…</span>
                <span x-show="result.needsReinstall" class="ml-1">
                    →
                    <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'settings']) }}#companion-install-btn"
                       class="underline font-medium">
                        Reinstall Companion
                    </a>
                </span>
            </div>
        </template>
    </div>
    <button type="button"
            class="btn-pill-nav text-xs shrink-0"
            :disabled="running"
            @click="refresh()">
        <i class="fa-solid" :class="running ? 'fa-spinner fa-spin' : 'fa-rotate'"></i>
        <span x-text="running ? 'Refreshing…' : 'Refresh Snapshot'"></span>
    </button>
</div>

{{-- 3-Column Command Center Widget Grid --}}
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 mb-8">
    {{-- Row 1: Core Maintenance & Health --}}
    <div>@include('dashboard.site.widgets._widget-updates')</div>
    <div>@include('dashboard.site.widgets._widget-uptime')</div>
    <div>@include('dashboard.site.widgets._widget-performance')</div>

    {{-- Row 2: Infrastructure & Operations --}}
    <div>@include('dashboard.site.widgets._widget-backups')</div>
    <div>@include('dashboard.site.widgets._widget-traffic')</div>
    <div>@include('dashboard.site.widgets._widget-notes')</div>

    {{-- Row 3: Security, SEO & Forms --}}
    <div>@include('dashboard.site.widgets._widget-security')</div>
    <div>@include('dashboard.site.widgets._widget-seo')</div>
    <div>@include('dashboard.site.widgets._widget-forms')</div>
</div>

{{-- Recent Activity Audit Timeline --}}
@include('dashboard.site._recent-activity')

{{-- Server Threat Telemetry (SSH-enabled servers only) --}}
@unless ($site->isPressable())
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-10 mt-8">
    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)]">Top Threat IPs (24h)</h2>
        </div>
        @if ($topIps24h->isEmpty())
            <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">No threat events in the last 24h.</div>
        @else
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'hits', defaultDir: 'desc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="ip"        class="px-5 py-2">IP</x-sort-th>
                        <x-sort-th key="hits"      align="right" class="px-5 py-2">Hits</x-sort-th>
                        <x-sort-th key="last_seen" class="px-5 py-2">Last seen</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($topIps24h as $row)
                        <tr
                            data-sort-ip="{{ $row->ip }}"
                            data-sort-hits="{{ $row->hits }}"
                            data-sort-last_seen="{{ \Carbon\Carbon::parse($row->last_seen)->getTimestamp() }}">
                            <td class="px-5 py-2"><x-ip-link :ip="$row->ip" /></td>
                            <td class="px-5 py-2 text-right">{{ number_format($row->hits) }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">
                                {{ \Carbon\Carbon::parse($row->last_seen)->diffForHumans() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)]">Recent Threat Log</h2>
        </div>
        @if ($recentLogs->isEmpty())
            <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">No threat events yet — nginx tailer has not ingested rows for this site.</div>
        @else
            <ul class="divide-y divide-[var(--color-border-light)] max-h-96 overflow-y-auto">
                @foreach ($recentLogs as $row)
                    <li class="px-5 py-2 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="text-[var(--color-ink-strong)]"><x-ip-link :ip="$row->ip" /></span>
                            <span class="px-1.5 rounded text-[10px]
                                @if ($row->status_code >= 500) bg-red-100 text-red-700
                                @elseif ($row->status_code >= 400) bg-yellow-100 text-yellow-700
                                @else bg-gray-100 text-gray-600 @endif">{{ $row->status_code }}</span>
                            <span class="font-mono text-[var(--color-ink-muted)]">{{ $row->request_method }}</span>
                            <span class="text-[var(--color-ink-muted)] truncate">{{ $row->request_path }}</span>
                        </div>
                        <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5 truncate">
                            {{ $row->event_at?->diffForHumans() }} · {{ Str::limit($row->user_agent, 90) }}
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endunless
