<div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Sites</h2>
        <div class="text-sm text-[var(--color-ink-soft)]">{{ $server->sites->count() }} total</div>
    </div>

    @if ($server->sites->isEmpty())
        <div class="p-10 text-center text-[var(--color-ink-soft)]">
            <p>No sites mapped to this server.</p>
            @if ($server->spinupwp_id && app(\Modules\Core\ModuleStateResolver::class)->isEnabled('spinupwp'))
                <form method="POST" action="{{ route('servers.refreshFromSpinupWp') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="btn-pill-nav text-xs"
                            onclick="this.disabled=true; this.querySelector('i').classList.add('fa-spin'); this.querySelector('span').textContent = 'Refreshing…';">
                        <i class="fa-solid fa-rotate"></i> <span>Sync sites from SpinupWP</span>
                    </button>
                </form>
            @elseif ($server->isGridPane() && app(\Modules\Core\ModuleStateResolver::class)->isEnabled('gridpane'))
                <form method="POST" action="{{ route('servers.refreshFromGridPane') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="btn-pill-nav text-xs"
                            onclick="this.disabled=true; this.querySelector('i').classList.add('fa-spin'); this.querySelector('span').textContent = 'Refreshing…';">
                        <i class="fa-solid fa-rotate"></i> <span>Sync sites from GridPane</span>
                    </button>
                </form>
            @endif
        </div>
    @else
        @php
            $usage = collect($server->sites)->map(function ($site) use ($poolStats, $requestsBySite) {
                $pool = $poolStats[$site->site_user] ?? null;
                return [
                    'domain' => $site->domain,
                    'cpu' => $pool['cpu'] ?? null,
                    'mem' => $pool['mem'] ?? null,
                    'workers' => $pool['workers'] ?? 0,
                    'cron_workers' => $pool['cron_workers'] ?? 0,
                    'requests_1h' => (int) ($requestsBySite[$site->id] ?? 0),
                ];
            });

            $hasLiveData = collect($poolStats)->isNotEmpty();
            $topByCpu = $usage->sortByDesc('cpu')->take(3)->values();
        @endphp

        @if ($hasLiveData)
            <div class="px-5 py-3 border-b border-[var(--color-border-light)] bg-[var(--color-surface-alt)] flex items-center gap-3 text-xs">
                <span class="text-[var(--color-ink-soft)] uppercase tracking-wide">Top CPU now</span>
                @foreach ($topByCpu as $row)
                    @if ($row['cpu'] !== null)
                        <span class="font-data text-[var(--color-ink-strong)]">
                            {{ $row['domain'] }}
                            <span class="text-[var(--color-ink-muted)]">({{ number_format($row['cpu'], 0) }}% cpu · {{ $row['workers'] }} {{ Str::plural('worker', $row['workers']) }}@if ($row['cron_workers'] > 0) <span class="text-[var(--color-status-yellow)]" title="wp-cron processes active — scheduled WordPress jobs running">+ cron</span>@endif)</span>
                        </span>
                    @endif
                @endforeach
                <span class="ml-auto text-[var(--color-ink-soft)]">live · cached 30s</span>
            </div>
        @endif

        <ul class="divide-y divide-[var(--color-border-light)]">
            @foreach ($server->sites as $site)
                @php
                    $pool = $poolStats[$site->site_user] ?? null;
                    $reqCount = (int) ($requestsBySite[$site->id] ?? 0);
                    $sslState = $site->sslState();
                    $sslMeta = match (true) {
                        $site->cert_source === 'redirect_only' => ['class' => 'status-unknown', 'icon' => 'fa-arrow-up-right-from-square', 'label' => 'redirect'],
                        $sslState === 'green' => ['class' => 'status-green', 'icon' => 'fa-lock', 'label' => 'SSL'],
                        $sslState === 'yellow' => ['class' => 'status-yellow', 'icon' => 'fa-clock-rotate-left', 'label' => 'SSL renew'],
                        $sslState === 'red' => ['class' => 'status-red', 'icon' => 'fa-lock-open', 'label' => 'SSL expired'],
                        default => null,
                    };
                    $sslTitle = match (true) {
                        $site->cert_source === 'redirect_only' => 'Redirect-only — SSL monitoring skipped',
                        $sslState === 'green' => 'Cert OK; expires ' . optional($site->cert_expires_at)->diffForHumans(),
                        $sslState === 'yellow' => 'Renewal window passed without rollover. Expires ' . optional($site->cert_expires_at)->diffForHumans(),
                        $sslState === 'red' => 'Cert expired ' . optional($site->cert_expires_at)->diffForHumans(),
                        default => 'No cert tracked',
                    };
                    $cfMeta = match ($site->cloudflare_state) {
                        'proxied' => ['class' => 'text-orange-500', 'icon' => 'fa-cloud', 'title' => 'Cloudflare proxied (orange cloud) — traffic flows through CF'],
                        'dns_only' => ['class' => 'text-yellow-500', 'icon' => 'fa-cloud', 'title' => 'Cloudflare DNS only (grey cloud) — proxy NOT enabled, traffic hits origin directly'],
                        'not_using' => ['class' => 'text-[var(--color-ink-soft)]', 'icon' => 'fa-cloud-slash', 'title' => 'Not on Cloudflare — traffic hits origin directly'],
                        default => null,
                    };

                    $ssoCapable = $site->companion_installed
                        && is_array($site->companion_capabilities ?? null)
                        && in_array('sso', $site->companion_capabilities, true);
                    $ssoAdmins = is_array($site->companion_snapshot['admins']['admins'] ?? null)
                        ? $site->companion_snapshot['admins']['admins']
                        : [];
                    $ssoFirstAdmin = $ssoAdmins[0]['login'] ?? null;
                @endphp
                <li class="relative px-5 py-3 flex items-center gap-3 hover:bg-[var(--color-surface-alt)] transition-colors">
                    <a href="{{ route('sites.show', $site) }}"
                       class="absolute inset-0 z-0"
                       aria-label="Open {{ $site->domain }}"></a>

                    {{-- Icon and domain text are inert overlays on top of the
                         row-link. pointer-events-none lets clicks fall through
                         to the <a> underneath so the whole row navigates. --}}
                    @if ($site->is_wordpress)
                        <i class="fa-brands fa-wordpress text-[var(--color-brand)] text-lg relative z-10 pointer-events-none" title="WordPress site"></i>
                    @else
                        <i class="fa-solid fa-globe text-[var(--color-ink-soft)] text-lg relative z-10 pointer-events-none" title="Non-WordPress site"></i>
                    @endif

                    <span class="font-medium text-[var(--color-ink-strong)] truncate flex-1 relative z-10 pointer-events-none">{{ $site->domain }}</span>

                    <div class="flex items-center gap-2 relative z-10">
                        @if ($pool)
                            @php $cronCount = $pool['cron_workers'] ?? 0; @endphp
                            <span class="text-xs font-data tabular-nums text-[var(--color-ink-muted)]"
                                  title="{{ $pool['workers'] }} PHP-FPM {{ Str::plural('worker', $pool['workers']) }}@if ($cronCount > 0) + {{ $cronCount }} wp-cron {{ Str::plural('process', $cronCount) }}@endif for '{{ $site->site_user }}'">
                                <span class="text-[var(--color-ink-soft)]">cpu</span>
                                <span class="@if ($pool['cpu'] >= 80) text-[var(--color-status-red)] @elseif ($pool['cpu'] >= 40) text-[var(--color-status-yellow)] @endif">{{ number_format($pool['cpu'], 0) }}%</span>
                                @if ($cronCount > 0)
                                    <span class="text-[var(--color-status-yellow)]" title="wp-cron running scheduled jobs">⏱</span>
                                @endif
                            </span>
                            <span class="text-xs font-data tabular-nums text-[var(--color-ink-muted)]" title="memory used by this pool">
                                <span class="text-[var(--color-ink-soft)]">mem</span> {{ number_format($pool['mem'], 1) }}%
                            </span>
                        @elseif ($hasLiveData)
                            <span class="text-xs font-data text-[var(--color-ink-soft)]" title="No PHP-FPM workers active for this site (idle)">idle</span>
                        @endif

                        @if ($reqCount > 0)
                            <span class="text-xs font-data tabular-nums text-[var(--color-ink-muted)]" title="Requests last hour (from nginx threat_logs)">
                                <span class="text-[var(--color-ink-soft)]">1h</span> {{ number_format($reqCount) }}
                            </span>
                        @endif

                        @if ($cfMeta)
                            <i class="fa-solid {{ $cfMeta['icon'] }} {{ $cfMeta['class'] }}" title="{{ $cfMeta['title'] }}"></i>
                        @endif
                        @if ($sslMeta)
                            <span class="status-pill {{ $sslMeta['class'] }} text-[10px]" title="{{ $sslTitle }}">
                                <i class="fa-solid {{ $sslMeta['icon'] }}"></i>
                                {{ $sslMeta['label'] }}
                            </span>
                        @endif
                        @if ($site->wordfence_enabled)
                            <span class="status-pill status-green text-[10px]">Wordfence</span>
                        @endif
                        @if ($site->llar_enabled)
                            <span class="status-pill status-green text-[10px]">LLAR</span>
                        @endif
                        @unless ($site->is_wordpress)
                            <span class="text-xs text-[var(--color-ink-soft)] uppercase tracking-wide">non-WP</span>
                        @endunless

                        @if ($ssoCapable && $ssoFirstAdmin && count($ssoAdmins) === 1)
                            <form method="POST" action="{{ route('sites.companion.sso', $site) }}" target="_blank" class="inline">
                                @csrf
                                <button type="submit"
                                        class="btn-pill-nav text-xs"
                                        title="One-click login to wp-admin as {{ $ssoFirstAdmin }}">
                                    <i class="fa-solid fa-right-to-bracket"></i>
                                </button>
                            </form>
                        @elseif ($ssoCapable && count($ssoAdmins) > 1)
                            <a href="{{ route('sites.show', $site) }}"
                               class="btn-pill-nav text-xs"
                               title="Multiple admins — open the site page to pick which one to log in as">
                                <i class="fa-solid fa-right-to-bracket"></i>
                            </a>
                        @endif

                        <i class="fa-solid fa-chevron-right text-[var(--color-ink-soft)] text-xs"></i>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
