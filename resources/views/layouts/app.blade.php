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
    <header class="border-b border-[var(--color-border-light)] bg-[var(--color-surface)] sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-6 h-16 flex items-center gap-6">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 mr-4">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-[var(--color-brand)] text-white">
                    <i class="fa-solid fa-clock text-sm"></i>
                </span>
                <span class="font-display text-lg font-semibold tracking-tight text-[var(--color-ink-strong)]">Clockwork Control</span>
            </a>

            <nav class="flex items-center gap-2">
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

            <div class="ml-auto flex items-center gap-3 text-sm text-[var(--color-ink-soft)]">
                <details class="relative" data-settings-menu @click.outside="$el.removeAttribute('open')">
                    <summary class="list-none cursor-pointer w-9 h-9 inline-flex items-center justify-center rounded-full hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] transition-colors {{ request()->routeIs('servers.credentials.*') || request()->routeIs('settings.*') || request()->routeIs('capacity.*') ? 'bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]' : '' }}"
                             title="Settings"
                             aria-label="Settings">
                        <i class="fa-solid fa-gear"></i>
                    </summary>
                    <div class="absolute right-0 mt-2 w-72 rounded-[var(--radius-card)] border border-[var(--color-border-light)] bg-[var(--color-surface)] shadow-xl py-2 z-50">
                        <div class="px-3 py-2 text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] font-semibold">Tools</div>
                        <a href="{{ route('capacity.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs('capacity.index') ? 'bg-[var(--color-surface-alt)]' : '' }}">
                            <i class="fa-solid fa-gauge-high text-[var(--color-ink-muted)] w-4"></i>
                            Capacity
                        </a>
                        <a href="{{ route('capacity.settings') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs('capacity.settings*') ? 'bg-[var(--color-surface-alt)]' : '' }}">
                            <i class="fa-solid fa-sliders text-[var(--color-ink-muted)] w-4"></i>
                            Capacity settings
                        </a>
                        <div class="border-t border-[var(--color-border-light)] my-1"></div>
                        <div class="px-3 py-2 text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Setup</div>
                        <a href="{{ route('setup.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs('setup.*') ? 'bg-[var(--color-surface-alt)]' : '' }}">
                            <i class="fa-solid fa-list-check text-[var(--color-ink-muted)] w-4"></i>
                            Setup
                        </a>
                        <a href="{{ route('servers.credentials.bulk') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                            <i class="fa-solid fa-key text-[var(--color-ink-muted)] w-4"></i>
                            SSH credentials (bulk)
                        </a>
                        <a href="{{ route('settings.tags.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                            <i class="fa-solid fa-tags text-[var(--color-ink-muted)] w-4"></i>
                            Server tags
                        </a>
                        <a href="{{ route('settings.ingest.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                            <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)] w-4"></i>
                            Scheduling
                        </a>
                        <a href="{{ route('settings.wordpress-plugins.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                            <i class="fa-brands fa-wordpress text-[var(--color-ink-muted)] w-4"></i>
                            WordPress plugins
                        </a>
                        <a href="{{ route('settings.companion.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs('settings.companion.*') ? 'bg-[var(--color-surface-alt)]' : '' }}">
                            <i class="fa-solid fa-paintbrush text-[var(--color-ink-muted)] w-4"></i>
                            Companion (White label)
                        </a>
                        <a href="{{ route('settings.security-scans.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                            <i class="fa-solid fa-shield-halved text-[var(--color-ink-muted)] w-4"></i>
                            Security scans
                        </a>
                        <a href="{{ route('settings.backup-relay.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs('settings.backup-relay.*') ? 'bg-[var(--color-surface-alt)]' : '' }}">
                            <i class="fa-solid fa-cloud-arrow-up text-[var(--color-ink-muted)] w-4"></i>
                            Backup relay
                        </a>
                        <a href="{{ route('settings.integrations.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs('settings.integrations.*') ? 'bg-[var(--color-surface-alt)]' : '' }}">
                            <i class="fa-solid fa-plug text-[var(--color-ink-muted)] w-4"></i>
                            API credentials
                        </a>
                        {{-- Module-contributed nav links (Modules\Core\ModuleRegistry::navItems())
                             — Modules\BillCom\BillComServiceProvider contributes "Bill.com sync",
                             Modules\Mattermost\MattermostServiceProvider contributes "Mattermost notifications",
                             Modules\Slack\SlackServiceProvider contributes "Slack notifications", and
                             Modules\Twilio\TwilioServiceProvider contributes "SMS notifications".
                             Each module dynamically gates its link so it only appears when that integration
                             is active and configured. --}}
                        @foreach (app(\Modules\Core\ModuleRegistry::class)->navItems() as $moduleNavItem)
                            @if ($moduleNavItem->isVisible())
                                <a href="{{ route($moduleNavItem->route) }}"
                                   class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs(explode('.', $moduleNavItem->route)[0].'.*') ? 'bg-[var(--color-surface-alt)]' : '' }}">
                                    <i class="{{ $moduleNavItem->icon }} text-[var(--color-ink-muted)] w-4"></i>
                                    {{ $moduleNavItem->label }}
                                </a>
                            @endif
                        @endforeach
                        <div class="border-t border-[var(--color-border-light)] my-1"></div>
                        <div class="px-3 py-2 text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Operations</div>
                        <a href="{{ route('operations.server-updates.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs('operations.server-updates.*') || request()->routeIs('operations.system-updates.*') ? 'bg-[var(--color-surface-alt)]' : '' }}">
                            <i class="fa-solid fa-cube text-[var(--color-ink-muted)] w-4"></i>
                            Server updates
                        </a>
                        <a href="{{ route('maintenance-history.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                            <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)] w-4"></i>
                            Maintenance history
                        </a>
                        <a href="{{ route('settings.weird-stats.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                            <i class="fa-solid fa-chart-pie text-[var(--color-ink-muted)] w-4"></i>
                            Weird Stats
                        </a>
                        <div class="border-t border-[var(--color-border-light)] my-1"></div>
                        <div class="px-3 py-2 text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Maintenance</div>
                        <a href="{{ route('settings.updates.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs('settings.updates.*') ? 'bg-[var(--color-surface-alt)]' : '' }}">
                            <i class="fa-solid fa-arrows-rotate text-[var(--color-ink-muted)] w-4"></i>
                            Updates
                        </a>
                        <a href="{{ route('settings.maintenance.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs('settings.maintenance.*') ? 'bg-[var(--color-surface-alt)]' : '' }}">
                            <i class="fa-solid fa-database text-[var(--color-ink-muted)] w-4"></i>
                            DB backup
                        </a>
                        <a href="{{ route('settings.diagnostics.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs('settings.diagnostics.*') ? 'bg-[var(--color-surface-alt)]' : '' }}">
                            <i class="fa-solid fa-stethoscope text-[var(--color-ink-muted)] w-4"></i>
                            Diagnostics
                        </a>
                        <div class="border-t border-[var(--color-border-light)] my-1"></div>
                        <div class="px-3 py-2 text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Help & Community</div>
                        <a href="{{ route('docs.index') }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] {{ request()->routeIs('docs.*') ? 'bg-[var(--color-surface-alt)]' : '' }}">
                            <i class="fa-solid fa-book text-[var(--color-ink-muted)] w-4"></i>
                            Documentation
                        </a>
                    </div>
                </details>

                @auth
                    {{-- Profile menu — identity-related items split out of the gear menu.
                         Sits to the right of the gear so the visual order is config (gear)
                         then "me" (avatar). --}}
                    <details class="relative" data-profile-menu x-data="themePicker()" @click.outside="$el.removeAttribute('open')">
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
