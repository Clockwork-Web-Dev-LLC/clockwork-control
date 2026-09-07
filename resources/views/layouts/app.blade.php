@php
    $themeCookie = null;
    try {
        $themeCookie = request()->cookie('cw_theme');
    } catch (\Throwable) {
        $themeCookie = $_COOKIE['cw_theme'] ?? null;
    }
    $initialTheme = ($themeCookie && $themeCookie !== 'system')
        ? $themeCookie
        : (auth()->check() && auth()->user()->theme && auth()->user()->theme !== 'system' ? auth()->user()->theme : 'light');
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="{{ $initialTheme }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Clockwork Control')</title>
    <script>
        (function () {
            try {
                var match = document.cookie.match(/(?:^|; )cw_theme=([^;]*)/);
                var theme = match ? decodeURIComponent(match[1]) : 'system';
                var resolved = theme;
                if (!resolved || resolved === 'system') {
                    resolved = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                document.documentElement.setAttribute('data-theme', resolved);
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--color-surface)] text-[var(--color-ink)]">
    <header class="border-b border-[var(--color-border-light)] bg-[var(--color-surface)] sticky top-0 z-40" x-data="{ mobileNavOpen: false, ...themePicker() }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
            <div class="flex items-center gap-6 min-w-0">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 mr-2 flex-shrink-0">
                    <i class="fa-solid fa-clock text-xl text-[var(--color-brand)]"></i>
                    <span class="font-display text-lg font-semibold tracking-tight text-[var(--color-ink-strong)]">Clockwork Control</span>
                </a>

                <nav class="hidden lg:flex items-center gap-2">
                    <a href="{{ route('dashboard') }}"
                       class="btn-pill-nav {{ request()->routeIs('dashboard') || (request()->routeIs('servers.*') && ! request()->routeIs('servers.credentials.*') && ! request()->routeIs('servers.create')) ? 'is-active' : '' }}">
                        <i class="fa-solid fa-server"></i>
                        Servers
                    </a>
                    <a href="{{ route('sites.index') }}"
                       class="btn-pill-nav {{ request()->routeIs('sites.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-globe"></i>
                        Sites
                    </a>
                    <a href="{{ route('issues.index') }}"
                       class="btn-pill-nav {{ request()->routeIs('issues.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        Issues
                        @isset($issueCount)
                            @if ($issueCount > 0)
                                <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-red)] text-white">{{ $issueCount }}</span>
                            @endif
                        @endisset
                    </a>
                    <a href="{{ route('updates.index') }}"
                       class="btn-pill-nav {{ request()->routeIs('updates.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-rotate"></i>
                        Updates
                        @isset($updatesPendingCount)
                            @if ($updatesPendingCount > 0)
                                <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-amber,#d97706)] text-white">{{ $updatesPendingCount }}</span>
                            @endif
                        @endisset
                    </a>
                    <a href="{{ route('security.scans') }}"
                       class="btn-pill-nav {{ (request()->routeIs('security.*') || request()->routeIs('bans.*')) ? 'is-active' : '' }}">
                        <i class="fa-solid fa-shield-halved"></i>
                        Security
                    </a>
                    <a href="{{ route('monitoring.index') }}"
                       class="btn-pill-nav {{ request()->routeIs('monitoring.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-heart-pulse"></i>
                        Monitoring
                        @isset($monitoringDownCount)
                            @if ($monitoringDownCount > 0)
                                <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-red)] text-white">{{ $monitoringDownCount }}</span>
                            @endif
                        @endisset
                    </a>
                    @if (Route::has('snippets.index') && app(\Modules\Core\ModuleStateResolver::class)->isEnabled('code-snippets'))
                    <a href="{{ route('snippets.index') }}"
                       class="btn-pill-nav {{ request()->routeIs('snippets.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-code"></i>
                        Code Snippets
                    </a>
                    @endif
                    @if (Route::has('client-reports.index') && app(\Modules\Core\ModuleStateResolver::class)->isEnabled('client-reports'))
                    <a href="{{ route('client-reports.index') }}"
                       class="btn-pill-nav {{ request()->routeIs('client-reports.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-file-lines"></i>
                        Reports
                    </a>
                    @endif
                    @if (Route::has('clients.index') && app(\Modules\Core\ModuleStateResolver::class)->isEnabled('client-management'))
                    <a href="{{ route('clients.index') }}"
                       class="btn-pill-nav {{ request()->routeIs('clients.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-users"></i>
                        Clients
                    </a>
                    @endif
                    @if (Route::has('forms.index') && app(\Modules\Core\ModuleStateResolver::class)->isEnabled('contact-forms'))
                    <a href="{{ route('forms.index') }}"
                       class="btn-pill-nav {{ request()->routeIs('forms.*') || request()->routeIs('sites.forms.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-envelope-circle-check"></i>
                        Forms
                        @isset($failingFormsCount)
                            @if ($failingFormsCount > 0)
                                <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-red)] text-white">{{ $failingFormsCount }}</span>
                            @endif
                        @endisset
                    </a>
                    @endif
                </nav>
            </div>

            <!-- Desktop right cluster (Gear + Avatar) -->
            <div class="hidden lg:flex items-center gap-3 text-sm text-[var(--color-ink-soft)] ml-auto">
                <details class="relative" data-settings-menu @click.outside="$el.removeAttribute('open')">
                    <summary class="list-none cursor-pointer w-9 h-9 inline-flex items-center justify-center rounded-full hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] transition-colors {{ request()->routeIs('servers.credentials.*') || request()->routeIs('settings.*') || request()->routeIs('capacity.*') ? 'bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]' : '' }}"
                             title="Settings"
                             aria-label="Settings">
                        <i class="fa-solid fa-gear"></i>
                    </summary>
                    <div class="absolute right-0 mt-2 w-[48rem] sm:w-[54rem] max-w-[calc(100vw-2rem)] rounded-[var(--radius-card)] border border-[var(--color-border-light)] bg-[var(--color-surface)] shadow-2xl z-50 overflow-hidden">
                        <div class="px-4 py-2.5 border-b border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/60 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-gear text-[var(--color-ink-muted)] text-xs"></i>
                                <span class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-strong)]">Settings &amp; Operations</span>
                            </div>
                            <a href="{{ route('settings.index') }}" class="text-xs font-medium text-[var(--color-primary-600)] hover:underline inline-flex items-center gap-1">
                                <span>Open Settings Hub</span>
                                <i class="fa-solid fa-arrow-right text-[10px]"></i>
                            </a>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-[var(--color-border-light)] p-3 text-xs">
                            {{-- Column 1: Fleet & Branding --}}
                            <div class="px-2 py-1 space-y-1">
                                <div class="px-2 py-1 text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] font-semibold flex items-center gap-1.5">
                                    <i class="fa-solid fa-sliders text-[10px]"></i>
                                    Fleet &amp; Branding
                                </div>
                                <a href="{{ route('settings.companion.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.companion.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-paintbrush text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Companion</span>
                                </a>
                                <a href="{{ route('settings.tags.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.tags.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-tags text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Server tags</span>
                                </a>
                                <a href="{{ route('settings.wordpress-plugins.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.wordpress-plugins.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-brands fa-wordpress text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">WP plugins</span>
                                </a>
                                <a href="{{ route('settings.ingest.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.ingest.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Scheduling</span>
                                </a>
                                <a href="{{ route('settings.security-scans.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.security-scans.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-shield-halved text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Security scans</span>
                                </a>
                                <a href="{{ route('settings.backup-relay.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.backup-relay.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-cloud-arrow-up text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Backup relay</span>
                                </a>
                            </div>

                            {{-- Column 2: Integrations & Alerts --}}
                            <div class="px-2 py-1 space-y-1">
                                <div class="px-2 py-1 text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] font-semibold flex items-center gap-1.5">
                                    <i class="fa-solid fa-plug text-[10px]"></i>
                                    Integrations
                                </div>
                                <a href="{{ route('settings.integrations.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.integrations.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-key text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">API credentials</span>
                                </a>
                                <a href="{{ route('settings.modules.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.modules.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-boxes-stacked text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Module Directory</span>
                                </a>
                                @foreach (app(\Modules\Core\ModuleRegistry::class)->navItems() as $moduleNavItem)
                                    @if ($moduleNavItem->isVisible())
                                        <a href="{{ route($moduleNavItem->route) }}"
                                           class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs(explode('.', $moduleNavItem->route)[0].'.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                            <i class="{{ $moduleNavItem->icon }} text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                            <span class="truncate">{{ $moduleNavItem->label }}</span>
                                        </a>
                                    @endif
                                @endforeach
                            </div>

                            {{-- Column 3: Operations & Tools --}}
                            <div class="px-2 py-1 space-y-1">
                                <div class="px-2 py-1 text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] font-semibold flex items-center gap-1.5">
                                    <i class="fa-solid fa-toolbox text-[10px]"></i>
                                    Operations
                                </div>
                                <a href="{{ route('capacity.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('capacity.index') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-gauge-high text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Capacity</span>
                                </a>
                                <a href="{{ route('capacity.settings') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('capacity.settings*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-sliders text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Capacity limits</span>
                                </a>
                                <a href="{{ route('operations.server-updates.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('operations.server-updates.*') || request()->routeIs('operations.system-updates.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-cube text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Server updates</span>
                                </a>
                                <a href="{{ route('maintenance-history.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('maintenance-history.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Maintenance log</span>
                                </a>
                                <a href="{{ route('servers.credentials.bulk') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('servers.credentials.bulk') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-key text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Bulk SSH</span>
                                </a>
                                <a href="{{ route('settings.weird-stats.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.weird-stats.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-chart-pie text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Weird Stats</span>
                                </a>
                            </div>

                            {{-- Column 4: System & Workspace --}}
                            <div class="px-2 py-1 space-y-1">
                                <div class="px-2 py-1 text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] font-semibold flex items-center gap-1.5">
                                    <i class="fa-solid fa-server text-[10px]"></i>
                                    System
                                </div>
                                <a href="{{ route('settings.users.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.users.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-people-group text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Team &amp; Users</span>
                                </a>
                                <a href="{{ route('settings.updates.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.updates.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-arrows-rotate text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">System updates</span>
                                </a>
                                <a href="{{ route('settings.maintenance.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.maintenance.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-database text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">DB backup</span>
                                </a>
                                <a href="{{ route('settings.diagnostics.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('settings.diagnostics.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-stethoscope text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Diagnostics</span>
                                </a>
                                <a href="{{ route('setup.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('setup.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-list-check text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Setup wizard</span>
                                </a>
                                <a href="{{ route('docs.index') }}"
                                   class="flex items-center gap-2 px-2 py-1.5 rounded-md text-xs text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('docs.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                    <i class="fa-solid fa-book text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                    <span class="truncate">Documentation</span>
                                </a>
                            </div>
                        </div>
                        <div class="px-4 py-2 border-t border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/30 flex items-center justify-between text-[11px] text-[var(--color-ink-soft)]">
                            <span>24 configuration tools &amp; utilities</span>
                            <a href="{{ route('settings.index') }}" class="font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] transition-colors">View all with descriptions &rarr;</a>
                        </div>
                    </div>
                </details>

                @auth
                    {{-- Profile menu — identity-related items split out of the gear menu.
                         Sits to the right of the gear so the visual order is config (gear)
                         then "me" (avatar). --}}
                    <details class="relative" data-profile-menu @click.outside="$el.removeAttribute('open')">
                        <summary class="list-none cursor-pointer w-9 h-9 inline-flex items-center justify-center rounded-full hover:ring-2 hover:ring-[var(--color-border-light)] transition-all overflow-hidden {{ request()->routeIs('settings.users.*') ? 'ring-2 ring-[var(--color-brand)]' : '' }}"
                                 title="{{ auth()->user()->email }}"
                                 aria-label="Profile">
                            @if (auth()->user()->avatar_url)
                                <img src="{{ auth()->user()->avatar_url }}" alt="" class="w-9 h-9 rounded-full object-cover">
                            @else
                                <i class="fa-solid fa-circle-user text-[var(--color-ink-muted)] text-2xl"></i>
                            @endif
                        </summary>
                        <div class="absolute right-0 mt-2 w-[22rem] rounded-[var(--radius-card)] border border-[var(--color-border-light)] bg-[var(--color-surface)] shadow-2xl py-2 z-50">
                            <div class="flex items-center gap-3 px-4 py-3">
                                @if (auth()->user()->avatar_url)
                                    <img src="{{ auth()->user()->avatar_url }}" alt="" class="w-10 h-10 rounded-full object-cover shadow-2xs">
                                @else
                                    <i class="fa-solid fa-circle-user text-[var(--color-ink-muted)] text-3xl"></i>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-semibold text-[var(--color-ink-strong)] truncate">{{ auth()->user()->name }}</div>
                                    <div class="text-xs text-[var(--color-ink-soft)] truncate">{{ auth()->user()->email }}</div>
                                </div>
                            </div>

                            <div class="border-t border-[var(--color-border-light)] my-1.5"></div>

                            {{-- Appearance / Theme Settings --}}
                            <div class="px-4 py-1.5 flex items-center justify-between">
                                <span class="text-[10px] uppercase tracking-wider font-semibold text-[var(--color-ink-soft)]">Appearance</span>
                                <div class="inline-flex items-center p-0.5 rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] shadow-2xs">
                                    <button type="button"
                                            @click.prevent="setTheme('light')"
                                            :class="!isDark ? 'bg-[var(--color-surface)] text-amber-500 shadow-xs font-semibold' : 'text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]'"
                                            class="px-2.5 py-1 rounded-full text-xs flex items-center gap-1.5 transition-all cursor-pointer"
                                            title="Switch to light mode">
                                        <i class="fa-solid fa-sun text-xs"></i>
                                        <span class="text-[11px]">Light</span>
                                    </button>
                                    <button type="button"
                                            @click.prevent="setTheme(current === 'midnight' ? 'midnight' : (current === 'high-contrast' ? 'high-contrast' : 'dark'))"
                                            :class="isDark ? 'bg-[var(--color-surface)] text-sky-400 shadow-xs font-semibold' : 'text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]'"
                                            class="px-2.5 py-1 rounded-full text-xs flex items-center gap-1.5 transition-all cursor-pointer"
                                            title="Switch to dark mode">
                                        <i class="fa-solid fa-moon text-xs"></i>
                                        <span class="text-[11px]">Dark</span>
                                    </button>
                                </div>
                            </div>

                            <div class="px-4 py-2">
                                <div class="grid grid-cols-5 gap-1.5">
                                    <template x-for="scheme in schemes" :key="scheme.key">
                                        <button type="button"
                                                @click.prevent="setTheme(scheme.key)"
                                                :aria-pressed="current === scheme.key ? 'true' : 'false'"
                                                :title="scheme.label"
                                                class="p-1.5 rounded-lg border text-center transition-all flex flex-col items-center gap-1.5 cursor-pointer"
                                                :class="current === scheme.key ? 'border-[var(--color-brand)] ring-2 ring-[var(--color-brand)]/20 bg-[var(--color-surface-alt)] font-semibold' : 'border-[var(--color-border-light)] hover:border-[var(--color-border)] hover:bg-[var(--color-surface-alt)]/50'">
                                            <div class="w-full h-4 rounded flex overflow-hidden border border-black/10 shadow-2xs">
                                                <div class="w-1/2 h-full" :style="'background-color: ' + scheme.surface"></div>
                                                <div class="w-1/4 h-full" :style="'background-color: ' + scheme.brand"></div>
                                                <div class="w-1/4 h-full" :style="'background-color: ' + scheme.ink"></div>
                                            </div>
                                            <span class="text-[10px] truncate max-w-full font-medium text-[var(--color-ink-strong)]" x-text="scheme.label"></span>
                                        </button>
                                    </template>
                                    <button type="button"
                                            @click.prevent="setTheme('system')"
                                            :aria-pressed="current === 'system' ? 'true' : 'false'"
                                            title="Auto (matches system OS)"
                                            class="p-1.5 rounded-lg border text-center transition-all flex flex-col items-center gap-1.5 cursor-pointer"
                                            :class="current === 'system' ? 'border-[var(--color-brand)] ring-2 ring-[var(--color-brand)]/20 bg-[var(--color-surface-alt)] font-semibold' : 'border-[var(--color-border-light)] hover:border-[var(--color-border)] hover:bg-[var(--color-surface-alt)]/50'">
                                        <div class="w-full h-4 rounded flex items-center justify-center bg-[var(--color-surface-alt)] border border-black/10 text-[var(--color-ink-muted)] shadow-2xs">
                                            <i class="fa-solid fa-circle-half-stroke text-[10px]"></i>
                                        </div>
                                        <span class="text-[10px] truncate max-w-full font-medium text-[var(--color-ink-strong)]">Auto</span>
                                    </button>
                                </div>
                            </div>

                            <div class="border-t border-[var(--color-border-light)] my-1.5"></div>

                            <a href="{{ route('settings.users.index') }}"
                               class="flex items-center gap-2.5 px-4 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs('settings.users.*') ? 'bg-[var(--color-surface-alt)] font-medium' : '' }}">
                                <i class="fa-solid fa-people-group text-[var(--color-ink-muted)] w-4"></i>
                                Team
                            </a>
                            <div class="border-t border-[var(--color-border-light)] my-1.5"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit"
                                        class="w-full text-left flex items-center gap-2.5 px-4 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] cursor-pointer">
                                    <i class="fa-solid fa-arrow-right-from-bracket text-[var(--color-ink-muted)] w-4"></i>
                                    Sign out
                                </button>
                            </form>
                        </div>
                    </details>
                @endauth
            </div>

            <!-- Mobile right cluster (Urgent Alerts + Hamburger Toggle) -->
            <div class="flex lg:hidden items-center gap-2.5">
                @isset($issueCount)
                    @if ($issueCount > 0)
                        <a href="{{ route('issues.index') }}"
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-[var(--color-status-red)] text-white shadow-2xs"
                           title="{{ $issueCount }} active issues">
                            <i class="fa-solid fa-triangle-exclamation text-[10px]"></i>
                            <span>{{ $issueCount }}</span>
                        </a>
                    @endif
                @endisset

                <button type="button"
                        @click="mobileNavOpen = true"
                        class="w-10 h-10 inline-flex items-center justify-center rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] hover:bg-[var(--color-border-light)] transition-colors cursor-pointer"
                        aria-label="Open mobile navigation">
                    <i class="fa-solid fa-bars text-base"></i>
                </button>
            </div>
        </div>

        <!-- Mobile Navigation Slide-Over Drawer -->
        <div x-show="mobileNavOpen"
             x-cloak
             class="lg:hidden fixed inset-0 z-50 overflow-hidden"
             role="dialog"
             aria-modal="true"
             @keydown.escape.window="mobileNavOpen = false">
            <!-- Backdrop -->
            <div x-show="mobileNavOpen"
                 x-transition:enter="ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 @click="mobileNavOpen = false"
                 class="fixed inset-0 bg-black/60 backdrop-blur-xs"></div>

            <!-- Slide-over Drawer Panel -->
            <div x-show="mobileNavOpen"
                 x-transition:enter="transform transition ease-out duration-300"
                 x-transition:enter-start="translate-x-full"
                 x-transition:enter-end="translate-x-0"
                 x-transition:leave="transform transition ease-in duration-200"
                 x-transition:leave-start="translate-x-0"
                 x-transition:leave-end="translate-x-full"
                 class="fixed inset-y-0 right-0 max-w-full w-full sm:w-96 bg-[var(--color-surface)] border-l border-[var(--color-border-light)] shadow-2xl flex flex-col z-50"
                 @click.stop>

                <!-- Drawer Header -->
                <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between bg-[var(--color-surface-alt)]/50">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-clock text-lg text-[var(--color-brand)]"></i>
                        <span class="font-display font-semibold text-base text-[var(--color-ink-strong)]">Clockwork Control</span>
                    </div>
                    <button type="button"
                            @click="mobileNavOpen = false"
                            class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] cursor-pointer">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <!-- Drawer Scrollable Body -->
                <div class="flex-1 overflow-y-auto p-4 space-y-5">
                    @auth
                    <!-- Operator Card -->
                    <div class="flex items-center gap-3 p-3 rounded-xl bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                        @if (auth()->user()->avatar_url)
                            <img src="{{ auth()->user()->avatar_url }}" alt="" class="w-10 h-10 rounded-full object-cover shadow-2xs">
                        @else
                            <i class="fa-solid fa-circle-user text-[var(--color-ink-muted)] text-3xl"></i>
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="text-xs font-semibold text-[var(--color-ink-strong)] truncate">{{ auth()->user()->name }}</div>
                            <div class="text-[11px] text-[var(--color-ink-soft)] truncate">{{ auth()->user()->email }}</div>
                        </div>
                    </div>
                    @endauth

                    <!-- Fleet Navigation -->
                    <div>
                        <div class="text-[10px] uppercase font-semibold tracking-wider text-[var(--color-ink-soft)] px-2 mb-2">Fleet Management</div>
                        <div class="space-y-1">
                            <a href="{{ route('dashboard') }}"
                               class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('dashboard') || (request()->routeIs('servers.*') && ! request()->routeIs('servers.credentials.*') && ! request()->routeIs('servers.create')) ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                <span class="flex items-center gap-3">
                                    <i class="fa-solid fa-server w-4 text-center text-[var(--color-ink-muted)]"></i>
                                    <span>Servers</span>
                                </span>
                            </a>
                            <a href="{{ route('sites.index') }}"
                               class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('sites.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                <span class="flex items-center gap-3">
                                    <i class="fa-solid fa-globe w-4 text-center text-[var(--color-ink-muted)]"></i>
                                    <span>Sites</span>
                                </span>
                            </a>
                            <a href="{{ route('issues.index') }}"
                               class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('issues.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                <span class="flex items-center gap-3">
                                    <i class="fa-solid fa-triangle-exclamation w-4 text-center text-[var(--color-ink-muted)]"></i>
                                    <span>Issues</span>
                                </span>
                                @isset($issueCount)
                                    @if ($issueCount > 0)
                                        <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-red)] text-white">{{ $issueCount }}</span>
                                    @endif
                                @endisset
                            </a>
                            <a href="{{ route('updates.index') }}"
                               class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('updates.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                <span class="flex items-center gap-3">
                                    <i class="fa-solid fa-rotate w-4 text-center text-[var(--color-ink-muted)]"></i>
                                    <span>Updates</span>
                                </span>
                                @isset($updatesPendingCount)
                                    @if ($updatesPendingCount > 0)
                                        <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-amber,#d97706)] text-white">{{ $updatesPendingCount }}</span>
                                    @endif
                                @endisset
                            </a>
                            <a href="{{ route('security.scans') }}"
                               class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ (request()->routeIs('security.*') || request()->routeIs('bans.*')) ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                <span class="flex items-center gap-3">
                                    <i class="fa-solid fa-shield-halved w-4 text-center text-[var(--color-ink-muted)]"></i>
                                    <span>Security</span>
                                </span>
                            </a>
                            <a href="{{ route('monitoring.index') }}"
                               class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('monitoring.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                <span class="flex items-center gap-3">
                                    <i class="fa-solid fa-heart-pulse w-4 text-center text-[var(--color-ink-muted)]"></i>
                                    <span>Monitoring</span>
                                </span>
                                @isset($monitoringDownCount)
                                    @if ($monitoringDownCount > 0)
                                        <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-red)] text-white">{{ $monitoringDownCount }}</span>
                                    @endif
                                @endisset
                            </a>
                            @if (Route::has('client-reports.index') && app(\Modules\Core\ModuleStateResolver::class)->isEnabled('client-reports'))
                            <a href="{{ route('client-reports.index') }}"
                               class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('client-reports.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                <span class="flex items-center gap-3">
                                    <i class="fa-solid fa-file-lines w-4 text-center text-[var(--color-ink-muted)]"></i>
                                    <span>Client Reports</span>
                                </span>
                            </a>
                            @endif
                            @if (Route::has('clients.index') && app(\Modules\Core\ModuleStateResolver::class)->isEnabled('client-management'))
                            <a href="{{ route('clients.index') }}"
                               class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('clients.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                <span class="flex items-center gap-3">
                                    <i class="fa-solid fa-users w-4 text-center text-[var(--color-ink-muted)]"></i>
                                    <span>Clients</span>
                                </span>
                            </a>
                            @endif
                            @if (Route::has('forms.index') && app(\Modules\Core\ModuleStateResolver::class)->isEnabled('contact-forms'))
                            <a href="{{ route('forms.index') }}"
                               class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('forms.*') || request()->routeIs('sites.forms.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                <span class="flex items-center gap-3">
                                    <i class="fa-solid fa-envelope-circle-check w-4 text-center text-[var(--color-ink-muted)]"></i>
                                    <span>Forms</span>
                                </span>
                                @isset($failingFormsCount)
                                    @if ($failingFormsCount > 0)
                                        <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-semibold bg-[var(--color-status-red)] text-white">{{ $failingFormsCount }}</span>
                                    @endif
                                @endisset
                            </a>
                            @endif
                            @if (Route::has('snippets.index') && app(\Modules\Core\ModuleStateResolver::class)->isEnabled('code-snippets'))
                            <a href="{{ route('snippets.index') }}"
                               class="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors {{ request()->routeIs('snippets.*') ? 'bg-[var(--color-surface-alt)] font-semibold text-[var(--color-brand)]' : '' }}">
                                <span class="flex items-center gap-3">
                                    <i class="fa-solid fa-code w-4 text-center text-[var(--color-ink-muted)]"></i>
                                    <span>Code Snippets</span>
                                </span>
                            </a>
                            @endif
                        </div>
                    </div>

                    <!-- Settings & Operations Quick Links -->
                    <div class="pt-4 border-t border-[var(--color-border-light)]">
                        <div class="flex items-center justify-between px-2 mb-2">
                            <span class="text-[10px] uppercase font-semibold tracking-wider text-[var(--color-ink-soft)]">Settings &amp; Tools</span>
                            <a href="{{ route('settings.index') }}" class="text-xs text-[var(--color-primary-600)] font-semibold hover:underline inline-flex items-center gap-1">
                                <span>Settings Hub</span>
                                <i class="fa-solid fa-arrow-right text-[10px]"></i>
                            </a>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5 text-xs">
                            <a href="{{ route('settings.companion.index') }}" class="p-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] flex items-center gap-2 border border-transparent hover:border-[var(--color-border-light)] transition-colors">
                                <i class="fa-solid fa-paintbrush text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                <span class="truncate">Companion</span>
                            </a>
                            <a href="{{ route('settings.integrations.index') }}" class="p-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] flex items-center gap-2 border border-transparent hover:border-[var(--color-border-light)] transition-colors">
                                <i class="fa-solid fa-key text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                <span class="truncate">API Keys</span>
                            </a>
                            <a href="{{ route('settings.modules.index') }}" class="p-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] flex items-center gap-2 border border-transparent hover:border-[var(--color-border-light)] transition-colors">
                                <i class="fa-solid fa-boxes-stacked text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                <span class="truncate">Modules</span>
                            </a>
                            <a href="{{ route('capacity.index') }}" class="p-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] flex items-center gap-2 border border-transparent hover:border-[var(--color-border-light)] transition-colors">
                                <i class="fa-solid fa-gauge-high text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                <span class="truncate">Capacity</span>
                            </a>
                            <a href="{{ route('operations.server-updates.index') }}" class="p-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] flex items-center gap-2 border border-transparent hover:border-[var(--color-border-light)] transition-colors">
                                <i class="fa-solid fa-cube text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                <span class="truncate">OS Updates</span>
                            </a>
                            <a href="{{ route('settings.users.index') }}" class="p-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] flex items-center gap-2 border border-transparent hover:border-[var(--color-border-light)] transition-colors">
                                <i class="fa-solid fa-people-group text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                <span class="truncate">Team</span>
                            </a>
                            <a href="{{ route('settings.updates.index') }}" class="p-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] flex items-center gap-2 border border-transparent hover:border-[var(--color-border-light)] transition-colors">
                                <i class="fa-solid fa-arrows-rotate text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                <span class="truncate">Core Updates</span>
                            </a>
                            <a href="{{ route('docs.index') }}" class="p-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] flex items-center gap-2 border border-transparent hover:border-[var(--color-border-light)] transition-colors">
                                <i class="fa-solid fa-book text-[var(--color-ink-muted)] w-3.5 text-center"></i>
                                <span class="truncate">Docs</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Drawer Footer (Theme & Sign Out) -->
                <div class="p-4 border-t border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/50 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-[var(--color-ink-soft)] font-medium">Theme:</span>
                        <button type="button"
                                @click="setTheme(isDark ? 'light' : 'dark')"
                                class="px-3 py-1 rounded-full text-xs font-semibold border border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] inline-flex items-center gap-1.5 shadow-2xs cursor-pointer">
                            <i :class="isDark ? 'fa-solid fa-moon text-sky-400' : 'fa-solid fa-sun text-amber-500'"></i>
                            <span x-text="isDark ? 'Dark' : 'Light'"></span>
                        </button>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-xs font-semibold text-[var(--color-ink-muted)] hover:text-[var(--color-status-red)] flex items-center gap-1.5 cursor-pointer">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                            <span>Sign Out</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-10">
        @yield('content')
    </main>

    <footer class="bg-[var(--color-surface-dark)] text-white/80 mt-20">
        <div class="max-w-7xl mx-auto px-6 py-10 text-sm">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div class="flex items-center gap-3 text-sm">
                    <span class="font-display font-medium text-white">Clockwork Control</span>
                    <span class="text-white/30">·</span>
                    <a href="{{ route('settings.updates.index') }}" class="text-white/70 hover:text-white underline inline-flex items-center gap-1.5">
                        <i class="fa-solid fa-arrows-rotate text-xs"></i>
                        <span>Updates (v{{ config('clockwork.version', '1.0.0') }})</span>
                    </a>
                    <span class="text-white/30">·</span>
                    <a href="{{ route('docs.index') }}" class="text-white/70 hover:text-white underline">Documentation</a>
                </div>
                <span class="text-white/50 text-xs sm:text-sm">Self-hosted WordPress fleet control panel · runs on your network</span>
            </div>
        </div>
    </footer>
</body>
</html>
