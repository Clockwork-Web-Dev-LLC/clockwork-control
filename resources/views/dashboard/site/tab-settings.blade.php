<div class="card p-5 mb-6" id="cert-detail">
    <div class="flex items-center justify-between mb-3">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Cert details</h2>
        <div class="flex items-center gap-3">
            {{-- Recheck-now asks SpinupWP's own per-site API for fresh cert
                 data — no equivalent exists for Pressable yet. --}}
            @unless ($site->isPressable())
                <button type="button" id="cert-recheck"
                        data-url="{{ route('sites.cert.recheck', $site) }}"
                        class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50">
                    <i class="fa-solid fa-rotate"></i> Recheck now
                </button>
            @endunless
            <button type="button" id="cert-edit-toggle" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)]">
                <i class="fa-solid fa-pen-to-square"></i> Edit
            </button>
        </div>
    </div>

    <div id="cert-recheck-result" class="hidden mb-3 text-sm"></div>

    <div id="cert-view" class="space-y-2 text-sm">
        <div><span class="text-[var(--color-ink-soft)]">Source:</span> <span class="font-data">{{ $site->cert_source }}</span></div>
        <div><span class="text-[var(--color-ink-soft)]">Expires:</span> {{ $site->cert_expires_at?->format('M j, Y H:i') ?: '—' }}</div>
        <div><span class="text-[var(--color-ink-soft)]">Renews:</span> {{ $site->cert_renews_at?->format('M j, Y H:i') ?: '—' }}</div>
        <div>
            <div class="text-[var(--color-ink-soft)] mb-1">Notes:</div>
            <div class="whitespace-pre-wrap text-[var(--color-ink-muted)]">{{ $site->cert_notes ?: '—' }}</div>
        </div>
        @if ($site->cert_source === 'spinupwp_le')
            <div class="text-xs text-[var(--color-ink-soft)] mt-3">
                <i class="fa-solid fa-circle-info"></i>
                Updated automatically by <code>clockwork:import-spinupwp</code> from the SpinupWP API.
            </div>
        @endif
    </div>

    <form id="cert-form" method="POST" action="{{ route('sites.cert.update', $site) }}" class="hidden space-y-3 text-sm">
        @csrf
        @method('PATCH')

        <label class="block">
            <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Source</span>
            <select name="cert_source" class="block w-full mt-1 border border-[var(--color-border-light)] rounded-md px-3 py-2">
                <option value="none" @selected($site->cert_source === 'none')>None</option>
                <option value="spinupwp_le" @selected($site->cert_source === 'spinupwp_le')>SpinupWP / Let's Encrypt</option>
                <option value="external" @selected($site->cert_source === 'external')>External (3rd-party)</option>
                <option value="redirect_only" @selected($site->cert_source === 'redirect_only')>Redirect-only / parked — skip SSL monitoring</option>
            </select>
            <p class="text-xs text-[var(--color-ink-soft)] mt-1">
                "Redirect-only" suppresses SSL alerts for sites that just redirect elsewhere, sit behind Cloudflare Flexible SSL, or are parked.
            </p>
        </label>

        <div class="grid grid-cols-2 gap-3">
            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Expires at</span>
                <input type="datetime-local" name="cert_expires_at"
                       value="{{ $site->cert_expires_at?->format('Y-m-d\TH:i') }}"
                       class="block w-full mt-1 border border-[var(--color-border-light)] rounded-md px-3 py-2">
            </label>
            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Renews at</span>
                <input type="datetime-local" name="cert_renews_at"
                       value="{{ $site->cert_renews_at?->format('Y-m-d\TH:i') }}"
                       class="block w-full mt-1 border border-[var(--color-border-light)] rounded-md px-3 py-2">
            </label>
        </div>

        <label class="block">
            <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Notes</span>
            <textarea name="cert_notes" rows="4" placeholder="Issuer, renewal portal URL, contact, etc."
                      class="block w-full mt-1 border border-[var(--color-border-light)] rounded-md px-3 py-2 font-data text-xs">{{ $site->cert_notes }}</textarea>
        </label>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn-primary">Save</button>
            <button type="button" id="cert-cancel" class="text-sm text-[var(--color-ink-soft)] hover:text-[var(--color-ink)]">Cancel</button>
        </div>
    </form>
</div>

