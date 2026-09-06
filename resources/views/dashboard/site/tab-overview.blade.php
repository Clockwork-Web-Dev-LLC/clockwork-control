@php
    $sslState = $site->sslState();
    $snapshotAt = $site->companion_snapshot_at;
    $certCheckedAt = $site->cert_checked_at ?? null;

    // Render a status pill for each update flag. Yellow when SpinupWP says
    // "updates pending", green when up to date, gray when we haven't pulled
    // from the API yet (the boolean is null on a fresh row).
    $updatePill = function (?bool $pending, string $label) {
        if ($pending === null) {
            return '<span class="status-pill status-unknown text-[10px]"><i class="fa-solid fa-question"></i> ' . $label . ' unknown</span>';
        }
        if ($pending) {
            return '<span class="status-pill status-yellow text-[10px]"><i class="fa-solid fa-triangle-exclamation"></i> ' . $label . ' updates available</span>';
        }
        return '<span class="status-pill status-green text-[10px]"><i class="fa-solid fa-circle-check"></i> ' . $label . ' up to date</span>';
    };
@endphp

{{-- Operator-triggered refresh: pulls the Companion snapshot (plugins + admins) and
     re-probes the SSL cert. Useful right after a site migration when the cards
     below are blank or stale because the nightly jobs haven't run yet. --}}
<div class="card px-5 py-3 mb-5 flex items-start justify-between flex-wrap gap-3"
     x-data="{
        running: false,
        result: null,
        // Look at a Companion-side error message and decide whether it points
        // at 'plugin missing/inactive' (404 + rest_no_route) — we can give
        // the user a direct path to the fix.
        looksLikePluginGone(combinedMessage) {
            return /rest_no_route|status code 404|Companion is not installed/i.test(combinedMessage);
        },
        // Clock-skew between Clockwork and the site breaks the HMAC replay
        // window — surface a specific hint instead of the raw signature error.
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
                // Don't trust `r.ok` alone — a 200 response with no `ok` key
                // (raw WP redirect HTML, plugin gone, etc.) lands as
                // `r.ok === undefined` and would silently count as success
                // before this guard. Check both HTTP status AND payload flag.
                const allOk = results.every(r => r.status >= 200 && r.status < 300 && r.ok === true);

                // Friendlier per-task line. For Companion 404s, replace the
                // raw rest_no_route blob with one short sentence.
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
        <div class="text-sm text-[var(--color-ink-strong)] font-medium">Refresh status</div>
        <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">
            @php $uptimeSuffix = $site->uptime_monitoring_enabled ? ' and uptime' : ''; @endphp
            @if ($site->companion_installed)
                Pulls a fresh snapshot from Companion + re-probes SSL{{ $uptimeSuffix }}.
            @else
                Re-probes SSL{{ $uptimeSuffix }}. Companion isn't installed on this site, so plugin/admin data won't change — install it from the Settings tab to capture more.
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
        <span x-text="running ? 'Refreshing…' : 'Refresh from Companion'"></span>
    </button>
</div>

@php
    // SpinupWP sites get a dedicated inventory pull (import-spinupwp) that
    // populates wp_core_update etc. independently of Companion — use that
    // when it's available since it's often fresher than the last snapshot.
    // Pressable has no such pull; those booleans are never populated there,
    // so fall back to the Companion snapshot's own update counts, which are
    // populated for both providers.
    if ($site->isPressable()) {
        $updatesCheckedAt = $site->companion_snapshot_at;
        $updatesSource = 'Companion snapshot';
        $corePending = $site->companion_snapshot !== null ? $site->core_update_available : null;
        $themesPending = $site->companion_snapshot !== null ? $site->theme_updates_available > 0 : null;
        $pluginsPending = $site->companion_snapshot !== null ? $site->plugin_updates_available > 0 : null;
    } else {
        $updatesCheckedAt = $site->wp_updates_checked_at;
        $updatesSource = 'SpinupWP';
        $corePending = $site->wp_core_update;
        $themesPending = $site->wp_theme_updates;
        $pluginsPending = $site->wp_plugin_updates;
    }
@endphp
@if ($site->is_wordpress)
    <div class="card px-5 py-3 mb-5 flex items-center flex-wrap gap-3">
        <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">WordPress updates</span>
        {!! $updatePill($corePending, 'Core') !!}
        {!! $updatePill($themesPending, 'Themes') !!}
        {!! $updatePill($pluginsPending, 'Plugins') !!}
        @if ($updatesCheckedAt)
            <span class="ml-auto text-[10px] text-[var(--color-ink-soft)]" title="{{ $updatesCheckedAt }}">
                checked from {{ $updatesSource }} {{ $updatesCheckedAt->diffForHumans() }}
            </span>
        @endif
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-8">
    <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'settings']) }}#cert-detail" class="card-link card p-5">
        <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">SSL cert</div>
        @if ($sslState === 'none')
            <div class="font-display text-xl text-[var(--color-ink-soft)]">Not tracked</div>
        @else
            <div class="font-display text-xl text-[var(--color-ink-strong)]">
                Expires {{ $site->cert_expires_at?->format('M j, Y') }}
            </div>
            <div class="text-sm text-[var(--color-ink-muted)] mt-1">
                {{ $site->cert_expires_at?->diffForHumans() }}
            </div>
        @endif
        <div class="text-xs text-[var(--color-ink-soft)] mt-3">
            Source: <span class="font-data">{{ $site->cert_source }}</span>
            @if ($site->cert_renews_at)
                · renews {{ $site->cert_renews_at->format('M j, Y') }}
            @endif
        </div>
    </a>

    {{-- Threat events + Active bans both source from nginx-log ingestion,
         which requires SSH/server access this app doesn't have for
         Pressable sites — hidden rather than shown as an always-empty 0. --}}
    @unless ($site->isPressable())
        <div class="card p-5">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Threat events (24h)</div>
            <div class="font-display text-2xl text-[var(--color-ink-strong)]">{{ number_format($logCount24h) }}</div>
            <div class="text-sm text-[var(--color-ink-muted)] mt-1">
                {{ number_format($logCountTotal) }} total ingested
            </div>
        </div>

        <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'bans']) }}" class="card-link card p-5">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Active bans (this site)</div>
            <div class="font-display text-2xl text-[var(--color-ink-strong)]">{{ $bansCount }}</div>
            <div class="text-sm text-[var(--color-ink-muted)] mt-1">
                @if ($bansCount > 0)
                    manage in Bans tab →
                @else
                    no active bans
                @endif
            </div>
        </a>
    @endunless

    {{-- Uptime status — green/red/grey based on latest probe. Source: uptime_state.
         Auth-protected sites (HTTP basic auth, IP allowlists, staging gates)
         report 401/403 to anonymous probes — those are now classified as
         "up" with a sub-label so the operator knows it's auth-protected
         rather than fully public. --}}
    @php
        $authProtected = $site->uptime_state === 'up'
            && in_array($site->uptime_last_status_code, [401, 403], true);
        if ($authProtected) {
            $usMap = ['label' => 'Up · auth required', 'pillClass' => 'status-green', 'icon' => 'fa-lock'];
        } else {
            $usMap = match ($site->uptime_state) {
                'up' => ['label' => 'Up', 'pillClass' => 'status-green', 'icon' => 'fa-circle-check'],
                'down' => ['label' => 'Down', 'pillClass' => 'status-red', 'icon' => 'fa-circle-exclamation'],
                default => ['label' => 'Not yet checked', 'pillClass' => 'status-unknown', 'icon' => 'fa-circle-question'],
            };
        }
    @endphp
    <div class="card p-5">
        <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Status</div>
        @if (! $site->uptime_monitoring_enabled)
            <div class="font-display text-base text-[var(--color-ink-muted)]"><i class="fa-solid fa-circle-pause mr-1"></i> Monitoring disabled</div>
            <form method="POST" action="{{ route('sites.uptime-monitoring.toggle', $site) }}" class="mt-2">
                @csrf
                <input type="hidden" name="enabled" value="1">
                <button type="submit" class="btn-pill-nav text-xs"><i class="fa-solid fa-toggle-off"></i> Enable monitoring</button>
            </form>
        @else
            <div class="font-display text-2xl text-[var(--color-ink-strong)]">
                <span class="status-pill {{ $usMap['pillClass'] }} text-base"><i class="fa-solid {{ $usMap['icon'] }}"></i> {{ $usMap['label'] }}</span>
            </div>
            <div class="text-sm text-[var(--color-ink-muted)] mt-1">
                @if ($site->uptime_state === 'down' && $site->uptime_down_since)
                    Down for {{ $site->uptime_down_since->diffForHumans(['parts' => 2, 'short' => true]) }}
                    @if ($site->uptime_last_status_code) (HTTP {{ $site->uptime_last_status_code }}) @endif
                @elseif ($authProtected)
                    Origin returned HTTP {{ $site->uptime_last_status_code }} — server alive, auth challenge presented to anonymous probes.
                @elseif ($site->uptime_last_up_at)
                    Last seen up {{ $site->uptime_last_up_at->diffForHumans() }}
                @else
                    Awaiting first probe
                @endif
            </div>
            <form method="POST" action="{{ route('sites.uptime-monitoring.toggle', $site) }}" class="mt-2">
                @csrf
                <input type="hidden" name="enabled" value="0">
                <button type="submit" class="btn-pill-nav text-xs"><i class="fa-solid fa-toggle-on"></i> Disable monitoring</button>
            </form>
        @endif
    </div>
</div>

@include('dashboard.site._recent-activity')

{{-- Both cards below source from nginx-log ingestion (SSH-only) — hidden
     for Pressable rather than shown as permanently empty. --}}
@unless ($site->isPressable())
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-10">
    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)]">Top IPs (24h)</h2>
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
            <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)]">Recent threat log</h2>
        </div>
        @if ($recentLogs->isEmpty())
            <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">No threat events yet — the nginx tailer hasn't ingested any rows for this site.</div>
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
