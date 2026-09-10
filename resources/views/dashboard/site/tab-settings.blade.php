{{-- 3-Column Settings Grid — styled consistently with Overview Command Center cards --}}
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 mb-8">

    {{-- Card 1: Cert Details --}}
    <div class="card p-5 flex flex-col justify-between h-full" id="cert-detail">
        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-certificate text-emerald-600"></i>
                    Cert details
                </h3>
                @php
                    $certStatusClass = 'status-unknown';
                    $certStatusText = 'No SSL';
                    if ($site->cert_source === 'redirect_only') {
                        $certStatusClass = 'status-unknown';
                        $certStatusText = 'Redirect only';
                    } elseif ($site->cert_expires_at) {
                        $daysRemaining = (int) now()->diffInDays($site->cert_expires_at, false);
                        if ($site->cert_expires_at->isPast() || $daysRemaining < 0) {
                            $certStatusClass = 'status-red';
                            $certStatusText = 'Expired';
                        } elseif ($daysRemaining < 14) {
                            $certStatusClass = 'status-red';
                            $certStatusText = 'Expires soon';
                        } elseif ($daysRemaining < 30) {
                            $certStatusClass = 'status-yellow';
                            $certStatusText = 'Expiring';
                        } else {
                            $certStatusClass = 'status-green';
                            $certStatusText = 'Valid SSL';
                        }
                    }
                @endphp
                <span class="status-pill {{ $certStatusClass }} text-[10px]">
                    <span class="status-dot"></span> {{ $certStatusText }}
                </span>
            </div>

            <div id="cert-recheck-result" class="hidden mb-3 text-xs"></div>

            <div id="cert-view" class="space-y-2">
                <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs space-y-1.5">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Source:</span>
                        <span class="font-mono font-medium text-[var(--color-ink-strong)]">{{ $site->cert_source }}</span>
                    </div>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Expires:</span>
                        <span class="font-medium text-[var(--color-ink-strong)]">{{ $site->cert_expires_at?->format('M j, Y H:i') ?: '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Renews:</span>
                        <span class="font-medium text-[var(--color-ink-strong)]">{{ $site->cert_renews_at?->format('M j, Y H:i') ?: '—' }}</span>
                    </div>
                </div>

                @if ($site->cert_notes)
                    <div class="text-[11px] text-[var(--color-ink-muted)] p-2 rounded bg-[var(--color-surface-alt)]/40 truncate" title="{{ $site->cert_notes }}">
                        <i class="fa-regular fa-note-sticky text-gray-400 mr-1"></i> {{ $site->cert_notes }}
                    </div>
                @endif

                @if ($site->cert_source === 'spinupwp_le')
                    <div class="text-[10px] text-[var(--color-ink-soft)] flex items-center gap-1 mt-1">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>Auto-synced via SpinupWP API</span>
                    </div>
                @endif
            </div>

            <form id="cert-form" method="POST" action="{{ route('sites.cert.update', $site) }}" class="hidden space-y-2.5 text-xs">
                @csrf
                @method('PATCH')

                <div>
                    <label class="block text-[10px] uppercase font-medium tracking-wide text-[var(--color-ink-soft)] mb-0.5">Source</label>
                    <select name="cert_source" class="block w-full border border-[var(--color-border-light)] rounded px-2 py-1 text-xs bg-[var(--color-surface)] text-[var(--color-ink-strong)]">
                        <option value="none" @selected($site->cert_source === 'none')>None</option>
                        <option value="spinupwp_le" @selected($site->cert_source === 'spinupwp_le')>SpinupWP / Let's Encrypt</option>
                        <option value="external" @selected($site->cert_source === 'external')>External (3rd-party)</option>
                        <option value="redirect_only" @selected($site->cert_source === 'redirect_only')>Redirect-only / parked</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[10px] uppercase font-medium tracking-wide text-[var(--color-ink-soft)] mb-0.5">Expires</label>
                        <input type="datetime-local" name="cert_expires_at"
                               value="{{ $site->cert_expires_at?->format('Y-m-d\TH:i') }}"
                               class="block w-full border border-[var(--color-border-light)] rounded px-2 py-1 text-xs">
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase font-medium tracking-wide text-[var(--color-ink-soft)] mb-0.5">Renews</label>
                        <input type="datetime-local" name="cert_renews_at"
                               value="{{ $site->cert_renews_at?->format('Y-m-d\TH:i') }}"
                               class="block w-full border border-[var(--color-border-light)] rounded px-2 py-1 text-xs">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] uppercase font-medium tracking-wide text-[var(--color-ink-soft)] mb-0.5">Notes</label>
                    <textarea name="cert_notes" rows="2" placeholder="Issuer, renewal portal URL, etc."
                              class="block w-full border border-[var(--color-border-light)] rounded px-2 py-1 text-[11px] font-data">{{ $site->cert_notes }}</textarea>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <button type="submit" class="btn-primary text-xs py-1 px-3">Save</button>
                    <button type="button" id="cert-cancel" class="btn-pill-nav text-xs py-1 px-2.5">Cancel</button>
                </div>
            </form>
        </div>

        <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
            <div>
                @unless ($site->isPressable())
                    <button type="button" id="cert-recheck"
                            data-url="{{ route('sites.cert.recheck', $site) }}"
                            class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50 flex items-center gap-1">
                        <i class="fa-solid fa-rotate text-[10px]"></i> Recheck now
                    </button>
                @else
                    <span class="text-[11px] text-[var(--color-ink-muted)]">Managed SSL</span>
                @endunless
            </div>
            <button type="button" id="cert-edit-toggle" class="btn-pill-nav text-xs flex items-center gap-1">
                <i class="fa-solid fa-pen-to-square text-[10px]"></i> Edit
            </button>
        </div>
    </div>

    {{-- Card 2: Cloudflare --}}
    <div class="card p-5 flex flex-col justify-between h-full">
        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-brands fa-cloudflare text-orange-500 text-base"></i>
                    Cloudflare
                </h3>
                @php
                    $cfPill = match ($site->cloudflare_state) {
                        'proxied' => ['class' => 'status-green', 'text' => 'Proxied'],
                        'dns_only' => ['class' => 'status-yellow', 'text' => 'DNS Only'],
                        'not_using' => ['class' => 'status-unknown', 'text' => 'Direct Origin'],
                        default => ['class' => 'status-unknown', 'text' => 'Unchecked'],
                    };
                @endphp
                <span class="status-pill {{ $cfPill['class'] }} text-[10px]">
                    <span class="status-dot"></span> {{ $cfPill['text'] }}
                </span>
            </div>

            @php
                $stateMessage = match ($site->cloudflare_state) {
                    'proxied' => 'Traffic flows through Cloudflare proxy. WAF & bot protection active before origin.',
                    'dns_only' => 'DNS is managed, but proxy is OFF (grey cloud). Traffic reaches origin directly.',
                    'not_using' => 'Domain is not using Cloudflare. All traffic reaches origin nginx directly.',
                    default => 'Cloudflare state has not been determined yet.',
                };
            @endphp
            <p class="text-xs text-[var(--color-ink-muted)] mb-3 leading-relaxed">
                {{ $stateMessage }}
            </p>

            <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs space-y-1.5">
                <div class="flex items-center justify-between text-[11px]">
                    <span class="text-[var(--color-ink-muted)] uppercase tracking-wider text-[10px]">A Record:</span>
                    <span class="font-mono font-medium text-[var(--color-ink-strong)] truncate max-w-[140px]" title="{{ $site->resolved_a_record }}">{{ $site->resolved_a_record ?? '—' }}</span>
                </div>
                <div class="flex items-center justify-between text-[11px]">
                    <span class="text-[var(--color-ink-muted)] uppercase tracking-wider text-[10px]">First NS:</span>
                    <span class="font-mono font-medium text-[var(--color-ink-strong)] truncate max-w-[140px]" title="{{ $site->resolved_ns_record }}">{{ $site->resolved_ns_record ?? '—' }}</span>
                </div>
            </div>
        </div>

        <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
            <span class="text-[11px] text-[var(--color-ink-muted)] flex items-center gap-1">
                <i class="fa-regular fa-clock text-[10px]"></i>
                {{ $site->cloudflare_checked_at?->diffForHumans() ?? 'never checked' }}
            </span>
            <span class="text-[10px] uppercase tracking-wider font-semibold text-[var(--color-ink-soft)]">
                DNS & Proxy
            </span>
        </div>
    </div>

    {{-- Card 3: WordPress Security --}}
    @if ($site->is_wordpress)
        <div class="card p-5 flex flex-col justify-between h-full">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                        <i class="fa-solid fa-shield-halved text-purple-600"></i>
                        WordPress security
                    </h3>
                    @php
                        $secPill = ($site->wordfence_enabled && $site->llar_enabled)
                            ? ['class' => 'status-green', 'text' => 'Hardened']
                            : (($site->wordfence_enabled || $site->llar_enabled)
                                ? ['class' => 'status-yellow', 'text' => 'Partial']
                                : ['class' => 'status-unknown', 'text' => 'Unconfigured']);
                    @endphp
                    <span class="status-pill {{ $secPill['class'] }} text-[10px]">
                        <span class="status-dot"></span> {{ $secPill['text'] }}
                    </span>
                </div>

                <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs space-y-2 mb-3">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Wordfence:</span>
                        @if ($site->wordfence_enabled)
                            <span class="text-emerald-600 font-medium text-[11px] flex items-center gap-1">
                                <i class="fa-solid fa-circle-check text-[10px]"></i> Enabled
                            </span>
                        @else
                            <span class="text-[var(--color-ink-soft)] text-[11px]">Not enabled</span>
                        @endif
                    </div>

                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Limit Login Attempts:</span>
                        <div id="llar-state">
                            @if ($site->llar_enabled)
                                <span class="text-emerald-600 font-medium text-[11px] flex items-center gap-1">
                                    <i class="fa-solid fa-circle-check text-[10px]"></i> Enabled
                                </span>
                            @else
                                <div class="flex items-center gap-2">
                                    <span class="text-[var(--color-ink-soft)] text-[11px]">Not enabled</span>
                                    @unless ($site->isPressable())
                                        <button type="button"
                                                id="llar-install-btn"
                                                class="btn-pill-nav text-[10px] py-0.5 px-2"
                                                data-url="{{ route('sites.llar.install', $site) }}"
                                                title="Install LLAR via wp-cli over SSH">
                                            <i class="fa-solid fa-download text-[9px]"></i> Install
                                        </button>
                                    @endunless
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div id="llar-install-result" class="hidden text-xs mb-2"></div>

                <div class="text-[11px] text-[var(--color-ink-soft)] flex items-start gap-1.5 leading-tight">
                    <i class="fa-solid fa-database text-[10px] mt-0.5 shrink-0"></i>
                    <span>
                        DB credentials
                        @if ($site->db_password)
                            are <span class="text-[var(--color-status-green)] font-medium">configured</span>.
                        @else
                            are <span class="text-[var(--color-ink-soft)]">missing</span>.
                        @endif
                    </span>
                </div>
            </div>

            <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between text-[11px]">
                <span class="text-[var(--color-ink-muted)]">Brute-force Protection</span>
                <span class="text-[var(--color-ink-soft)] font-mono text-[10px]">wp-cli / SSH</span>
            </div>
        </div>
    @endif

    {{-- Card 4: Pressable Server Tools --}}
    @if ($site->isPressable())
        <div class="card p-5 flex flex-col justify-between h-full"
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
             }"
             :class="metricsLoaded ? 'md:col-span-2 lg:col-span-3' : ''">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                        <i class="fa-solid fa-server text-blue-600"></i>
                        Pressable tools
                    </h3>
                    <span class="status-pill status-green text-[10px]">
                        <span class="status-dot"></span> Connected
                    </span>
                </div>

                <p class="text-xs text-[var(--color-ink-muted)] mb-3">
                    Direct server controls straight from Pressable's cloud metrics API.
                </p>

                <div class="space-y-2">
                    <div>
                        <button type="button" class="btn-pill-nav text-xs w-full justify-center flex items-center gap-1.5" :disabled="flushing" @click="flushObjectCache()">
                            <i class="fa-solid" :class="flushing ? 'fa-spinner fa-spin' : 'fa-broom'"></i>
                            <span x-text="flushing ? 'Flushing object cache…' : 'Flush object cache'"></span>
                        </button>
                        <template x-if="flushResult">
                            <div class="text-[11px] mt-1 text-center" :class="flushResult.ok ? 'text-[var(--color-status-green)]' : 'text-[var(--color-status-red)]'" x-text="flushResult.message"></div>
                        </template>
                    </div>

                    <div>
                        <button type="button" class="btn-pill-nav text-xs w-full justify-center flex items-center gap-1.5" :disabled="loadingMetrics" @click="loadResourceMetrics()">
                            <i class="fa-solid" :class="loadingMetrics ? 'fa-spinner fa-spin' : 'fa-chart-line'"></i>
                            <span x-text="loadingMetrics ? 'Loading metrics…' : (metricsLoaded ? 'Reload resource metrics' : 'Load resource metrics (24h)')"></span>
                        </button>
                        <template x-if="metricsError">
                            <div class="text-[11px] mt-1 text-center text-[var(--color-status-red)]" x-text="metricsError"></div>
                        </template>
                    </div>
                </div>

                <div x-show="metricsLoaded" x-cloak class="mt-4 pt-4 border-t border-[var(--color-border-light)]">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">CPU usage (per PHP pool)</div>
                            <div id="pressable-cpu-chart" style="height: 220px;"></div>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">MySQL connections</div>
                            <div id="pressable-mysql-chart" style="height: 220px;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between text-[11px]">
                <span class="text-[var(--color-ink-muted)]">Pressable Hosting</span>
                <span class="text-[var(--color-ink-soft)]">15m resolution</span>
            </div>
        </div>

        <script>
            function renderPressableResourceCharts(cpuPeriods, mysqlPeriods) {
                if (! window.echarts) return;

                const toSeries = (periods, pick) => (periods || []).map(p => [Number(p.timestamp) * 1000, pick(p)]);
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
                if (cpuEl) {
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
                }

                const sumPoolValues = (obj) => Object.values(obj || {}).reduce((sum, v) => sum + Number(v || 0), 0);
                const mysqlSeries = [{
                    name: 'Connections',
                    type: 'line',
                    showSymbol: false,
                    data: toSeries(mysqlPeriods, p => sumPoolValues((p.server || {}).mysql_total_connections)),
                }];

                const mysqlEl = document.getElementById('pressable-mysql-chart');
                if (mysqlEl) {
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
            }
        </script>
    @endif

    {{-- Card 5: Billing & Care Plan --}}
    <div class="card p-5 flex flex-col justify-between h-full">
        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-file-invoice-dollar text-emerald-600"></i>
                    Billing &amp; care plan
                </h3>
                @if ($site->care_plan_enabled)
                    <span class="status-pill status-green text-[10px]">
                        <span class="status-dot"></span> On care plan
                    </span>
                @else
                    <span class="status-pill status-unknown text-[10px]">
                        <span class="status-dot"></span> No care plan
                    </span>
                @endif
            </div>

            <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs space-y-2 mb-3">
                <div class="flex items-center justify-between text-[11px]">
                    <span class="text-[var(--color-ink-muted)]">Plan status:</span>
                    <span class="font-medium text-[var(--color-ink-strong)] flex items-center gap-1">
                        @if ($site->care_plan_enabled)
                            <i class="fa-solid fa-shield-heart text-emerald-600"></i> Maintenance included
                        @else
                            <i class="fa-regular fa-circle text-[var(--color-ink-soft)]"></i> Bill separately
                        @endif
                    </span>
                </div>

                <div class="flex items-center justify-between text-[11px]">
                    <span class="text-[var(--color-ink-muted)]">Sync source:</span>
                    <span class="text-[var(--color-ink-soft)] flex items-center gap-1">
                        @if ($site->care_plan_override !== null)
                            <i class="fa-solid fa-hand text-amber-500"></i> Manual override
                        @else
                            <i class="fa-solid fa-rotate text-gray-400"></i> Bill.com auto
                        @endif
                    </span>
                </div>

                @if ($site->bill_com_customer_id)
                    <div class="flex items-center justify-between text-[11px] pt-1 border-t border-[var(--color-border-light)]">
                        <span class="text-[var(--color-ink-muted)]">Customer:</span>
                        <span class="font-medium text-[var(--color-ink-strong)] truncate max-w-[130px]" title="{{ $site->bill_com_customer_name ?: $site->bill_com_customer_id }}">
                            {{ $site->bill_com_customer_name ?: $site->bill_com_customer_id }}
                        </span>
                    </div>
                @endif
            </div>

            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <form method="POST" action="{{ route('sites.care-plan', $site) }}" class="flex-1">
                        @csrf
                        <input type="hidden" name="enabled" value="{{ $site->care_plan_enabled ? '0' : '1' }}">
                        <button type="submit" class="btn-pill-nav text-xs w-full justify-center">
                            @if ($site->care_plan_enabled)
                                <i class="fa-solid fa-toggle-on text-emerald-600"></i> Mark NOT on care plan
                            @else
                                <i class="fa-solid fa-toggle-off text-gray-400"></i> Mark on care plan
                            @endif
                        </button>
                    </form>

                    @if ($site->care_plan_override !== null)
                        <form method="POST" action="{{ route('sites.care-plan.clear-override', $site) }}">
                            @csrf
                            <button type="submit" class="btn-pill-nav text-xs" title="Clear manual override and let Bill.com decide">
                                <i class="fa-solid fa-rotate"></i>
                            </button>
                        </form>
                    @endif
                </div>

                @if ($site->care_plan_enabled)
                    @php $autoOn = ! $site->auto_updates_paused; @endphp
                    <div class="p-2 rounded bg-[var(--color-surface-alt)]/40 flex items-center justify-between gap-2 text-xs">
                        <div>
                            <div class="text-[11px] font-medium text-[var(--color-ink-strong)] flex items-center gap-1">
                                <i class="fa-solid fa-moon text-indigo-500 text-[10px]"></i> Auto-updates
                            </div>
                            <div class="text-[10px] text-[var(--color-ink-soft)]">
                                {{ $autoOn ? 'Nightly (2–6 AM)' : 'Paused' }}
                            </div>
                        </div>
                        <form method="POST" action="{{ route('sites.auto-updates.toggle', $site) }}">
                            @csrf
                            <input type="hidden" name="paused" value="{{ $autoOn ? '1' : '0' }}">
                            <button type="submit" class="btn-pill-nav text-[10px] py-1 px-2">
                                {{ $autoOn ? 'Pause' : 'Enable' }}
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>

        <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between text-[11px]">
            <span class="text-[var(--color-ink-muted)]">Care Plan Contract</span>
            <span class="text-[10px] text-[var(--color-ink-soft)]">Bill.com</span>
        </div>
    </div>

    {{-- Card 6: Uptime Monitoring & Alerts --}}
    <div class="card p-5 flex flex-col justify-between h-full">
        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-heart-pulse text-rose-500"></i>
                    Uptime monitoring
                </h3>
                @if (! $site->uptime_monitoring_enabled)
                    <span class="status-pill status-unknown text-[10px]"><span class="status-dot"></span> Off</span>
                @elseif ($site->isUptimeIgnored())
                    <span class="status-pill status-yellow text-[10px]"><span class="status-dot"></span> Ignored</span>
                @else
                    <span class="status-pill status-green text-[10px]"><span class="status-dot"></span> Active</span>
                @endif
            </div>

            <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs space-y-2 mb-3">
                <div class="flex items-center justify-between text-[11px]">
                    <span class="text-[var(--color-ink-muted)]">5-min Probe:</span>
                    <span class="font-medium text-[var(--color-ink-strong)]">
                        @if ($site->uptime_monitoring_enabled)
                            <span class="text-emerald-600 flex items-center gap-1"><i class="fa-solid fa-eye text-[10px]"></i> Probing</span>
                        @else
                            <span class="text-[var(--color-ink-soft)] flex items-center gap-1"><i class="fa-solid fa-eye-slash text-[10px]"></i> Disabled</span>
                        @endif
                    </span>
                </div>

                <div class="flex items-center justify-between text-[11px]">
                    <span class="text-[var(--color-ink-muted)]">Alert routing:</span>
                    <span class="font-medium">
                        @if ($site->isUptimeIgnored())
                            @if ($site->isUptimeSlaExempt())
                                <span class="text-amber-600 flex items-center gap-1"><i class="fa-solid fa-shield-halved text-[10px]"></i> Not Our Fault (SLA Exempt)</span>
                            @else
                                <span class="text-amber-600 flex items-center gap-1"><i class="fa-solid fa-bell-slash text-[10px]"></i> Silenced (Legit Outage)</span>
                            @endif
                        @else
                            <span class="text-emerald-600 flex items-center gap-1"><i class="fa-solid fa-bell text-[10px]"></i> Active</span>
                        @endif
                    </span>
                </div>
            </div>

            <div class="space-y-2">
                <form method="POST" action="{{ route('sites.uptime-monitoring.toggle', $site) }}">
                    @csrf
                    <input type="hidden" name="enabled" value="{{ $site->uptime_monitoring_enabled ? '0' : '1' }}">
                    <button type="submit" class="btn-pill-nav text-xs w-full justify-center flex items-center gap-1.5">
                        @if ($site->uptime_monitoring_enabled)
                            <i class="fa-solid fa-toggle-on text-emerald-600"></i> Disable monitoring probe
                        @else
                            <i class="fa-solid fa-toggle-off text-gray-400"></i> Enable monitoring probe
                        @endif
                    </button>
                </form>

                <form method="POST" action="{{ route('sites.uptime-ignore.toggle', $site) }}" class="space-y-2">
                    @csrf
                    @if ($site->isUptimeIgnored())
                        <input type="hidden" name="ignore" value="0">
                        <div class="p-2.5 rounded bg-amber-500/10 border border-amber-500/20 text-xs text-amber-700 dark:text-amber-300">
                            <div class="font-semibold flex items-center gap-1">
                                <i class="fa-solid fa-shield-halved text-[11px]"></i>
                                {{ $site->isUptimeSlaExempt() ? 'SLA Protected (Not Our Fault)' : 'Alerts Silenced' }}
                            </div>
                            @if ($site->uptime_ignore_reason)
                                <div class="text-[11px] mt-0.5 text-amber-600 dark:text-amber-400">{{ $site->uptime_ignore_reason }}</div>
                            @endif
                        </div>
                        <button type="submit" class="btn-pill-nav text-xs w-full justify-center flex items-center gap-1.5">
                            <i class="fa-solid fa-bell text-emerald-600"></i> Stop ignoring & reset SLA exemption
                        </button>
                    @else
                        <input type="hidden" name="ignore" value="1">
                        <div class="p-2.5 rounded border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/50 space-y-2 text-xs">
                            <div class="font-semibold text-[var(--color-ink-strong)]">Outage Classification</div>
                            <label class="flex items-start gap-2 cursor-pointer">
                                <input type="radio" name="is_sla_exempt" value="1" checked class="mt-0.5">
                                <div>
                                    <span class="font-medium text-amber-600 dark:text-amber-400">Not Our Fault (SLA Exempt)</span>
                                    <p class="text-[10px] text-[var(--color-ink-muted)]">Exclude downtime from uptime ratings and fleet SLA (client DNS, expired domain, etc.)</p>
                                </div>
                            </label>
                            <label class="flex items-start gap-2 cursor-pointer">
                                <input type="radio" name="is_sla_exempt" value="0" class="mt-0.5">
                                <div>
                                    <span class="font-medium text-[var(--color-ink-strong)]">Legit Outage</span>
                                    <p class="text-[10px] text-[var(--color-ink-muted)]">Silence alerts only; downtime still counts against uptime record</p>
                                </div>
                            </label>
                            <div class="space-y-1.5 pt-1.5 border-t border-[var(--color-border-light)]">
                                <select name="exemption_reason" class="w-full px-2 py-1 rounded border border-[var(--color-border)] text-xs bg-[var(--color-surface)] text-[var(--color-ink-strong)]">
                                    <option value="client_dns">Client DNS / Nameserver change</option>
                                    <option value="domain_expired">Domain expired / Registrar hold</option>
                                    <option value="third_party">Third-party / Upstream outage</option>
                                    <option value="client_requested">Client requested downtime</option>
                                    <option value="other">Other (not our fault)</option>
                                </select>
                                <div class="flex items-center gap-1">
                                    <input type="text" name="reason" maxlength="255" placeholder="Reason / notes (optional)"
                                           class="px-2 py-1 rounded border border-[var(--color-border)] text-xs flex-1 focus:outline-none focus:border-[var(--color-brand)] bg-[var(--color-surface)]">
                                    <button type="submit" class="btn-pill-nav text-xs shrink-0 font-medium" title="Silence alerts">
                                        <i class="fa-solid fa-shield-halved text-amber-500"></i> Silence
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif
                </form>
            </div>
        </div>

        <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between text-[11px]">
            <span class="text-[var(--color-ink-muted)]">Current: <strong class="text-[var(--color-ink-strong)]">{{ $site->uptime_state ?? 'UP' }}</strong></span>
            <span class="text-[var(--color-ink-soft)]">Mattermost & Issues</span>
        </div>
    </div>

    {{-- Card 7: Site Status (Active / Inactive) --}}
    <div class="card p-5 flex flex-col justify-between h-full">
        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-circle-nodes text-sky-600"></i>
                    Site status
                </h3>
                @if ($site->is_inactive)
                    <span class="status-pill status-unknown text-[10px]"><span class="status-dot"></span> Inactive</span>
                @else
                    <span class="status-pill status-green text-[10px]"><span class="status-dot"></span> Active</span>
                @endif
            </div>

            <p class="text-xs text-[var(--color-ink-muted)] mb-3 leading-relaxed">
                @if ($site->is_inactive)
                    Site is winding down. Routine maintenance alerts are silenced, but active incidents still alert.
                @else
                    Site is fully active. All health checks, issues, and maintenance alerts fire normally.
                @endif
            </p>

            @if ($site->is_inactive && $site->inactive_reason)
                <div class="p-2 rounded bg-[var(--color-surface-alt)]/60 text-[11px] text-[var(--color-ink-muted)] italic mb-3 truncate" title="{{ $site->inactive_reason }}">
                    "{{ $site->inactive_reason }}"
                </div>
            @endif

            <form method="POST" action="{{ route('sites.inactive.toggle', $site) }}" class="space-y-2">
                @csrf
                @if ($site->is_inactive)
                    <input type="hidden" name="inactive" value="0">
                    <button type="submit" class="btn-pill-nav text-xs w-full justify-center flex items-center gap-1.5">
                        <i class="fa-solid fa-sun text-amber-500"></i> Reactivate site
                    </button>
                @else
                    <input type="hidden" name="inactive" value="1">
                    <div class="space-y-1.5">
                        <input type="text" name="reason" maxlength="255" placeholder="Reason (e.g. client winding down)"
                               class="w-full px-2 py-1 rounded border border-[var(--color-border)] text-xs focus:outline-none focus:border-[var(--color-brand)]">
                        <button type="submit" class="btn-pill-nav text-xs w-full justify-center flex items-center gap-1.5">
                            <i class="fa-solid fa-moon text-indigo-500"></i> Mark inactive
                        </button>
                    </div>
                @endif
            </form>
        </div>

        <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between text-[11px]">
            <span class="text-[var(--color-ink-muted)]">Fleet Visibility</span>
            <span class="text-[var(--color-ink-soft)]">Always searchable</span>
        </div>
    </div>

    {{-- Card 8: Companion Plugin --}}
    <div class="card p-5 flex flex-col justify-between h-full">
        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-puzzle-piece text-violet-600"></i>
                    Companion mu-plugin
                </h3>
                @if ($site->companion_installed)
                    <span class="status-pill status-green text-[10px]"><span class="status-dot"></span> v{{ $site->companion_version }}</span>
                @else
                    <span class="status-pill status-unknown text-[10px]"><span class="status-dot"></span> Not installed</span>
                @endif
            </div>

            <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs space-y-1.5 mb-3">
                <div class="flex items-center justify-between text-[11px]">
                    <span class="text-[var(--color-ink-muted)]">Status:</span>
                    <span class="font-medium text-[var(--color-ink-strong)]">
                        @if ($site->companion_installed)
                            Installed & Active
                        @else
                            Not installed
                        @endif
                    </span>
                </div>
                <div class="flex items-center justify-between text-[11px]">
                    <span class="text-[var(--color-ink-muted)]">Last seen:</span>
                    <span class="text-[var(--color-ink-strong)]">
                        {{ $site->companion_last_seen_at?->diffForHumans() ?? 'never' }}
                    </span>
                </div>
            </div>

            <div class="space-y-2">
                @if ($site->companion_installed)
                    <button type="button"
                            id="companion-push-update-btn"
                            class="btn-pill-nav text-xs w-full justify-center flex items-center gap-1.5"
                            data-url="{{ route('sites.companion.push-update', $site) }}"
                            title="Force-refresh snapshot and push fresh backups report">
                        <i class="fa-solid fa-rotate"></i> Push update
                    </button>
                @endif

                @if ($site->host()->supports(\Modules\Core\Contracts\HostingProvider::CAP_COMPANION))
                    <button type="button"
                            id="companion-install-btn"
                            class="btn-pill-nav text-xs w-full justify-center flex items-center gap-1.5"
                            data-url="{{ route('sites.contact-form.install-companion', $site) }}"
                            title="Install or upgrade Clockwork Companion over SSH">
                        <i class="fa-solid fa-download"></i>
                        {{ $site->companion_installed ? 'Reinstall Companion' : 'Install Companion' }}
                    </button>
                @else
                    <div class="text-[10px] text-[var(--color-ink-soft)] text-center">
                        <i class="fa-solid fa-circle-info"></i> Install unavailable ({{ $site->host()->label() }} View-Only)
                    </div>
                @endif
            </div>

            <div id="companion-install-result" class="hidden text-xs mt-2"></div>
            <div id="companion-push-update-result" class="hidden text-xs mt-2"></div>
        </div>

        <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between text-[11px]">
            <span class="text-[var(--color-ink-muted)]">Tools & SSO Plugin</span>
            <span class="text-[var(--color-ink-soft)]">mu-plugin</span>
        </div>
    </div>

    {{-- Card 9: Contact Form Testing (if Care Plan Enabled) --}}
    @if ($site->care_plan_enabled)
        <div class="card p-5 flex flex-col justify-between h-full">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                        <i class="fa-solid fa-envelope-circle-check text-indigo-600"></i>
                        Contact forms
                    </h3>
                    <span class="status-pill status-green text-[10px]">
                        <span class="status-dot"></span> Dedicated Tab
                    </span>
                </div>

                <p class="text-xs text-[var(--color-ink-muted)] mb-3 leading-relaxed">
                    Automated synthetic submission tests verify that lead capture and notification delivery work continuously.
                </p>

                <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs flex items-center justify-between text-[11px]">
                    <span class="text-[var(--color-ink-muted)]">Max forms:</span>
                    <span class="font-medium text-[var(--color-ink-strong)]">Up to {{ \App\Models\ContactFormTest::MAX_PER_SITE }} forms</span>
                </div>
            </div>

            <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
                <span class="text-[11px] text-[var(--color-ink-muted)]">Forms Testing</span>
                <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'forms']) }}" class="btn-pill-nav text-xs font-medium text-indigo-600 hover:text-indigo-800 flex items-center gap-1">
                    Manage forms <i class="fa-solid fa-chevron-right text-[10px]"></i>
                </a>
            </div>
        </div>
    @endif

