@extends('layouts.app')

@section('title', 'Issues · Clockwork')

@section('content')
    @php
        $categoryDefinitions = [
            // Tier 1: Critical & Security (Major Issues)
            'down_sites' => [
                'label' => 'Sites Down',
                'tier' => 'critical',
                'class' => 'status-red',
                'icon' => 'fa-circle-exclamation',
                'html_id' => 'section-down-sites',
                'description' => 'Sites currently reporting down or unreachable',
            ],
            'scheduler_stale' => [
                'label' => 'Scheduler Stale',
                'tier' => 'critical',
                'class' => 'status-red',
                'icon' => 'fa-clock',
                'html_id' => 'section-scheduler_stale',
                'description' => 'Crontab is not spawning schedule:run',
            ],
            'malware' => [
                'label' => 'Malware / Blacklist',
                'tier' => 'critical',
                'class' => 'status-red',
                'icon' => 'fa-bug',
                'html_id' => 'section-malware',
                'description' => 'Sucuri SiteCheck flagged malware or blacklist hit',
            ],
            'companion_malware' => [
                'label' => 'Malware Findings',
                'tier' => 'critical',
                'class' => 'status-red',
                'icon' => 'fa-shield-virus',
                'html_id' => 'section-companion_malware',
                'description' => 'Companion PHP-in-uploads or obfuscation findings',
            ],
            'tampering' => [
                'label' => 'Core Tampering',
                'tier' => 'critical',
                'class' => 'status-red',
                'icon' => 'fa-file-shield',
                'html_id' => 'section-tampering',
                'description' => 'Modified, missing, or unexpected WordPress core files',
            ],
            'health' => [
                'label' => 'Server Health',
                'tier' => 'critical',
                'class' => 'status-red',
                'icon' => 'fa-heart-pulse',
                'html_id' => 'section-health',
                'description' => 'Servers with status red / offline',
            ],
            'forms_failing' => [
                'label' => 'Form Tests Failing',
                'tier' => 'critical',
                'class' => 'status-red',
                'icon' => 'fa-envelope-circle-check',
                'html_id' => 'section-forms_failing',
                'description' => 'Care-plan contact form tests failing repeatedly',
            ],
            'stuck_maintenance' => [
                'label' => 'Stuck in Maintenance',
                'tier' => 'critical',
                'class' => 'status-red',
                'icon' => 'fa-wrench',
                'html_id' => 'section-stuck_maintenance',
                'description' => 'Sites lingering in maintenance mode > 2 hours',
            ],
            'seo-indexability' => [
                'label' => 'SEO Blocked',
                'tier' => 'critical',
                'class' => 'status-red',
                'icon' => 'fa-magnifying-glass-chart',
                'html_id' => 'section-seo-indexability',
                'description' => 'Production sites blocking search engine indexing',
            ],
            'ssl' => [
                'label' => 'SSL Certificates',
                'tier' => 'critical',
                'class' => 'status-yellow',
                'icon' => 'fa-lock',
                'html_id' => 'section-ssl',
                'description' => 'Certificates expired or nearing expiration',
            ],

            // Tier 2: Infrastructure & Gaps
            'hot' => [
                'label' => 'Hot Servers',
                'tier' => 'infrastructure',
                'class' => 'status-yellow',
                'icon' => 'fa-fire',
                'html_id' => 'section-hot',
                'description' => '24h average CPU, RAM, or Disk exceeding threshold',
            ],
            'domain-expiration' => [
                'label' => 'Domain Expiring',
                'tier' => 'infrastructure',
                'class' => 'status-yellow',
                'icon' => 'fa-globe',
                'html_id' => 'section-domain-expiration',
                'description' => 'Domains expiring within 30 days',
            ],
            'reboot' => [
                'label' => 'Reboot Required',
                'tier' => 'infrastructure',
                'class' => 'status-yellow',
                'icon' => 'fa-power-off',
                'html_id' => 'section-reboot',
                'description' => 'Servers requiring a reboot to apply kernel updates',
            ],
            'patches' => [
                'label' => 'OS Patches',
                'tier' => 'infrastructure',
                'class' => 'status-yellow',
                'icon' => 'fa-cube',
                'html_id' => 'section-patches',
                'description' => 'System packages available for upgrade',
            ],
            'cf' => [
                'label' => 'Cloudflare DNS Only',
                'tier' => 'infrastructure',
                'class' => 'status-yellow',
                'icon' => 'fa-cloud',
                'html_id' => 'section-cf',
                'description' => 'Proxy disabled on Cloudflare DNS records',
            ],
            'no_ssh' => [
                'label' => 'SSH Access Gaps',
                'tier' => 'infrastructure',
                'class' => 'status-yellow',
                'icon' => 'fa-key',
                'html_id' => 'section-no_ssh',
                'description' => 'Servers without confirmed SSH access',
            ],
            'no_jail' => [
                'label' => 'Clockwork Jail Missing',
                'tier' => 'infrastructure',
                'class' => 'status-yellow',
                'icon' => 'fa-shield-halved',
                'html_id' => 'section-no_jail',
                'description' => 'Servers without an isolated Clockwork chroot jail',
            ],
            'no_db' => [
                'label' => 'DB Creds Missing',
                'tier' => 'infrastructure',
                'class' => 'status-yellow',
                'icon' => 'fa-database',
                'html_id' => 'section-no_db',
                'description' => 'WordPress sites missing database credentials',
            ],
            'no_companion' => [
                'label' => 'Companion Missing',
                'tier' => 'infrastructure',
                'class' => 'status-yellow',
                'icon' => 'fa-plug-circle-xmark',
                'html_id' => 'section-no_companion',
                'description' => 'Companion plugin missing on sites with form tests',
            ],
            'orphans' => [
                'label' => 'Orphaned Sites',
                'tier' => 'infrastructure',
                'class' => 'status-yellow',
                'icon' => 'fa-link-slash',
                'html_id' => 'section-orphans',
                'description' => 'Sites disconnected from their hosting provider',
            ],

            // Tier 3: Routine Maintenance ("Not a big deal")
            'plugins_outdated' => [
                'label' => 'Plugins Out of Date',
                'tier' => 'routine',
                'class' => 'status-yellow',
                'icon' => 'fa-cubes',
                'html_id' => 'section-plugins_outdated',
                'description' => 'Routine updates available for WordPress plugins',
            ],
            'plugins_closed' => [
                'label' => 'Closed Plugins',
                'tier' => 'routine',
                'class' => 'status-yellow',
                'icon' => 'fa-box-archive',
                'html_id' => 'section-plugins_closed',
                'description' => 'Installed plugins closed or unmaintained on wp.org',
            ],
            'wp_admins' => [
                'label' => 'WP Admins Flagged',
                'tier' => 'routine',
                'class' => 'status-yellow',
                'icon' => 'fa-user-shield',
                'html_id' => 'section-wp_admins',
                'description' => 'Unrecognized or non-standard administrator users',
            ],
        ];

        $criticalKeys = ['down_sites', 'scheduler_stale', 'malware', 'companion_malware', 'tampering', 'health', 'forms_failing', 'stuck_maintenance', 'seo-indexability', 'ssl'];
        $infraKeys = ['hot', 'domain-expiration', 'reboot', 'patches', 'cf', 'no_ssh', 'no_jail', 'no_db', 'no_companion', 'orphans'];
        $routineKeys = ['plugins_outdated', 'plugins_closed', 'wp_admins'];

        $tierTotals = [
            'critical' => collect($criticalKeys)->sum(fn($k) => $totals[$k] ?? 0),
            'infrastructure' => collect($infraKeys)->sum(fn($k) => $totals[$k] ?? 0),
            'routine' => collect($routineKeys)->sum(fn($k) => $totals[$k] ?? 0),
        ];

        $issuesSubtitle = $totals['all'] === 0
            ? 'All clear across the fleet.'
            : $totals['all'] . ' total ' . Str::plural('item', $totals['all']) . ' (' .
              $tierTotals['critical'] . ' critical · ' .
              $tierTotals['infrastructure'] . ' infrastructure · ' .
              $tierTotals['routine'] . ' routine)';
    @endphp

    <div x-data="issuesDashboard({
        initialTotals: {{ \Illuminate\Support\Js::from($totals) }},
        categories: {{ \Illuminate\Support\Js::from($categoryDefinitions) }},
        initialLevels: {{ \Illuminate\Support\Js::from($categoryLevels) }},
        defaultLevels: {{ \Illuminate\Support\Js::from(app(\App\Support\IssueCategoryConfig::class)->defaults()) }},
        updateLevelUrl: '{{ route('issues.category-level.update') }}',
        updateAllLevelsUrl: '{{ route('issues.category-levels.update') }}',
        resetLevelsUrl: '{{ route('issues.category-levels.reset') }}',
        csrfToken: '{{ csrf_token() }}'
    })">
        <x-page-header :title="'Issues'">
            <x-slot:subtitle>
                @if ($totals['all'] === 0)
                    <span>All clear across the fleet.</span>
                @else
                    <span>
                        <span x-text="visibleItemsCount">{{ $totals['all'] }}</span> of {{ $totals['all'] }} {{ Str::plural('item', $totals['all']) }} shown
                        <span x-show="disabledCategoriesCount > 0" class="text-slate-400 font-medium ml-1" x-cloak>
                            (<span x-text="disabledItemsCount"></span> muted across <span x-text="disabledCategoriesCount"></span> <span x-text="disabledCategoriesCount === 1 ? 'category' : 'categories'"></span>)
                        </span>
                        <span x-show="hiddenItemsCount > 0" class="text-[var(--color-status-yellow)] font-medium ml-1" x-cloak>
                            (<span x-text="hiddenItemsCount"></span> hidden)
                        </span>
                        <span class="text-[var(--color-ink-soft)] font-normal text-xs ml-1">
                            · <span class="text-red-500 font-semibold"><span x-text="emergencyItemsCount"></span> emergency</span>
                            · <span class="text-amber-500 font-semibold"><span x-text="pressingItemsCount"></span> pressing</span>
                            · <span class="text-slate-400"><span x-text="notPressingItemsCount"></span> routine</span>
                        </span>
                    </span>
                @endif
            </x-slot:subtitle>
            <x-slot:actions>
                <div class="flex items-center gap-2 flex-wrap text-sm">
                    @php
                        $activeCategoriesCount = collect($categoryDefinitions)->filter(fn($cat, $k) => ($totals[$k] ?? 0) > 0)->count();
                    @endphp

                    {{-- Jump to Active Issue Dropdown --}}
                    @if ($activeCategoriesCount > 0)
                        <div class="relative" @click.outside="jumpMenuOpen = false">
                            <button type="button"
                                    @click="jumpMenuOpen = !jumpMenuOpen"
                                    class="btn-pill-nav inline-flex items-center gap-1.5 cursor-pointer text-xs md:text-sm font-medium"
                                    title="Jump directly to an active issue category section">
                                <i class="fa-solid fa-bolt text-amber-500 text-xs"></i>
                                <span>Jump to Issue</span>
                                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono font-semibold bg-amber-500/15 text-amber-500">
                                    {{ $activeCategoriesCount }}
                                </span>
                                <i class="fa-solid fa-chevron-down text-[10px] opacity-60 transition-transform duration-200"
                                   :class="jumpMenuOpen ? 'rotate-180' : ''"></i>
                            </button>

                            <div x-show="jumpMenuOpen"
                                 x-transition:enter="transition ease-out duration-150"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 x-transition:leave="transition ease-in duration-100"
                                 x-transition:leave-start="opacity-100 scale-100"
                                 x-transition:leave-end="opacity-0 scale-95"
                                 x-cloak
                                 class="absolute right-0 mt-1.5 w-72 rounded-[var(--radius-card)] border border-[var(--color-border-light)] bg-[var(--color-surface)] shadow-2xl py-2 z-50 max-h-96 overflow-y-auto">
                                <div class="px-3.5 py-1.5 text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-soft)] border-b border-[var(--color-border-light)] flex items-center justify-between">
                                    <span>Active Categories</span>
                                    <span class="font-mono text-[var(--color-ink-muted)]">{{ $totals['all'] }} items</span>
                                </div>
                                <div class="py-1">
                                    @foreach ($categoryDefinitions as $catKey => $cat)
                                        @if (($totals[$catKey] ?? 0) > 0)
                                            <a href="#{{ $cat['html_id'] }}"
                                               @click.prevent="jumpMenuOpen = false; jumpToCategory('{{ $cat['html_id'] }}')"
                                               x-show="isCategoryVisible('{{ $catKey }}')"
                                               class="flex items-center justify-between px-3.5 py-2 text-xs hover:bg-[var(--color-surface-alt)] transition-colors group">
                                                <div class="flex items-center gap-2.5 truncate">
                                                    <span class="status-dot"></span>
                                                    <span class="truncate font-medium text-[var(--color-ink-strong)] group-hover:text-[var(--color-brand)]">
                                                        {{ $cat['label'] }}
                                                    </span>
                                                </div>
                                                <span class="status-pill {{ $cat['class'] }} text-[10px] font-mono px-1.5 py-0.2 shrink-0">
                                                    {{ $totals[$catKey] }}
                                                </span>
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- WordPress-Style Screen Options Tab Button --}}
                    <button type="button"
                            @click="screenOptionsOpen = !screenOptionsOpen"
                            :class="screenOptionsOpen ? 'bg-[var(--color-brand)] text-white border-[var(--color-brand)] shadow-xs' : (hiddenCategoriesCount > 0 ? 'border-[var(--color-brand)] text-[var(--color-brand)]' : '')"
                            class="btn-pill-nav inline-flex items-center gap-1.5 cursor-pointer text-xs md:text-sm font-medium transition-all"
                            title="Customize which issue sections appear on this screen">
                        <i class="fa-solid fa-sliders text-xs" :class="screenOptionsOpen ? 'text-white' : 'text-[var(--color-brand)]'"></i>
                        <span>Screen Options</span>
                        <span x-show="hiddenCategoriesCount > 0"
                              x-cloak
                              x-text="hiddenCategoriesCount + ' hidden'"
                              class="px-1.5 py-0.2 text-[10px] rounded-full bg-amber-500/20 text-amber-500 font-semibold font-mono"
                              :class="screenOptionsOpen ? 'bg-white/25 text-white' : ''"></span>
                        <i class="fa-solid fa-chevron-down text-[10px] opacity-70 transition-transform duration-200"
                           :class="screenOptionsOpen ? 'rotate-180' : ''"></i>
                    </button>

                    {{-- Page Refresh --}}
                    <a href="{{ route('issues.index') }}"
                       class="btn-pill-nav inline-flex items-center gap-1.5 text-xs md:text-sm"
                       title="Loaded {{ now()->format('g:i:s a') }}"
                       onclick="this.querySelector('i').classList.add('fa-spin'); this.querySelector('span').textContent = 'Refreshing…';">
                        <i class="fa-solid fa-rotate"></i> <span>Refresh</span>
                    </a>
                </div>
            </x-slot:actions>
        </x-page-header>

        {{-- WORDPRESS-STYLE SCREEN OPTIONS DRAWER --}}
        <div x-show="screenOptionsOpen"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-2"
             x-cloak
             class="mb-6 rounded-[var(--radius-card)] border-2 border-[var(--color-brand)]/40 bg-[var(--color-surface)] shadow-xl overflow-hidden">
            {{-- Screen Options Top Control Bar --}}
            <div class="px-5 py-3.5 bg-[var(--color-surface-alt)]/80 border-b border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-[var(--color-brand)]"></span>
                    <span class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-strong)]">Screen Options: Elements on this page</span>
                    <span class="text-xs text-[var(--color-ink-muted)] hidden sm:inline">— Select categories to display. Saved in your browser.</span>
                </div>
                <div class="flex items-center gap-2 text-xs flex-wrap">
                    <span class="text-[var(--color-ink-soft)] font-medium">Presets:</span>
                    <button type="button"
                            @click="showAllCategories()"
                            class="px-2 py-1 rounded bg-[var(--color-surface)] border border-[var(--color-border-light)] hover:border-[var(--color-brand)] text-[var(--color-ink-strong)] transition-all cursor-pointer font-medium">
                        Show All
                    </button>
                    <button type="button"
                            @click="showOnlyCritical()"
                            class="px-2 py-1 rounded bg-[var(--color-surface)] border border-[var(--color-border-light)] hover:border-red-500 text-red-500 transition-all cursor-pointer font-medium">
                        Critical Only
                    </button>
                    <button type="button"
                            @click="hideRoutine()"
                            class="px-2 py-1 rounded bg-[var(--color-surface)] border border-[var(--color-border-light)] hover:border-amber-500 text-[var(--color-ink-strong)] transition-all cursor-pointer font-medium">
                        Hide Routine
                    </button>
                    <button type="button"
                            @click="resetCategories()"
                            class="px-2 py-1 rounded bg-[var(--color-surface)] border border-[var(--color-border-light)] hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] transition-all cursor-pointer">
                        Reset
                    </button>
                    <span class="text-[var(--color-border-light)]">|</span>
                    <button type="button"
                            @click="prioritiesModalOpen = true"
                            class="text-[var(--color-brand)] hover:underline font-semibold cursor-pointer inline-flex items-center gap-1">
                        <i class="fa-solid fa-sliders text-[11px]"></i> Fleet Priorities
                    </button>
                    <button type="button"
                            @click="screenOptionsOpen = false"
                            class="ml-2 px-3 py-1 rounded bg-[var(--color-brand)] text-white hover:opacity-90 font-medium text-xs cursor-pointer shadow-xs">
                        Done
                    </button>
                </div>
            </div>

            {{-- 3-Tier Checkbox Columns --}}
            <div class="p-5 grid grid-cols-1 md:grid-cols-3 gap-6">
                @php
                    $tierColumns = [
                        'critical' => [
                            'title' => 'Critical & Security',
                            'icon' => 'fa-shield-halved',
                            'color' => 'text-[var(--color-status-red)]',
                            'badge' => 'bg-red-500/10 text-red-500',
                        ],
                        'infrastructure' => [
                            'title' => 'Infrastructure & Health',
                            'icon' => 'fa-server',
                            'color' => 'text-[var(--color-status-yellow)]',
                            'badge' => 'bg-amber-500/10 text-amber-500',
                        ],
                        'routine' => [
                            'title' => 'Routine Maintenance',
                            'icon' => 'fa-screwdriver-wrench',
                            'color' => 'text-slate-400',
                            'badge' => 'bg-slate-500/10 text-slate-400',
                        ],
                    ];
                @endphp

                @foreach ($tierColumns as $tierKey => $tierInfo)
                    <div class="flex flex-col space-y-2">
                        <div class="flex items-center gap-2 pb-2 border-b border-[var(--color-border-light)]">
                            <i class="fa-solid {{ $tierInfo['icon'] }} {{ $tierInfo['color'] }} text-xs"></i>
                            <h4 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-strong)]">
                                {{ $tierInfo['title'] }}
                            </h4>
                            <span class="ml-auto text-[11px] font-mono font-semibold px-1.5 py-0.5 rounded-full {{ $tierInfo['badge'] }}">
                                {{ $tierTotals[$tierKey] ?? 0 }}
                            </span>
                        </div>
                        <div class="space-y-1 pt-1">
                            @foreach ($categoryDefinitions as $catKey => $cat)
                                @if ($cat['tier'] === $tierKey)
                                    <label class="flex items-center justify-between p-2 rounded-lg hover:bg-[var(--color-surface-alt)] cursor-pointer transition-colors group">
                                        <div class="flex items-center gap-2.5 min-w-0 pr-2">
                                            <input type="checkbox"
                                                   :checked="isCategoryVisible('{{ $catKey }}')"
                                                   @change="toggleCategory('{{ $catKey }}')"
                                                   class="w-4 h-4 rounded border-[var(--color-border-light)] text-[var(--color-brand)] focus:ring-[var(--color-brand)] cursor-pointer" />
                                            <i class="fa-solid {{ $cat['icon'] }} text-xs opacity-70 w-4 text-center shrink-0"></i>
                                            <span class="text-xs font-medium text-[var(--color-ink-strong)] truncate group-hover:text-[var(--color-brand)]">
                                                {{ $cat['label'] }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-1.5 shrink-0">
                                            <span x-show="isCategoryOff('{{ $catKey }}')"
                                                  x-cloak
                                                  class="text-[10px] text-slate-400 italic">
                                                (muted)
                                            </span>
                                            @if (($totals[$catKey] ?? 0) > 0)
                                                <span class="status-pill {{ $cat['class'] }} text-[10px] font-mono px-1.5 py-0.2">
                                                    {{ $totals[$catKey] }}
                                                </span>
                                            @else
                                                <span class="text-[10px] font-mono text-[var(--color-ink-soft)] px-1">0</span>
                                            @endif
                                        </div>
                                    </label>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- UNIFIED TOOLBAR: Tier Tabs (Left) + Urgency Filters & Global Actions (Right) --}}
        <div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
            {{-- Left: Tier filter tabs --}}
            <div class="max-w-full min-w-0 w-full sm:w-auto overflow-x-auto scrollbar-none flex items-center gap-1 p-1 bg-[var(--color-surface-alt)] rounded-lg border border-[var(--color-border-light)] text-xs md:text-sm">
                <button type="button"
                        @click="setTier('all')"
                        :class="tierTab === 'all' ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-xs font-semibold' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'"
                        class="px-3 py-1.5 rounded-md transition-all flex items-center gap-1.5 cursor-pointer">
                    <span>All Issues</span>
                    <span class="px-1.5 py-0.2 rounded-full bg-[var(--color-surface-alt)] text-xs font-mono">{{ $totals['all'] }}</span>
                </button>
                <button type="button"
                        @click="setTier('critical')"
                        :class="tierTab === 'critical' ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-xs font-semibold' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'"
                        class="px-3 py-1.5 rounded-md transition-all flex items-center gap-1.5 cursor-pointer">
                    <span class="w-2 h-2 rounded-full bg-[var(--color-status-red)]"></span>
                    <span>Critical & Security</span>
                    <span class="px-1.5 py-0.2 rounded-full {{ $tierTotals['critical'] > 0 ? 'bg-red-500/15 text-[var(--color-status-red)] font-semibold' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]' }} text-xs font-mono">{{ $tierTotals['critical'] }}</span>
                </button>
                <button type="button"
                        @click="setTier('infrastructure')"
                        :class="tierTab === 'infrastructure' ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-xs font-semibold' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'"
                        class="px-3 py-1.5 rounded-md transition-all flex items-center gap-1.5 cursor-pointer">
                    <span class="w-2 h-2 rounded-full bg-[var(--color-status-yellow)]"></span>
                    <span>Infrastructure</span>
                    <span class="px-1.5 py-0.2 rounded-full {{ $tierTotals['infrastructure'] > 0 ? 'bg-amber-500/15 text-[var(--color-status-yellow)] font-semibold' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]' }} text-xs font-mono">{{ $tierTotals['infrastructure'] }}</span>
                </button>
                <button type="button"
                        @click="setTier('routine')"
                        :class="tierTab === 'routine' ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-xs font-semibold' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'"
                        class="px-3 py-1.5 rounded-md transition-all flex items-center gap-1.5 cursor-pointer"
                        title="Outdated plugins, closed plugins, and routine admin audits">
                    <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                    <span>Routine</span>
                    <span class="px-1.5 py-0.2 rounded-full bg-[var(--color-surface-alt)] text-xs font-mono">{{ $tierTotals['routine'] }}</span>
                </button>
            </div>

            {{-- Right: Urgency Level Filters + Priorities Button + Collapse All --}}
            <div class="flex items-center gap-2.5 flex-wrap w-full sm:w-auto min-w-0">
                {{-- Priority Filter Pills --}}
                <div class="max-w-full min-w-0 overflow-x-auto scrollbar-none flex items-center gap-1 p-1 bg-[var(--color-surface-alt)] rounded-lg border border-[var(--color-border-light)] text-xs">
                    <button type="button"
                            @click="setPriorityFilter('all')"
                            :class="priorityFilter === 'all' ? 'bg-[var(--color-surface)] text-[var(--color-ink-strong)] shadow-xs font-semibold' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'"
                            class="px-2 py-1 rounded-md transition-all flex items-center gap-1 cursor-pointer">
                        <span>All Active</span>
                    </button>
                    <button type="button"
                            @click="setPriorityFilter('emergency')"
                            :class="priorityFilter === 'emergency' ? 'bg-[var(--color-surface)] text-red-500 shadow-xs font-semibold' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'"
                            class="px-2 py-1 rounded-md transition-all flex items-center gap-1 cursor-pointer"
                            title="Show only emergency / critical alert categories">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                        <span>Emergency</span>
                        <span class="px-1 py-0.2 rounded-full text-[10px] font-mono"
                              :class="emergencyItemsCount > 0 ? 'bg-red-500/15 text-red-500 font-semibold' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]'"
                              x-text="emergencyItemsCount"></span>
                    </button>
                    <button type="button"
                            @click="setPriorityFilter('pressing')"
                            :class="priorityFilter === 'pressing' ? 'bg-[var(--color-surface)] text-amber-500 shadow-xs font-semibold' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'"
                            class="px-2 py-1 rounded-md transition-all flex items-center gap-1 cursor-pointer"
                            title="Show only urgent / pressing alert categories">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                        <span>Pressing</span>
                        <span class="px-1 py-0.2 rounded-full text-[10px] font-mono"
                              :class="pressingItemsCount > 0 ? 'bg-amber-500/15 text-amber-500 font-semibold' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]'"
                              x-text="pressingItemsCount"></span>
                    </button>
                    <button type="button"
                            @click="setPriorityFilter('not_pressing')"
                            :class="priorityFilter === 'not_pressing' ? 'bg-[var(--color-surface)] text-slate-400 shadow-xs font-semibold' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'"
                            class="px-2 py-1 rounded-md transition-all flex items-center gap-1 cursor-pointer"
                            title="Show only routine / low priority categories">
                        <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                        <span>Routine</span>
                        <span class="px-1 py-0.2 rounded-full bg-[var(--color-surface-alt)] text-[10px] font-mono"
                              x-text="notPressingItemsCount"></span>
                    </button>
                    <button type="button"
                            x-show="disabledCategoriesCount > 0"
                            x-cloak
                            @click="setPriorityFilter('off')"
                            :class="priorityFilter === 'off' ? 'bg-[var(--color-surface)] text-slate-400 shadow-xs font-semibold' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'"
                            class="px-2 py-1 rounded-md transition-all flex items-center gap-1 cursor-pointer"
                            title="Show categories turned off / muted fleet-wide">
                        <i class="fa-solid fa-bell-slash text-[9px]"></i>
                        <span>Muted</span>
                        <span class="px-1 py-0.2 rounded-full bg-slate-500/20 text-[10px] font-mono"
                              x-text="disabledCategoriesCount"></span>
                    </button>
                </div>

                {{-- Fleet Priorities Config Button --}}
                <button type="button"
                        @click="prioritiesModalOpen = true"
                        class="btn-pill-nav text-xs inline-flex items-center gap-1.5 cursor-pointer py-1.5"
                        :class="disabledCategoriesCount > 0 ? 'border-amber-500/50 text-amber-500 ring-1 ring-amber-500/20' : ''"
                        title="Configure category alert priorities or mute categories fleet-wide">
                    <i class="fa-solid fa-sliders text-[var(--color-brand)]"></i>
                    <span>Priorities</span>
                    <span x-show="disabledCategoriesCount > 0"
                          x-cloak
                          x-text="disabledCategoriesCount + ' muted'"
                          class="px-1.5 py-0.2 text-[10px] rounded-full bg-slate-500/20 text-[var(--color-ink-soft)] font-semibold font-mono"></span>
                </button>

                {{-- Collapse / Expand All button --}}
                <button type="button"
                        @click="toggleCollapseAll()"
                        class="btn-pill-nav text-xs inline-flex items-center gap-1.5 cursor-pointer py-1.5"
                        :title="isAllCollapsed ? 'Expand all section cards' : 'Collapse all section cards into compact headers'">
                    <i class="fa-solid" :class="isAllCollapsed ? 'fa-angles-down' : 'fa-angles-up'"></i>
                    <span x-text="isAllCollapsed ? 'Expand all' : 'Collapse all'"></span>
                </button>
            </div>
        </div>

        @if ($totals['all'] === 0)
            <div class="card p-10 text-center">
                <i class="fa-solid fa-circle-check text-5xl text-[var(--color-status-green)] mb-3"></i>
                <p class="text-lg font-medium text-[var(--color-ink-strong)]">All clear</p>
                <p class="text-sm text-[var(--color-ink-muted)] mt-1">
                    No SSL issues, no unhealthy servers, no provisioning gaps, no missing DB creds.
                </p>
            </div>
        @endif

        {{-- Tab Empty States --}}
        <div x-show="tierTab === 'critical' && {{ $tierTotals['critical'] }} === 0" class="card p-8 text-center mb-6" x-cloak>
            <i class="fa-solid fa-circle-check text-4xl text-[var(--color-status-green)] mb-2"></i>
            <p class="font-medium text-[var(--color-ink-strong)]">No critical issues</p>
            <p class="text-xs text-[var(--color-ink-muted)] mt-1">All sites and servers are operating normally with no active emergencies.</p>
        </div>
        <div x-show="tierTab === 'infrastructure' && {{ $tierTotals['infrastructure'] }} === 0" class="card p-8 text-center mb-6" x-cloak>
            <i class="fa-solid fa-circle-check text-4xl text-[var(--color-status-green)] mb-2"></i>
            <p class="font-medium text-[var(--color-ink-strong)]">Infrastructure healthy</p>
            <p class="text-xs text-[var(--color-ink-muted)] mt-1">No provisioning gaps, reboots, or server metric alerts.</p>
        </div>
        <div x-show="tierTab === 'routine' && {{ $tierTotals['routine'] }} === 0" class="card p-8 text-center mb-6" x-cloak>
            <i class="fa-solid fa-circle-check text-4xl text-[var(--color-status-green)] mb-2"></i>
            <p class="font-medium text-[var(--color-ink-strong)]">Up to date</p>
            <p class="text-xs text-[var(--color-ink-muted)] mt-1">All plugins and admin accounts are up to date and clean.</p>
        </div>

        {{-- All Hidden Empty State --}}
        <div x-show="visibleItemsCount === 0 && {{ $totals['all'] }} > 0" class="card p-8 text-center mb-6" x-cloak>
            <i class="fa-solid fa-filter text-4xl text-[var(--color-ink-muted)] mb-2"></i>
            <p class="font-medium text-[var(--color-ink-strong)]">All active categories are hidden</p>
            <p class="text-xs text-[var(--color-ink-muted)] mt-1">You have hidden categories that contain all {{ $totals['all'] }} current issues.</p>
            <button type="button" @click="showAllCategories()" class="btn-pill-nav text-xs mt-3 inline-flex items-center gap-1 cursor-pointer">
                <i class="fa-solid fa-eye mr-1"></i> Show all categories
            </button>
        </div>

        {{-- =========================================================================
             TIER 1: CRITICAL & SECURITY
             ========================================================================= --}}
        <div x-show="(tierTab === 'all' || tierTab === 'critical') && hasVisibleCategoryInTier('critical')"
             class="mb-3 flex items-center justify-between gap-2 border-b border-[var(--color-border-light)] pb-2 pt-1">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-[var(--color-status-red)]"></span>
                <h3 class="font-display text-xs uppercase tracking-wider font-semibold text-[var(--color-status-red)]">
                    Critical & Security
                </h3>
            </div>
            <span class="text-xs text-[var(--color-ink-muted)] font-medium font-mono">{{ $tierTotals['critical'] }} total</span>
        </div>

        {{-- SCHEDULER HEARTBEAT — if this is stale, every other monitor is lying --}}
    @if ($schedulerHeartbeat->isStale())
        <section id="section-scheduler_stale" x-show="isCategoryVisible('scheduler_stale') && matchesTier('critical')" class="card mb-6 ring-1 ring-[var(--color-status-red)]/30">
            <div @click="toggleSection('scheduler_stale')" class="px-5 py-4 flex items-start justify-between gap-4 cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('scheduler_stale') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-clock text-[var(--color-status-red)] mr-2"></i>
                        Scheduler has not ticked in {{ $schedulerHeartbeat->ageLabel() }}
                    </h2>
                    <p class="text-sm text-[var(--color-ink-muted)] mt-1">
                        crontab is not spawning <code class="font-data">php artisan schedule:run</code>.
                        Uptime, ingest, bans, and updates are frozen. Last tick:
                        {{ $schedulerHeartbeat->lastAt?->diffForHumans() ?? 'unknown' }}.
                    </p>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-2">
                        <a href="{{ route('docs.show', 'runbooks/scheduler-stuck') }}" class="text-[var(--color-primary-600)] hover:underline">Scheduler stuck runbook</a>
                        ·
                        <a href="{{ route('monitoring.settings') }}" class="text-[var(--color-primary-600)] hover:underline">Monitoring settings</a>
                    </p>
                </div>
                <span class="status-pill status-red">1</span>
                <x-issue-priority-menu :category="'scheduler_stale'"/>
            
                    <button type="button" @click.stop="toggleSection('scheduler_stale')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('scheduler_stale') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('scheduler_stale') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('scheduler_stale')" class="rounded-b-[var(--radius-card)] overflow-hidden">
        </div>
        </section>
    @endif

        {{-- MALWARE / BLACKLIST (Sucuri SiteCheck) --}}
    @if ($malwareHits->isNotEmpty())
        <section id="section-malware" x-show="isCategoryVisible('malware') && matchesTier('critical')" class="card mb-6">
            <div @click="toggleSection('malware')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('malware') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-bug text-[var(--color-status-red)] mr-2"></i>
                        Malware or blacklist hit
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Latest Sucuri SiteCheck flagged the site as compromised or on a public blacklist.</p>
                </div>
                <span class="status-pill status-red">{{ $malwareHits->count() }}</span>
                <x-issue-priority-menu :category="'malware'"/>
            
                    <button type="button" @click.stop="toggleSection('malware')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('malware') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('malware') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('malware')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-5 py-2">Site</th>
                        <th class="text-left px-5 py-2">Server</th>
                        <th class="text-left px-5 py-2">Flag</th>
                        <th class="text-left px-5 py-2">Summary</th>
                        <th class="text-left px-5 py-2">Scanned</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($malwareHits as $row)
                        <tr>
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', [$row->site, 'security']) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $row->site->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs">
                                @if ($row->site->server)
                                    <a href="{{ route('servers.show', $row->site->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $row->site->server->name }}">{{ $row->site->server->display_name }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs">
                                @if ($row->has_malware_hit)<span class="status-pill status-red">malware</span>@endif
                                @if ($row->blacklist_hit)<span class="status-pill status-red">blacklist</span>@endif
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] truncate max-w-md">{{ $row->summary }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]" title="{{ $row->scanned_at }}">{{ $row->scanned_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- COMPANION MALWARE FINDINGS (PHP-in-uploads + obfuscation signatures, scanned on-site) --}}
    @if ($companionMalwareFindings->isNotEmpty())
        <section id="section-companion_malware" x-show="isCategoryVisible('companion_malware') && matchesTier('critical')" class="card mb-6">
            <div @click="toggleSection('companion_malware')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('companion_malware') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-file-circle-exclamation text-[var(--color-status-red)] mr-2"></i>
                        Companion malware findings
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Latest Companion file scan flagged PHP in uploads or obfuscation/webshell signatures. Clients see the same result on their wp-admin Security page — get there first.</p>
                </div>
                <span class="status-pill status-red">{{ $companionMalwareFindings->count() }}</span>
                <x-issue-priority-menu :category="'companion_malware'"/>
            
                    <button type="button" @click.stop="toggleSection('companion_malware')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('companion_malware') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('companion_malware') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('companion_malware')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-5 py-2">Site</th>
                        <th class="text-left px-5 py-2">Server</th>
                        <th class="text-right px-5 py-2">Findings</th>
                        <th class="text-left px-5 py-2">Summary</th>
                        <th class="text-left px-5 py-2">Scanned</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($companionMalwareFindings as $row)
                        <tr>
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', [$row->site, 'security']) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $row->site->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs">
                                @if ($row->site->server)
                                    <a href="{{ route('servers.show', $row->site->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $row->site->server->name }}">{{ $row->site->server->display_name }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs text-right font-data">{{ number_format($row->modified_files_count) }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] truncate max-w-md">{{ $row->summary }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]" title="{{ $row->scanned_at }}">{{ $row->scanned_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- CORE FILE TAMPERING (wp core verify-checksums) --}}
    @if ($checksumTampering->isNotEmpty())
        <section id="section-tampering" x-show="isCategoryVisible('tampering') && matchesTier('critical')" class="card mb-6">
            <div @click="toggleSection('tampering')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('tampering') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-shield-halved text-[var(--color-status-red)] mr-2"></i>
                        Core file tampering
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Latest <code class="font-data">wp core verify-checksums</code> flagged modified, missing, or unexpected core files.</p>
                </div>
                <span class="status-pill status-red">{{ $checksumTampering->count() }}</span>
                <x-issue-priority-menu :category="'tampering'"/>
            
                    <button type="button" @click.stop="toggleSection('tampering')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('tampering') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('tampering') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('tampering')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-5 py-2">Site</th>
                        <th class="text-left px-5 py-2">Server</th>
                        <th class="text-right px-5 py-2">Files flagged</th>
                        <th class="text-left px-5 py-2">Summary</th>
                        <th class="text-left px-5 py-2">Scanned</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($checksumTampering as $row)
                        <tr>
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', [$row->site, 'security']) }}#core-integrity" class="text-[var(--color-primary-600)] hover:underline">{{ $row->site->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs">
                                @if ($row->site->server)
                                    <a href="{{ route('servers.show', $row->site->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $row->site->server->name }}">{{ $row->site->server->display_name }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs text-right font-data">{{ number_format($row->modified_files_count) }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] truncate max-w-md">{{ $row->summary }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]" title="{{ $row->scanned_at }}">{{ $row->scanned_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- SITES CURRENTLY DOWN — real outages outrank everything below --}}
    @if ($downSites->isNotEmpty())
        <section id="section-down-sites" x-show="isCategoryVisible('down_sites') && matchesTier('critical')" class="card mb-6 ring-1 ring-[var(--color-status-red)]/30">
            <div @click="toggleSection('down_sites')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('down_sites') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-circle-exclamation text-[var(--color-status-red)] mr-2"></i>
                        Sites currently down
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">HTTP probe failed 2+ times in a row. Mattermost was alerted at the transition.</p>
                </div>
                <span class="status-pill status-red">{{ $downSites->count() }}</span>
                <x-issue-priority-menu :category="'down_sites'"/>
            
                    <button type="button" @click.stop="toggleSection('down_sites')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('down_sites') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('down_sites') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('down_sites')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="px-5 py-2 text-left">Site</th>
                        <th class="px-5 py-2 text-left">Server</th>
                        <th class="px-5 py-2 text-left">Failure</th>
                        <th class="px-5 py-2 text-left">Down for</th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($downSites as $site)
                        <tr>
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $site) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $site->domain }}</a>
                            </td>
                            <td class="px-5 py-2 font-data text-[var(--color-ink-muted)]">{{ $site->server?->name ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-strong)] cell-failure">
                                @if ($site->uptime_last_status_code)
                                    HTTP {{ $site->uptime_last_status_code }}
                                @else
                                    <span class="text-[var(--color-ink-muted)]">unreachable</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-status-red)] font-data tabular-nums">
                                {{ $site->uptime_down_since?->diffForHumans(['parts' => 2, 'short' => true]) ?? '—' }}
                            </td>
                            <td class="px-5 py-2 text-right">
                                <button type="button"
                                        class="uptime-recheck-btn text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50"
                                        data-url="{{ route('sites.uptime.recheck', $site) }}">
                                    <i class="fa-solid fa-rotate"></i> Recheck
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <script>
                (function () {
                    const csrf = '{{ csrf_token() }}';
                    document.querySelectorAll('#section-down-sites .uptime-recheck-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const row = btn.closest('tr');
                            const original = btn.innerHTML;
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                            try {
                                const r = await fetch(btn.dataset.url, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                });
                                const data = await r.json();
                                if (data.ok) {
                                    if (data.state === 'up') {
                                        row.style.transition = 'opacity 400ms';
                                        row.style.opacity = '0';
                                        setTimeout(() => row.remove(), 450);
                                    } else {
                                        const failureCell = row.querySelector('.cell-failure');
                                        if (failureCell) {
                                            failureCell.innerHTML = data.status_code
                                                ? 'HTTP ' + data.status_code
                                                : '<span class="text-[var(--color-ink-muted)]">unreachable</span>';
                                        }
                                        btn.innerHTML = original;
                                        btn.disabled = false;
                                    }
                                } else {
                                    btn.innerHTML = '<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> ' + (data.message || 'Failed');
                                    setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                                }
                            } catch (e) {
                                btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
                                setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                            }
                        });
                    });
                })();
            </script>
        </div>
        </section>
    @endif

        {{-- STUCK MAINTENANCE — forgotten windows, not outages --}}
    @if ($stuckMaintenanceSites->isNotEmpty())
        <section id="section-stuck_maintenance" x-show="isCategoryVisible('stuck_maintenance') && matchesTier('critical')" class="card mb-6">
            <div @click="toggleSection('stuck_maintenance')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('stuck_maintenance') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-wrench text-[var(--color-status-yellow)] mr-2"></i>
                        Maintenance running longer than {{ \App\Models\Site::UPTIME_STUCK_MAINTENANCE_HOURS }} hours
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Likely a forgotten maintenance plugin or an update that never finished. Outage alerts stay suppressed until the site comes back up.</p>
                </div>
                <span class="status-pill status-yellow">{{ $stuckMaintenanceSites->count() }}</span>
                <x-issue-priority-menu :category="'stuck_maintenance'"/>
            
                    <button type="button" @click.stop="toggleSection('stuck_maintenance')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('stuck_maintenance') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('stuck_maintenance') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('stuck_maintenance')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="px-5 py-2 text-left">Site</th>
                        <th class="px-5 py-2 text-left">Server</th>
                        <th class="px-5 py-2 text-left">In maintenance</th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($stuckMaintenanceSites as $site)
                        <tr>
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $site) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $site->domain }}</a>
                            </td>
                            <td class="px-5 py-2 font-data text-[var(--color-ink-muted)]">{{ $site->server?->name ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-status-yellow)] font-data tabular-nums">
                                {{ $site->uptime_maintenance_since?->diffForHumans(['parts' => 2, 'short' => true]) ?? '—' }}
                            </td>
                            <td class="px-5 py-2 text-right">
                                <a href="{{ route('monitoring.index') }}" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)]">Monitoring</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- HEALTH --}}
    @if ($unhealthyServers->isNotEmpty())
        <section id="section-health" x-show="isCategoryVisible('health') && matchesTier('critical')" class="card mb-6">
            <div @click="toggleSection('health')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('health') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-heart-pulse text-[var(--color-status-red)] mr-2"></i>
                        Server health
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Servers reporting status=red from the DigitalOcean poller.</p>
                </div>
                <span class="status-pill status-red">{{ $unhealthyServers->count() }}</span>
                <x-issue-priority-menu :category="'health'"/>
            
                    <button type="button" @click.stop="toggleSection('health')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('health') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('health') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('health')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'server', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="polled" class="px-5 py-2">Last polled</x-sort-th>
                        <x-sort-th key="alert" class="px-5 py-2">Last alert</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($unhealthyServers as $s)
                        <tr
                            data-sort-server="{{ $s->name }}"
                            data-sort-polled="{{ $s->last_polled_at?->getTimestamp() ?? '' }}"
                            data-sort-alert="{{ $s->last_alert_at?->getTimestamp() ?? '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('servers.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] cell-polled">{{ $s->last_polled_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $s->last_alert_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-5 py-2 text-right">
                                @if ($s->provider_id)
                                    <button type="button"
                                            class="health-recheck-btn text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50"
                                            data-url="{{ route('servers.recheck-health', $s) }}">
                                        <i class="fa-solid fa-rotate"></i> Recheck
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <script>
                (function () {
                    const csrf = '{{ csrf_token() }}';
                    document.querySelectorAll('#section-health .health-recheck-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const row = btn.closest('tr');
                            const original = btn.innerHTML;
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                            try {
                                const r = await fetch(btn.dataset.url, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                });
                                const data = await r.json();
                                if (data.ok) {
                                    if (data.status === 'green' || data.status === 'yellow') {
                                        row.style.transition = 'opacity 400ms';
                                        row.style.opacity = '0';
                                        setTimeout(() => row.remove(), 450);
                                    } else {
                                        row.querySelector('.cell-polled').textContent = data.last_polled ?? 'just now';
                                        btn.innerHTML = original;
                                        btn.disabled = false;
                                    }
                                } else {
                                    btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-[var(--color-status-red)]"></i>';
                                    btn.title = data.error ?? 'Poll failed';
                                    setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                                }
                            } catch (e) {
                                btn.innerHTML = original;
                                btn.disabled = false;
                            }
                        });
                    });
                })();
            </script>
        </div>
        </section>
    @endif

        {{-- FORM TESTING FAILING --}}
    @if ($failedFormTests->isNotEmpty())
        <section id="section-forms_failing" x-show="isCategoryVisible('forms_failing') && matchesTier('critical')" class="card mb-6">
            <div @click="toggleSection('forms_failing')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('forms_failing') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-envelope-circle-check text-[var(--color-status-red)] mr-2"></i>
                        Contact form failing
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Care-plan sites with contact-form testing on whose daily test has failed two or more runs in a row. Mattermost was pinged on the second failure.</p>
                </div>
                <span class="status-pill status-red">{{ $failedFormTests->count() }}</span>
                <x-issue-priority-menu :category="'forms_failing'"/>
            
                    <button type="button" @click.stop="toggleSection('forms_failing')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('forms_failing') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('forms_failing') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('forms_failing')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'streak', defaultDir: 'desc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="plugin" class="px-5 py-2">Plugin</x-sort-th>
                        <x-sort-th key="streak" align="right" class="px-5 py-2">Streak</x-sort-th>
                        <x-sort-th key="last_test" class="px-5 py-2">Last test</x-sort-th>
                        <x-sort-th key="error" class="px-5 py-2">Error</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($failedFormTests as $cft)
                        @php $s = $cft->site; @endphp
                        <tr
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name ?? '' }}"
                            data-sort-plugin="{{ $cft->form_plugin }}"
                            data-sort-streak="{{ $cft->failure_streak }}"
                            data-sort-last_test="{{ $cft->last_test_at?->getTimestamp() ?? '' }}"
                            data-sort-error="{{ $cft->last_test_error }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', ['site' => $s, 'tab' => 'forms']) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                                <span class="text-[10px] text-[var(--color-ink-soft)] ml-1">{{ $cft->form_id }}</span>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                @if ($s->server)
                                    <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server->name }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs">{{ $cft->form_plugin }}</td>
                            <td class="px-5 py-2 text-right text-xs font-data">{{ $cft->failure_streak }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $cft->last_test_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] truncate max-w-xs" title="{{ $cft->last_test_error }}">{{ \Illuminate\Support\Str::limit($cft->last_test_error, 80) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- SSL --}}
    @if ($sslIssues->isNotEmpty())
        @php
            // Orange cloud = CF proxy on (origin cert may be cosmetic — visitors see CF's edge cert).
            // Gray cloud = CF DNS only, proxy off (origin cert is what users see, matters fully).
            // No icon = not on Cloudflare at all (origin cert matters fully).
            $renderCfIcon = function (?string $state) {
                return match ($state) {
                    'proxied' => '<i class="fa-solid fa-cloud" style="color: #F38020" title="Cloudflare proxy active — visitors see CF edge cert, not origin"></i>',
                    'dns_only' => '<i class="fa-solid fa-cloud text-[var(--color-ink-soft)]" title="On Cloudflare DNS but proxy is OFF — origin cert is user-facing"></i>',
                    default => '',
                };
            };
        @endphp
        <section id="section-ssl" x-show="isCategoryVisible('ssl') && matchesTier('critical')" class="card mb-6">
            <div @click="toggleSection('ssl')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('ssl') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-lock-open text-[var(--color-ink-muted)] mr-2"></i>
                        SSL certificates
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Yellow = renewal window passed without rollover · Red = already expired. <i class="fa-solid fa-cloud" style="color: #F38020"></i> = on Cloudflare with proxy active · <i class="fa-solid fa-cloud text-[var(--color-ink-soft)]"></i> = on Cloudflare DNS only.</p>
                </div>
                <span class="status-pill status-yellow">{{ $sslIssues->count() }}</span>
                <x-issue-priority-menu :category="'ssl'"/>
            
                    <button type="button" @click.stop="toggleSection('ssl')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('ssl') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('ssl') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('ssl')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'expires', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="w-8 px-3 py-2"></th>
                        <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="state" class="px-5 py-2">State</x-sort-th>
                        <x-sort-th key="expires" class="px-5 py-2">Expires</x-sort-th>
                        <x-sort-th key="source" class="px-5 py-2">Source</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($sslIssues as $s)
                        @php
                            $state = $s->sslState();
                            $stateClass = $state === 'red' ? 'status-red' : 'status-yellow';
                            $stateLabel = $state === 'red' ? 'Expired' : 'Renewal needed';
                        @endphp
                        <tr data-site-row="{{ $s->id }}"
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name ?? '' }}"
                            data-sort-state="{{ $state }}"
                            data-sort-expires="{{ $s->cert_expires_at?->getTimestamp() ?? '' }}"
                            data-sort-source="{{ $s->cert_source }}">
                            <td class="px-3 py-2 text-center">
                                {!! $renderCfIcon($s->cloudflare_state) !!}
                            </td>
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                @if ($s->server)
                                    <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server->name }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-2 cell-state">
                                <span class="status-pill {{ $stateClass }} text-[10px]">{{ $stateLabel }}</span>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] cell-expires">
                                {{ $s->cert_expires_at?->format('M j, Y') }}
                                <span class="text-[var(--color-ink-soft)]">({{ $s->cert_expires_at?->diffForHumans() }})</span>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] font-data">{{ $s->cert_source }}</td>
                            <td class="px-5 py-2 text-right">
                                <button type="button"
                                        class="recheck-btn text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50"
                                        data-url="{{ route('sites.cert.recheck', $s) }}">
                                    <i class="fa-solid fa-rotate"></i> Recheck
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <script>
                (function () {
                    const csrf = '{{ csrf_token() }}';
                    document.querySelectorAll('#section-ssl .recheck-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const row = btn.closest('tr');
                            const original = btn.innerHTML;
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                            try {
                                const r = await fetch(btn.dataset.url, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                });
                                const data = await r.json();
                                if (data.ok) {
                                    if (data.state === 'green' || data.state === 'none') {
                                        row.style.transition = 'opacity 400ms';
                                        row.style.opacity = '0';
                                        setTimeout(() => row.remove(), 450);
                                    } else {
                                        const cls = data.state === 'red' ? 'status-red' : 'status-yellow';
                                        const label = data.state === 'red' ? 'Expired' : 'Renewal needed';
                                        const pill = document.createElement('span');
                                        pill.className = `status-pill ${cls} text-[10px]`;
                                        pill.textContent = label;
                                        const stateCell = row.querySelector('.cell-state');
                                        stateCell.textContent = '';
                                        stateCell.appendChild(pill);
                                        if (data.expires_at) {
                                            row.querySelector('.cell-expires').textContent =
                                                new Date(data.expires_at).toLocaleString();
                                        }
                                        btn.innerHTML = original;
                                        btn.disabled = false;
                                    }
                                } else {
                                    btn.innerHTML = '<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> ' + (data.message || 'Failed');
                                    setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                                }
                            } catch (e) {
                                btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
                                setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                            }
                        });
                    });
                })();
            </script>
        </div>
        </section>
    @endif

        {{-- SEO INDEXABILITY --}}
    @if ($seoIssues->isNotEmpty() || $ignoredSeoIssues->isNotEmpty())
        <section id="section-seo-indexability" x-show="isCategoryVisible('seo-indexability') && matchesTier('critical')" class="card mb-6" x-data="{
            activeTab: 'active',
            ignoreModalOpen: false,
            targetSiteId: null,
            targetDomain: '',
            ignoreReason: '',
            openIgnore(id, domain) {
                this.targetSiteId = id;
                this.targetDomain = domain;
                this.ignoreReason = '';
                this.ignoreModalOpen = true;
            }
        }">
            <div @click="toggleSection('seo-indexability')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-3 cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('seo-indexability') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-magnifying-glass text-[var(--color-status-red)] mr-2"></i>
                        SEO indexability
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                        Red = production site blocking search engines · Gray = staging environment protected from search.
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="inline-flex items-center p-0.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-xs">
                        <button type="button"
                                @click="activeTab = 'active'"
                                :class="activeTab === 'active' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]'"
                                class="px-2.5 py-1 rounded-md transition-all inline-flex items-center gap-1.5 cursor-pointer">
                            <span>Active Issues</span>
                            @if ($totals['seo_blocked'] > 0)
                                <span class="status-pill status-red text-[10px] py-0 px-1.5 leading-tight">{{ $totals['seo_blocked'] }}</span>
                            @else
                                <span class="status-pill status-green text-[10px] py-0 px-1.5 leading-tight">0</span>
                            @endif
                        </button>
                        <button type="button"
                                @click="activeTab = 'ignored'"
                                :class="activeTab === 'ignored' ? 'bg-[var(--color-surface)] shadow-xs font-semibold text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]'"
                                class="px-2.5 py-1 rounded-md transition-all inline-flex items-center gap-1.5 cursor-pointer">
                            <span>Ignored / Suppressed</span>
                            <span class="px-1.5 py-0.2 rounded-full text-[10px] font-mono font-medium {{ $ignoredSeoIssues->isNotEmpty() ? 'bg-[var(--color-surface-subtle)] text-[var(--color-ink-strong)] border border-[var(--color-border-light)]' : 'text-[var(--color-ink-muted)]' }}">
                                {{ $ignoredSeoIssues->count() }}
                            </span>
                        </button>
                    </div>
                </div>
                <x-issue-priority-menu :category="'seo-indexability'"/>
            
                    <button type="button" @click.stop="toggleSection('seo-indexability')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('seo-indexability') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('seo-indexability') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('seo-indexability')" class="rounded-b-[var(--radius-card)] overflow-hidden">

            {{-- ACTIVE SEO ISSUES TABLE --}}
            <div x-show="activeTab === 'active'">
                @if ($seoIssues->isEmpty())
                    <div class="p-8 text-center text-xs text-[var(--color-ink-muted)]">
                        <i class="fa-solid fa-circle-check text-emerald-500 text-lg mb-2 block"></i>
                        No active SEO indexability issues across the fleet.
                        @if ($ignoredSeoIssues->isNotEmpty())
                            <span class="block mt-1">
                                ({{ $ignoredSeoIssues->count() }} {{ Str::plural('site', $ignoredSeoIssues->count()) }} currently suppressed in <button type="button" @click="activeTab = 'ignored'" class="text-[var(--color-brand)] underline hover:text-[var(--color-brand-dark)] cursor-pointer">Ignored</button>)
                            </span>
                        @endif
                    </div>
                @else
                    <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                                <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                                <x-sort-th key="status" class="px-5 py-2">Status</x-sort-th>
                                <x-sort-th key="reason" class="px-5 py-2">Blocked vector</x-sort-th>
                                <th class="px-5 py-2">Snippet</th>
                                <x-sort-th key="checked" class="px-5 py-2">Checked</x-sort-th>
                                <th class="px-5 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($seoIssues as $s)
                                @php
                                    $isStaging = $s->server?->isStaging() ?? false;
                                    $pillClass = $isStaging ? 'status-gray' : 'status-red';
                                    $statusLabel = $s->seoStatusLabel();
                                    $reasonLabel = match ($s->seo_blocked_reason) {
                                        'meta_noindex' => 'Meta noindex',
                                        'header_noindex' => 'X-Robots-Tag',
                                        'robots_disallow_all' => 'robots.txt Disallow',
                                        default => $s->seo_blocked_reason ?? 'Blocked',
                                    };
                                @endphp
                                <tr data-site-row="{{ $s->id }}"
                                    data-sort-site="{{ $s->domain }}"
                                    data-sort-server="{{ $s->server?->name ?? '' }}"
                                    data-sort-status="{{ $isStaging ? 'staging' : 'production' }}"
                                    data-sort-reason="{{ $s->seo_blocked_reason }}"
                                    data-sort-checked="{{ $s->seo_checked_at?->getTimestamp() ?? '' }}">
                                    <td class="px-5 py-2 font-data">
                                        <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                                    </td>
                                    <td class="px-5 py-2 text-xs font-data">
                                        @if ($s->server)
                                            <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server->name }}</a>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 cell-status">
                                        <span class="status-pill {{ $pillClass }} text-[10px]">{{ $statusLabel }}</span>
                                    </td>
                                    <td class="px-5 py-2 text-xs font-medium text-[var(--color-ink)] cell-reason">
                                        {{ $reasonLabel }}
                                    </td>
                                    <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] font-mono cell-snippet">
                                        @if ($s->seo_blocked_snippet)
                                            <code class="bg-[var(--color-surface-subtle)] px-1.5 py-0.5 rounded text-[11px]">{{ Str::limit($s->seo_blocked_snippet, 55) }}</code>
                                        @else
                                            <span class="text-[var(--color-ink-soft)]">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] cell-checked">
                                        {{ $s->seo_checked_at?->diffForHumans() ?? 'never' }}
                                    </td>
                                    <td class="px-5 py-2 text-right">
                                        <div class="flex items-center justify-end gap-2.5">
                                            <button type="button"
                                                    class="text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] inline-flex items-center gap-1 cursor-pointer"
                                                    title="Ignore this alert if noindex is intentional"
                                                    @click="openIgnore({{ $s->id }}, '{{ $s->domain }}')">
                                                <i class="fa-solid fa-eye-slash text-[11px]"></i>
                                                <span>Ignore</span>
                                            </button>
                                            <button type="button"
                                                    class="preflight-btn text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50 inline-flex items-center gap-1 cursor-pointer"
                                                    data-url="{{ route('sites.seo.preflight', $s) }}">
                                                <i class="fa-solid fa-rotate"></i>
                                                <span>Pre-flight check</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- IGNORED SEO ISSUES TABLE --}}
            <div x-show="activeTab === 'ignored'" x-cloak>
                @if ($ignoredSeoIssues->isEmpty())
                    <div class="p-8 text-center text-xs text-[var(--color-ink-muted)]">
                        <i class="fa-solid fa-info-circle text-[var(--color-ink-soft)] text-lg mb-2 block"></i>
                        No SEO indexability issues are currently ignored.
                    </div>
                @else
                    <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                                <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                                <th class="px-5 py-2">Blocked Vector</th>
                                <th class="px-5 py-2">Ignore Reason</th>
                                <x-sort-th key="ignored_at" class="px-5 py-2">Ignored Date</x-sort-th>
                                <th class="px-5 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($ignoredSeoIssues as $item)
                                @php
                                    $site = $item->site;
                                    $reasonLabel = match ($site?->seo_blocked_reason) {
                                        'meta_noindex' => 'Meta noindex',
                                        'header_noindex' => 'X-Robots-Tag',
                                        'robots_disallow_all' => 'robots.txt Disallow',
                                        default => $site?->seo_blocked_reason ?? 'Blocked',
                                    };
                                @endphp
                                <tr data-sort-site="{{ $site?->domain ?? '' }}"
                                    data-sort-server="{{ $site?->server?->name ?? '' }}"
                                    data-sort-ignored_at="{{ $item->created_at?->getTimestamp() ?? '' }}">
                                    <td class="px-5 py-2 font-data">
                                        @if ($site)
                                            <a href="{{ route('sites.show', $site) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $site->domain }}</a>
                                        @else
                                            <span class="text-[var(--color-ink-muted)]">Deleted Site #{{ $item->site_id }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-xs font-data">
                                        @if ($site?->server)
                                            <a href="{{ route('servers.show', $site->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $site->server->name }}</a>
                                        @else
                                            <span class="text-[var(--color-ink-soft)]">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-xs text-[var(--color-ink)] font-medium">
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-[var(--color-surface-subtle)] text-[11px] border border-[var(--color-border-light)]">
                                            <i class="fa-solid fa-ban text-[var(--color-ink-muted)] text-[10px]"></i>
                                            {{ $reasonLabel }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-2 text-xs text-[var(--color-ink-strong)]">
                                        @if ($item->reason)
                                            <span class="font-medium text-[var(--color-ink-strong)]">{{ $item->reason }}</span>
                                        @else
                                            <span class="text-[var(--color-ink-soft)] italic">No reason provided</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] font-data">
                                        {{ $item->created_at?->format('M j, Y') }}
                                        @if ($item->user)
                                            <span class="text-[11px] text-[var(--color-ink-muted)]">by {{ $item->user->name }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-right">
                                        <div class="flex items-center justify-end gap-2.5">
                                            <form method="POST" action="{{ route('issues.unignore', $item) }}" class="inline">
                                                @csrf
                                                <button type="submit"
                                                        class="btn-pill-nav text-xs cursor-pointer"
                                                        title="Resume monitoring and alerting for this site">
                                                    <i class="fa-solid fa-play text-emerald-600 text-[10px]"></i>
                                                    <span>Resume monitoring</span>
                                                </button>
                                            </form>
                                            @if ($site)
                                                <button type="button"
                                                        class="preflight-btn text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50 inline-flex items-center gap-1 cursor-pointer"
                                                        data-url="{{ route('sites.seo.preflight', $site) }}">
                                                    <i class="fa-solid fa-rotate"></i>
                                                    <span>Pre-flight</span>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- IGNORE MODAL --}}
            <div x-show="ignoreModalOpen"
                 x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-xs"
                 @keydown.escape.window="ignoreModalOpen = false">
                <div class="card p-5 max-w-md w-full bg-[var(--color-surface)] shadow-xl border border-[var(--color-border-light)] rounded-xl"
                     @click.outside="ignoreModalOpen = false">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-8 h-8 rounded-full bg-amber-500/10 text-amber-600 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-eye-slash"></i>
                            </span>
                            <div>
                                <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)]">Ignore SEO Indexability Alert</h3>
                                <p class="text-xs text-[var(--color-ink-muted)] font-data" x-text="targetDomain"></p>
                            </div>
                        </div>
                        <button type="button" @click="ignoreModalOpen = false" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] cursor-pointer">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <p class="text-xs text-[var(--color-ink-muted)] mb-4 leading-relaxed">
                        Suppressing this alert hides the site from <code class="text-[11px] px-1 py-0.5 rounded bg-[var(--color-surface-subtle)]">/issues</code> and decrements the navigation badge. You can review all ignored sites and resume monitoring anytime.
                    </p>

                    <form method="POST" action="{{ route('issues.ignore') }}">
                        @csrf
                        <input type="hidden" name="issue_type" value="seo_indexability">
                        <input type="hidden" name="site_id" :value="targetSiteId">

                        <div class="mb-4">
                            <label class="block text-[11px] uppercase tracking-wider text-[var(--color-ink-soft)] font-semibold mb-1.5">
                                Reason for ignoring (optional)
                            </label>
                            <input type="text"
                                   name="reason"
                                   x-model="ignoreReason"
                                   placeholder="e.g. Internal employee intranet, volunteer portal, deliberate noindex"
                                   class="w-full text-xs px-3 py-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] placeholder:text-[var(--color-ink-muted)] focus:outline-hidden focus:ring-2 focus:ring-[var(--color-brand)]/20 focus:border-[var(--color-brand)]">
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-[var(--color-border-light)]">
                            <button type="button"
                                    @click="ignoreModalOpen = false"
                                    class="btn-pill-nav text-xs cursor-pointer">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="btn-primary text-xs font-medium cursor-pointer">
                                <i class="fa-solid fa-eye-slash text-[11px]"></i>
                                <span>Ignore Alert</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                (function () {
                    const csrf = '{{ csrf_token() }}';
                    document.querySelectorAll('#section-seo-indexability .preflight-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const row = btn.closest('tr');
                            const original = btn.innerHTML;
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking…';
                            try {
                                const r = await fetch(btn.dataset.url, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                });
                                const data = await r.json();
                                if (data.ok) {
                                    if (data.indexable) {
                                        row.style.transition = 'opacity 400ms';
                                        row.style.opacity = '0';
                                        setTimeout(() => row.remove(), 450);
                                    } else {
                                        const pillClass = data.is_staging ? 'status-gray' : 'status-red';
                                        const pill = document.createElement('span');
                                        pill.className = `status-pill ${pillClass} text-[10px]`;
                                        pill.textContent = data.status_label;
                                        const statusCell = row.querySelector('.cell-status');
                                        statusCell.textContent = '';
                                        statusCell.appendChild(pill);

                                        if (data.reason) {
                                            row.querySelector('.cell-reason').textContent = data.reason;
                                        }
                                        if (data.snippet) {
                                            const codeEl = document.createElement('code');
                                            codeEl.className = 'bg-[var(--color-surface-subtle)] px-1.5 py-0.5 rounded text-[11px]';
                                            codeEl.textContent = data.snippet.substring(0, 55);
                                            const snippetCell = row.querySelector('.cell-snippet');
                                            snippetCell.textContent = '';
                                            snippetCell.appendChild(codeEl);
                                        }
                                        row.querySelector('.cell-checked').textContent = 'just now';
                                        btn.innerHTML = original;
                                        btn.disabled = false;
                                    }
                                } else {
                                    btn.innerHTML = '<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> Failed';
                                    setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                                }
                            } catch (e) {
                                btn.innerHTML = '<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> Error';
                                setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                            }
                        });
                    });
                })();
            </script>
        </div>
        </section>
    @endif

        {{-- =========================================================================
             TIER 2: INFRASTRUCTURE & SERVER HEALTH
             ========================================================================= --}}
        <div x-show="(tierTab === 'all' || tierTab === 'infrastructure') && hasVisibleCategoryInTier('infrastructure')"
             class="mb-3 mt-8 flex items-center justify-between gap-2 border-b border-[var(--color-border-light)] pb-2">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-[var(--color-status-yellow)]"></span>
                <h3 class="font-display text-xs uppercase tracking-wider font-semibold text-[var(--color-status-yellow)]">
                    Infrastructure & Server Health
                </h3>
            </div>
            <span class="text-xs text-[var(--color-ink-muted)] font-medium font-mono">{{ $tierTotals['infrastructure'] }} total</span>
        </div>

        {{-- HOT --}}
    @if ($hotServers->isNotEmpty())
        <section id="section-hot" x-show="isCategoryVisible('hot') && matchesTier('infrastructure')" class="card mb-6">
            <div @click="toggleSection('hot')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('hot') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-temperature-three-quarters text-[var(--color-status-yellow)] mr-2"></i>
                        Hot servers
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">24-hour average exceeds CPU/memory/disk yellow thresholds (sustained, not spikes).</p>
                </div>
                <span class="status-pill status-yellow">{{ $hotServers->count() }}</span>
                <x-issue-priority-menu :category="'hot'"/>
            
                    <button type="button" @click.stop="toggleSection('hot')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('hot') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('hot') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('hot')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'cpu', defaultDir: 'desc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="reasons" class="px-5 py-2">Reasons (24h avg)</x-sort-th>
                        <x-sort-th key="cpu" align="right" class="px-5 py-2">CPU avg</x-sort-th>
                        <x-sort-th key="mem" align="right" class="px-5 py-2">Mem avg</x-sort-th>
                        <x-sort-th key="disk" align="right" class="px-5 py-2">Disk avg</x-sort-th>
                        <x-sort-th key="load" align="right" class="px-5 py-2">Load avg</x-sort-th>
                        <x-sort-th key="samples" align="right" class="px-5 py-2">Samples</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($hotServers as $s)
                        @php $a = $s->_avg; @endphp
                        <tr
                            data-sort-server="{{ $s->name }}"
                            data-sort-reasons="{{ implode(' · ', $s->_reasons) }}"
                            data-sort-cpu="{{ $a->avg_cpu ?? '' }}"
                            data-sort-mem="{{ $a->avg_memory ?? '' }}"
                            data-sort-disk="{{ $a->avg_disk ?? '' }}"
                            data-sort-load="{{ $a->avg_load ?? '' }}"
                            data-sort-samples="{{ $a->samples }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('servers.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ implode(' · ', $s->_reasons) }}</td>
                            <td class="px-5 py-2 text-right text-xs">{{ $a->avg_cpu !== null ? number_format((float) $a->avg_cpu, 0) . '%' : '—' }}</td>
                            <td class="px-5 py-2 text-right text-xs">{{ $a->avg_memory !== null ? number_format((float) $a->avg_memory, 0) . '%' : '—' }}</td>
                            <td class="px-5 py-2 text-right text-xs">{{ $a->avg_disk !== null ? number_format((float) $a->avg_disk, 0) . '%' : '—' }}</td>
                            <td class="px-5 py-2 text-right text-xs">{{ $a->avg_load !== null ? number_format((float) $a->avg_load, 2) : '—' }}</td>
                            <td class="px-5 py-2 text-right text-xs text-[var(--color-ink-soft)]">{{ $a->samples }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- DOMAIN EXPIRATION --}}
    @if ($domainExpirationIssues->isNotEmpty())
        <section id="section-domain-expiration" x-show="isCategoryVisible('domain-expiration') && matchesTier('infrastructure')" class="card mb-6">
            <div @click="toggleSection('domain-expiration')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('domain-expiration') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-globe text-[var(--color-ink-muted)] mr-2"></i>
                        Domain expiration
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                        Yellow = renewal window (≤30 days) · Red = expiring soon (≤7 days) or redemption/pending delete.
                    </p>
                </div>
                <span class="status-pill status-yellow">{{ $domainExpirationIssues->count() }}</span>
                <x-issue-priority-menu :category="'domain-expiration'"/>
            
                    <button type="button" @click.stop="toggleSection('domain-expiration')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('domain-expiration') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('domain-expiration') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('domain-expiration')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'expires', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="state" class="px-5 py-2">State</x-sort-th>
                        <x-sort-th key="expires" class="px-5 py-2">Expires</x-sort-th>
                        <x-sort-th key="registrar" class="px-5 py-2">Registrar</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($domainExpirationIssues as $s)
                        @php
                            $state = $s->domainExpirationState();
                            $stateClass = $state === 'red' ? 'status-red' : 'status-yellow';
                            $stateLabel = $s->domainExpirationStateLabel();
                        @endphp
                        <tr data-site-row="{{ $s->id }}"
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name ?? '' }}"
                            data-sort-state="{{ $state }}"
                            data-sort-expires="{{ $s->domain_expires_at?->getTimestamp() ?? '' }}"
                            data-sort-registrar="{{ $s->domain_registrar ?? '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                @if ($s->server)
                                    <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server->name }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-2 cell-state">
                                <span class="status-pill {{ $stateClass }} text-[10px]">{{ $stateLabel }}</span>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] cell-expires">
                                {{ $s->domain_expires_at?->format('M j, Y') }}
                                <span class="text-[var(--color-ink-soft)]">({{ $s->domain_expires_at?->diffForHumans() }})</span>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] font-data cell-registrar">
                                {{ $s->domain_registrar ?? 'Unknown' }}
                            </td>
                            <td class="px-5 py-2 text-right">
                                <button type="button"
                                        class="recheck-btn text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50"
                                        data-url="{{ route('sites.domain.recheck', $s) }}">
                                    <i class="fa-solid fa-rotate"></i> Recheck
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <script>
                (function () {
                    const csrf = '{{ csrf_token() }}';
                    document.querySelectorAll('#section-domain-expiration .recheck-btn').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const row = btn.closest('tr');
                            const original = btn.innerHTML;
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                            try {
                                const r = await fetch(btn.dataset.url, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                                });
                                const data = await r.json();
                                if (data.ok) {
                                    if (data.state === 'green' || data.state === 'none') {
                                        row.style.transition = 'opacity 400ms';
                                        row.style.opacity = '0';
                                        setTimeout(() => row.remove(), 450);
                                    } else {
                                        const cls = data.state === 'red' ? 'status-red' : 'status-yellow';
                                        const pill = document.createElement('span');
                                        pill.className = `status-pill ${cls} text-[10px]`;
                                        pill.textContent = data.state_label;
                                        const stateCell = row.querySelector('.cell-state');
                                        stateCell.textContent = '';
                                        stateCell.appendChild(pill);
                                        if (data.expires_formatted) {
                                            row.querySelector('.cell-expires').textContent =
                                                data.expires_formatted + (data.days_remaining !== null ? ` (${data.days_remaining}d left)` : '');
                                        }
                                        if (data.registrar) {
                                            row.querySelector('.cell-registrar').textContent = data.registrar;
                                        }
                                        btn.innerHTML = original;
                                        btn.disabled = false;
                                    }
                                } else {
                                    btn.innerHTML = '<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> Failed';
                                    setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                                }
                            } catch (e) {
                                btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
                                setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 4000);
                            }
                        });
                    });
                })();
            </script>
        </div>
        </section>
    @endif

        {{-- CLOUDFLARE MISCONFIG --}}
    @if ($cfMisconfigured->isNotEmpty())
        <section id="section-cf" x-show="isCategoryVisible('cf') && matchesTier('infrastructure')" class="card mb-6">
            <div @click="toggleSection('cf')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('cf') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-cloud text-yellow-500 mr-2"></i>
                        Cloudflare misconfigured
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                        These domains use Cloudflare DNS but the proxy (orange cloud) is off — traffic is hitting the origin directly. Toggle the proxy on in Cloudflare → DNS to fix.
                    </p>
                </div>
                <span class="status-pill status-yellow">{{ $cfMisconfigured->count() }}</span>
                <x-issue-priority-menu :category="'cf'"/>
            
                    <button type="button" @click.stop="toggleSection('cf')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('cf') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('cf') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('cf')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="arecord" class="px-5 py-2 font-data">A record (origin)</x-sort-th>
                        <x-sort-th key="ns" class="px-5 py-2 font-data">NS</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($cfMisconfigured as $s)
                        <tr
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name ?? '' }}"
                            data-sort-arecord="{{ $s->resolved_a_record ?? '' }}"
                            data-sort-ns="{{ $s->resolved_ns_record ?? '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                @if ($s->server)
                                    <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server->name }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs font-data text-[var(--color-ink-soft)]">{{ $s->resolved_a_record ?? '—' }}</td>
                            <td class="px-5 py-2 text-xs font-data text-[var(--color-ink-soft)]">{{ $s->resolved_ns_record ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- Shared banner for any in-page server-reboot button — sits above both
         Patches Available and Reboot Required so the JS handler below can
         flash status without depending on which card is rendered. --}}
    @if ($patchesAvailable->isNotEmpty() || $rebootRequired->isNotEmpty())
        <div id="reboot-action-banner" class="hidden card px-5 py-3 mb-4 text-sm"></div>
    @endif

    {{-- PATCHES (Server.upgrade_required — set by SpinupWP import or the daily SSH poll) --}}
    @if ($patchesAvailable->isNotEmpty())
        <section id="section-patches" x-show="isCategoryVisible('patches') && matchesTier('infrastructure')" class="card mb-6">
            <div @click="toggleSection('patches')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('patches') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-cube text-[var(--color-ink-muted)] mr-2"></i>
                        Patches available
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Non-security apt updates are pending. Security updates auto-install via unattended-upgrades.</p>
                </div>
                <span class="status-pill status-yellow">{{ $patchesAvailable->count() }}</span>
                <x-issue-priority-menu :category="'patches'"/>
            
                    <button type="button" @click.stop="toggleSection('patches')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('patches') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('patches') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('patches')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'server', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="ubuntu" class="px-5 py-2">Ubuntu</x-sort-th>
                        <x-sort-th key="reboot" class="px-5 py-2">Reboot</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($patchesAvailable as $s)
                        <tr
                            data-sort-server="{{ $s->name }}"
                            data-sort-ubuntu="{{ $s->ubuntu_version ?: '' }}"
                            data-sort-reboot="{{ $s->reboot_required ? '1' : '0' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('servers.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] font-data">ubuntu {{ $s->ubuntu_version ?: '?' }}</td>
                            <td class="px-5 py-2 text-xs text-right">
                                @if ($s->reboot_required)
                                    <span class="text-[var(--color-ink-soft)] mr-2">reboot pending</span>
                                @endif
                                <button type="button"
                                        class="reboot-now text-xs text-[var(--color-status-yellow)] hover:opacity-80 disabled:opacity-50 font-medium"
                                        data-url="{{ route('servers.reboot', $s) }}"
                                        data-server-name="{{ $s->name }}">
                                    <i class="fa-solid fa-power-off"></i> Reboot now
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- REBOOT (Server.reboot_required — typically a kernel patch was applied via unattended-upgrades) --}}
    @if ($rebootRequired->isNotEmpty())
        <section id="section-reboot" x-show="isCategoryVisible('reboot') && matchesTier('infrastructure')" class="card mb-6">
            <div @click="toggleSection('reboot')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('reboot') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-power-off text-[var(--color-ink-muted)] mr-2"></i>
                        Reboot required
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">A kernel or system update has been applied; the server is running an old image until rebooted.</p>
                </div>
                <span class="status-pill status-yellow">{{ $rebootRequired->count() }}</span>
                <x-issue-priority-menu :category="'reboot'"/>
            
                    <button type="button" @click.stop="toggleSection('reboot')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('reboot') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('reboot') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('reboot')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'server', defaultDir: 'asc' })" id="reboot-list">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="ubuntu" class="px-5 py-2">Ubuntu</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($rebootRequired as $s)
                        <tr data-server-id="{{ $s->id }}"
                            data-sort-server="{{ $s->name }}"
                            data-sort-ubuntu="{{ $s->ubuntu_version ?: '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('servers.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] font-data">ubuntu {{ $s->ubuntu_version ?: '?' }}</td>
                            <td class="px-5 py-2 text-right">
                                <div class="inline-flex items-center gap-3 justify-end">
                                    <button type="button"
                                            class="reboot-recheck text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50"
                                            data-url="{{ route('servers.reboot.probe', $s) }}"
                                            data-server-name="{{ $s->name }}">
                                        <i class="fa-solid fa-rotate"></i> Recheck
                                    </button>
                                    <button type="button"
                                            class="reboot-now text-xs text-[var(--color-status-yellow)] hover:opacity-80 disabled:opacity-50 font-medium"
                                            data-url="{{ route('servers.reboot', $s) }}"
                                            data-server-name="{{ $s->name }}">
                                        <i class="fa-solid fa-power-off"></i> Reboot now
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- Reboot-button JS handler — wired for any .reboot-now / .reboot-recheck
         button on this page. Renders whenever either the Patches Available or
         Reboot Required section is shown so the shared #reboot-action-banner
         it talks to is always in the DOM. --}}
    @if ($patchesAvailable->isNotEmpty() || $rebootRequired->isNotEmpty())
        <script>
            (function () {
                const result = document.getElementById('reboot-action-banner');
                const setBanner = (cls, html) => {
                    // Preserve the card framing the blade markup sets up; just
                    // swap the color modifier.
                    result.className = `card px-5 py-3 mb-4 text-sm ${cls}`;
                    result.innerHTML = html;
                    result.classList.remove('hidden');
                };
                const fadeOut = (row) => {
                    row.style.transition = 'opacity 0.5s';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 500);
                };

                document.querySelectorAll('.reboot-recheck').forEach((btn) => {
                    btn.addEventListener('click', async () => {
                        const row = btn.closest('tr');
                        btn.disabled = true;
                        const original = btn.innerHTML;
                        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Probing…';
                        setBanner('text-[var(--color-ink-muted)]', `Probing ${btn.dataset.serverName}…`);
                        try {
                            const r = await fetch(btn.dataset.url, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                            });
                            const data = await r.json();
                            if (data.ok) {
                                if (data.reboot_required) {
                                    setBanner('text-[var(--color-status-yellow)]',
                                        `<i class="fa-solid fa-triangle-exclamation"></i> ${btn.dataset.serverName}: ${data.message}`);
                                } else {
                                    setBanner('text-[var(--color-status-green)]',
                                        `<i class="fa-solid fa-circle-check"></i> ${btn.dataset.serverName}: ${data.message} Removing from list.`);
                                    fadeOut(row);
                                }
                            } else {
                                setBanner('text-[var(--color-status-red)]',
                                    `<i class="fa-solid fa-circle-xmark"></i> ${btn.dataset.serverName}: ${data.message || 'Failed.'}`);
                            }
                        } catch (e) {
                            setBanner('text-[var(--color-status-red)]', 'Network error: ' + e.message);
                        } finally {
                            btn.disabled = false;
                            btn.innerHTML = original;
                        }
                    });
                });

                // Reboot now — confirm, POST, fade row. Posts no reboot_at, so
                // ServerUpdateController::reboot schedules a +1-minute reboot.
                document.querySelectorAll('.reboot-now').forEach((btn) => {
                    btn.addEventListener('click', async () => {
                        const row = btn.closest('tr');
                        const name = btn.dataset.serverName;
                        const ok = await window.confirmModal({
                            title: `Reboot ${name} now?`,
                            details: 'The box will go down momentarily and come back in ~30–90 seconds.',
                            confirmText: 'Reboot Server',
                            variant: 'warning'
                        });
                        if (! ok) {
                            return;
                        }
                        btn.disabled = true;
                        const original = btn.innerHTML;
                        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Issuing…';
                        setBanner('text-[var(--color-ink-muted)]', `Issuing reboot on ${name}…`);
                        try {
                            const r = await fetch(btn.dataset.url, {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({}),
                            });
                            const data = await r.json();
                            if (data.ok) {
                                setBanner('text-[var(--color-status-green)]',
                                    `<i class="fa-solid fa-circle-check"></i> ${name}: ${data.message}`);
                                fadeOut(row);
                            } else {
                                setBanner('text-[var(--color-status-red)]',
                                    `<i class="fa-solid fa-circle-xmark"></i> ${name}: ${data.message || 'Reboot failed.'}`);
                            }
                        } catch (e) {
                            setBanner('text-[var(--color-status-red)]', 'Network error: ' + e.message);
                        } finally {
                            btn.disabled = false;
                            btn.innerHTML = original;
                        }
                    });
                });
            })();
        </script>
    @endif

    {{-- SSH --}}
    @if ($missingSsh->isNotEmpty())
        <section id="section-no_ssh" x-show="isCategoryVisible('no_ssh') && matchesTier('infrastructure')" class="card mb-6">
            <div @click="toggleSection('no_ssh')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('no_ssh') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-key text-[var(--color-ink-muted)] mr-2"></i>
                        SSH not verified
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Until SSH is verified, the server can't pull logs or ban IPs.</p>
                </div>
                <span class="status-pill status-yellow">{{ $missingSsh->count() }}</span>
                <x-issue-priority-menu :category="'no_ssh'"/>
            
                    <button type="button" @click.stop="toggleSection('no_ssh')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('no_ssh') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('no_ssh') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('no_ssh')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'server', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="status" class="px-5 py-2">Status</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($missingSsh as $s)
                        @php $sshStatus = $s->ssh_password ? 'Password stored — needs Test SSH' : 'No credentials yet'; @endphp
                        <tr
                            data-sort-server="{{ $s->name }}"
                            data-sort-status="{{ $sshStatus }}"
                            x-data="{ testing: false, result: null }"
                        >
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('servers.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->name }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs">
                                <span x-show="result === null" class="text-[var(--color-ink-soft)]">{{ $sshStatus }}</span>
                                <span x-show="result !== null && result.ok" x-cloak class="text-green-600 font-medium" x-text="result?.message"></span>
                                <span x-show="result !== null && !result.ok" x-cloak class="text-red-500" x-text="result?.message"></span>
                            </td>
                            <td class="px-5 py-2 text-right">
                                @if ($s->ssh_password)
                                    <button
                                        @click="
                                            testing = true;
                                            fetch('{{ route('servers.test', $s) }}', {
                                                method: 'POST',
                                                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
                                            })
                                            .then(r => r.json())
                                            .then(d => { result = d; testing = false; })
                                            .catch(() => { result = { ok: false, message: 'Request failed' }; testing = false; })
                                        "
                                        :disabled="testing"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] hover:bg-gray-200 transition-colors disabled:opacity-50"
                                    >
                                        <span x-show="!testing"><i class="fa-solid fa-plug mr-1"></i>Test SSH</span>
                                        <span x-show="testing" x-cloak><i class="fa-solid fa-spinner fa-spin mr-1"></i>Testing…</span>
                                    </button>
                                @else
                                    <a href="{{ route('servers.credentials.edit', $s) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] hover:bg-gray-200 transition-colors">
                                        <i class="fa-solid fa-key mr-1"></i>Add password
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- JAIL --}}
    @if ($missingJail->isNotEmpty())
        <section id="section-no_jail" x-show="isCategoryVisible('no_jail') && matchesTier('infrastructure')" class="card mb-6">
            <div @click="toggleSection('no_jail')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('no_jail') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-shield-halved text-[var(--color-ink-muted)] mr-2"></i>
                        Fail2ban not provisioned
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">SSH works but the clockwork jail isn't installed yet — banning IPs won't work on these.</p>
                </div>
                <span class="status-pill status-yellow">{{ $missingJail->count() }}</span>
                <x-issue-priority-menu :category="'no_jail'"/>
            
                    <button type="button" @click.stop="toggleSection('no_jail')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('no_jail') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('no_jail') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('no_jail')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'server', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($missingJail as $s)
                        <tr data-sort-server="{{ $s->name }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('servers.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->name }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- COMPANION MISSING / STALE --}}
    @if ($companionMissing->isNotEmpty())
        <section id="section-no_companion" x-show="isCategoryVisible('no_companion') && matchesTier('infrastructure')" class="card mb-6">
            <div @click="toggleSection('no_companion')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('no_companion') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-plug-circle-xmark text-[var(--color-ink-muted)] mr-2"></i>
                        Companion plugin not reachable
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Form testing is enabled for these sites but the Companion mu-plugin hasn't checked in within 48h (or was never installed). Click into each site and hit <strong>Install Companion</strong> on the Settings tab.</p>
                </div>
                <span class="status-pill status-yellow">{{ $companionMissing->count() }}</span>
                <x-issue-priority-menu :category="'no_companion'"/>
            
                    <button type="button" @click.stop="toggleSection('no_companion')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('no_companion') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('no_companion') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('no_companion')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        <x-sort-th key="installed" class="px-5 py-2">Installed</x-sort-th>
                        <x-sort-th key="version" class="px-5 py-2">Version</x-sort-th>
                        <x-sort-th key="last_seen" class="px-5 py-2">Last seen</x-sort-th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($companionMissing as $s)
                        <tr
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name ?? '' }}"
                            data-sort-installed="{{ $s->companion_installed ? '1' : '0' }}"
                            data-sort-version="{{ $s->companion_version }}"
                            data-sort-last_seen="{{ $s->companion_last_seen_at?->getTimestamp() ?? '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', ['site' => $s, 'tab' => 'forms']) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                @if ($s->server)
                                    <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server->name }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs">{{ $s->companion_installed ? 'yes' : 'no' }}</td>
                            <td class="px-5 py-2 text-xs font-data text-[var(--color-ink-soft)]">{{ $s->companion_version ?: '—' }}</td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $s->companion_last_seen_at?->diffForHumans() ?? 'never' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- ORPHAN SITES --}}
    @if ($orphanSites->isNotEmpty())
        <section id="section-orphans" x-show="isCategoryVisible('orphans') && matchesTier('infrastructure')" class="card mb-6">
            <div @click="toggleSection('orphans')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('orphans') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-link-slash text-[var(--color-ink-muted)] mr-2"></i>
                        Orphaned sites
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Local Site rows whose SpinupWP linkage is gone. Either consolidated under another site (safe to archive) or unknown (needs review). Refresh with <code class="bg-[var(--color-surface-alt)] px-1.5 py-0.5 rounded">php artisan clockwork:find-orphan-sites --archive-consolidated</code>.</p>
                </div>
                <span class="status-pill status-yellow">{{ $orphanSites->count() }}</span>
                <x-issue-priority-menu :category="'orphans'"/>
            
                    <button type="button" @click.stop="toggleSection('orphans')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('orphans') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('orphans') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('orphans')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-5 py-2">Domain</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Was on server</x-sort-th>
                        <x-sort-th key="status" class="px-5 py-2">Status</x-sort-th>
                        <x-sort-th key="parent" class="px-5 py-2">Consolidated under</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($orphanSites as $s)
                        @php
                            $parent = $s->consolidated_into_site_id ? ($orphanParents[$s->consolidated_into_site_id] ?? null) : null;
                            $statusLabel = $parent ? 'Consolidated' : 'Unknown';
                        @endphp
                        <tr
                            data-sort-site="{{ $s->domain }}"
                            data-sort-server="{{ $s->server?->name ?? '' }}"
                            data-sort-status="{{ $statusLabel }}"
                            data-sort-parent="{{ $parent?->domain ?? '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs font-data">
                                @if ($s->server)
                                    <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $s->server->name }}">{{ $s->server->display_name }}</a>
                                @else
                                    <span class="text-[var(--color-ink-soft)]">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-2">
                                @if ($parent)
                                    <span class="status-pill status-yellow text-[10px]">Consolidated</span>
                                @else
                                    <span class="status-pill status-red text-[10px]">Unknown</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-xs">
                                @if ($parent)
                                    <a href="{{ route('sites.show', $parent) }}" class="text-[var(--color-primary-600)] hover:underline font-data">{{ $parent->domain }}</a>
                                @else
                                    <span class="text-[var(--color-ink-soft)]">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-2 text-right">
                                <form method="POST" action="{{ route('issues.orphans.destroy', $s->id) }}"
                                      data-confirm="Remove {{ $s->domain }} from monitoring?"
                                      data-confirm-details="This archives the site row and removes it from all listings."
                                      data-confirm-btn="Remove Site"
                                      data-confirm-variant="danger">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded text-xs font-medium bg-red-50 text-red-700 hover:bg-red-100 transition-colors">
                                        <i class="fa-solid fa-trash"></i> Remove
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </section>
    @endif

        {{-- DB CREDS --}}
    @if ($missingDbCreds->isNotEmpty())
        <section id="section-no_db" x-show="isCategoryVisible('no_db') && matchesTier('infrastructure')" class="card mb-6">
            <div @click="toggleSection('no_db')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('no_db') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-database text-[var(--color-ink-muted)] mr-2"></i>
                        WordPress DB credentials missing
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Wordfence + LLAR ingest needs these. Fetched over SSH from wp-config.php.</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="status-pill status-yellow">{{ $missingDbCreds->count() }}</span>
                    <form method="POST" action="{{ route('issues.fetch-all-db-creds') }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] hover:bg-gray-200 transition-colors">
                            <i class="fa-solid fa-rotate"></i> Fetch all
                        </button>
                    </form>
                </div>
                <x-issue-priority-menu :category="'no_db'"/>
            
                    <button type="button" @click.stop="toggleSection('no_db')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('no_db') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('no_db') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('no_db')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <div class="max-h-96 overflow-y-auto">
                <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'site', defaultDir: 'asc' })">
                    <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                        <tr>
                            <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                            <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($missingDbCreds as $s)
                            <tr
                                data-sort-site="{{ $s->domain }}"
                                data-sort-server="{{ $s->server?->name ?? '' }}">
                                <td class="px-5 py-2 font-data">
                                    <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                                </td>
                                <td class="px-5 py-2 text-xs font-data">
                                    @if ($s->server)
                                        <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">{{ $s->server->name }}</a>
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-right">
                                    <form method="POST" action="{{ route('sites.fetch-db-creds', $s) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded text-xs font-medium bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] hover:bg-gray-200 transition-colors">
                                            <i class="fa-solid fa-rotate"></i> Fetch
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        </section>
    @endif

        {{-- =========================================================================
             TIER 3: ROUTINE MAINTENANCE & AUDITS
             ========================================================================= --}}
        <div x-show="(tierTab === 'all' || tierTab === 'routine') && hasVisibleCategoryInTier('routine')"
             class="mb-3 mt-8 flex items-center justify-between gap-2 border-b border-[var(--color-border-light)] pb-2">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span>
                <h3 class="font-display text-xs uppercase tracking-wider font-semibold text-[var(--color-ink-muted)]">
                    Routine Maintenance & Audits
                </h3>
                <span class="text-[11px] text-[var(--color-ink-soft)] font-normal font-sans">(Lower priority upkeep)</span>
            </div>
            <span class="text-xs text-[var(--color-ink-muted)] font-medium font-mono">{{ $tierTotals['routine'] }} total</span>
        </div>

        {{-- WP PLUGINS OUTDATED --}}
    @if ($pluginsOutdated->isNotEmpty())
        <section id="section-plugins_outdated" x-show="isCategoryVisible('plugins_outdated') && matchesTier('routine')" class="card mb-6">
            <div @click="toggleSection('plugins_outdated')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('plugins_outdated') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-cube text-[var(--color-ink-muted)] mr-2"></i>
                        WordPress plugins out of date
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Pulled from each site's Companion snapshot (refreshed nightly at 03:00). Counts reflect the cached <code class="bg-[var(--color-surface-alt)] px-1.5 py-0.5 rounded">update_plugins</code> transient on the site.</p>
                </div>
                <span class="status-pill status-yellow">{{ $pluginsOutdated->count() }}</span>
                <x-issue-priority-menu :category="'plugins_outdated'"/>
            
                    <button type="button" @click.stop="toggleSection('plugins_outdated')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('plugins_outdated') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('plugins_outdated') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('plugins_outdated')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            <div class="max-h-[32rem] overflow-y-auto">
                <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'security', defaultDir: 'desc' })">
                    <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                        <tr>
                            <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                            <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                            <x-sort-th key="careplan" class="px-5 py-2 text-center" title="Sort to group care-plan sites at the top — they're the ones we update routinely. Non-care-plan sites only get touched for security-driven updates.">Care plan</x-sort-th>
                            <x-sort-th key="security" class="px-5 py-2 text-center" title="Number of installed plugins matching a known CVE in the wpvulnerability.net mirror. Default sort to surface security-driven updates first regardless of care-plan status.">Security</x-sort-th>
                            <x-sort-th key="updates" class="px-5 py-2 text-right">Updates</x-sort-th>
                            <x-sort-th key="active" class="px-5 py-2 text-right">Active</x-sort-th>
                            <x-sort-th key="total" class="px-5 py-2 text-right">Total</x-sort-th>
                            <x-sort-th key="snapshot" class="px-5 py-2">Snapshot</x-sort-th>
                            <th class="px-5 py-2 text-center text-xs uppercase tracking-wide" title="Email a vulnerability report to a chosen recipient. Only enabled when CVEs are present.">Email</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($pluginsOutdated as $s)
                            @php
                                $counts = $s->companion_snapshot['plugins']['counts'] ?? [];
                                $updates = (int) ($counts['updates_available'] ?? 0);
                                $active = (int) ($counts['active'] ?? 0);
                                $total = (int) ($counts['total'] ?? 0);
                                $onCarePlan = (bool) $s->care_plan_enabled;
                                $vulns = $vulnsBySiteId[$s->id] ?? [];
                                $vulnCount = count($vulns);
                                $serverShortName = $s->server?->display_name ?? '';
                            @endphp
                            <tr
                                x-data="{
                                    vulnModalOpen: false,
                                    emailModalOpen: false,
                                    emailRecipient: '{{ Auth::user()?->email }}',
                                    emailSending: false,
                                    emailResult: null,
                                    refreshing: false,
                                    refreshError: null,
                                    snapshotAge: '{{ $s->companion_snapshot_at?->diffForHumans() ?? 'never' }}',
                                    updatesCount: {{ $updates }},
                                    async doRefresh() {
                                        if (this.refreshing) return;
                                        this.refreshing = true;
                                        this.refreshError = null;
                                        try {
                                            const res = await fetch('{{ route('sites.companion.refresh-snapshot', $s) }}', {
                                                method: 'POST',
                                                headers: {
                                                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                                    'Accept': 'application/json',
                                                },
                                            });
                                            const data = await res.json().catch(() => ({}));
                                            if (!res.ok || !data.ok) {
                                                this.refreshError = data.error || ('HTTP ' + res.status);
                                            } else {
                                                this.updatesCount = data.updates;
                                                this.snapshotAge = 'just now';
                                            }
                                        } catch (e) {
                                            this.refreshError = e.message;
                                        } finally {
                                            this.refreshing = false;
                                        }
                                    },
                                    async sendVulnEmail() {
                                        if (this.emailSending) return;
                                        this.emailSending = true;
                                        this.emailResult = null;
                                        try {
                                            const res = await fetch('{{ route('sites.email-vuln-report', $s) }}', {
                                                method: 'POST',
                                                headers: {
                                                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                                    'Content-Type': 'application/json',
                                                    'Accept': 'application/json',
                                                },
                                                body: JSON.stringify({ recipient: this.emailRecipient }),
                                            });
                                            const data = await res.json().catch(() => ({}));
                                            if (!res.ok || !data.ok) {
                                                this.emailResult = { ok: false, message: data.error || ('HTTP ' + res.status) };
                                            } else {
                                                this.emailResult = {
                                                    ok: true,
                                                    message: 'Sent to ' + data.recipient + ' (' + data.count + ' CVE' + (data.count === 1 ? '' : 's') + ')'
                                                        + (data.mailer === 'log' ? ' — driver=log, check storage/logs/laravel.log' : '')
                                                };
                                            }
                                        } catch (e) {
                                            this.emailResult = { ok: false, message: 'Request failed: ' + e.message };
                                        } finally {
                                            this.emailSending = false;
                                        }
                                    }
                                }"
                                data-sort-site="{{ $s->domain }}"
                                data-sort-server="{{ $serverShortName }}"
                                data-sort-careplan="{{ $onCarePlan ? 1 : 0 }}"
                                data-sort-security="{{ $vulnCount }}"
                                data-sort-updates="{{ $updates }}"
                                data-sort-active="{{ $active }}"
                                data-sort-total="{{ $total }}"
                                data-sort-snapshot="{{ $s->companion_snapshot_at?->getTimestamp() ?? '' }}">
                                <td class="px-5 py-2 font-data">
                                    <a href="{{ route('sites.show', $s) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $s->domain }}</a>
                                </td>
                                <td class="px-5 py-2 text-xs font-data">
                                    @if ($s->server)
                                        <a href="{{ route('servers.show', $s->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $s->server->name }}">{{ $serverShortName }}</a>
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-center text-xs">
                                    @if ($onCarePlan)
                                        <i class="fa-solid fa-shield-halved text-[var(--color-status-green)]" title="On care plan — updates and routine maintenance are included."></i>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]" title="Not on a care plan — only update for security reasons.">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-center text-xs">
                                    @if ($vulnCount > 0)
                                        <button type="button"
                                                class="status-pill status-red cursor-pointer hover:opacity-80"
                                                title="Click for CVE details"
                                                @click="vulnModalOpen = true">
                                            <i class="fa-solid fa-triangle-exclamation"></i> {{ $vulnCount }} CVE{{ $vulnCount === 1 ? '' : 's' }}
                                        </button>

                                        {{-- Modal: CVE details for this site. Click-outside / Esc to close. --}}
                                        <div x-show="vulnModalOpen"
                                             x-cloak
                                             @keydown.escape.window="vulnModalOpen = false"
                                             class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 bg-black/40"
                                             @click.self="vulnModalOpen = false"
                                             role="dialog"
                                             aria-modal="true">
                                            <div class="bg-[var(--color-surface)] rounded-[var(--radius-card)] shadow-2xl max-w-3xl w-full max-h-[85vh] overflow-y-auto border border-[var(--color-border)]"
                                                 @click.stop>
                                                <div class="px-6 py-4 border-b border-[var(--color-border-light)] flex items-start justify-between gap-4 sticky top-0 bg-[var(--color-surface)] z-10">
                                                    <div class="min-w-0">
                                                        <h3 class="font-display text-lg text-[var(--color-ink-strong)]">
                                                            <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-red)] mr-1"></i>
                                                            {{ $s->domain }}
                                                        </h3>
                                                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">{{ $vulnCount }} known CVE{{ $vulnCount === 1 ? '' : 's' }} matching installed plugin version{{ $vulnCount === 1 ? '' : 's' }} · sorted by severity</p>
                                                    </div>
                                                    <button type="button" @click="vulnModalOpen = false" class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] text-xl leading-none -mt-1" aria-label="Close">×</button>
                                                </div>
                                                <div class="px-6 py-4 space-y-4 text-left">
                                                    @foreach ($vulns as $v)
                                                        @php
                                                            $vuln = $v['vulnerability'];
                                                            $sev = $vuln->cvss_severity ?: ($vuln->cvss_score ? null : null);
                                                            $sevClass = match (strtolower((string) $sev)) {
                                                                'critical' => 'status-red',
                                                                'high' => 'status-red',
                                                                'medium' => 'status-yellow',
                                                                'low' => 'status-unknown',
                                                                default => 'status-unknown',
                                                            };
                                                        @endphp
                                                        <div class="border-l-2 {{ $v['patch_available'] ? 'border-[var(--color-status-yellow)]' : 'border-[var(--color-status-red)]' }} pl-3">
                                                            <div class="flex items-start justify-between gap-2 flex-wrap">
                                                                <div class="font-medium text-sm text-[var(--color-ink-strong)]">
                                                                    {{ $v['plugin_name'] }}
                                                                    <span class="text-xs text-[var(--color-ink-muted)] font-data">({{ $v['plugin_slug'] }})</span>
                                                                </div>
                                                                @if ($vuln->cve)
                                                                    <div class="flex items-center gap-2">
                                                                        <a href="https://www.cve.org/CVERecord?id={{ urlencode($vuln->cve) }}"
                                                                           target="_blank" rel="noopener"
                                                                           class="text-xs font-data text-[var(--color-primary-600)] hover:underline">
                                                                            {{ $vuln->cve }} <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                                                        </a>
                                                                        @if (in_array($vuln->cve, $cisaKevCves ?? [], true))
                                                                            <span class="status-pill status-red text-[10px] font-semibold" title="Listed in CISA's Known Exploited Vulnerabilities catalog (actively exploited in the wild)">
                                                                                <i class="fa-solid fa-triangle-exclamation mr-1"></i>Actively exploited (CISA KEV)
                                                                            </span>
                                                                        @endif
                                                                    </div>
                                                                @endif
                                                            </div>
                                                            <div class="text-xs text-[var(--color-ink-muted)] mt-1">{{ $vuln->title }}</div>
                                                            <div class="flex items-center gap-3 text-xs mt-2 flex-wrap">
                                                                <span class="font-data">
                                                                    <span class="text-[var(--color-ink-soft)]">installed:</span>
                                                                    <span class="text-[var(--color-status-red)] font-medium">{{ $v['current_version'] }}</span>
                                                                </span>
                                                                @if ($vuln->patched_in)
                                                                    <span class="font-data">
                                                                        <span class="text-[var(--color-ink-soft)]">→ patched in:</span>
                                                                        <span class="text-[var(--color-status-green)] font-medium">{{ $vuln->patched_in }}</span>
                                                                    </span>
                                                                @else
                                                                    <span class="status-pill status-red text-[10px]" title="No fixed version published yet — only mitigation is removing the plugin.">no patch yet</span>
                                                                @endif
                                                                @if (! $v['active'])
                                                                    <span class="status-pill status-unknown text-[10px]" title="Plugin is installed but not activated on this site.">inactive</span>
                                                                @endif
                                                                @if ($vuln->cvss_score)
                                                                    <span class="status-pill {{ $sevClass }} text-[10px]">CVSS {{ number_format($vuln->cvss_score, 1) }}{{ $sev ? ' · '.ucfirst($sev) : '' }}</span>
                                                                @endif
                                                                @if ($vuln->url)
                                                                    <a href="{{ $vuln->url }}" target="_blank" rel="noopener" class="text-[var(--color-primary-600)] hover:underline text-xs">
                                                                        Source <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                                                    </a>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]" title="No installed plugin versions match a known CVE.">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-right font-data text-xs"><span class="status-pill status-yellow" x-text="updatesCount"></span></td>
                                <td class="px-5 py-2 text-right text-xs font-data text-[var(--color-ink-soft)]">{{ $active }}</td>
                                <td class="px-5 py-2 text-right text-xs font-data text-[var(--color-ink-soft)]">{{ $total }}</td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]" x-text="snapshotAge"></td>
                                <td class="px-5 py-2 text-center text-xs">
                                    @if ($vulnCount > 0)
                                        <button type="button"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-full text-[var(--color-primary-600)] hover:bg-[var(--color-surface-alt)] cursor-pointer transition"
                                                title="Email this vulnerability list to a recipient"
                                                @click="emailModalOpen = true; emailResult = null">
                                            <i class="fa-regular fa-envelope"></i>
                                        </button>

                                        {{-- Modal: send vulnerability report. Click-outside / Esc to close. --}}
                                        <div x-show="emailModalOpen"
                                             x-cloak
                                             @keydown.escape.window="emailModalOpen = false"
                                             class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 bg-black/40"
                                             @click.self="emailModalOpen = false"
                                             role="dialog"
                                             aria-modal="true">
                                            <div class="bg-[var(--color-surface)] rounded-[var(--radius-card)] shadow-2xl max-w-lg w-full border border-[var(--color-border)]"
                                                 @click.stop>
                                                <div class="px-6 py-4 border-b border-[var(--color-border-light)] flex items-start justify-between gap-4">
                                                    <div class="min-w-0">
                                                        <h3 class="font-display text-lg text-[var(--color-ink-strong)]">
                                                            <i class="fa-regular fa-envelope text-[var(--color-primary-600)] mr-1"></i>
                                                            Email vulnerability report
                                                        </h3>
                                                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">{{ $s->domain }} · {{ $vulnCount }} CVE{{ $vulnCount === 1 ? '' : 's' }} will be included</p>
                                                    </div>
                                                    <button type="button" @click="emailModalOpen = false" class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] text-xl leading-none -mt-1" aria-label="Close">×</button>
                                                </div>
                                                <div class="px-6 py-5 text-left space-y-3">
                                                    <label class="block text-sm font-medium text-[var(--color-ink-strong)]">
                                                        Send to
                                                        <input type="email"
                                                               x-model="emailRecipient"
                                                               required
                                                               placeholder="recipient@example.com"
                                                               class="mt-1 block w-full px-3 py-2 border border-[var(--color-border)] rounded-md font-data text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)]"
                                                               @keydown.enter="sendVulnEmail()">
                                                    </label>
                                                    <p class="text-xs text-[var(--color-ink-soft)]">
                                                        @if (config('mail.default') === 'log')
                                                            <strong class="text-[var(--color-status-yellow)]">Note:</strong> mail driver is <code class="bg-[var(--color-surface-alt)] px-1 rounded">log</code> — the rendered email will be written to <code class="bg-[var(--color-surface-alt)] px-1 rounded">storage/logs/laravel.log</code> instead of being delivered. Switch <code class="bg-[var(--color-surface-alt)] px-1 rounded">MAIL_MAILER=mailgun</code> in <code class="bg-[var(--color-surface-alt)] px-1 rounded">.env</code> and add Mailgun credentials to send for real.
                                                        @else
                                                            Sent immediately via the <code class="bg-[var(--color-surface-alt)] px-1 rounded">{{ config('mail.default') }}</code> driver. The action is logged in audit history.
                                                        @endif
                                                    </p>
                                                    <template x-if="emailResult">
                                                        <div x-show="emailResult"
                                                             :class="emailResult.ok ? 'bg-[var(--color-status-green-bg)] text-[var(--color-status-green)] border-[var(--color-status-green)]' : 'bg-[var(--color-status-red-bg)] text-[var(--color-status-red)] border-[var(--color-status-red)]'"
                                                             class="text-xs px-3 py-2 rounded border">
                                                            <i class="fa-solid" :class="emailResult.ok ? 'fa-circle-check' : 'fa-circle-exclamation'"></i>
                                                            <span x-text="emailResult.message"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                                <div class="px-6 py-3 border-t border-[var(--color-border-light)] flex items-center justify-end gap-2 bg-[var(--color-border-light)]/40">
                                                    <button type="button"
                                                            @click="emailModalOpen = false"
                                                            class="px-3 py-1.5 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                                                        Close
                                                    </button>
                                                    <button type="button"
                                                            @click="sendVulnEmail()"
                                                            :disabled="emailSending || !emailRecipient"
                                                            class="btn-primary text-sm disabled:opacity-50 disabled:cursor-not-allowed">
                                                        <i class="fa-regular fa-paper-plane mr-1"></i>
                                                        <span x-text="emailSending ? 'Sending…' : 'Send report'"></span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]" title="No CVEs to report.">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <button type="button"
                                            @click="doRefresh()"
                                            :disabled="refreshing"
                                            :title="refreshError ? 'Error: ' + refreshError : 'Refresh Companion snapshot for this site'"
                                            class="inline-flex items-center justify-center w-7 h-7 rounded text-[var(--color-ink-soft)] hover:text-[var(--color-primary-600)] hover:bg-[var(--color-surface-alt)] transition disabled:opacity-40">
                                        <i class="fa-solid text-xs" :class="refreshing ? 'fa-spinner fa-spin' : (refreshError ? 'fa-triangle-exclamation text-[var(--color-status-amber)]' : 'fa-rotate-right')"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        </section>
    @endif

    {{-- AUTOMATIC UPDATES PAUSED (AUTO-IGNORED) --}}
    @if (! empty($autoIgnoredUpdates) && $autoIgnoredUpdates->isNotEmpty())
        <section id="section-auto_ignored_updates" class="card mb-6">
            <div @click="toggleSection('auto_ignored_updates')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('auto_ignored_updates') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-pause text-[var(--color-status-yellow)] mr-2"></i>
                        Automatic updates paused
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                        Plugins and themes excluded from the nightly auto-update loop after repeated update failures. Other plugins on these sites continue updating.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="status-pill status-yellow">{{ $autoIgnoredUpdates->count() }}</span>
                    <button type="button" @click.stop="toggleSection('auto_ignored_updates')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('auto_ignored_updates') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('auto_ignored_updates') ? 'rotate-180' : ''"></i>
                    </button>
                </div>
            </div>
            <div x-show="!isSectionCollapsed('auto_ignored_updates')" class="rounded-b-[var(--radius-card)] overflow-hidden">
                <div class="max-h-[28rem] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-5 py-2 text-left">Site</th>
                                <th class="px-5 py-2 text-left">Target</th>
                                <th class="px-5 py-2 text-center">Consecutive Failures</th>
                                <th class="px-5 py-2 text-left">Last Error</th>
                                <th class="px-5 py-2 text-left">Paused</th>
                                <th class="px-5 py-2 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($autoIgnoredUpdates as $ign)
                                @php $targetId = "{$ign->target_kind}:{$ign->site_id}:{$ign->target_slug}"; @endphp
                                <tr>
                                    <td class="px-5 py-2 font-data text-xs">
                                        <a href="{{ route('sites.show', ['site' => $ign->site_id, 'tab' => 'updates']) }}" class="text-[var(--color-primary-600)] hover:underline">
                                            {{ $ign->site?->domain ?? '#' . $ign->site_id }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-2 text-xs">
                                        <span class="font-medium text-[var(--color-ink-strong)]">{{ $ign->target_slug }}</span>
                                        <span class="text-[10px] text-[var(--color-ink-soft)]">({{ $ign->target_kind }})</span>
                                    </td>
                                    <td class="px-5 py-2 text-center font-data text-xs">
                                        <span class="px-1.5 py-0.5 rounded bg-[var(--color-status-yellow)]/10 text-[var(--color-status-yellow)] font-semibold">
                                            {{ $ign->failure_count ?? 5 }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] max-w-xs truncate" title="{{ $ign->last_error }}">
                                        {{ $ign->last_error ?: 'Automatic update failed repeated runs' }}
                                    </td>
                                    <td class="px-5 py-2 text-xs text-[var(--color-ink-soft)] whitespace-nowrap">
                                        {{ $ign->ignored_at?->diffForHumans() ?? 'recently' }}
                                    </td>
                                    <td class="px-5 py-2 text-right text-xs whitespace-nowrap">
                                        <form method="POST" action="{{ route('updates.bulkUnignore') }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="targets[]" value="{{ $targetId }}">
                                            <button type="submit" class="btn-pill-nav text-[11px] py-0.5 px-2" title="Resume automatic updates">
                                                Resume
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif

        {{-- CLOSED PLUGINS ON WORDPRESS.ORG --}}
    @if ($closedPluginSites->isNotEmpty() || $ignoredClosedPluginIssues->isNotEmpty())
        <section id="section-plugins_closed" x-show="isCategoryVisible('plugins_closed') && matchesTier('routine')" class="card mb-6">
            <div @click="toggleSection('plugins_closed')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-3 cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('plugins_closed') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-box-archive text-amber-600 mr-2"></i>
                        Plugins closed on WordPress.org
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                        Active plugins removed or closed in the official WordPress plugin directory. Closed plugins receive no updates or security patches.
                    </p>
                </div>
                <x-issue-priority-menu :category="'plugins_closed'"/>
            
                    <button type="button" @click.stop="toggleSection('plugins_closed')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('plugins_closed') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('plugins_closed') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('plugins_closed')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            @if ($closedPluginSites->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-5 py-2.5 text-left">Site</th>
                                <th class="px-5 py-2.5 text-left">Server</th>
                                <th class="px-5 py-2.5 text-left">Closed Plugin</th>
                                <th class="px-5 py-2.5 text-left">Closure Detail</th>
                                <th class="px-5 py-2.5 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($closedPluginSites as $site)
                                @php
                                    $siteFindings = $closedPluginFindingsBySiteId[$site->id] ?? [];
                                @endphp
                                @foreach ($siteFindings as $finding)
                                    <tr class="hover:bg-[var(--color-surface-hover)] transition-colors">
                                        <td class="px-5 py-3 font-medium">
                                            <a href="{{ route('sites.show', $site) }}" class="text-[var(--color-primary-600)] hover:underline flex items-center gap-1.5">
                                                <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-[var(--color-ink-muted)]"></i>
                                                {{ $site->domain }}
                                            </a>
                                        </td>
                                        <td class="px-5 py-3 text-[var(--color-ink-muted)]">
                                            {{ $site->server?->name ?? 'Standalone' }}
                                        </td>
                                        <td class="px-5 py-3">
                                            <div class="font-medium text-[var(--color-ink-strong)]">
                                                {{ $finding['name'] }}
                                            </div>
                                            <div class="text-xs text-[var(--color-ink-muted)] flex items-center gap-2 mt-0.5">
                                                <code>{{ $finding['slug'] }}</code>
                                                @if (! empty($finding['version']))
                                                    <span>v{{ $finding['version'] }}</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-5 py-3 text-xs max-w-md">
                                            @if (! empty($finding['reason']))
                                                <div class="text-[var(--color-ink-soft)] line-clamp-2" title="{{ $finding['reason'] }}">
                                                    {{ $finding['reason'] }}
                                                </div>
                                            @else
                                                <span class="text-[var(--color-ink-muted)] italic">No closure reason provided by WordPress.org</span>
                                            @endif
                                            @if (! empty($finding['closed_date']))
                                                <div class="text-[11px] text-[var(--color-ink-muted)] mt-0.5">
                                                    Closed: {{ $finding['closed_date'] }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-right">
                                            <form method="POST" action="{{ route('issues.ignore') }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="issue_type" value="plugin_closed">
                                                <input type="hidden" name="site_id" value="{{ $site->id }}">
                                                <button type="submit" class="text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:underline" title="Suppress closed plugin warning for {{ $site->domain }}">
                                                    Ignore site
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($ignoredClosedPluginIssues->isNotEmpty())
                <div class="px-5 py-3 border-t border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)] bg-[var(--color-surface-alt)] flex items-center flex-wrap gap-2">
                    <span class="font-medium">{{ $ignoredClosedPluginIssues->count() }} site(s) ignored:</span>
                    @foreach ($ignoredClosedPluginIssues as $ignored)
                        <form method="POST" action="{{ route('issues.unignore', $ignored) }}" class="inline">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-[var(--color-surface)] border border-[var(--color-border-light)] hover:border-[var(--color-border-strong)] text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors">
                                <span>{{ $ignored->site?->domain ?? 'Site #' . $ignored->site_id }}</span>
                                <i class="fa-solid fa-rotate-left text-[10px]"></i>
                            </button>
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
        </section>
    @endif

        @if ($flaggedAdminSites->isNotEmpty() || $ignoredAdminIssues->isNotEmpty())
        <section id="section-wp_admins" x-show="isCategoryVisible('wp_admins') && matchesTier('routine')" class="card mb-6">
            <div @click="toggleSection('wp_admins')" class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-3 cursor-pointer select-none hover:bg-[var(--color-surface-alt)]/50 transition-colors" :class="isSectionCollapsed('wp_admins') ? 'rounded-[var(--radius-card)] border-b-0' : 'rounded-t-[var(--radius-card)]'">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-user-shield text-amber-600 mr-2"></i>
                        Flagged WordPress administrators
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                        Default <code>admin</code> logins, or emails outside the approved allowlist.
                        <a href="{{ route('security.admins') }}" class="text-[var(--color-primary-600)] hover:underline">Open fleet directory</a>
                    </p>
                </div>
                <x-issue-priority-menu :category="'wp_admins'"/>
            
                    <button type="button" @click.stop="toggleSection('wp_admins')" class="p-1 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] transition-colors ml-1.5 cursor-pointer" :title="isSectionCollapsed('wp_admins') ? 'Expand section' : 'Collapse section'">
                        <i class="fa-solid fa-chevron-up text-xs transition-transform duration-200" :class="isSectionCollapsed('wp_admins') ? 'rotate-180' : ''"></i>
                    </button>
            </div>
            <div x-show="!isSectionCollapsed('wp_admins')" class="rounded-b-[var(--radius-card)] overflow-hidden">
            @if ($flaggedAdminSites->isNotEmpty())
                <table class="w-full text-sm">
                    <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-5 py-2 text-left">Site</th>
                            <th class="px-5 py-2 text-left">Server</th>
                            <th class="px-5 py-2 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($flaggedAdminSites as $site)
                            <tr>
                                <td class="px-5 py-2">
                                    <a href="{{ route('sites.show', $site) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $site->domain }}</a>
                                </td>
                                <td class="px-5 py-2 text-[var(--color-ink-muted)]">{{ $site->server?->name ?? '—' }}</td>
                                <td class="px-5 py-2 text-right">
                                    <form method="POST" action="{{ route('issues.ignore') }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="issue_type" value="wp_admin_flagged">
                                        <input type="hidden" name="site_id" value="{{ $site->id }}">
                                        <button type="submit" class="text-xs text-[var(--color-ink-muted)] hover:underline">Ignore site</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @if ($ignoredAdminIssues->isNotEmpty())
                <div class="px-5 py-3 border-t border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)]">
                    {{ $ignoredAdminIssues->count() }} site(s) acknowledged.
                    @foreach ($ignoredAdminIssues as $ignored)
                        <form method="POST" action="{{ route('issues.unignore', $ignored) }}" class="inline ml-2">
                            @csrf
                            <button type="submit" class="hover:underline">Restore {{ $ignored->site?->domain }}</button>
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
        </section>
    @endif


        {{-- TURNED OFF / MUTED CATEGORIES SUMMARY TRAY --}}
        <div x-show="disabledCategoriesCount > 0" x-cloak class="mt-8 p-4 rounded-[var(--radius-card)] bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span>
                    <span class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">
                        Turned Off Categories (<span x-text="disabledCategoriesCount"></span> muted fleet-wide)
                    </span>
                </div>
                <button type="button" @click="prioritiesModalOpen = true" class="text-xs text-[var(--color-brand)] hover:underline cursor-pointer flex items-center gap-1 font-medium">
                    <i class="fa-solid fa-sliders text-[10px]"></i> Manage all priorities →
                </button>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <template x-for="catKey in disabledCategories" :key="catKey">
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-[var(--color-surface)] border border-[var(--color-border-light)] text-xs shadow-xs">
                        <i class="fa-solid" :class="categoryMeta[catKey]?.icon || 'fa-bell-slash'" class="opacity-60 text-xs text-[var(--color-ink-muted)]"></i>
                        <span class="font-medium text-[var(--color-ink-strong)]" x-text="categoryMeta[catKey]?.label || catKey"></span>
                        <span class="px-1.5 py-0.2 rounded-full bg-[var(--color-surface-alt)] text-[10px] font-mono text-[var(--color-ink-soft)]" x-text="(totals[catKey] ?? 0) + ' items'"></span>
                        <button type="button"
                                @click="setCategoryLevel(catKey, 'not_pressing')"
                                class="text-[var(--color-brand)] hover:underline ml-1 font-medium cursor-pointer"
                                title="Turn back on (as Not Pressing)">
                            Turn On
                        </button>
                    </div>
                </template>
            </div>
        </div>

        {{-- Toast feedback notification --}}
        <div x-show="feedbackToast"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-2"
             x-cloak
             class="fixed bottom-6 right-6 z-50 flex items-center gap-2 px-4 py-2.5 rounded-lg bg-[var(--color-surface)] border border-[var(--color-border-strong)] text-sm shadow-xl text-[var(--color-ink-strong)] font-medium">
            <i class="fa-solid fa-circle-check text-[var(--color-status-green)]"></i>
            <span x-text="feedbackToast"></span>
        </div>

        {{-- Manage priorities modal --}}
        @include('dashboard.partials.issue-priorities-modal')

    </div> {{-- End x-data="issuesDashboard" --}}
@endsection
