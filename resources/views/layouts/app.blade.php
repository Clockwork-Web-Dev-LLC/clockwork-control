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
    if ($initialTheme === 'midnight') {
        $initialTheme = 'dark';
    }
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="{{ $initialTheme }}" data-layout-style="modern">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Clockwork Control')</title>
    <script>
        (function () {
            try {
                // 1. Theme pre-application (zero-FOUC)
                var theme = null;
                try {
                    theme = localStorage.getItem('cw_theme');
                } catch (e) {}
                if (!theme) {
                    var themeMatch = document.cookie.match(/(?:^|; )cw_theme=([^;]*)/);
                    theme = themeMatch ? decodeURIComponent(themeMatch[1]) : null;
                }
                var resolvedTheme = theme;
                if (!resolvedTheme || resolvedTheme === 'system') {
                    resolvedTheme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                if (resolvedTheme === 'midnight') {
                    resolvedTheme = 'dark';
                }
                document.documentElement.setAttribute('data-theme', resolvedTheme);

                // 2. Root font-scale pre-application (zero-FOUT)
                var scale = localStorage.getItem('cw_font_scale');
                if (!scale) {
                    var scaleMatch = document.cookie.match(/(?:^|; )cw_font_scale=([^;]*)/);
                    scale = scaleMatch ? decodeURIComponent(scaleMatch[1]) : null;
                }
                if (scale) {
                    var parsed = parseInt(scale, 10);
                    if (!isNaN(parsed) && parsed >= 85 && parsed <= 125) {
                        document.documentElement.style.fontSize = parsed + '%';
                    }
                }

                // 3. Layout style pre-application (modern vs command-center)
                var layout = localStorage.getItem('cw_layout_style');
                if (!layout) {
                    var layoutMatch = document.cookie.match(/(?:^|; )cw_layout_style=([^;]*)/);
                    layout = layoutMatch ? decodeURIComponent(layoutMatch[1]) : null;
                }
                if (!layout || !['modern', 'command-center'].includes(layout)) {
                    layout = 'modern';
                }
                document.documentElement.setAttribute('data-layout-style', layout);
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--color-surface)] text-[var(--color-ink)] antialiased font-sans"
      x-data="appChrome()"
      @keydown.window.cmd.k.prevent="paletteOpen = true"
      @keydown.window.ctrl.k.prevent="paletteOpen = true"
      @keydown.window.cmd.b.prevent="if (style === 'command-center') toggleSidebar()"
      @keydown.window.ctrl.b.prevent="if (style === 'command-center') toggleSidebar()"
      @keydown.escape="paletteOpen = false">

    <div class="flex min-h-screen">
        <!-- ================================================================= -->
        <!-- COMMAND RAIL (Left Sidebar - Command Center Layout Only)          -->
        <!-- ================================================================= -->
        <aside class="cw-sidebar-rail hidden lg:flex flex-col justify-between sticky top-0 h-screen border-r border-[var(--color-border-light)] bg-[var(--color-surface)] z-30 transition-all duration-200 select-none shrink-0"
               :class="sidebarOpen ? 'w-64' : 'w-18'">

            <!-- Top Sidebar Header & Navigation -->
            <div class="flex flex-col min-h-0 overflow-y-auto px-3 py-4 space-y-6">
                <!-- Brand Header -->
                <div class="flex items-center justify-between px-2">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 min-w-0">
                        <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-[var(--color-brand)] to-sky-400 flex items-center justify-center shrink-0 text-white shadow-xs">
                            <i class="fa-solid fa-clock text-sm"></i>
                        </div>
                        <div x-show="sidebarOpen" x-transition.opacity class="flex flex-col min-w-0 leading-tight">
                            <span class="font-display font-bold text-sm tracking-tight text-[var(--color-ink-strong)] truncate">CLOCKWORK</span>
                            <span class="text-[9px] uppercase tracking-widest font-mono text-[var(--color-ink-soft)] font-semibold">CONTROL</span>
                        </div>
                    </a>
                    <button type="button"
                            @click="toggleSidebar()"
                            class="p-1.5 rounded-md text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors cursor-pointer"
                            :title="sidebarOpen ? 'Collapse rail (⌘B)' : 'Expand rail (⌘B)'">
                        <i class="fa-solid" :class="sidebarOpen ? 'fa-angles-left text-xs' : 'fa-angles-right text-xs'"></i>
                    </button>
                </div>

                <!-- Primary Workspaces Navigation -->
                <div class="space-y-1">
                    <div x-show="sidebarOpen" x-transition.opacity class="px-2 text-[10px] uppercase tracking-wider font-semibold text-[var(--color-ink-soft)]">
                        Workspaces
                    </div>

                    <a href="{{ route('dashboard') }}"
                       class="cmd-nav-item {{ request()->routeIs('dashboard') || (request()->routeIs('servers.*') && ! request()->routeIs('servers.credentials.*') && ! request()->routeIs('servers.create')) ? 'is-active' : '' }}"
                       :title="!sidebarOpen ? 'Servers' : ''">
                        <i class="fa-solid fa-server w-4 text-center shrink-0"></i>
                        <span x-show="sidebarOpen" x-transition.opacity class="truncate flex-1">Servers</span>
                    </a>

                    <a href="{{ route('sites.index') }}"
                       class="cmd-nav-item {{ request()->routeIs('sites.*') ? 'is-active' : '' }}"
                       :title="!sidebarOpen ? 'Sites' : ''">
                        <i class="fa-solid fa-globe w-4 text-center shrink-0"></i>
                        <span x-show="sidebarOpen" x-transition.opacity class="truncate flex-1">Sites</span>
                    </a>

                    <a href="{{ route('issues.index') }}"
                       class="cmd-nav-item {{ request()->routeIs('issues.*') ? 'is-active' : '' }}"
                       :title="!sidebarOpen ? 'Issues' : ''">
                        <i class="fa-solid fa-triangle-exclamation w-4 text-center shrink-0"></i>
                        <span x-show="sidebarOpen" x-transition.opacity class="truncate flex-1">Issues</span>
                        @isset($issueCount)
                            @if ($issueCount > 0)
                                <span class="inline-flex items-center justify-center min-w-[1.25rem] h-4 px-1 rounded-full text-[10px] font-bold bg-[var(--color-status-red)] text-white">{{ $issueCount }}</span>
                            @endif
                        @endisset
                    </a>

                    <a href="{{ route('updates.index') }}"
                       class="cmd-nav-item {{ request()->routeIs('updates.*') ? 'is-active' : '' }}"
                       :title="!sidebarOpen ? 'Updates' : ''">
                        <i class="fa-solid fa-rotate w-4 text-center shrink-0"></i>
                        <span x-show="sidebarOpen" x-transition.opacity class="truncate flex-1">Updates</span>
                        @isset($updatesPendingCount)
                            @if ($updatesPendingCount > 0)
                                <span class="inline-flex items-center justify-center min-w-[1.25rem] h-4 px-1 rounded-full text-[10px] font-bold bg-[var(--color-status-yellow)] text-white">{{ $updatesPendingCount }}</span>
                            @endif
                        @endisset
                    </a>

                    <a href="{{ route('monitoring.index') }}"
                       class="cmd-nav-item {{ request()->routeIs('monitoring.*') ? 'is-active' : '' }}"
                       :title="!sidebarOpen ? 'Monitoring' : ''">
                        <i class="fa-solid fa-heart-pulse w-4 text-center shrink-0"></i>
                        <span x-show="sidebarOpen" x-transition.opacity class="truncate flex-1">Monitoring</span>
                    </a>

                    <a href="{{ route('security.scans') }}"
                       class="cmd-nav-item {{ (request()->routeIs('security.*') || request()->routeIs('bans.*')) ? 'is-active' : '' }}"
                       :title="!sidebarOpen ? 'Security' : ''">
                        <i class="fa-solid fa-shield-halved w-4 text-center shrink-0"></i>
                        <span x-show="sidebarOpen" x-transition.opacity class="truncate flex-1">Security</span>
                    </a>
                </div>

                <!-- Operations Navigation -->
                <div class="space-y-1">
                    <div x-show="sidebarOpen" x-transition.opacity class="px-2 text-[10px] uppercase tracking-wider font-semibold text-[var(--color-ink-soft)]">
                        Operations
                    </div>

                    <a href="{{ route('capacity.index') }}"
                       class="cmd-nav-item {{ request()->routeIs('capacity.*') ? 'is-active' : '' }}"
                       :title="!sidebarOpen ? 'Capacity' : ''">
                        <i class="fa-solid fa-gauge-high w-4 text-center shrink-0"></i>
                        <span x-show="sidebarOpen" x-transition.opacity class="truncate flex-1">Capacity</span>
                    </a>

                    <a href="{{ route('operations.server-updates.index') }}"
                       class="cmd-nav-item {{ request()->routeIs('operations.*') ? 'is-active' : '' }}"
                       :title="!sidebarOpen ? 'Fleet Updates' : ''">
                        <i class="fa-solid fa-cube w-4 text-center shrink-0"></i>
                        <span x-show="sidebarOpen" x-transition.opacity class="truncate flex-1">Fleet Updates</span>
                    </a>

                    <a href="{{ route('settings.index') }}"
                       class="cmd-nav-item {{ request()->routeIs('settings.*') ? 'is-active' : '' }}"
                       :title="!sidebarOpen ? 'Settings Hub' : ''">
                        <i class="fa-solid fa-sliders w-4 text-center shrink-0"></i>
                        <span x-show="sidebarOpen" x-transition.opacity class="truncate flex-1">Settings</span>
                    </a>

                    <a href="{{ route('docs.index') }}"
                       class="cmd-nav-item {{ request()->routeIs('docs.*') ? 'is-active' : '' }}"
                       :title="!sidebarOpen ? 'Documentation' : ''">
                        <i class="fa-solid fa-book-bookmark w-4 text-center shrink-0"></i>
                        <span x-show="sidebarOpen" x-transition.opacity class="truncate flex-1">Docs &amp; Runbooks</span>
                    </a>
                </div>
            </div>

            <!-- Bottom Sidebar Footer (User & Quick Action) -->
            <div class="p-3 border-t border-[var(--color-border-light)] flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="w-8 h-8 rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border)] flex items-center justify-center font-bold text-xs text-[var(--color-ink-strong)] shrink-0">
                        {{ strtoupper(substr(auth()->user()?->name ?? 'OP', 0, 2)) }}
                    </div>
                    <div x-show="sidebarOpen" x-transition.opacity class="min-w-0 leading-tight">
                        <p class="text-xs font-semibold text-[var(--color-ink-strong)] truncate">{{ auth()->user()?->name ?? 'Operator' }}</p>
                        <p class="text-[10px] text-[var(--color-ink-soft)] truncate">Online</p>
                    </div>
                </div>
                <form x-show="sidebarOpen" x-transition.opacity method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="p-1.5 rounded-md text-[var(--color-ink-soft)] hover:text-[var(--color-status-red)] transition-colors cursor-pointer" title="Sign out">
                        <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i>
                    </button>
                </form>
            </div>
        </aside>

        <!-- ================================================================= -->
        <!-- MAIN CANVAS COLUMN                                                -->
        <!-- ================================================================= -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Global Top Navigation Header -->
            <header class="cw-top-header border-b border-[var(--color-border)] bg-[var(--color-surface)] sticky top-0 z-40 shadow-xs">
                <!-- Tier 1: Primary Toolbar -->
                <div class="cw-toolbar-inner max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
                    <!-- Left: Brand (Modern Studio) or Command Search (Command Center) -->
                    <div class="flex items-center gap-3 min-w-0">
                        <a href="{{ route('dashboard') }}" class="cw-top-brand flex items-center gap-2.5 shrink-0">
                            <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-[var(--color-brand)] to-sky-400 flex items-center justify-center text-white shadow-xs">
                                <i class="fa-solid fa-clock text-sm"></i>
                            </div>
                            <span class="font-display text-lg font-bold tracking-tight text-[var(--color-ink-strong)]">Clockwork Control</span>
                        </a>

                        <div class="cw-top-cmd-search items-center gap-2">
                            <button type="button"
                                    @click="paletteOpen = true"
                                    class="flex items-center justify-between px-3 py-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-alt)]/60 text-xs text-[var(--color-ink-muted)] hover:border-[var(--color-brand)] focus:outline-none transition-all cursor-pointer shadow-2xs w-64 sm:w-80">
                                <div class="flex items-center gap-2 truncate">
                                    <i class="fa-solid fa-magnifying-glass text-[var(--color-ink-soft)] text-xs"></i>
                                    <span class="truncate">Search fleet, commands, servers…</span>
                                </div>
                                <kbd class="cmd-kbd">⌘K</kbd>
                            </button>
                        </div>
                    </div>

                    <!-- Right Controls: ⌘K Palette, Layout Switcher, Font Stepper, Theme, Profile -->
                    <div class="flex items-center gap-2 sm:gap-3">
                        <!-- Quick Jump / Command Palette Button (Modern Studio) -->
                        <button type="button"
                                @click="paletteOpen = true"
                                class="cw-top-jump-btn hidden sm:inline-flex items-center gap-2 px-2.5 py-1.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-alt)] text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] transition-colors cursor-pointer"
                                title="Quick Command Palette (⌘K)">
                            <i class="fa-solid fa-terminal text-[11px]"></i>
                            <span>Jump</span>
                            <kbd class="cmd-kbd">⌘K</kbd>
                        </button>

                        <!-- Layout Style Switcher (Command Center vs Modern Studio) -->
                        <button type="button"
                                @click="toggleLayoutStyle()"
                                class="p-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors text-xs cursor-pointer"
                                :title="style === 'command-center' ? 'Switch to Modern Studio layout' : 'Switch to Command Center layout'"
                                :aria-label="style === 'command-center' ? 'Switch to Modern Studio layout' : 'Switch to Command Center layout'">
                            <i :class="style === 'command-center' ? 'fa-solid fa-gauge-high text-sky-500' : 'fa-solid fa-table-columns text-[var(--color-brand)]'"></i>
                        </button>

                        <!-- Font Size Stepper (+ / -) -->
                        <div class="hidden sm:inline-flex cw-font-stepper text-xs" x-data="fontScaler" title="Adjust application font size">
                            <button type="button" @click="decrease()" :disabled="scale <= min" aria-label="Decrease font size" title="Smaller text (A-)">
                                <i class="fa-solid fa-minus text-[10px]"></i>
                            </button>
                            <span class="cw-font-value" @click="reset()" x-text="scale + '%'" title="Click to reset font size to 100%"></span>
                            <button type="button" @click="increase()" :disabled="scale >= max" aria-label="Increase font size" title="Larger text (A+)">
                                <i class="fa-solid fa-plus text-[10px]"></i>
                            </button>
                        </div>

                        <!-- Dark / Light Theme Toggle -->
                        <button type="button"
                                @click="toggleDark()"
                                class="p-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors text-xs cursor-pointer"
                                :title="isDark ? 'Switch to Light mode' : 'Switch to Dark mode'"
                                :aria-label="isDark ? 'Switch to Light mode' : 'Switch to Dark mode'">
                            <i :class="isDark ? 'fa-solid fa-sun text-amber-400' : 'fa-solid fa-moon text-indigo-500'"></i>
                        </button>

                        <!-- Primary Action: Add Server -->
                        <a href="{{ route('servers.create') }}" class="px-3.5 py-1.5 rounded-lg bg-[var(--color-brand)] text-white text-xs font-semibold shadow-xs hover:opacity-90 transition-opacity inline-flex items-center gap-1.5">
                            <i class="fa-solid fa-plus text-xs"></i>
                            <span class="hidden sm:inline">Add Server</span>
                        </a>

                        <!-- Profile Dropdown Menu -->
                        <div class="relative" @click.outside="userMenuOpen = false">
                            <button type="button"
                                    @click="userMenuOpen = !userMenuOpen"
                                    class="w-8 h-8 rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border)] flex items-center justify-center font-bold text-xs text-[var(--color-ink-strong)] hover:border-[var(--color-brand)] transition-colors cursor-pointer"
                                    aria-label="User menu">
                                {{ strtoupper(substr(auth()->user()?->name ?? 'OP', 0, 2)) }}
                            </button>

                            <div x-show="userMenuOpen"
                                 x-cloak
                                 class="absolute right-0 mt-2 w-60 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-xl z-50 py-1 text-xs">
                                <div class="px-3 py-2 border-b border-[var(--color-border-light)]">
                                    <p class="font-semibold text-[var(--color-ink-strong)] truncate">{{ auth()->user()?->name ?? 'Operator' }}</p>
                                    <p class="text-[10px] text-[var(--color-ink-soft)] truncate">{{ auth()->user()?->email ?? 'admin@lan' }}</p>
                                </div>
                                <a href="{{ route('settings.users.index') }}" class="flex items-center gap-2 px-3 py-2 text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                                    <i class="fa-solid fa-user-gear text-[var(--color-ink-muted)]"></i> Account &amp; Team
                                </a>
                                <a href="{{ route('settings.index') }}" class="flex items-center gap-2 px-3 py-2 text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                                    <i class="fa-solid fa-sliders text-[var(--color-ink-muted)]"></i> Settings Hub
                                </a>
                                @foreach (app(\Modules\Core\ModuleRegistry::class)->navItems() as $moduleNavItem)
                                    @if ($moduleNavItem->isVisible())
                                        <a href="{{ route($moduleNavItem->route) }}" class="flex items-center gap-2 px-3 py-2 text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                                            <i class="{{ $moduleNavItem->icon }} text-[var(--color-ink-muted)]"></i> {{ $moduleNavItem->label }}
                                        </a>
                                    @endif
                                @endforeach

                                {{-- Appearance / Theme Selector --}}
                                <div class="px-3 py-2 border-t border-[var(--color-border-light)]">
                                    <div class="flex items-center justify-between mb-1.5">
                                        <span class="text-[10px] uppercase tracking-wider font-semibold text-[var(--color-ink-soft)] font-mono">Theme Mode</span>
                                        <span class="text-[10px] font-medium text-[var(--color-brand)] capitalize" x-text="current"></span>
                                    </div>
                                    <div class="grid grid-cols-4 gap-1">
                                        <button type="button"
                                                @click.prevent="setTheme('light')"
                                                :class="current === 'light' ? 'border-[var(--color-brand)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] font-semibold ring-1 ring-[var(--color-brand)]/30' : 'border-[var(--color-border-light)] text-[var(--color-ink-muted)] hover:border-[var(--color-border)] hover:bg-[var(--color-surface-alt)]/60'"
                                                class="p-1.5 rounded-lg border text-center transition-all flex flex-col items-center gap-1 cursor-pointer"
                                                title="Light theme">
                                            <i class="fa-solid fa-sun text-amber-500 text-xs"></i>
                                            <span class="text-[10px]">Light</span>
                                        </button>
                                        <button type="button"
                                                @click.prevent="setTheme('dark')"
                                                :class="current === 'dark' ? 'border-[var(--color-brand)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] font-semibold ring-1 ring-[var(--color-brand)]/30' : 'border-[var(--color-border-light)] text-[var(--color-ink-muted)] hover:border-[var(--color-border)] hover:bg-[var(--color-surface-alt)]/60'"
                                                class="p-1.5 rounded-lg border text-center transition-all flex flex-col items-center gap-1 cursor-pointer"
                                                title="Dark theme">
                                            <i class="fa-solid fa-moon text-indigo-400 text-xs"></i>
                                            <span class="text-[10px]">Dark</span>
                                        </button>
                                        <button type="button"
                                                @click.prevent="setTheme('high-contrast')"
                                                :class="current === 'high-contrast' ? 'border-[var(--color-brand)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] font-semibold ring-1 ring-[var(--color-brand)]/30' : 'border-[var(--color-border-light)] text-[var(--color-ink-muted)] hover:border-[var(--color-border)] hover:bg-[var(--color-surface-alt)]/60'"
                                                class="p-1.5 rounded-lg border text-center transition-all flex flex-col items-center gap-1 cursor-pointer"
                                                title="High contrast theme">
                                            <i class="fa-solid fa-circle-half-stroke text-[var(--color-ink-strong)] text-xs"></i>
                                            <span class="text-[10px]">Contrast</span>
                                        </button>
                                        <button type="button"
                                                @click.prevent="setTheme('system')"
                                                :class="current === 'system' ? 'border-[var(--color-brand)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] font-semibold ring-1 ring-[var(--color-brand)]/30' : 'border-[var(--color-border-light)] text-[var(--color-ink-muted)] hover:border-[var(--color-border)] hover:bg-[var(--color-surface-alt)]/60'"
                                                class="p-1.5 rounded-lg border text-center transition-all flex flex-col items-center gap-1 cursor-pointer"
                                                title="Auto (matches system preference)">
                                            <i class="fa-solid fa-laptop text-[var(--color-ink-soft)] text-xs"></i>
                                            <span class="text-[10px]">Auto</span>
                                        </button>
                                    </div>
                                </div>

                                <form method="POST" action="{{ route('logout') }}" class="border-t border-[var(--color-border-light)] mt-1">
                                    @csrf
                                    <button type="submit" class="w-full text-left flex items-center gap-2 px-3 py-2 text-[var(--color-status-red)] hover:bg-[var(--color-surface-alt)] cursor-pointer">
                                        <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Mobile Menu Button -->
                        <button type="button"
                                @click="mobileNavOpen = !mobileNavOpen"
                                class="md:hidden p-2 rounded-lg text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)] cursor-pointer"
                                aria-label="Toggle navigation drawer">
                            <i :class="mobileNavOpen ? 'fa-solid fa-xmark' : 'fa-solid fa-bars'"></i>
                        </button>
                    </div>
                </div>

                <!-- Tier 2: Sub-navigation Segmented Tabs (Modern Studio Layout Only) -->
                <div class="cw-subnav-tier border-t border-[var(--color-border-light)] bg-[var(--color-surface)]/60 backdrop-blur-xs">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                        <nav class="flex items-center gap-1 overflow-x-auto py-2 scrollbar-none text-xs">
                            <a href="{{ route('dashboard') }}"
                               class="studio-nav-tab {{ request()->routeIs('dashboard') || (request()->routeIs('servers.*') && ! request()->routeIs('servers.credentials.*') && ! request()->routeIs('servers.create')) ? 'is-active' : '' }}">
                                <i class="fa-solid fa-server"></i> Servers
                            </a>
                            <a href="{{ route('sites.index') }}"
                               class="studio-nav-tab {{ request()->routeIs('sites.*') ? 'is-active' : '' }}">
                                <i class="fa-solid fa-globe"></i> Sites
                            </a>
                            <a href="{{ route('issues.index') }}"
                               class="studio-nav-tab {{ request()->routeIs('issues.*') ? 'is-active' : '' }}">
                                <i class="fa-solid fa-triangle-exclamation"></i> Issues
                                @isset($issueCount)
                                    @if ($issueCount > 0)
                                        <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-4.5 px-1.5 rounded-full text-[10px] font-bold bg-[var(--color-status-red)] text-white">{{ $issueCount }}</span>
                                    @endif
                                @endisset
                            </a>
                            <a href="{{ route('updates.index') }}"
                               class="studio-nav-tab {{ request()->routeIs('updates.*') ? 'is-active' : '' }}">
                                <i class="fa-solid fa-rotate"></i> Updates
                                @isset($updatesPendingCount)
                                    @if ($updatesPendingCount > 0)
                                        <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-4.5 px-1.5 rounded-full text-[10px] font-bold bg-[var(--color-status-yellow)] text-white">{{ $updatesPendingCount }}</span>
                                    @endif
                                @endisset
                            </a>
                            <a href="{{ route('monitoring.index') }}"
                               class="studio-nav-tab {{ request()->routeIs('monitoring.*') ? 'is-active' : '' }}">
                                <i class="fa-solid fa-heart-pulse"></i> Monitoring
                                @isset($monitoringDownCount)
                                    @if ($monitoringDownCount > 0)
                                        <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-4.5 px-1.5 rounded-full text-[10px] font-bold bg-[var(--color-status-red)] text-white">{{ $monitoringDownCount }}</span>
                                    @endif
                                @endisset
                            </a>
                            <a href="{{ route('security.scans') }}"
                               class="studio-nav-tab {{ (request()->routeIs('security.*') || request()->routeIs('bans.*')) ? 'is-active' : '' }}">
                                <i class="fa-solid fa-shield-halved"></i> Security
                            </a>
                            <a href="{{ route('capacity.index') }}"
                               class="studio-nav-tab {{ request()->routeIs('capacity.*') ? 'is-active' : '' }}">
                                <i class="fa-solid fa-gauge-high"></i> Capacity
                            </a>
                            <a href="{{ route('operations.server-updates.index') }}"
                               class="studio-nav-tab {{ request()->routeIs('operations.*') ? 'is-active' : '' }}">
                                <i class="fa-solid fa-cube"></i> Operations
                            </a>
                            <a href="{{ route('settings.index') }}"
                               class="studio-nav-tab {{ request()->routeIs('settings.*') ? 'is-active' : '' }}">
                                <i class="fa-solid fa-sliders"></i> Settings
                            </a>
                            <a href="{{ route('docs.index') }}"
                               class="studio-nav-tab {{ request()->routeIs('docs.*') ? 'is-active' : '' }}">
                                <i class="fa-solid fa-book-bookmark"></i> Docs
                            </a>
                        </nav>
                    </div>
                </div>

                <!-- Mobile Drawer Navigation -->
                <div x-show="mobileNavOpen"
                     x-cloak
                     class="md:hidden border-t border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-3 space-y-2 text-xs">
                    <div class="flex items-center justify-between pb-2 border-b border-[var(--color-border-light)]">
                        <span class="font-semibold text-[var(--color-ink-muted)]">Font Size</span>
                        <div class="inline-flex cw-font-stepper" x-data="fontScaler">
                            <button type="button" @click="decrease()" :disabled="scale <= min" aria-label="Decrease font size">
                                <i class="fa-solid fa-minus text-[10px]"></i>
                            </button>
                            <span class="cw-font-value" @click="reset()" x-text="scale + '%'"></span>
                            <button type="button" @click="increase()" :disabled="scale >= max" aria-label="Increase font size">
                                <i class="fa-solid fa-plus text-[10px]"></i>
                            </button>
                        </div>
                    </div>
                    <div class="flex items-center justify-between pb-2 border-b border-[var(--color-border-light)]">
                        <span class="font-semibold text-[var(--color-ink-muted)]">Theme Mode</span>
                        <button type="button"
                                @click="toggleDark()"
                                class="px-2.5 py-1 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-alt)] text-xs font-medium text-[var(--color-ink-strong)] flex items-center gap-1.5 cursor-pointer">
                            <i :class="isDark ? 'fa-solid fa-sun text-amber-400' : 'fa-solid fa-moon text-indigo-500'"></i>
                            <span x-text="isDark ? 'Light' : 'Dark'"></span>
                        </button>
                    </div>
                    <div class="grid grid-cols-2 gap-1 pt-1">
                        <a href="{{ route('dashboard') }}" class="px-3 py-2 rounded-lg text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] font-medium">
                            <i class="fa-solid fa-server mr-2 text-[var(--color-brand)]"></i> Servers
                        </a>
                        <a href="{{ route('sites.index') }}" class="px-3 py-2 rounded-lg text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] font-medium">
                            <i class="fa-solid fa-globe mr-2 text-[var(--color-brand)]"></i> Sites
                        </a>
                        <a href="{{ route('issues.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] font-medium">
                            <span><i class="fa-solid fa-triangle-exclamation mr-2 text-[var(--color-status-yellow)]"></i> Issues</span>
                            @isset($issueCount)
                                @if ($issueCount > 0)
                                    <span class="inline-flex items-center justify-center min-w-[1.25rem] h-4.5 px-1.5 rounded-full text-[10px] font-bold bg-[var(--color-status-red)] text-white">{{ $issueCount }}</span>
                                @endif
                            @endisset
                        </a>
                        <a href="{{ route('updates.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] font-medium">
                            <span><i class="fa-solid fa-rotate mr-2 text-[var(--color-brand)]"></i> Updates</span>
                            @isset($updatesPendingCount)
                                @if ($updatesPendingCount > 0)
                                    <span class="inline-flex items-center justify-center min-w-[1.25rem] h-4.5 px-1.5 rounded-full text-[10px] font-bold bg-[var(--color-status-yellow)] text-white">{{ $updatesPendingCount }}</span>
                                @endif
                            @endisset
                        </a>
                        <a href="{{ route('monitoring.index') }}" class="px-3 py-2 rounded-lg text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] font-medium">
                            <i class="fa-solid fa-heart-pulse mr-2 text-[var(--color-status-green)]"></i> Monitoring
                        </a>
                        <a href="{{ route('security.scans') }}" class="px-3 py-2 rounded-lg text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] font-medium">
                            <i class="fa-solid fa-shield-halved mr-2 text-[var(--color-brand)]"></i> Security
                        </a>
                        <a href="{{ route('capacity.index') }}" class="px-3 py-2 rounded-lg text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] font-medium">
                            <i class="fa-solid fa-gauge-high mr-2 text-[var(--color-ink-muted)]"></i> Capacity
                        </a>
                        <a href="{{ route('operations.server-updates.index') }}" class="px-3 py-2 rounded-lg text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] font-medium">
                            <i class="fa-solid fa-cube mr-2 text-[var(--color-ink-muted)]"></i> Operations
                        </a>
                        <a href="{{ route('settings.index') }}" class="px-3 py-2 rounded-lg text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] font-medium">
                            <i class="fa-solid fa-sliders mr-2 text-[var(--color-ink-muted)]"></i> Settings
                        </a>
                        <a href="{{ route('docs.index') }}" class="px-3 py-2 rounded-lg text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] font-medium">
                            <i class="fa-solid fa-book-bookmark mr-2 text-[var(--color-brand)]"></i> Docs
                        </a>
                    </div>
                </div>
            </header>

            <!-- Stale scheduler warning -->
            @isset($schedulerHeartbeat)
                @if ($schedulerHeartbeat->needsAttention())
                    <div class="border-b {{ $schedulerHeartbeat->isStale() ? 'bg-[var(--color-status-red)]/10 border-[var(--color-status-red)]/30' : 'bg-[var(--color-status-yellow)]/10 border-[var(--color-status-yellow)]/30' }}">
                        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex items-center justify-between gap-3 text-sm">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <i class="fa-solid {{ $schedulerHeartbeat->isStale() ? 'fa-clock text-[var(--color-status-red)]' : 'fa-triangle-exclamation text-[var(--color-status-yellow)]' }}"></i>
                                <span class="font-semibold text-[var(--color-ink-strong)]">Scheduler alert:</span>
                                <span class="text-[var(--color-ink-muted)] truncate">Crontab has not executed schedule:run in {{ $schedulerHeartbeat->ageLabel() }}. Fleet metrics may lag.</span>
                            </div>
                            <a href="{{ route('docs.show', 'runbooks/scheduler-stuck') }}" class="text-xs font-semibold text-[var(--color-brand)] hover:underline shrink-0">Runbook &rarr;</a>
                        </div>
                    </div>
                @endif
            @endisset

            <!-- Main Content Canvas -->
            <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-1 w-full">
                @yield('content')
            </main>

            <!-- Clean Clockwork Control Footer -->
            <footer class="border-t border-[var(--color-border)] bg-[var(--color-surface)] text-xs text-[var(--color-ink-muted)] py-6 mt-16">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between flex-wrap gap-4">
                    <div class="flex items-center gap-3">
                        <span class="font-semibold text-[var(--color-ink-strong)]">Clockwork Control</span>
                        <span>·</span>
                        <a href="{{ route('settings.updates.index') }}" class="text-[var(--color-brand)] hover:underline">v{{ config('clockwork.version', '1.0.0') }}</a>
                        <span>·</span>
                        <a href="{{ route('docs.index') }}" class="hover:text-[var(--color-ink-strong)]">Documentation</a>
                    </div>
                    <div>
                        Self-hosted fleet control panel
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- QUICK JUMP / COMMAND PALETTE MODAL (⌘K)                               -->
    <!-- ===================================================================== -->
    <div x-show="paletteOpen"
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto p-4 sm:p-6 md:p-20 flex items-start justify-center"
         role="dialog"
         aria-modal="true">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity" @click="paletteOpen = false"></div>

        <div class="relative w-full max-w-xl transform overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-2xl transition-all">
            <div class="relative">
                <i class="fa-solid fa-terminal pointer-events-none absolute left-4 top-3.5 text-[var(--color-ink-soft)] text-sm"></i>
                <input type="text"
                       x-model="paletteQuery"
                       class="h-12 w-full border-0 bg-transparent pl-11 pr-4 text-[var(--color-ink-strong)] placeholder:text-[var(--color-ink-soft)] focus:ring-0 text-sm font-sans"
                       placeholder="Type a command or jump to workspace… (Esc to exit)"
                       x-ref="paletteInput"
                       x-init="$watch('paletteOpen', value => { if (value) setTimeout(() => $refs.paletteInput.focus(), 50); })">
            </div>

            <div class="border-t border-[var(--color-border-light)] max-h-80 overflow-y-auto p-2 text-xs divide-y divide-[var(--color-border-light)]">
                <!-- Navigation Targets -->
                <div class="py-1">
                    <div class="px-3 py-1.5 text-[10px] uppercase font-semibold text-[var(--color-ink-soft)] tracking-wider">Quick Jump</div>
                    <a href="{{ route('dashboard') }}" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]">
                        <span class="flex items-center gap-2"><i class="fa-solid fa-server w-4 text-[var(--color-brand)]"></i> Servers Fleet</span>
                        <kbd class="cmd-kbd">G S</kbd>
                    </a>
                    <a href="{{ route('sites.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]">
                        <span class="flex items-center gap-2"><i class="fa-solid fa-globe w-4 text-[var(--color-brand)]"></i> Sites Directory</span>
                        <kbd class="cmd-kbd">G T</kbd>
                    </a>
                    <a href="{{ route('issues.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]">
                        <span class="flex items-center gap-2"><i class="fa-solid fa-triangle-exclamation w-4 text-[var(--color-status-yellow)]"></i> Issues Console</span>
                        <kbd class="cmd-kbd">G I</kbd>
                    </a>
                    <a href="{{ route('monitoring.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]">
                        <span class="flex items-center gap-2"><i class="fa-solid fa-heart-pulse w-4 text-[var(--color-status-green)]"></i> Uptime Monitoring</span>
                        <kbd class="cmd-kbd">G M</kbd>
                    </a>
                    <a href="{{ route('updates.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]">
                        <span class="flex items-center gap-2"><i class="fa-solid fa-rotate w-4 text-[var(--color-brand)]"></i> Updates Manager</span>
                        <kbd class="cmd-kbd">G U</kbd>
                    </a>
                    <a href="{{ route('security.scans') }}" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]">
                        <span class="flex items-center gap-2"><i class="fa-solid fa-shield-halved w-4 text-[var(--color-brand)]"></i> Security Scans</span>
                        <kbd class="cmd-kbd">G X</kbd>
                    </a>
                    <a href="{{ route('docs.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]">
                        <span class="flex items-center gap-2"><i class="fa-solid fa-book-bookmark w-4 text-[var(--color-brand)]"></i> Documentation &amp; Runbooks</span>
                        <kbd class="cmd-kbd">G D</kbd>
                    </a>
                </div>

                <!-- Operations -->
                <div class="py-1">
                    <div class="px-3 py-1.5 text-[10px] uppercase font-semibold text-[var(--color-ink-soft)] tracking-wider">Operations</div>
                    <a href="{{ route('capacity.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]">
                        <span class="flex items-center gap-2"><i class="fa-solid fa-gauge-high w-4 text-[var(--color-ink-muted)]"></i> Capacity &amp; Resource Usage</span>
                    </a>
                    <a href="{{ route('operations.server-updates.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]">
                        <span class="flex items-center gap-2"><i class="fa-solid fa-cube w-4 text-[var(--color-ink-muted)]"></i> Fleet OS Updates</span>
                    </a>
                    <a href="{{ route('servers.credentials.bulk') }}" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]">
                        <span class="flex items-center gap-2"><i class="fa-solid fa-key w-4 text-[var(--color-ink-muted)]"></i> Bulk SSH Passwords</span>
                    </a>
                    <a href="{{ route('settings.index') }}" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]">
                        <span class="flex items-center gap-2"><i class="fa-solid fa-sliders w-4 text-[var(--color-ink-muted)]"></i> Global Settings Hub</span>
                    </a>
                </div>
            </div>

            <div class="p-2.5 border-t border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/50 text-[10px] text-[var(--color-ink-soft)] flex items-center justify-between">
                <span>Navigate with <kbd class="cmd-kbd">↑</kbd> <kbd class="cmd-kbd">↓</kbd> · Select with <kbd class="cmd-kbd">↵</kbd></span>
                <span>Clockwork Control</span>
            </div>
        </div>
    </div>
</body>
</html>
