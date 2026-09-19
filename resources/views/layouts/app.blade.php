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
      @keydown.escape="paletteOpen = false; userMenuOpen = false; sidebarUserMenuOpen = false; mobileNavOpen = false">

    <!-- ================================================================= -->
    <!-- FLOATING TOAST PILL (Layout Switch Feedback)                      -->
    <!-- ================================================================= -->
    <div x-show="toastVisible"
         x-transition:enter="transition ease-out duration-350"
         x-transition:enter-start="opacity-0 -translate-y-6 scale-75"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 -translate-y-4 scale-90"
         x-cloak
         class="fixed top-6 left-1/2 -translate-x-1/2 z-50 pointer-events-none select-none">
        <div class="cw-layout-toast flex items-center gap-4 px-7 py-3.5 rounded-full border border-[var(--color-border)] bg-[var(--color-surface)]/95 backdrop-blur-md shadow-2xl">
            <div class="w-12 h-12 rounded-full flex items-center justify-center shrink-0 bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] shadow-xs"
                 :class="toastData.color">
                <i class="fa-solid text-xl" :class="toastData.icon"></i>
            </div>
            <span class="text-xl font-bold tracking-tight text-[var(--color-ink-strong)]" x-text="toastData.title"></span>
        </div>
    </div>

    <div class="flex min-h-screen">
        <!-- ================================================================= -->
        <!-- COMMAND RAIL (Left Sidebar - Command Center Layout Only)          -->
        <!-- ================================================================= -->
        <aside class="cw-sidebar-rail hidden lg:flex flex-col justify-between sticky top-0 h-screen border-r border-[var(--color-border-light)] bg-[var(--color-surface)] z-40 transition-all duration-200 select-none shrink-0"
               :class="sidebarOpen ? 'w-64' : 'w-18'">

            <!-- Top Sidebar Header & Navigation -->
            <div class="flex flex-col min-h-0 overflow-y-auto px-3 py-4 space-y-6">
                <!-- Brand Header -->
                <div :class="sidebarOpen ? 'flex items-center justify-between px-2' : 'flex flex-col items-center gap-2.5 py-1 px-1'">
                    <a href="{{ route('dashboard') }}"
                       class="flex items-center gap-2.5 min-w-0"
                       :class="sidebarOpen ? '' : 'justify-center'"
                       title="Clockwork Control">
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
                            class="rounded-md text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors cursor-pointer flex items-center justify-center"
                            :class="sidebarOpen ? 'p-1.5' : 'w-7 h-7'"
                            :title="sidebarOpen ? 'Collapse rail (⌘B)' : 'Expand rail (⌘B)'"
                            :aria-label="sidebarOpen ? 'Collapse rail (⌘B)' : 'Expand rail (⌘B)'">
                        <i class="fa-solid text-xs" :class="sidebarOpen ? 'fa-angles-left' : 'fa-angles-right'"></i>
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
                       class="cmd-nav-item {{ request()->routeIs('operations.server-updates.*') ? 'is-active' : '' }}"
                       :title="!sidebarOpen ? 'Fleet Updates' : ''">
                        <i class="fa-solid fa-cube w-4 text-center shrink-0"></i>
                        <span x-show="sidebarOpen" x-transition.opacity class="truncate flex-1">Fleet Updates</span>
                    </a>

                    <a href="{{ route('maintenance-history.index') }}"
                       class="cmd-nav-item {{ request()->routeIs('maintenance-history.*') ? 'is-active' : '' }}"
                       :title="!sidebarOpen ? 'Maintenance History' : ''">
                        <i class="fa-solid fa-clock-rotate-left w-4 text-center shrink-0"></i>
                        <span x-show="sidebarOpen" x-transition.opacity class="truncate flex-1">Maintenance</span>
                    </a>
                </div>

                <!-- Configuration Navigation -->
                <div class="space-y-1">
                    <div x-show="sidebarOpen" x-transition.opacity class="px-2 text-[10px] uppercase tracking-wider font-semibold text-[var(--color-ink-soft)]">
                        Configuration
                    </div>

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

            <!-- Bottom Sidebar Footer (User & Popover Menu) -->
            <div class="p-3 border-t border-[var(--color-border-light)] relative"
                 @click.outside="sidebarUserMenuOpen = false">
                <button type="button"
                        @click="sidebarUserMenuOpen = !sidebarUserMenuOpen"
                        class="w-full flex items-center gap-2.5 p-1.5 rounded-xl hover:bg-[var(--color-surface-alt)] transition-colors cursor-pointer group text-left"
                        :class="[
                            sidebarUserMenuOpen ? 'bg-[var(--color-surface-alt)]' : '',
                            sidebarOpen ? 'justify-between' : 'justify-center'
                        ]"
                        aria-label="User menu"
                        :title="!sidebarOpen ? '{{ auth()->user()?->name ?? 'Operator' }}' : ''">
                    <div class="flex items-center gap-2.5 min-w-0" :class="sidebarOpen ? '' : 'justify-center'">
                        <div class="w-8 h-8 rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border)] group-hover:border-[var(--color-brand)] transition-colors flex items-center justify-center font-bold text-xs text-[var(--color-ink-strong)] shrink-0 overflow-hidden">
                            @if (auth()->check())
                                <img src="{{ auth()->user()->avatarUrl(64) }}"
                                     alt="{{ auth()->user()->name }}"
                                     class="w-full h-full object-cover rounded-full"
                                     loading="lazy"
                                     referrerpolicy="no-referrer"
                                     onerror="this.onerror=null; this.classList.add('hidden'); if(this.nextElementSibling) this.nextElementSibling.classList.remove('hidden');">
                                <span class="hidden font-bold text-xs text-[var(--color-ink-strong)]">
                                    {{ auth()->user()->initials() }}
                                </span>
                            @else
                                OP
                            @endif
                        </div>
                        <div x-show="sidebarOpen" x-transition.opacity class="min-w-0 leading-tight">
                            <p class="text-xs font-semibold text-[var(--color-ink-strong)] truncate">{{ auth()->user()?->name ?? 'Operator' }}</p>
                            <p class="text-[10px] text-[var(--color-ink-soft)] truncate">{{ auth()->user()?->email ?? 'admin@lan' }}</p>
                        </div>
                    </div>
                    <div x-show="sidebarOpen" x-transition.opacity class="text-[var(--color-ink-soft)] group-hover:text-[var(--color-ink-strong)] transition-colors shrink-0 pr-1">
                        <i class="fa-solid fa-ellipsis-vertical text-xs"></i>
                    </div>
                </button>

                <!-- Upward Popover Menu -->
                <div x-show="sidebarUserMenuOpen"
                     x-cloak
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="opacity-0 translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-75"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 translate-y-2"
                     class="absolute bottom-full left-2 mb-2 w-60 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-2xl z-50 py-1 text-xs">
                    <div class="px-3 py-2 border-b border-[var(--color-border-light)] flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border)] flex items-center justify-center font-bold text-xs text-[var(--color-ink-strong)] shrink-0 overflow-hidden">
                            @if (auth()->check())
                                <img src="{{ auth()->user()->avatarUrl(64) }}"
                                     alt="{{ auth()->user()->name }}"
                                     class="w-full h-full object-cover rounded-full"
                                     loading="lazy"
                                     referrerpolicy="no-referrer"
                                     onerror="this.onerror=null; this.classList.add('hidden'); if(this.nextElementSibling) this.nextElementSibling.classList.remove('hidden');">
                                <span class="hidden font-bold text-xs text-[var(--color-ink-strong)]">
                                    {{ auth()->user()->initials() }}
                                </span>
                            @else
                                OP
                            @endif
                        </div>
                        <div class="min-w-0 flex-1 leading-tight">
                            <p class="font-semibold text-[var(--color-ink-strong)] truncate">{{ auth()->user()?->name ?? 'Operator' }}</p>
                            <p class="text-[10px] text-[var(--color-ink-soft)] truncate">{{ auth()->user()?->email ?? 'admin@lan' }}</p>
                        </div>
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

                    <form method="POST" action="{{ route('logout') }}" class="border-t border-[var(--color-border-light)] mt-1">
                        @csrf
                        <button type="submit" class="w-full text-left flex items-center gap-2 px-3 py-2 text-[var(--color-status-red)] hover:bg-[var(--color-surface-alt)] cursor-pointer">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out
                        </button>
                    </form>
                </div>
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
                            <span class="hidden sm:inline font-display text-lg font-bold tracking-tight text-[var(--color-ink-strong)]">Clockwork Control</span>
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
                                class="cw-top-jump-btn hidden md:inline-flex items-center gap-2 px-2.5 py-1.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-alt)] text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] transition-colors cursor-pointer"
                                title="Quick Command Palette (⌘K)">
                            <i class="fa-solid fa-terminal text-[11px]"></i>
                            <span>Jump</span>
                            <kbd class="cmd-kbd">⌘K</kbd>
                        </button>

                        <!-- Layout Style Switcher (Command Center vs Modern Studio) -->
                        <button type="button"
                                @click="toggleLayoutStyle()"
                                class="cw-layout-switcher-btn hidden lg:inline-flex relative p-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors text-xs cursor-pointer select-none"
                                :class="{ 'cw-jelly-squish': buttonSquish }"
                                :title="style === 'command-center' ? 'Switch to Modern Studio layout' : 'Switch to Command Center layout'"
                                :aria-label="style === 'command-center' ? 'Switch to Modern Studio layout' : 'Switch to Command Center layout'">
                            <span class="inline-flex items-center justify-center pointer-events-none"
                                  :class="{ 'cw-icon-spin': iconSpinning }">
                                <i :class="style === 'command-center' ? 'fa-solid fa-gauge-high' : 'fa-solid fa-table-columns'" class="text-[var(--color-brand)]"></i>
                            </span>
                        </button>

                        <!-- Font Size Stepper (+ / -) -->
                        <div class="hidden md:inline-flex cw-font-stepper text-xs" x-data="fontScaler" title="Adjust application font size">
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

                        <!-- Profile Dropdown Menu (Modern Studio Layout / Mobile) -->
                        <div class="relative cw-top-profile" @click.outside="userMenuOpen = false">
                            <button type="button"
                                    @click="userMenuOpen = !userMenuOpen"
                                    class="w-8 h-8 rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border)] flex items-center justify-center font-bold text-xs text-[var(--color-ink-strong)] hover:border-[var(--color-brand)] transition-colors cursor-pointer overflow-hidden"
                                    aria-label="User menu">
                                @if (auth()->check())
                                    <img src="{{ auth()->user()->avatarUrl(64) }}"
                                         alt="{{ auth()->user()->name }}"
                                         class="w-full h-full object-cover rounded-full"
                                         loading="lazy"
                                         referrerpolicy="no-referrer"
                                         onerror="this.onerror=null; this.classList.add('hidden'); if(this.nextElementSibling) this.nextElementSibling.classList.remove('hidden');">
                                    <span class="hidden font-bold text-xs text-[var(--color-ink-strong)]">
                                        {{ auth()->user()->initials() }}
                                    </span>
                                @else
                                    OP
                                @endif
                            </button>

                            <div x-show="userMenuOpen"
                                 x-cloak
                                 class="absolute right-0 mt-2 w-60 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-xl z-50 py-1 text-xs">
                                <div class="px-3 py-2 border-b border-[var(--color-border-light)] flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border)] flex items-center justify-center font-bold text-xs text-[var(--color-ink-strong)] shrink-0 overflow-hidden">
                                        @if (auth()->check())
                                            <img src="{{ auth()->user()->avatarUrl(64) }}"
                                                 alt="{{ auth()->user()->name }}"
                                                 class="w-full h-full object-cover rounded-full"
                                                 loading="lazy"
                                                 referrerpolicy="no-referrer"
                                                 onerror="this.onerror=null; this.classList.add('hidden'); if(this.nextElementSibling) this.nextElementSibling.classList.remove('hidden');">
                                            <span class="hidden font-bold text-xs text-[var(--color-ink-strong)]">
                                                {{ auth()->user()->initials() }}
                                            </span>
                                        @else
                                            OP
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex-1 leading-tight">
                                        <p class="font-semibold text-[var(--color-ink-strong)] truncate">{{ auth()->user()?->name ?? 'Operator' }}</p>
                                        <p class="text-[10px] text-[var(--color-ink-soft)] truncate">{{ auth()->user()?->email ?? 'admin@lan' }}</p>
                                    </div>
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
                                :aria-expanded="mobileNavOpen.toString()"
                                aria-controls="cw-mobile-drawer"
                                aria-label="Toggle navigation drawer">
                            <i :class="mobileNavOpen ? 'fa-solid fa-xmark' : 'fa-solid fa-bars'"></i>
                        </button>
                    </div>
                </div>

                <!-- Tier 2: Sub-navigation Segmented Tabs (Modern Studio, md+) -->
                <div class="cw-subnav-tier hidden md:block border-t border-[var(--color-border-light)] bg-[var(--color-surface)]/60 backdrop-blur-xs">
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
                               class="studio-nav-tab {{ (request()->routeIs('operations.*') || request()->routeIs('capacity.*') || request()->routeIs('maintenance-history.*')) ? 'is-active' : '' }}">
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

            </header>

            <!-- Mobile navigation sheet — the only primary nav below md. -->
            <div x-show="mobileNavOpen"
                 x-cloak
                 class="md:hidden">
                <div class="fixed inset-0 z-[60] bg-black/50 backdrop-blur-xs"
                     @click="closeMobileNav()"
                     x-transition:enter="ease-out duration-200"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="ease-in duration-150"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"></div>
                <nav id="cw-mobile-drawer"
                     class="cw-mobile-drawer fixed inset-y-0 right-0 z-[61] w-[min(20rem,88vw)] flex flex-col"
                     role="dialog"
                     aria-modal="true"
                     aria-label="Navigation"
                     x-transition:enter="transform ease-out duration-250"
                     x-transition:enter-start="translate-x-full"
                     x-transition:enter-end="translate-x-0"
                     x-transition:leave="transform ease-in duration-200"
                     x-transition:leave-start="translate-x-0"
                     x-transition:leave-end="translate-x-full">
                    <div class="flex items-center justify-between px-4 h-16 border-b border-[var(--color-border-light)] shrink-0">
                        <span class="font-display text-sm font-bold tracking-tight text-[var(--color-ink-strong)]">Menu</span>
                        <button type="button"
                                @click="closeMobileNav()"
                                class="p-2 rounded-lg text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)] cursor-pointer"
                                aria-label="Close navigation">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <div class="flex-1 overflow-y-auto px-3 py-3 space-y-1">
                        <a href="{{ route('dashboard') }}"
                           class="cw-mobile-nav-link {{ request()->routeIs('dashboard') || (request()->routeIs('servers.*') && ! request()->routeIs('servers.credentials.*') && ! request()->routeIs('servers.create')) ? 'is-active' : '' }}">
                            <span><i class="fa-solid fa-server mr-2 text-[var(--color-brand)]"></i> Servers</span>
                        </a>
                        <a href="{{ route('sites.index') }}"
                           class="cw-mobile-nav-link {{ request()->routeIs('sites.*') ? 'is-active' : '' }}">
                            <span><i class="fa-solid fa-globe mr-2 text-[var(--color-brand)]"></i> Sites</span>
                        </a>
                        <a href="{{ route('issues.index') }}"
                           class="cw-mobile-nav-link {{ request()->routeIs('issues.*') ? 'is-active' : '' }}">
                            <span><i class="fa-solid fa-triangle-exclamation mr-2 text-[var(--color-status-yellow)]"></i> Issues</span>
                            @isset($issueCount)
                                @if ($issueCount > 0)
                                    <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-bold bg-[var(--color-status-red)] text-white">{{ $issueCount }}</span>
                                @endif
                            @endisset
                        </a>
                        <a href="{{ route('updates.index') }}"
                           class="cw-mobile-nav-link {{ request()->routeIs('updates.*') ? 'is-active' : '' }}">
                            <span><i class="fa-solid fa-rotate mr-2 text-[var(--color-brand)]"></i> Updates</span>
                            @isset($updatesPendingCount)
                                @if ($updatesPendingCount > 0)
                                    <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-bold bg-[var(--color-status-yellow)] text-white">{{ $updatesPendingCount }}</span>
                                @endif
                            @endisset
                        </a>
                        <a href="{{ route('monitoring.index') }}"
                           class="cw-mobile-nav-link {{ request()->routeIs('monitoring.*') ? 'is-active' : '' }}">
                            <span><i class="fa-solid fa-heart-pulse mr-2 text-[var(--color-status-green)]"></i> Monitoring</span>
                            @isset($monitoringDownCount)
                                @if ($monitoringDownCount > 0)
                                    <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-bold bg-[var(--color-status-red)] text-white">{{ $monitoringDownCount }}</span>
                                @endif
                            @endisset
                        </a>
                        <a href="{{ route('security.scans') }}"
                           class="cw-mobile-nav-link {{ (request()->routeIs('security.*') || request()->routeIs('bans.*')) ? 'is-active' : '' }}">
                            <span><i class="fa-solid fa-shield-halved mr-2 text-[var(--color-brand)]"></i> Security</span>
                        </a>
                        <a href="{{ route('capacity.index') }}"
                           class="cw-mobile-nav-link {{ (request()->routeIs('operations.*') || request()->routeIs('capacity.*') || request()->routeIs('maintenance-history.*')) ? 'is-active' : '' }}">
                            <span><i class="fa-solid fa-cube mr-2 text-[var(--color-brand)]"></i> Operations</span>
                        </a>
                        <a href="{{ route('settings.index') }}"
                           class="cw-mobile-nav-link {{ request()->routeIs('settings.*') ? 'is-active' : '' }}">
                            <span><i class="fa-solid fa-sliders mr-2 text-[var(--color-ink-muted)]"></i> Settings</span>
                        </a>
                        <a href="{{ route('docs.index') }}"
                           class="cw-mobile-nav-link {{ request()->routeIs('docs.*') ? 'is-active' : '' }}">
                            <span><i class="fa-solid fa-book-bookmark mr-2 text-[var(--color-brand)]"></i> Docs</span>
                        </a>
                    </div>
                    <div class="px-4 py-3 border-t border-[var(--color-border-light)] shrink-0 flex items-center justify-between gap-3">
                        <span class="text-xs font-semibold text-[var(--color-ink-muted)]">Font size</span>
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
                </nav>
            </div>

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
    <script>
        window.cwQuickJumpItems = [
            { id: 'servers', section: 'Quick Jump', label: 'Servers Fleet', code: 'GS', kbd: 'G S', icon: 'fa-solid fa-server text-[var(--color-brand)]', url: '{{ route('dashboard') }}' },
            { id: 'sites', section: 'Quick Jump', label: 'Sites Directory', code: 'GT', kbd: 'G T', icon: 'fa-solid fa-globe text-[var(--color-brand)]', url: '{{ route('sites.index') }}' },
            { id: 'issues', section: 'Quick Jump', label: 'Issues Console', code: 'GI', kbd: 'G I', icon: 'fa-solid fa-triangle-exclamation text-[var(--color-status-yellow)]', url: '{{ route('issues.index') }}' },
            { id: 'monitoring', section: 'Quick Jump', label: 'Uptime Monitoring', code: 'GM', kbd: 'G M', icon: 'fa-solid fa-heart-pulse text-[var(--color-status-green)]', url: '{{ route('monitoring.index') }}' },
            { id: 'updates', section: 'Quick Jump', label: 'Updates Manager', code: 'GU', kbd: 'G U', icon: 'fa-solid fa-rotate text-[var(--color-brand)]', url: '{{ route('updates.index') }}' },
            { id: 'security', section: 'Quick Jump', label: 'Security Scans', code: 'GX', kbd: 'G X', icon: 'fa-solid fa-shield-halved text-[var(--color-brand)]', url: '{{ route('security.scans') }}' },
            { id: 'capacity', section: 'Operations', label: 'Capacity Dashboard', code: null, kbd: null, icon: 'fa-solid fa-gauge-high text-[var(--color-ink-muted)]', url: '{{ route('capacity.index') }}' },
            { id: 'server-updates', section: 'Operations', label: 'Fleet OS Updates', code: null, kbd: null, icon: 'fa-solid fa-cube text-[var(--color-ink-muted)]', url: '{{ route('operations.server-updates.index') }}' },
            { id: 'maintenance-history', section: 'Operations', label: 'Maintenance History', code: null, kbd: null, icon: 'fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)]', url: '{{ route('maintenance-history.index') }}' },
            { id: 'credentials', section: 'Operations', label: 'Bulk SSH Passwords', code: null, kbd: null, icon: 'fa-solid fa-key text-[var(--color-ink-muted)]', url: '{{ route('servers.credentials.bulk') }}' },
            { id: 'settings', section: 'Configuration', label: 'Global Settings Hub', code: null, kbd: null, icon: 'fa-solid fa-sliders text-[var(--color-ink-muted)]', url: '{{ route('settings.index') }}' },
            { id: 'docs', section: 'Configuration', label: 'Documentation & Runbooks', code: 'GD', kbd: 'G D', icon: 'fa-solid fa-book-bookmark text-[var(--color-brand)]', url: '{{ route('docs.index') }}' }
        ];
    </script>
    <div id="cw-quick-jump-modal"
         x-show="paletteOpen"
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto p-4 sm:p-6 md:p-20 flex items-start justify-center"
         role="dialog"
         aria-modal="true"
         aria-label="Quick Jump Palette"
         @keydown.escape.prevent="paletteOpen = false">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity" @click="paletteOpen = false"></div>

        <div class="relative w-full max-w-xl transform overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-2xl transition-all"
             @click.outside="paletteOpen = false">
            <div class="relative">
                <i class="fa-solid fa-terminal pointer-events-none absolute left-4 top-3.5 text-[var(--color-ink-soft)] text-sm"></i>
                <input type="text"
                       x-model="paletteQuery"
                       @input="onPaletteInput()"
                       @keydown.down.prevent="paletteDown()"
                       @keydown.up.prevent="paletteUp()"
                       @keydown.enter.prevent="selectCurrent()"
                       class="h-12 w-full border-0 bg-transparent pl-11 pr-4 text-[var(--color-ink-strong)] placeholder:text-[var(--color-ink-soft)] focus:ring-0 text-sm font-sans"
                       placeholder="Type a command or jump code (e.g. GS, GT)… (Esc to exit)"
                       x-ref="paletteInput">
            </div>

            <div class="border-t border-[var(--color-border-light)] max-h-80 overflow-y-auto p-2 text-xs divide-y divide-[var(--color-border-light)]">
                <template x-for="group in groupedPaletteItems" :key="group.section">
                    <div class="py-1">
                        <div class="px-3 py-1.5 text-[10px] uppercase font-semibold text-[var(--color-ink-soft)] tracking-wider" x-text="group.section"></div>
                        <template x-for="item in group.items" :key="item.id">
                            <a :href="item.url"
                               :id="'cw-palette-item-' + getItemGlobalIndex(item)"
                               @click.prevent="navigateTo(item.url)"
                               @mouseenter="paletteSelectedIndex = getItemGlobalIndex(item)"
                               class="flex items-center justify-between px-3 py-2 rounded-lg transition-colors cursor-pointer select-none"
                               :class="paletteSelectedIndex === getItemGlobalIndex(item) ? 'bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] ring-1 ring-[var(--color-border-light)] font-medium' : 'text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]'">
                                <span class="flex items-center gap-2">
                                    <i :class="item.icon" class="w-4 text-center"></i>
                                    <span x-text="item.label"></span>
                                </span>
                                <template x-if="item.kbd">
                                    <kbd class="cmd-kbd" x-text="item.kbd"></kbd>
                                </template>
                            </a>
                        </template>
                    </div>
                </template>

                <div x-show="filteredPaletteItems.length === 0" class="py-8 text-center text-xs text-[var(--color-ink-muted)]">
                    No destinations matching "<span class="font-medium text-[var(--color-ink-strong)]" x-text="paletteQuery"></span>"
                </div>
            </div>

            <div class="p-2.5 border-t border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/50 text-[10px] text-[var(--color-ink-soft)] flex items-center justify-between">
                <span>Navigate with <kbd class="cmd-kbd">↑</kbd> <kbd class="cmd-kbd">↓</kbd> · Select with <kbd class="cmd-kbd">↵</kbd></span>
            </div>
        </div>
    </div>
    {{-- Global Confirmation & Prompt Modal --}}
    <x-confirm-modal />
</body>
</html>