<div class="card p-5 mb-6">
    <div class="flex items-center justify-between mb-2">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Cloudflare</h2>
        <span class="text-xs text-[var(--color-ink-soft)]">
            {{ $site->cloudflare_checked_at?->diffForHumans() ?? 'never checked' }}
        </span>
    </div>
    @php
        $stateMessage = match ($site->cloudflare_state) {
            'proxied' => 'Traffic flows through Cloudflare. Their WAF + bot management filter requests before they reach the origin nginx.',
            'dns_only' => 'Cloudflare manages DNS, but the proxy is OFF (grey cloud). Traffic still hits the origin directly. Consider toggling the proxy on for this domain in Cloudflare DNS settings.',
            'not_using' => 'Domain is not using Cloudflare. All traffic — including spam and bots — reaches the origin nginx directly.',
            default => 'Cloudflare state has not been determined yet.',
        };
    @endphp
    <p class="text-sm text-[var(--color-ink-muted)] mb-3">{{ $stateMessage }}</p>
    <div class="grid grid-cols-2 gap-4 text-xs font-data">
        <div>
            <div class="text-[var(--color-ink-soft)] uppercase tracking-wide mb-1 text-[10px]">A record</div>
            <div class="text-[var(--color-ink-strong)]">{{ $site->resolved_a_record ?? '—' }}</div>
        </div>
        <div>
            <div class="text-[var(--color-ink-soft)] uppercase tracking-wide mb-1 text-[10px]">First NS record</div>
            <div class="text-[var(--color-ink-strong)] truncate">{{ $site->resolved_ns_record ?? '—' }}</div>
        </div>
    </div>
</div>

@if ($site->is_wordpress)
    <div class="card p-5 mb-10">
        <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-2">WordPress security</h2>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Wordfence</div>
                @if ($site->wordfence_enabled)
                    <div class="text-[var(--color-ink-strong)]">Enabled — ingest pending</div>
                @else
                    <div class="text-[var(--color-ink-soft)]">Not enabled</div>
                @endif
            </div>
            <div>
                <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Limit Login Attempts</div>
                <div id="llar-state">
                    @if ($site->llar_enabled)
                        <div class="text-[var(--color-ink-strong)]">Enabled — ingest pending</div>
                    @else
                        <div class="flex items-center gap-3">
                            <div class="text-[var(--color-ink-soft)]">Not enabled</div>
                            {{-- Installs via wp-cli over SSH — no equivalent
                                 transport wired up for Pressable yet. --}}
                            @unless ($site->isPressable())
                                <button type="button"
                                        id="llar-install-btn"
                                        class="btn-pill-nav text-xs"
                                        data-url="{{ route('sites.llar.install', $site) }}"
                                        title="Install LLAR via wp-cli over SSH and turn off its lockout email feature. If LLAR is already installed, the existing config is left untouched.">
                                    <i class="fa-solid fa-download"></i> Install LLAR
                                </button>
                            @endunless
                        </div>
                    @endif
                </div>
                <div id="llar-install-result" class="hidden text-xs mt-2"></div>
            </div>
        </div>
        <div class="text-xs text-[var(--color-ink-soft)] mt-3">
            <i class="fa-solid fa-circle-info"></i>
            Wordfence + LLAR per-site stats arrive once the DB ingest lands. DB credentials
            @if ($site->db_password)
                are <span class="text-[var(--color-status-green)]">configured</span>.
            @else
                are <span class="text-[var(--color-ink-soft)]">missing</span> — run <code>php artisan clockwork:extract-wp-configs --site={{ $site->domain }}</code>.
            @endif
        </div>
    </div>

