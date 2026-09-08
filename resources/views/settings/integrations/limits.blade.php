@extends('layouts.app')

@section('title', $service['name'] . ' API Limits & Documentation · Clockwork')

@section('content')
    <div class="mb-8">
        <x-page-header :title="$service['name'] . ' API Limits & Quotas'"
            subtitle="Official rate limits, quota reset headers, fleet polling impact, and operator pacing tunables for this integration.">
            <x-slot:actions>
                <a href="{{ route('settings.integrations.index') }}" class="btn-pill-nav text-sm">
                    <i class="fa-solid fa-arrow-left text-[var(--color-ink-muted)]"></i>
                    <span>All Integrations</span>
                </a>
                <a href="{{ route('setup.index') }}" class="btn-pill-nav text-sm">
                    <i class="fa-solid fa-sliders text-[var(--color-ink-muted)]"></i>
                    <span>Setup Wizard</span>
                </a>
                <a href="{{ $service['rate_limit_docs_url'] }}" target="_blank" rel="noopener noreferrer" class="btn-pill-nav text-sm text-[var(--color-primary-600)]">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    <span>Official Vendor Docs</span>
                </a>
            </x-slot:actions>
        </x-page-header>

        @if (session('status'))
            <div class="card p-4 mb-6 status-green flex items-center gap-2">
                <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
            </div>
        @endif
        @if (session('status_error'))
            <div class="card p-4 mb-6 status-red flex items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation"></i> {{ session('status_error') }}
            </div>
        @endif

        {{-- 4-Column Metric Roll-up Tiles --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6 max-w-5xl">
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Official Rate Limit</div>
                <div class="text-xl sm:text-2xl font-display text-[var(--color-ink-strong)] font-data mt-0.5 truncate" title="{{ $service['official_limits']['standard'] }}">
                    {{ $service['official_limits']['standard'] }}
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">{{ $service['category'] }}</div>
            </div>
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Timeout Limit</div>
                <div class="text-xl sm:text-2xl font-display text-[var(--color-ink-strong)] font-data mt-0.5">
                    {{ $tunables['timeout'] }}s
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">
                    {{ $tunables['is_custom'] ? 'Custom operator override' : 'Default ' . $service['defaults']['timeout'] . 's' }}
                </div>
            </div>
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Max Concurrency</div>
                <div class="text-xl sm:text-2xl font-display text-[var(--color-ink-strong)] font-data mt-0.5">
                    {{ $tunables['concurrency'] }} <span class="text-xs font-normal text-[var(--color-ink-muted)]">parallel</span>
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">
                    {{ $tunables['delay_ms'] > 0 ? $tunables['delay_ms'] . 'ms inter-request delay' : 'Instant queuing' }}
                </div>
            </div>
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Auto Retries</div>
                <div class="text-xl sm:text-2xl font-display text-[var(--color-ink-strong)] font-data mt-0.5">
                    {{ $tunables['retry_attempts'] }} &times;
                </div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">
                    {{ $tunables['retry_attempts'] > 0 ? 'Exponential backoff' : 'Fail immediately' }}
                </div>
            </div>
        </div>

            {{-- Left Column: Credentials, Rate Limit Specs & Fleet Impact --}}
            <div class="lg:col-span-7 space-y-6">
                <!-- Card: API Credentials (.env file) -->
                <div class="card p-6 border-l-4 border-l-[var(--color-primary-600)]">
                    <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
                        <div class="flex items-center gap-2.5">
                            <div class="w-9 h-9 rounded-lg bg-[var(--color-primary-50)] text-[var(--color-primary-600)] flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-key text-base"></i>
                            </div>
                            <div>
                                <h2 class="font-display text-lg font-bold text-[var(--color-ink-strong)] leading-tight">
                                    API Credentials (.env file)
                                </h2>
                                <span class="text-xs text-[var(--color-ink-muted)]">Saved directly into root <code class="font-data text-xs">.env</code> &bull; No database storage</span>
                            </div>
                        </div>
                    </div>

                    @if (count($credentials) > 0)
                        <form method="POST" action="{{ route('settings.integrations.limits.update', $service['id']) }}" class="space-y-4">
                            @csrf
                            @method('PATCH')

                            @foreach ($credentials as $cred)
                                <div class="p-3.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] space-y-2">
                                    <div class="flex items-center justify-between flex-wrap gap-2">
                                        <div>
                                            <span class="text-xs font-bold text-[var(--color-ink-strong)]">{{ $cred['label'] }}</span>
                                            <code class="font-data text-[11px] text-[var(--color-ink-soft)] ml-1.5 px-1.5 py-0.5 rounded bg-white border border-[var(--color-border-light)]">{{ $cred['env_var'] }}</code>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            @if ($cred['configured'])
                                                <span class="status-pill status-green text-[10px] font-mono">
                                                    <i class="fa-solid fa-check"></i> Set in .env
                                                </span>
                                                <button type="submit" name="clear_cred_{{ $cred['field'] }}" value="1"
                                                        class="text-rose-600 hover:text-rose-700 hover:bg-rose-50 px-2 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer border border-rose-200"
                                                        onclick="return confirm('Remove {{ $cred['label'] }} from your .env file?');">
                                                    <i class="fa-solid fa-trash-can mr-1"></i> Remove from .env
                                                </button>
                                            @else
                                                <span class="status-pill status-unknown text-[10px] font-mono">
                                                    Not set
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    <input type="{{ $cred['secret'] ? 'password' : 'text' }}"
                                           name="cred_{{ $cred['field'] }}"
                                           placeholder="{{ $cred['configured'] ? 'Currently set in .env (' . ($cred['preview'] ?? 'configured') . ') — paste new value to replace' : 'Paste ' . $cred['label'] . ' here' }}"
                                           class="w-full font-data text-xs text-[var(--color-ink-strong)] border border-[var(--color-border)] rounded-md px-3 py-2 bg-white focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">

                                    <div class="flex items-center justify-between text-[11px] text-[var(--color-ink-soft)] flex-wrap gap-1">
                                        <span>{{ $cred['guide'] }}</span>
                                        @if (!empty($cred['url']))
                                            <a href="{{ $cred['url'] }}" target="_blank" rel="noopener noreferrer" class="text-[var(--color-primary-600)] hover:underline flex items-center gap-1 font-medium">
                                                <span>Get API Key</span>
                                                <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            <div class="flex justify-between items-center pt-2 border-t border-[var(--color-border-light)] flex-wrap gap-2">
                                @if (!empty($testable))
                                    <button type="submit" formaction="{{ route('settings.integrations.test', $service['id']) }}" formmethod="POST"
                                            class="btn-pill-nav text-xs font-semibold text-[var(--color-ink-strong)] hover:text-[var(--color-primary-600)] hover:bg-white border border-[var(--color-border)] shadow-2xs flex items-center gap-1.5 px-3 py-2 cursor-pointer">
                                        <i class="fa-solid fa-bolt text-amber-500"></i>
                                        <span>Test Connection</span>
                                    </button>
                                @else
                                    <div></div>
                                @endif
                                <button type="submit" class="btn btn-primary text-xs px-4 py-2">
                                    <i class="fa-solid fa-floppy-disk mr-1.5"></i> Save Credentials to .env
                                </button>
                            </div>
                        </form>
                    @else
                        <div class="p-3.5 bg-[var(--color-surface-alt)] rounded-lg border border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)] flex items-center gap-2">
                            <i class="fa-solid fa-circle-info text-[var(--color-primary-600)]"></i>
                            <span>Zero API credentials required — this service operates publicly without an access key.</span>
                        </div>
                    @endif
                </div>

                @if ($isCloudProvider)
                    <!-- Card: How this integrates (Cloud Provider Architecture) -->
                    <div class="p-5 rounded-xl bg-blue-50/80 border border-blue-200 text-slate-800 space-y-3">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/15 text-blue-700 flex items-center justify-center text-sm flex-shrink-0">
                                <i class="fa-solid fa-cloud"></i>
                            </div>
                            <div>
                                <h3 class="font-display font-bold text-sm text-blue-950 leading-tight">
                                    How {{ $service['name'] }} Integrates with Clockwork Control
                                </h3>
                                <span class="text-xs text-slate-600">Hardware Telemetry &bull; Alive/Dead Status Checks &bull; IP Matching</span>
                            </div>
                        </div>
                        <p class="text-xs text-slate-700 leading-relaxed">
                            <strong>Hosting Panels vs Cloud Infrastructure:</strong> Server management panels (like <strong>SpinupWP</strong> or <strong>GridPane</strong>) manage your WordPress sites, Nginx configs, and database credentials. <strong>{{ $service['name'] }}</strong> manages the underlying virtual machines and hardware specifications (vCPUs, RAM, disk, alive state).
                        </p>
                        <div class="grid sm:grid-cols-2 gap-2.5 pt-1 text-xs">
                            <div class="p-3 rounded-lg bg-white/90 border border-blue-100 space-y-1">
                                <div class="font-semibold text-blue-950 flex items-center gap-1.5">
                                    <i class="fa-solid fa-server text-blue-600 text-xs"></i>
                                    <span>Hosting Panel Managed Server</span>
                                </div>
                                <p class="text-[11px] text-slate-600 leading-normal">
                                    When you import from SpinupWP or GridPane, Clockwork matches servers to {{ $service['name'] }} instances by IP address to monitor CPU, RAM, and droplet health.
                                </p>
                            </div>
                            <div class="p-3 rounded-lg bg-white/90 border border-blue-100 space-y-1">
                                <div class="font-semibold text-blue-950 flex items-center gap-1.5">
                                    <i class="fa-solid fa-cloud-arrow-down text-blue-600 text-xs"></i>
                                    <span>Standalone Cloud Server</span>
                                </div>
                                <p class="text-[11px] text-slate-600 leading-normal">
                                    Import any standalone {{ $service['name'] }} instance directly into your Server Fleet below to track uptime and hardware specifications.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Card: Detected Cloud Instances -->
                    <div class="card p-6 border-l-4 border-l-blue-600">
                        <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
                                    <i class="fa-solid fa-network-wired text-base"></i>
                                </div>
                                <div>
                                    <h2 class="font-display text-lg font-bold text-[var(--color-ink-strong)] leading-tight">
                                        Detected {{ $service['name'] }} Instances
                                    </h2>
                                    <span class="text-xs text-[var(--color-ink-muted)]">Live cloud resources queried from {{ $service['name'] }} API ({{ count($detectedInstances) }} detected)</span>
                                </div>
                            </div>

                            <form method="POST" action="{{ route('settings.integrations.reconcile', $service['id']) }}">
                                @csrf
                                <button type="submit"
                                        class="btn-pill-nav text-xs font-semibold text-[var(--color-ink-strong)] hover:text-[var(--color-primary-600)] hover:bg-[var(--color-surface-alt)] border border-[var(--color-border)] shadow-2xs flex items-center gap-1.5 px-3 py-1.5 cursor-pointer">
                                    <i class="fa-solid fa-arrows-rotate text-[var(--color-primary-500)]"></i>
                                    <span>Reconcile Hardware Specs</span>
                                </button>
                            </form>
                        </div>

                        @if (count($detectedInstances) > 0)
                            <div class="space-y-3">
                                @foreach ($detectedInstances as $inst)
                                    <div class="p-4 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] space-y-2.5">
                                        <div class="flex items-center justify-between flex-wrap gap-2">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="font-display font-bold text-sm text-[var(--color-ink-strong)]">{{ $inst['name'] ?: ($inst['ip'] ?: $inst['id']) }}</span>
                                                @if (!empty($inst['ip']))
                                                    <code class="font-data text-xs text-[var(--color-ink-soft)] px-1.5 py-0.5 rounded bg-white border border-[var(--color-border-light)]">{{ $inst['ip'] }}</code>
                                                @endif
                                                @if (!empty($inst['region']))
                                                    <span class="status-pill status-blue text-[10px] font-mono">{{ $inst['region'] }}</span>
                                                @endif
                                            </div>

                                            <div class="flex items-center gap-2">
                                                @if ($inst['is_linked'])
                                                    <span class="status-pill status-green text-xs font-mono flex items-center gap-1">
                                                        <i class="fa-solid fa-link text-[10px]"></i>
                                                        <span>Linked to Fleet</span>
                                                    </span>
                                                @else
                                                    <span class="status-pill status-yellow text-xs font-mono flex items-center gap-1">
                                                        <i class="fa-solid fa-unlink text-[10px]"></i>
                                                        <span>Not in Fleet</span>
                                                    </span>
                                                @endif
                                            </div>
                                        </div>

                                        <!-- Specs Row -->
                                        <div class="flex items-center gap-2.5 text-xs text-[var(--color-ink-muted)] flex-wrap">
                                            @if (!empty($inst['plan']))
                                                <span class="font-mono text-xs px-2 py-0.5 rounded bg-white border border-[var(--color-border-light)] font-semibold text-[var(--color-ink-strong)]">{{ $inst['plan'] }}</span>
                                            @endif
                                            @if ($inst['vcpus'])
                                                <span><strong>{{ $inst['vcpus'] }}</strong> vCPU</span>
                                            @endif
                                            @if ($inst['memory_mb'])
                                                <span>&bull; <strong>{{ round($inst['memory_mb'] / 1024, 1) }} GB</strong> RAM</span>
                                            @endif
                                            @if ($inst['disk_gb'])
                                                <span>&bull; <strong>{{ $inst['disk_gb'] }} GB</strong> Disk</span>
                                            @endif
                                            @if (!empty($inst['status']))
                                                <span class="text-[10px] uppercase font-mono px-1.5 py-0.5 rounded {{ in_array($inst['status'], ['active', 'running']) ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600' }}">
                                                    {{ $inst['status'] }}
                                                </span>
                                            @endif
                                            @foreach ($inst['tags'] as $tag)
                                                <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 border border-indigo-100">#{{ $tag }}</span>
                                            @endforeach
                                        </div>

                                        <!-- Actions Row -->
                                        <div class="pt-2 border-t border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-2">
                                            @if ($inst['is_linked'] && !empty($inst['linked_server']))
                                                <div class="text-xs text-[var(--color-ink-muted)] flex items-center gap-1.5">
                                                    <i class="fa-solid fa-check-circle text-emerald-600 text-sm"></i>
                                                    <span>Server #{{ $inst['linked_server']['id'] }}: <strong>{{ $inst['linked_server']['name'] }}</strong></span>
                                                </div>
                                                <a href="{{ $inst['linked_server']['url'] }}" class="btn-pill-nav text-xs px-3 py-1 text-[var(--color-primary-600)] hover:underline flex items-center gap-1">
                                                    <span>View Server</span>
                                                    <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                                </a>
                                            @else
                                                <div class="flex items-center justify-between w-full flex-wrap gap-2">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        @if ($inst['suggested_panel'] === 'spinupwp' && !empty($hostingPanels['spinupwp']['enabled']))
                                                            <form method="POST" action="{{ route('servers.refreshFromSpinupWp') }}">
                                                                @csrf
                                                                <button type="submit" class="btn-pill-nav text-xs font-semibold text-emerald-700 hover:bg-emerald-50 border border-emerald-300 shadow-2xs flex items-center gap-1.5 px-3 py-1.5 cursor-pointer">
                                                                    <i class="fa-solid fa-arrows-rotate text-emerald-600"></i>
                                                                    <span>Sync from SpinupWP</span>
                                                                </button>
                                                            </form>
                                                        @elseif ($inst['suggested_panel'] === 'gridpane' && !empty($hostingPanels['gridpane']['enabled']))
                                                            <form method="POST" action="{{ route('servers.refreshFromGridPane') }}">
                                                                @csrf
                                                                <button type="submit" class="btn-pill-nav text-xs font-semibold text-emerald-700 hover:bg-emerald-50 border border-emerald-300 shadow-2xs flex items-center gap-1.5 px-3 py-1.5 cursor-pointer">
                                                                    <i class="fa-solid fa-arrows-rotate text-emerald-600"></i>
                                                                    <span>Sync from GridPane</span>
                                                                </button>
                                                            </form>
                                                        @endif
                                                    </div>
                                                    <form method="POST" action="{{ route('settings.integrations.importInstance', $service['id']) }}">
                                                        @csrf
                                                        <input type="hidden" name="instance_id" value="{{ $inst['id'] }}">
                                                        <button type="submit" class="btn btn-primary text-xs px-3 py-1.5 shadow-2xs flex items-center gap-1.5">
                                                            <i class="fa-solid fa-plus"></i>
                                                            <span>Import as Standalone Server</span>
                                                        </button>
                                                    </form>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="p-4 bg-[var(--color-surface-alt)] rounded-lg border border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)] flex items-center gap-2">
                                <i class="fa-solid fa-circle-info text-[var(--color-ink-soft)]"></i>
                                @if (collect($credentials)->some(fn($c) => $c['configured']))
                                    <span>No cloud instances found on this {{ $service['name'] }} account.</span>
                                @else
                                    <span>Configure and save your API credentials above to discover cloud instances automatically.</span>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif

                <!-- Card: Official Vendor Rate Limits & Headers -->
                <div class="card p-6">
                    <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-[var(--color-surface-alt)] flex items-center justify-center flex-shrink-0">
                                <x-service-logo :service="$service['id']" class="w-7 h-7" />
                            </div>
                            <div>
                                <h2 class="font-display text-lg font-bold text-[var(--color-ink-strong)] leading-tight">
                                    Official Rate Limit Specifications
                                </h2>
                                <span class="text-xs text-[var(--color-ink-muted)]">{{ $service['category'] }} API Documentation</span>
                            </div>
                        </div>
                        <span class="status-pill status-blue text-xs font-data">
                            HTTP {{ $service['official_limits']['exceeded_code'] }}
                        </span>
                    </div>

                    <div class="space-y-4 text-sm text-[var(--color-ink)]">
                        <div class="p-3.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                            <div class="text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider mb-1">Quota &amp; Enforcement Window</div>
                            <div class="font-display font-bold text-base text-[var(--color-ink-strong)] mb-1">
                                {{ $service['official_limits']['standard'] }}
                            </div>
                            <p class="text-xs text-[var(--color-ink-muted)] leading-relaxed">
                                {{ $service['official_limits']['window'] }}
                            </p>
                        </div>

                        <div>
                            <div class="text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider mb-2">Rate Limit Response Headers Monitored</div>
                            <div class="flex flex-wrap gap-2">
                                @forelse ($service['official_limits']['headers'] as $header)
                                    <span class="font-data text-xs px-2.5 py-1 rounded bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-[var(--color-ink-strong)] font-semibold">
                                        <i class="fa-solid fa-code text-[var(--color-primary-500)] text-[10px] mr-1"></i>{{ $header }}
                                    </span>
                                @empty
                                    <span class="text-xs text-[var(--color-ink-muted)] italic">Standard HTTP status codes (429 Too Many Requests)</span>
                                @endforelse
                            </div>
                        </div>

                        <div>
                            <div class="text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider mb-1">Burst Handling &amp; Concurrency Notes</div>
                            <p class="text-xs text-[var(--color-ink-muted)] leading-relaxed">
                                {{ $service['official_limits']['burst_notes'] }}
                            </p>
                        </div>

                        <div class="pt-2 border-t border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-3">
                            <a href="{{ $service['rate_limit_docs_url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-[var(--color-primary-600)] hover:underline flex items-center gap-1.5">
                                <i class="fa-solid fa-book-open"></i>
                                View official vendor rate limits &rarr;
                            </a>
                            <a href="{{ $service['docs_url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] flex items-center gap-1">
                                <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                Full API Reference
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Card: Fleet Telemetry Impact & Scale Projections -->
                <div class="card p-6 border-l-4 border-l-[var(--color-primary-500)]">
                    <h3 class="font-display text-base font-bold text-[var(--color-ink-strong)] mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-network-wired text-[var(--color-primary-500)]"></i>
                        Fleet Impact &amp; Polling Telemetry Costs
                    </h3>
                    <p class="text-xs text-[var(--color-ink-muted)] leading-relaxed mb-4">
                        Clockwork Control monitors your servers, droplets, and sites at regular background cron intervals. Here is how your fleet consumes this API:
                    </p>

                    <div class="space-y-3.5 text-xs text-[var(--color-ink-muted)]">
                        <div class="p-3 rounded-lg bg-[var(--color-surface-alt)]/60 border border-[var(--color-border-light)]">
                            <span class="font-semibold text-[var(--color-ink-strong)] block mb-1">Per-Resource API Footprint:</span>
                            <span class="leading-relaxed">{{ $service['fleet_impact']['calls_per_server'] }}</span>
                        </div>

                        <div class="p-3 rounded-lg bg-[var(--color-surface-alt)]/60 border border-[var(--color-border-light)]">
                            <span class="font-semibold text-[var(--color-ink-strong)] block mb-1">Fleet Scale Projection:</span>
                            <span class="leading-relaxed">{{ $service['fleet_impact']['fleet_projection'] }}</span>
                        </div>

                        <div class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-900">
                            <span class="font-bold block mb-1 text-emerald-950 flex items-center gap-1.5">
                                <i class="fa-solid fa-lightbulb text-emerald-600"></i> Recommended Production Strategy:
                            </span>
                            <span class="leading-relaxed">{{ $service['fleet_impact']['recommendation'] }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right Column: Operator Tunables & Quick Navigation --}}
            <div class="lg:col-span-5 space-y-6">
                <!-- Card: Operator Connection Tunables Form -->
                <div class="card p-6">
                    <div class="flex items-center justify-between gap-2 mb-4">
                        <h2 class="font-display text-lg font-bold text-[var(--color-ink-strong)]">
                            API Connection Tunables
                        </h2>
                        @if ($tunables['is_custom'])
                            <span class="status-pill status-blue text-[11px] font-mono">
                                <i class="fa-solid fa-pen-nib"></i> Customized
                            </span>
                        @else
                            <span class="status-pill status-green text-[11px] font-mono">
                                <i class="fa-solid fa-check"></i> Factory Defaults
                            </span>
                        @endif
                    </div>

                    <p class="text-xs text-[var(--color-ink-muted)] leading-relaxed mb-5">
                        Adjust Clockwork's HTTP client timeouts, rate pacing, and retry behaviors to match your server fleet scale and avoid 429 errors.
                    </p>

                    <form method="POST" action="{{ route('settings.integrations.limits.update', $service['id']) }}" class="space-y-4">
                        @csrf
                        @method('PATCH')

                        <!-- Rate Limit Cap -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label for="field-rate-limit" class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">
                                    Rate Limit Ceiling
                                </label>
                                <span class="text-[11px] font-data text-[var(--color-ink-soft)]">{{ $service['defaults']['rate_limit_unit'] }}</span>
                            </div>
                            <input type="number" name="rate_limit" id="field-rate-limit" min="1" max="1000000"
                                   value="{{ old('rate_limit', $tunables['rate_limit']) }}"
                                   class="w-full font-data text-sm text-[var(--color-ink-strong)] border border-[var(--color-border)] rounded-md px-3 py-2 bg-white focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                            <span class="text-[11px] text-[var(--color-ink-soft)] mt-1 block">Default: {{ number_format($service['defaults']['rate_limit']) }} {{ $service['defaults']['rate_limit_unit'] }}</span>
                        </div>

                        <!-- Timeout -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label for="field-timeout" class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">
                                    HTTP Request Timeout
                                </label>
                                <span class="text-[11px] font-data text-[var(--color-ink-soft)]">Seconds</span>
                            </div>
                            <input type="number" name="timeout" id="field-timeout" min="1" max="300"
                                   value="{{ old('timeout', $tunables['timeout']) }}"
                                   class="w-full font-data text-sm text-[var(--color-ink-strong)] border border-[var(--color-border)] rounded-md px-3 py-2 bg-white focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                            <span class="text-[11px] text-[var(--color-ink-soft)] mt-1 block">Default: {{ $service['defaults']['timeout'] }}s (Max allowed: 300s)</span>
                        </div>

                        <!-- Concurrency -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label for="field-concurrency" class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">
                                    Worker Concurrency
                                </label>
                                <span class="text-[11px] font-data text-[var(--color-ink-soft)]">Parallel Requests</span>
                            </div>
                            <input type="number" name="concurrency" id="field-concurrency" min="1" max="10"
                                   value="{{ old('concurrency', $tunables['concurrency']) }}"
                                   class="w-full font-data text-sm text-[var(--color-ink-strong)] border border-[var(--color-border)] rounded-md px-3 py-2 bg-white focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                            <span class="text-[11px] text-[var(--color-ink-soft)] mt-1 block">Default: {{ $service['defaults']['concurrency'] }} parallel connections</span>
                        </div>

                        <!-- Inter-request delay pacing -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label for="field-delay" class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">
                                    Inter-Request Pacing Delay
                                </label>
                                <span class="text-[11px] font-data text-[var(--color-ink-soft)]">Milliseconds</span>
                            </div>
                            <input type="number" name="delay_ms" id="field-delay" min="0" max="5000" step="10"
                                   value="{{ old('delay_ms', $tunables['delay_ms']) }}"
                                   class="w-full font-data text-sm text-[var(--color-ink-strong)] border border-[var(--color-border)] rounded-md px-3 py-2 bg-white focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                            <span class="text-[11px] text-[var(--color-ink-soft)] mt-1 block">Sleeps between successive API calls to smooth bursts (Default: {{ $service['defaults']['delay_ms'] }}ms)</span>
                        </div>

                        <!-- Retries -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label for="field-retries" class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)]">
                                    Automatic Retry Attempts
                                </label>
                                <span class="text-[11px] font-data text-[var(--color-ink-soft)]">Attempts</span>
                            </div>
                            <input type="number" name="retry_attempts" id="field-retries" min="0" max="5"
                                   value="{{ old('retry_attempts', $tunables['retry_attempts']) }}"
                                   class="w-full font-data text-sm text-[var(--color-ink-strong)] border border-[var(--color-border)] rounded-md px-3 py-2 bg-white focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                            <span class="text-[11px] text-[var(--color-ink-soft)] mt-1 block">Retries with exponential backoff on 429 or 503 (Default: {{ $service['defaults']['retry_attempts'] }})</span>
                        </div>

                        <div class="pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between gap-3">
                            <button type="submit" class="btn btn-primary text-sm px-4 py-2">
                                <i class="fa-solid fa-floppy-disk mr-1.5"></i> Save Settings
                            </button>
                    </form>

                    <form method="POST" action="{{ route('settings.integrations.limits.reset', $service['id']) }}" onsubmit="return confirm('Reset {{ $service['name'] }} rate limit tunables back to factory defaults?');">
                        @csrf
                        <button type="submit" class="btn-pill-nav text-xs text-[var(--color-ink-muted)] hover:text-rose-600">
                            <i class="fa-solid fa-arrow-rotate-left mr-1"></i> Reset Defaults
                        </button>
                    </form>
                        </div>
                </div>

                <!-- Quick Service Switcher -->
                <div class="card p-5">
                    <h3 class="font-display text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)] mb-3">
                        Switch Integration Limits
                    </h3>
                    <div class="grid grid-cols-2 gap-1.5 max-h-64 overflow-y-auto pr-1">
                        @foreach ($allServices as $otherId => $other)
                            <a href="{{ route('settings.integrations.limits', $otherId) }}"
                               class="flex items-center gap-2 p-2 rounded-md text-xs transition-colors {{ $otherId === $service['id'] ? 'bg-[var(--color-primary-50)] text-[var(--color-primary-700)] font-semibold' : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)]' }}">
                                <x-service-logo :service="$otherId" class="w-4 h-4 flex-shrink-0" />
                                <span class="truncate">{{ $other['name'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