</div>

{{-- Danger Zone: Remove from Monitoring --}}
<div class="card p-5 border border-[var(--color-status-red)]/30 bg-rose-50/10 mb-8">
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div class="max-w-xl">
            <h3 class="font-display text-base font-semibold text-[var(--color-ink-strong)] flex items-center gap-2 mb-1">
                <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-red)]"></i>
                Remove from monitoring
            </h3>
            <p class="text-xs text-[var(--color-ink-muted)] leading-relaxed">
                Use this when the site has been deleted from the host, moved away, or should no longer appear in dashboards. The row is hidden from every listing (Sites, monitoring, issues), while historical scans and logs are retained for audit trail.
            </p>
        </div>
        <button type="button" id="archive-site-toggle" class="btn-pill-nav text-xs text-[var(--color-status-red)] border-[var(--color-status-red)]/40 hover:bg-rose-50 flex items-center gap-1.5">
            <i class="fa-solid fa-trash-can text-[11px]"></i> Remove site
        </button>
    </div>

    <form id="archive-site-form" method="POST" action="{{ route('sites.archive', $site) }}" class="mt-4 hidden pt-3 border-t border-rose-200/50">
        @csrf
        <p class="text-xs text-[var(--color-ink-muted)] mb-2">
            Type <code class="bg-[var(--color-surface-alt)] px-1.5 py-0.5 rounded text-[var(--color-ink-strong)] font-data">{{ $site->domain }}</code> below to confirm.
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <input type="text" name="confirm_domain" autocomplete="off" spellcheck="false"
                   placeholder="{{ $site->domain }}"
                   class="w-full font-data text-xs border border-[var(--color-border)] rounded-md px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-[var(--color-status-red)]/30">
            <input type="text" name="reason" autocomplete="off"
                   placeholder="Reason (optional, e.g. 'moved to new host')"
                   class="w-full text-xs border border-[var(--color-border)] rounded-md px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-[var(--color-status-red)]/30">
        </div>
        <div class="mt-3 flex items-center gap-2">
            <button type="submit" class="btn-primary text-xs py-1.5 px-3 bg-[var(--color-status-red)] hover:bg-[var(--color-status-red)]/90 flex items-center gap-1.5">
                <i class="fa-solid fa-check"></i> Confirm removal
            </button>
            <button type="button" id="archive-site-cancel" class="btn-pill-nav text-xs py-1.5 px-3">Cancel</button>
        </div>
    </form>
