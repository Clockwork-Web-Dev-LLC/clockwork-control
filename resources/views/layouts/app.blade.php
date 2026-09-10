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
<html lang="en" data-theme="{{ $initialTheme }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Clockwork Control') · Modern Studio</title>
    <script>
        (function () {
            try {
                var match = document.cookie.match(/(?:^|; )cw_theme=([^;]*)/);
                var theme = match ? decodeURIComponent(match[1]) : 'light';
                var resolved = theme;
                if (!resolved || resolved === 'system') {
                    resolved = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                if (resolved === 'midnight') {
                    resolved = 'dark';
                }
                document.documentElement.setAttribute('data-theme', resolved);
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--color-surface)] text-[var(--color-ink)] antialiased font-sans"
      x-data="{
          mobileNavOpen: false,
          userMenuOpen: false,
          settingsMenuOpen: false,
          ...themePicker()
      }">

    <!-- ===================================================================== -->
    <!-- DUAL-TIER TOP NAVIGATION BAR (Vercel / Stripe SaaS Style)              -->
    <!-- ===================================================================== -->
    <header class="border-b border-[var(--color-border)] bg-[var(--color-surface)] sticky top-0 z-40 shadow-xs">
        <!-- Tier 1: Global Header -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
            <!-- Left: Logo + Scope -->
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-[var(--color-brand)] to-sky-400 flex items-center justify-center text-white shadow-xs">
                        <i class="fa-solid fa-clock text-sm"></i>
                    </div>
                    <span class="font-display text-lg font-bold tracking-tight text-[var(--color-ink-strong)]">Clockwork</span>
                </a>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] border border-[var(--color-border-light)]">
                    Studio
                </span>
            </div>

            <!-- Right: Search, Actions, Profile -->
            <div class="flex items-center gap-3">
                <!-- Fleet Status Pill -->
                @isset($statusCounts)
                    <div class="hidden md:flex items-center gap-2 text-xs">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[var(--color-status-green)]/10 text-[var(--color-status-green)] font-semibold border border-[var(--color-status-green)]/20">
                            <span class="w-2 h-2 rounded-full bg-[var(--color-status-green)]"></span>
                            {{ $statusCounts[\App\Models\Server::STATUS_GREEN] ?? 0 }} Online
                        </span>
                        @if (($statusCounts[\App\Models\Server::STATUS_RED] ?? 0) > 0)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[var(--color-status-red)]/10 text-[var(--color-status-red)] font-semibold border border-[var(--color-status-red)]/20 animate-pulse">
                                <span class="w-2 h-2 rounded-full bg-[var(--color-status-red)]"></span>
                                {{ $statusCounts[\App\Models\Server::STATUS_RED] }} Alert
                            </span>
                        @endif
                    </div>
                @endisset

                <!-- Primary Action -->
                <a href="{{ route('servers.create') }}" class="px-3.5 py-1.5 rounded-lg bg-[var(--color-brand)] text-white text-xs font-semibold shadow-xs hover:opacity-90 transition-opacity inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-plus text-xs"></i>
                    <span>Add Server</span>
                </a>

                <!-- Theme Toggle -->
                <button type="button"
                        @click="setTheme(isDark ? 'light' : 'dark')"
                        class="p-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] transition-colors text-xs"
                        :title="isDark ? 'Light mode' : 'Dark mode'">
                    <i :class="isDark ? 'fa-solid fa-moon text-sky-400' : 'fa-solid fa-sun text-amber-500'"></i>
                </button>

                <!-- Profile Menu -->
                <div class="relative" @click.outside="userMenuOpen = false">
                    <button type="button"
                            @click="userMenuOpen = !userMenuOpen"
                            class="w-8 h-8 rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border)] flex items-center justify-center font-bold text-xs text-[var(--color-ink-strong)] hover:border-[var(--color-brand)] transition-colors cursor-pointer">
                        {{ strtoupper(substr(auth()->user()?->name ?? 'OP', 0, 2)) }}
                    </button>

                    <div x-show="userMenuOpen"
                         x-cloak
                         class="absolute right-0 mt-2 w-48 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-xl z-50 py-1 text-xs">
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
                        <form method="POST" action="{{ route('logout') }}" class="border-t border-[var(--color-border-light)] mt-1">
                            @csrf
                            <button type="submit" class="w-full text-left flex items-center gap-2 px-3 py-2 text-[var(--color-status-red)] hover:bg-[var(--color-surface-alt)]">
                                <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Mobile Menu Button -->
                <button type="button"
                        @click="mobileNavOpen = !mobileNavOpen"
                        class="md:hidden p-2 rounded-lg text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)]">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
        </div>

        <!-- Tier 2: Sub-navigation Segmented Tabs -->
        <div class="border-t border-[var(--color-border-light)] bg-[var(--color-surface)]/60 backdrop-blur-xs">
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
                </nav>
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
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-1">
        @yield('content')
    </main>

    <!-- Clean Studio Footer -->
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
                Self-hosted fleet control panel · LAN environment
            </div>
        </div>
    </footer>
</body>
</html>