@if ($site->isPressable())
    {{-- Pressable-native server tools — no SpinupWP/SSH equivalent needed since
         this reads straight from Pressable's own API, not through Companion. --}}
    <div class="card p-5 mb-10"
         x-data="{
            flushing: false,
            flushResult: null,
            async flushObjectCache() {
                if (this.flushing) return;
                this.flushing = true;
                this.flushResult = null;
                try {
                    const r = await fetch('{{ route('sites.pressable.flush-object-cache', $site) }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    });
                    const d = await r.json();
                    this.flushResult = { ok: d.ok === true, message: d.message || d.error || 'Unknown error' };
                } catch (e) {
                    this.flushResult = { ok: false, message: 'Request failed: ' + e.message };
                } finally {
                    this.flushing = false;
                }
            },
            loadingMetrics: false,
            metricsError: null,
            metricsLoaded: false,
            async loadResourceMetrics() {
                if (this.loadingMetrics) return;
                this.loadingMetrics = true;
                this.metricsError = null;
                try {
                    const r = await fetch('{{ route('sites.pressable.resource-metrics', $site) }}', { headers: { 'Accept': 'application/json' } });
                    const d = await r.json();
                    if (!d.ok) { this.metricsError = d.error || 'Unknown error'; return; }
                    this.metricsLoaded = true;
                    this.$nextTick(() => renderPressableResourceCharts(d.cpu, d.mysql));
                } catch (e) {
                    this.metricsError = 'Request failed: ' + e.message;
                } finally {
                    this.loadingMetrics = false;
                }
            },
         }">
        <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-3">
            <i class="fa-solid fa-server text-[var(--color-ink-muted)] mr-1"></i>
            Pressable server tools
        </h2>

        <div class="flex items-center flex-wrap gap-3 mb-2">
            <button type="button" class="btn-pill-nav text-xs" :disabled="flushing" @click="flushObjectCache()"
                    title="Flush WordPress's object cache (Redis/Memcached). Useful after a direct DB write, a restored backup, or anything else that bypasses the normal WordPress write path. Distinct from the edge/CDN cache purge this app already does automatically before every snapshot pull.">
                <i class="fa-solid" :class="flushing ? 'fa-spinner fa-spin' : 'fa-broom'"></i>
                <span x-text="flushing ? 'Flushing…' : 'Flush object cache'"></span>
            </button>
            <template x-if="flushResult">
                <span class="text-xs" :class="flushResult.ok ? 'text-[var(--color-status-green)]' : 'text-[var(--color-status-red)]'" x-text="flushResult.message"></span>
            </template>
        </div>

        <div class="flex items-center flex-wrap gap-3 mb-3">
            <button type="button" class="btn-pill-nav text-xs" :disabled="loadingMetrics" @click="loadResourceMetrics()">
                <i class="fa-solid" :class="loadingMetrics ? 'fa-spinner fa-spin' : 'fa-chart-line'"></i>
                <span x-text="loadingMetrics ? 'Loading…' : 'Load resource metrics (past 24h)'"></span>
            </button>
            <template x-if="metricsError">
                <span class="text-xs text-[var(--color-status-red)]" x-text="metricsError"></span>
            </template>
        </div>

        <div x-show="metricsLoaded" x-cloak class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
                <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">CPU usage (per PHP pool)</div>
                <div id="pressable-cpu-chart" style="height: 220px;"></div>
            </div>
            <div>
                <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">MySQL connections</div>
                <div id="pressable-mysql-chart" style="height: 220px;"></div>
            </div>
        </div>
        <div class="text-xs text-[var(--color-ink-soft)] mt-2">
            <i class="fa-solid fa-circle-info"></i>
            Pulled live from Pressable's own metrics API — read-only, nothing stored. Bucketed at 15-minute resolution.
        </div>
    </div>

    <script>
        // Renders once per "Load resource metrics" click — safe to redefine,
        // and cheap since it only runs when the button is clicked.
        function renderPressableResourceCharts(cpuPeriods, mysqlPeriods) {
            if (! window.echarts) return;

            const toSeries = (periods, pick) => (periods || []).map(p => [Number(p.timestamp) * 1000, pick(p)]);

            // CPU is requested as a single metric, so Pressable keys each
            // period's result by dimension value (one entry per PHP pool)
            // under `dimension`, not under a `server`-named key — that
            // nesting only happens with a multi-metric request (see MySQL
            // below). Confirmed live 2026-08-29.
            const poolKeys = new Set();
            (cpuPeriods || []).forEach(p => {
                const bucket = p.dimension || {};
                Object.keys(bucket).forEach(k => poolKeys.add(k));
            });
            const cpuSeries = Array.from(poolKeys).map(pool => ({
                name: pool,
                type: 'line',
                showSymbol: false,
                data: toSeries(cpuPeriods, p => Number((p.dimension || {})[pool] ?? 0)),
            }));

            const cpuEl = document.getElementById('pressable-cpu-chart');
            const cpuChart = window.echarts.init(cpuEl);
            cpuChart.setOption({
                tooltip: { trigger: 'axis' },
                legend: { top: 0, textStyle: { fontSize: 10 } },
                grid: { left: 40, right: 10, top: 28, bottom: 24 },
                xAxis: { type: 'time' },
                yAxis: { type: 'value' },
                series: cpuSeries,
            });
            window.addEventListener('resize', () => cpuChart.resize());

            // Multi-metric request nests one more level than CPU: each metric
            // under `server` is itself an object keyed by PHP pool, e.g.
            // server.mysql_total_connections["pool156-305-37"] = "29" — sum
            // across pools to get one connections figure per bucket. A
            // metric can be entirely absent from a given period (sparse data
            // when the value was negligible), hence the `|| {}` guards.
            // Confirmed live 2026-08-29.
            const sumPoolValues = (obj) => Object.values(obj || {}).reduce((sum, v) => sum + Number(v || 0), 0);

            const mysqlSeries = [{
                name: 'Connections',
                type: 'line',
                showSymbol: false,
                data: toSeries(mysqlPeriods, p => sumPoolValues((p.server || {}).mysql_total_connections)),
            }];

            const mysqlEl = document.getElementById('pressable-mysql-chart');
            const mysqlChart = window.echarts.init(mysqlEl);
            mysqlChart.setOption({
                tooltip: { trigger: 'axis' },
                grid: { left: 40, right: 10, top: 16, bottom: 24 },
                xAxis: { type: 'time' },
                yAxis: { type: 'value' },
                series: mysqlSeries,
            });
            window.addEventListener('resize', () => mysqlChart.resize());
        }
    </script>
@endif

{{-- Billing & care plan — driven by Bill.com sync, manually overridable. --}}
    <div class="card p-5 mb-6">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-file-invoice-dollar text-[var(--color-ink-muted)] mr-1"></i>
                Billing & care plan
            </h2>
            @if ($site->care_plan_enabled)
                <span class="status-pill status-green">On a care plan</span>
            @else
                <span class="status-pill status-unknown">No care plan</span>
            @endif
        </div>
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div class="text-sm flex-1 min-w-[18rem]">
                <div class="text-[var(--color-ink-strong)]">
                    @if ($site->care_plan_enabled)
                        <i class="fa-solid fa-shield-heart text-[var(--color-status-green)]"></i>
                        On a care plan
                        <span class="text-[var(--color-ink-soft)]">— maintenance work is included</span>
                    @else
                        <i class="fa-regular fa-circle text-[var(--color-ink-soft)]"></i>
                        Not on a care plan
                        <span class="text-[var(--color-ink-soft)]">— bill maintenance separately</span>
                    @endif
                </div>
                <div class="text-xs text-[var(--color-ink-soft)] mt-1">
                    @if ($site->care_plan_override !== null)
                        <i class="fa-solid fa-hand text-[var(--color-status-yellow)]"></i>
                        Manual override — Bill.com sync won't touch this flag.
                    @else
                        <i class="fa-solid fa-rotate text-[var(--color-ink-muted)]"></i>
                        Auto — Bill.com sync sets this based on invoice activity.
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <form method="POST" action="{{ route('sites.care-plan', $site) }}">
                    @csrf
                    <input type="hidden" name="enabled" value="{{ $site->care_plan_enabled ? '0' : '1' }}">
                    <button type="submit" class="btn-pill-nav text-xs">
                        @if ($site->care_plan_enabled)
                            <i class="fa-solid fa-toggle-on"></i> Mark as NOT on care plan
                        @else
                            <i class="fa-solid fa-toggle-off"></i> Mark as on care plan
                        @endif
                    </button>
                </form>
                @if ($site->care_plan_override !== null)
                    <form method="POST" action="{{ route('sites.care-plan.clear-override', $site) }}">
                        @csrf
                        <button type="submit" class="btn-pill-nav text-xs"
                                title="Clear the manual override; the next Bill.com sync will re-derive the flag from invoice activity.">
                            <i class="fa-solid fa-rotate"></i> Let Bill.com decide
                        </button>
                    </form>
                @endif
            </div>
        </div>

        @if ($site->bill_com_customer_id)
            <div class="text-xs text-[var(--color-ink-muted)] mt-3 pt-3 border-t border-[var(--color-border-light)]">
                <i class="fa-solid fa-file-invoice-dollar text-[var(--color-ink-muted)]"></i>
                Bill.com customer: <strong class="text-[var(--color-ink-strong)]">{{ $site->bill_com_customer_name ?: $site->bill_com_customer_id }}</strong>
                @if ($site->bill_com_linked_via_invoice)
                    <span class="text-[var(--color-ink-soft)]">
                        — linked via invoice {{ $site->bill_com_linked_via_invoice }}
                        @if ($site->bill_com_linked_at)
                            ({{ $site->bill_com_linked_at->diffForHumans() }})
                        @endif
                    </span>
                @endif
            </div>
        @endif

        {{-- Nightly auto-updates pause/resume — only meaningful when the
             site is on a care plan, but the toggle stays visible so an
             operator can pre-pause a site before flipping the care-plan
             switch. --}}
        @if ($site->care_plan_enabled)
            @php $autoOn = ! $site->auto_updates_paused; @endphp
            <div class="mt-4 pt-4 border-t border-[var(--color-border-light)]">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div class="text-sm flex-1 min-w-[18rem]">
                        <div class="text-[var(--color-ink-strong)]">
                            <i class="fa-solid fa-moon text-[var(--color-ink-muted)]"></i>
                            Nightly auto-updates
                            @if ($autoOn)
                                <span class="text-[var(--color-status-green)]">— ON</span>
                                <span class="text-[var(--color-ink-soft)] text-xs">(plugins update automatically 2&ndash;6 AM ET)</span>
                            @else
                                <span class="text-[var(--color-ink-muted)]">— OFF</span>
                                <span class="text-[var(--color-ink-soft)] text-xs">(opt in to put this site on the nightly path)</span>
                            @endif
                        </div>
                        @if (! $autoOn && $site->auto_updates_paused_reason)
                            <div class="text-xs text-[var(--color-ink-soft)] mt-1">
                                <i class="fa-solid fa-circle-info"></i>
                                {{ $site->auto_updates_paused_reason }}
                            </div>
                        @endif
                        @if ($site->auto_updates_last_run_at)
                            <div class="text-xs text-[var(--color-ink-soft)] mt-1">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                                Last considered {{ $site->auto_updates_last_run_at->diffForHumans() }}
                            </div>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('sites.auto-updates.toggle', $site) }}" class="inline">
                        @csrf
                        <input type="hidden" name="paused" value="{{ $autoOn ? '1' : '0' }}">
                        <button type="submit" class="btn-pill-nav text-xs">
                            @if ($autoOn)
                                <i class="fa-solid fa-toggle-on"></i> Turn auto-updates OFF
                            @else
                                <i class="fa-solid fa-toggle-off"></i> Turn auto-updates ON
                            @endif
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>

    {{-- Uptime monitoring & alerts — disable stops probing entirely; ignore
         keeps probing but suppresses alerts/Issues. Two distinct knobs in
         one card because they're conceptually the same topic. --}}
    <div class="card p-5 mb-6">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-heart-pulse text-[var(--color-ink-muted)] mr-1"></i>
                Uptime monitoring & alerts
            </h2>
            @if (! $site->uptime_monitoring_enabled)
                <span class="status-pill status-unknown">Off</span>
            @elseif ($site->isUptimeIgnored())
                <span class="status-pill status-yellow"><i class="fa-solid fa-bell-slash"></i> Ignored</span>
            @else
                <span class="status-pill status-green">Active</span>
            @endif
        </div>

        {{-- Probe on/off (disable = stop probing entirely) --}}
        <div class="flex items-center justify-between gap-3 flex-wrap py-3 border-t border-[var(--color-border-light)]">
            <div class="text-sm">
                <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Probe</div>
                <div class="text-[var(--color-ink-strong)]">
                    @if ($site->uptime_monitoring_enabled)
                        <i class="fa-solid fa-eye text-[var(--color-status-green)]"></i>
                        Enabled
                        <span class="text-[var(--color-ink-soft)]">— probes every 5 min, alerts Mattermost on 10+ min outages</span>
                    @else
                        <i class="fa-solid fa-eye-slash text-[var(--color-ink-soft)]"></i>
                        Disabled
                        <span class="text-[var(--color-ink-soft)]">— this site is excluded from the every-5-min probe</span>
                    @endif
                </div>
            </div>
            <form method="POST" action="{{ route('sites.uptime-monitoring.toggle', $site) }}">
                @csrf
                <input type="hidden" name="enabled" value="{{ $site->uptime_monitoring_enabled ? '0' : '1' }}">
                <button type="submit" class="btn-pill-nav text-xs">
                    @if ($site->uptime_monitoring_enabled)
                        <i class="fa-solid fa-toggle-on"></i> Disable monitoring
                    @else
                        <i class="fa-solid fa-toggle-off"></i> Enable monitoring
                    @endif
                </button>
            </form>
        </div>

        {{-- Ignore alerts (probe keeps running, alerts/Issues silenced) --}}
        <div class="flex items-start justify-between gap-3 flex-wrap py-3 border-t border-[var(--color-border-light)]">
            <div class="text-sm flex-1 min-w-[20rem]">
                <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Alert routing</div>
                <div class="text-[var(--color-ink-strong)]">
                    @if ($site->isUptimeIgnored())
                        <i class="fa-solid fa-bell-slash text-[var(--color-status-yellow)]"></i>
                        Ignored
                        <span class="text-[var(--color-ink-soft)]">since {{ $site->uptime_ignored_at->diffForHumans() }}</span>
                        @if ($site->uptime_ignore_reason)
                            <div class="text-xs text-[var(--color-ink-soft)] mt-1">"{{ $site->uptime_ignore_reason }}"</div>
                        @endif
                        <div class="text-xs text-[var(--color-ink-soft)] mt-1">
                            Probe is still running — current state: <strong>{{ $site->uptime_state }}</strong>.
                            Issues page and Mattermost alerts are suppressed.
                        </div>
                    @else
                        <i class="fa-solid fa-bell text-[var(--color-ink-soft)]"></i>
                        Active
                        <span class="text-[var(--color-ink-soft)]">— alerts and Issues entries fire normally.</span>
                        <div class="text-xs text-[var(--color-ink-soft)] mt-1">
                            Use Ignore when a site is known down indefinitely. Probe keeps running so we know when it recovers; you just don't get noisy alerts in the meantime.
                        </div>
                    @endif
                </div>
            </div>
            <form method="POST" action="{{ route('sites.uptime-ignore.toggle', $site) }}" class="flex flex-col gap-2 items-end">
                @csrf
                @if ($site->isUptimeIgnored())
                    <input type="hidden" name="ignore" value="0">
                    <button type="submit" class="btn-pill-nav text-xs">
                        <i class="fa-solid fa-bell"></i> Stop ignoring
                    </button>
                @else
                    <input type="hidden" name="ignore" value="1">
                    <input type="text" name="reason" maxlength="255" placeholder="Reason (optional)"
                           class="px-3 py-1.5 rounded-md border border-[var(--color-border)] text-sm w-64 focus:outline-none focus:border-[var(--color-brand)]">
                    <button type="submit" class="btn-pill-nav text-xs">
                        <i class="fa-solid fa-bell-slash"></i> Ignore alerts
                    </button>
                @endif
            </form>
        </div>
    </div>

    {{-- Fleet-wide inactive flag — site stays visible everywhere (unlike
         Archive, which hides it entirely) but is excluded from the Issues
         page, nav badge, and every routine-maintenance alert (SSL renewal,
         plugin updates, 2FA, contact-form/Companion health). Active-incident
         signals (malware, uptime down/up, blocked IPs) are NOT affected —
         those still fire even for an inactive site. Use case: a client
         migrated away but asked to keep the site reachable a while longer. --}}
    <div class="card p-5 mb-6">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-moon text-[var(--color-ink-muted)] mr-1"></i>
                Site status
            </h2>
            @if ($site->is_inactive)
                <span class="status-pill status-unknown"><i class="fa-solid fa-moon"></i> Inactive</span>
            @else
                <span class="status-pill status-green">Active</span>
            @endif
        </div>

        <div class="flex items-start justify-between gap-3 flex-wrap py-3 border-t border-[var(--color-border-light)]">
            <div class="text-sm flex-1 min-w-[20rem]">
                <div class="text-[var(--color-ink-strong)]">
                    @if ($site->is_inactive)
                        <i class="fa-solid fa-moon text-[var(--color-ink-soft)]"></i>
                        Inactive
                        @if ($site->inactive_reason)
                            <div class="text-xs text-[var(--color-ink-soft)] mt-1">"{{ $site->inactive_reason }}"</div>
                        @endif
                        <div class="text-xs text-[var(--color-ink-soft)] mt-1">
                            Still shows up everywhere (Sites, search, this page). Excluded from the Issues page, nav badge, and routine-maintenance alerts — SSL renewal, plugin/theme updates, 2FA migration, contact-form/Companion health. Malware findings, uptime, and blocked IPs still alert normally.
                        </div>
                    @else
                        <i class="fa-solid fa-sun text-[var(--color-ink-soft)]"></i>
                        Active
                        <span class="text-[var(--color-ink-soft)]">— issues and alerts fire normally.</span>
                        <div class="text-xs text-[var(--color-ink-soft)] mt-1">
                            Mark inactive when a site is winding down (client migrated, kept alive a while longer) but you don't want routine maintenance nags for it anymore. Different from Archive — the site stays fully visible.
                        </div>
                    @endif
                </div>
            </div>
            <form method="POST" action="{{ route('sites.inactive.toggle', $site) }}" class="flex flex-col gap-2 items-end">
                @csrf
                @if ($site->is_inactive)
                    <input type="hidden" name="inactive" value="0">
                    <button type="submit" class="btn-pill-nav text-xs">
                        <i class="fa-solid fa-sun"></i> Reactivate
                    </button>
                @else
                    <input type="hidden" name="inactive" value="1">
                    <input type="text" name="reason" maxlength="255" placeholder="Reason (optional)"
                           class="px-3 py-1.5 rounded-md border border-[var(--color-border)] text-sm w-64 focus:outline-none focus:border-[var(--color-brand)]">
                    <button type="submit" class="btn-pill-nav text-xs">
                        <i class="fa-solid fa-moon"></i> Mark inactive
                    </button>
                @endif
            </form>
        </div>
    </div>

    {{-- Companion mu-plugin — install/upgrade + push-update. Self-contained.
         No coupling to billing/uptime/contact-form; it's its own thing. --}}
    <div class="card p-5 mb-6">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-puzzle-piece text-[var(--color-ink-muted)] mr-1"></i>
                Companion mu-plugin
            </h2>
            @if ($site->companion_installed)
                <span class="status-pill status-green">v{{ $site->companion_version }}</span>
            @else
                <span class="status-pill status-unknown">Not installed</span>
            @endif
        </div>
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="text-sm">
                <div id="companion-state" class="text-[var(--color-ink-strong)]">
                    @if ($site->companion_installed)
                        v{{ $site->companion_version }}
                        <span class="text-[var(--color-ink-soft)]">— last seen {{ $site->companion_last_seen_at?->diffForHumans() ?? 'never' }}</span>
                    @else
                        <span class="text-[var(--color-ink-soft)]">Not installed on this site.</span>
                    @endif
                </div>
                <div class="text-xs text-[var(--color-ink-soft)] mt-1">
                    Powers the client-visible Tools → Clockwork pages (Activity / Uptime / Security / Performance / Backups), plus enables SSO, plugin updates, and contact-form testing.
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @if ($site->companion_installed)
                    <button type="button"
                            id="companion-push-update-btn"
                            class="btn-pill-nav text-xs"
                            data-url="{{ route('sites.companion.push-update', $site) }}"
                            title="Force-refresh the Companion snapshot (plugins/admins/cron/comments) and push a fresh backups report — without waiting for the scheduled jobs.">
                        <i class="fa-solid fa-rotate"></i> Push update
                    </button>
                @endif
                @if ($site->host()->supports(\Modules\Core\Contracts\HostingProvider::CAP_COMPANION))
                    <button type="button"
                            id="companion-install-btn"
                            class="btn-pill-nav text-xs"
                            data-url="{{ route('sites.contact-form.install-companion', $site) }}"
                            title="Install (or upgrade) the Clockwork Companion mu-plugin on this site over SSH. Safe to run repeatedly.">
                        <i class="fa-solid fa-download"></i>
                        {{ $site->companion_installed ? 'Reinstall Companion' : 'Install Companion' }}
                    </button>
                @else
                    <span class="text-xs text-[var(--color-ink-soft)]" title="{{ $site->host()->label() }} is in View-Only mode — confirm live write access in /settings/integrations to enable Companion install.">
                        <i class="fa-solid fa-circle-info"></i> Install unavailable ({{ $site->host()->label() }} is View-Only)
                    </span>
                @endif
            </div>
        </div>
        <div id="companion-install-result" class="hidden text-xs mt-2"></div>
        <div id="companion-push-update-result" class="hidden text-xs mt-2"></div>

        <script>
            (function () {
                const btn = document.getElementById('companion-push-update-btn');
                if (!btn) return;
                const result = document.getElementById('companion-push-update-result');
                btn.addEventListener('click', async () => {
                    const original = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Pushing…';
                    result.className = 'mt-2 text-xs text-[var(--color-ink-muted)]';
                    result.textContent = 'Refreshing snapshot + pushing backups report…';
                    result.classList.remove('hidden');

                    try {
                        const r = await fetch(btn.dataset.url, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                        });
                        const data = await r.json();
                        const cls = data.all_ok ? 'status-green' : (data.ok ? 'status-yellow' : 'status-red');
                        const icon = data.all_ok ? 'fa-circle-check' : (data.ok ? 'fa-circle-info' : 'fa-circle-xmark');
                        result.className = 'mt-2 status-pill ' + cls + ' inline-block text-xs';
                        result.innerHTML = '<i class="fa-solid ' + icon + '"></i> ' + (data.message || 'Done.');
                    } catch (e) {
                        result.className = 'mt-2 status-pill status-red inline-block text-xs';
                        result.textContent = 'Network error: ' + e.message;
                    } finally {
                        btn.disabled = false;
                        btn.innerHTML = original;
                    }
                });
            })();
        </script>
    </div>

    {{-- Contact form testing has moved to its own Forms tab. The Companion
         install card stays on this page because it's not per-form. --}}
    @if ($site->care_plan_enabled)
        <div class="card p-5 mb-6 flex items-center justify-between gap-3 flex-wrap">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-envelope-circle-check text-[var(--color-ink-muted)] mr-1"></i>
                    Contact form testing
                </h2>
                <p class="text-sm text-[var(--color-ink-muted)] mt-1">
                    Configure up to {{ \App\Models\ContactFormTest::MAX_PER_SITE }} forms to test, each on its own schedule. Moved to a dedicated tab.
                </p>
            </div>
            <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'forms']) }}" class="btn-pill-nav text-sm">
                Manage forms →
            </a>
        </div>
    @endif

    <script>
    (function () {
        const btn = document.getElementById('companion-install-btn');
        if (! btn) return;
        const result = document.getElementById('companion-install-result');
        const csrf = '{{ csrf_token() }}';
        btn.addEventListener('click', async () => {
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Installing…';
            result.className = 'text-xs mt-2 text-[var(--color-ink-muted)]';
            result.textContent = 'Pushing the plugin over SSH and verifying via /health…';
            result.classList.remove('hidden');
            try {
                const r = await fetch(btn.dataset.url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                const data = await r.json();
                if (r.ok) {
                    result.className = 'text-xs mt-2 text-[var(--color-status-green)]';
                    result.innerHTML = `<i class="fa-solid fa-circle-check"></i> ${data.message ?? 'Installed.'} Refreshing…`;
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    result.className = 'text-xs mt-2 text-[var(--color-status-red)]';
                    let html = `<i class="fa-solid fa-circle-xmark"></i> ${data.message ?? 'Failed'}`;
                    if (data.output) {
                        // Surface the captured wp-cli / SSH stderr in a collapsible
                        // <details> so the operator can see WHY it failed without
                        // burying it in the network tab. Common signal: wrong wp_path,
                        // missing site_user, non-default table_prefix, sudo password
                        // mismatch.
                        const escaped = data.output.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        html += `
                            <details class="mt-2">
                                <summary class="cursor-pointer text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">Show wp-cli / SSH output</summary>
                                <pre class="mt-1 p-2 bg-[var(--color-surface-alt)] rounded text-[10px] text-[var(--color-ink-muted)] whitespace-pre-wrap break-words">${escaped}</pre>
                            </details>`;
                    }
                    result.innerHTML = html;
                    btn.disabled = false;
                    btn.innerHTML = original;
                }
            } catch (e) {
                result.className = 'text-xs mt-2 text-[var(--color-status-red)]';
                result.textContent = 'Network error: ' + e.message;
                btn.disabled = false;
                btn.innerHTML = original;
            }
        });
    })();
    </script>

    {{-- Remove from monitoring (soft archive). The Site model's global scope
         hides archived rows from every listing automatically. Historical rows
         (scans, bans, traffic) stay in the DB so we don't lose audit trail. --}}
    <div class="card p-5 mb-6 border border-[var(--color-status-red)]/30">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div class="max-w-xl">
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1">Remove from monitoring</h2>
                <p class="text-sm text-[var(--color-ink-muted)]">
                    Use this when the site has been deleted from SpinupWP, moved to another host, or otherwise should no longer appear in dashboards. The row is hidden from <em>every</em> listing — dashboard, monitoring, issues, server site lists. Historical scans, bans, and traffic data are retained.
                </p>
            </div>
            <button type="button" id="archive-site-toggle" class="btn-pill-nav text-[var(--color-status-red)] border-[var(--color-status-red)]/40">
                <i class="fa-solid fa-trash-can"></i> Remove site
            </button>
        </div>

        <form id="archive-site-form" method="POST" action="{{ route('sites.archive', $site) }}" class="mt-4 hidden">
            @csrf
            <p class="text-sm text-[var(--color-ink-muted)] mb-2">
                Type <code class="bg-[var(--color-surface-alt)] px-1.5 py-0.5 rounded text-[var(--color-ink-strong)] font-data">{{ $site->domain }}</code> below to confirm.
            </p>
            <input type="text" name="confirm_domain" autocomplete="off" spellcheck="false"
                   placeholder="{{ $site->domain }}"
                   class="w-full font-data text-sm border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-status-red)]/30">
            <input type="text" name="reason" autocomplete="off"
                   placeholder="Reason (optional, e.g. 'moved to Liquid Web 2026-05-06')"
                   class="mt-2 w-full text-sm border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-status-red)]/30">
            <div class="mt-3 flex items-center gap-2">
                <button type="submit" class="btn-primary bg-[var(--color-status-red)] hover:bg-[var(--color-status-red)]/90">
                    <i class="fa-solid fa-check"></i> Remove this site
                </button>
                <button type="button" id="archive-site-cancel" class="btn-pill-nav">Cancel</button>
            </div>
        </form>
    </div>
    <script>
        (function () {
            const toggle = document.getElementById('archive-site-toggle');
            const form   = document.getElementById('archive-site-form');
            const cancel = document.getElementById('archive-site-cancel');
            if (!toggle || !form || !cancel) return;
            toggle.addEventListener('click', () => { form.classList.remove('hidden'); toggle.classList.add('hidden'); form.querySelector('input[name="confirm_domain"]').focus(); });
            cancel.addEventListener('click', () => { form.classList.add('hidden'); toggle.classList.remove('hidden'); form.reset(); });
        })();
    </script>
@endif

<script>
    (function () {
        const toggle = document.getElementById('cert-edit-toggle');
        const cancel = document.getElementById('cert-cancel');
        const view = document.getElementById('cert-view');
        const form = document.getElementById('cert-form');

        function show(editing) {
            view.classList.toggle('hidden', editing);
            form.classList.toggle('hidden', !editing);
            toggle.classList.toggle('hidden', editing);
        }

        toggle?.addEventListener('click', () => show(true));
        cancel?.addEventListener('click', () => show(false));

        const recheck = document.getElementById('cert-recheck');
        const result = document.getElementById('cert-recheck-result');
        recheck?.addEventListener('click', async () => {
            recheck.disabled = true;
            recheck.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking…';
            result.className = 'mb-3 text-sm text-[var(--color-ink-muted)]';
            result.textContent = 'Asking SpinupWP for the latest cert info…';
            result.classList.remove('hidden');
            try {
                const r = await fetch(recheck.dataset.url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                });
                const data = await r.json();
                if (data.ok) {
                    result.className = 'mb-3 text-sm status-pill status-green inline-block';
                    result.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + data.message + ' Reloading…';
                    setTimeout(() => location.reload(), 800);
                } else {
                    result.className = 'mb-3 text-sm status-pill status-red inline-block';
                    result.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> ' + (data.message || 'Failed.');
                }
            } catch (e) {
                result.className = 'mb-3 text-sm status-pill status-red inline-block';
                result.textContent = 'Network error: ' + e.message;
            } finally {
                recheck.disabled = false;
                recheck.innerHTML = '<i class="fa-solid fa-rotate"></i> Recheck now';
            }
        });

        const llarBtn = document.getElementById('llar-install-btn');
        const llarResult = document.getElementById('llar-install-result');
        const llarState = document.getElementById('llar-state');
        llarBtn?.addEventListener('click', async () => {
            if (!confirm('Install Limit Login Attempts Reloaded on this site? Email-on-lockout will be turned off. If LLAR is already there, nothing will be changed.')) {
                return;
            }
            llarBtn.disabled = true;
            const original = llarBtn.innerHTML;
            llarBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Installing…';
            llarResult.className = 'text-xs mt-2 text-[var(--color-ink-muted)]';
            llarResult.textContent = 'Connecting over SSH and running wp-cli…';
            llarResult.classList.remove('hidden');
            try {
                const r = await fetch(llarBtn.dataset.url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                });
                const data = await r.json();
                if (data.ok) {
                    const colorClass = data.result === 'installed' ? 'status-green' : 'status-yellow';
                    const icon = data.result === 'installed' ? 'fa-circle-check' : 'fa-circle-info';
                    llarResult.className = 'text-xs mt-2 status-pill ' + colorClass + ' inline-block';
                    llarResult.innerHTML = '<i class="fa-solid ' + icon + '"></i> ' + data.message + ' Reloading…';
                    setTimeout(() => location.reload(), 1200);
                } else {
                    llarResult.className = 'text-xs mt-2 status-pill status-red inline-block';
                    llarResult.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> ' + (data.message || 'Failed.');
                }
            } catch (e) {
                llarResult.className = 'text-xs mt-2 status-pill status-red inline-block';
                llarResult.textContent = 'Network error: ' + e.message;
            } finally {
                llarBtn.disabled = false;
                llarBtn.innerHTML = original;
            }
        });
    })();
</script>