</div>

{{-- Scripts --}}
<script>
    (function () {
        // Cert toggle & edit
        const certToggle = document.getElementById('cert-edit-toggle');
        const certCancel = document.getElementById('cert-cancel');
        const certView = document.getElementById('cert-view');
        const certForm = document.getElementById('cert-form');

        function showCertEdit(editing) {
            certView.classList.toggle('hidden', editing);
            certForm.classList.toggle('hidden', !editing);
            certToggle.classList.toggle('hidden', editing);
        }

        certToggle?.addEventListener('click', () => showCertEdit(true));
        certCancel?.addEventListener('click', () => showCertEdit(false));

        // Cert recheck
        const certRecheck = document.getElementById('cert-recheck');
        const certResult = document.getElementById('cert-recheck-result');
        certRecheck?.addEventListener('click', async () => {
            certRecheck.disabled = true;
            certRecheck.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking…';
            certResult.className = 'mb-3 text-xs text-[var(--color-ink-muted)]';
            certResult.textContent = 'Asking SpinupWP for latest cert info…';
            certResult.classList.remove('hidden');
            try {
                const r = await fetch(certRecheck.dataset.url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                });
                const data = await r.json();
                if (data.ok) {
                    certResult.className = 'mb-3 text-xs status-pill status-green inline-block';
                    certResult.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + data.message + ' Reloading…';
                    setTimeout(() => location.reload(), 800);
                } else {
                    certResult.className = 'mb-3 text-xs status-pill status-red inline-block';
                    certResult.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> ' + (data.message || 'Failed.');
                }
            } catch (e) {
                certResult.className = 'mb-3 text-xs status-pill status-red inline-block';
                certResult.textContent = 'Network error: ' + e.message;
            } finally {
                certRecheck.disabled = false;
                certRecheck.innerHTML = '<i class="fa-solid fa-rotate"></i> Recheck now';
            }
        });

        // LLAR Install
        const llarBtn = document.getElementById('llar-install-btn');
        const llarResult = document.getElementById('llar-install-result');
        llarBtn?.addEventListener('click', async () => {
            if (!confirm('Install Limit Login Attempts Reloaded on this site? Email-on-lockout will be turned off.')) {
                return;
            }
            llarBtn.disabled = true;
            const original = llarBtn.innerHTML;
            llarBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Installing…';
            llarResult.className = 'text-xs mb-2 text-[var(--color-ink-muted)]';
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
                    llarResult.className = 'text-xs mb-2 status-pill ' + colorClass + ' inline-block';
                    llarResult.innerHTML = '<i class="fa-solid ' + icon + '"></i> ' + data.message + ' Reloading…';
                    setTimeout(() => location.reload(), 1200);
                } else {
                    llarResult.className = 'text-xs mb-2 status-pill status-red inline-block';
                    llarResult.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> ' + (data.message || 'Failed.');
                }
            } catch (e) {
                llarResult.className = 'text-xs mb-2 status-pill status-red inline-block';
                llarResult.textContent = 'Network error: ' + e.message;
            } finally {
                llarBtn.disabled = false;
                llarBtn.innerHTML = original;
            }
        });

        // Companion Push Update
        const companionPushBtn = document.getElementById('companion-push-update-btn');
        const companionPushResult = document.getElementById('companion-push-update-result');
        companionPushBtn?.addEventListener('click', async () => {
            const original = companionPushBtn.innerHTML;
            companionPushBtn.disabled = true;
            companionPushBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Pushing…';
            companionPushResult.className = 'mt-2 text-xs text-[var(--color-ink-muted)]';
            companionPushResult.textContent = 'Refreshing snapshot + pushing backups report…';
            companionPushResult.classList.remove('hidden');

            try {
                const r = await fetch(companionPushBtn.dataset.url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                });
                const data = await r.json();
                const cls = data.all_ok ? 'status-green' : (data.ok ? 'status-yellow' : 'status-red');
                const icon = data.all_ok ? 'fa-circle-check' : (data.ok ? 'fa-circle-info' : 'fa-circle-xmark');
                companionPushResult.className = 'mt-2 status-pill ' + cls + ' inline-block text-xs';
                companionPushResult.innerHTML = '<i class="fa-solid ' + icon + '"></i> ' + (data.message || 'Done.');
            } catch (e) {
                companionPushResult.className = 'mt-2 status-pill status-red inline-block text-xs';
                companionPushResult.textContent = 'Network error: ' + e.message;
            } finally {
                companionPushBtn.disabled = false;
                companionPushBtn.innerHTML = original;
            }
        });

        // Companion Install
        const companionInstallBtn = document.getElementById('companion-install-btn');
        const companionInstallResult = document.getElementById('companion-install-result');
        companionInstallBtn?.addEventListener('click', async () => {
            const original = companionInstallBtn.innerHTML;
            companionInstallBtn.disabled = true;
            companionInstallBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Installing…';
            companionInstallResult.className = 'text-xs mt-2 text-[var(--color-ink-muted)]';
            companionInstallResult.textContent = 'Pushing plugin over SSH and verifying via /health…';
            companionInstallResult.classList.remove('hidden');
            try {
                const r = await fetch(companionInstallBtn.dataset.url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                });
                const data = await r.json();
                if (r.ok) {
                    companionInstallResult.className = 'text-xs mt-2 text-[var(--color-status-green)]';
                    companionInstallResult.innerHTML = `<i class="fa-solid fa-circle-check"></i> ${data.message ?? 'Installed.'} Refreshing…`;
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    companionInstallResult.className = 'text-xs mt-2 text-[var(--color-status-red)]';
                    let html = `<i class="fa-solid fa-circle-xmark"></i> ${data.message ?? 'Failed'}`;
                    if (data.output) {
                        const escaped = data.output.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        html += `
                            <details class="mt-2">
                                <summary class="cursor-pointer text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">Show wp-cli / SSH output</summary>
                                <pre class="mt-1 p-2 bg-[var(--color-surface-alt)] rounded text-[10px] text-[var(--color-ink-muted)] whitespace-pre-wrap break-words">${escaped}</pre>
                            </details>`;
                    }
                    companionInstallResult.innerHTML = html;
                    companionInstallBtn.disabled = false;
                    companionInstallBtn.innerHTML = original;
                }
            } catch (e) {
                companionInstallResult.className = 'text-xs mt-2 text-[var(--color-status-red)]';
                companionInstallResult.textContent = 'Network error: ' + e.message;
                companionInstallBtn.disabled = false;
                companionInstallBtn.innerHTML = original;
            }
        });

        // Archive toggle
        const archiveToggle = document.getElementById('archive-site-toggle');
        const archiveForm   = document.getElementById('archive-site-form');
        const archiveCancel = document.getElementById('archive-site-cancel');
        if (archiveToggle && archiveForm && archiveCancel) {
            archiveToggle.addEventListener('click', () => {
                archiveForm.classList.remove('hidden');
                archiveToggle.classList.add('hidden');
                archiveForm.querySelector('input[name="confirm_domain"]')?.focus();
            });
            archiveCancel.addEventListener('click', () => {
                archiveForm.classList.add('hidden');
                archiveToggle.classList.remove('hidden');
                archiveForm.reset();
            });
        }
    })();
</script>
