@php
    $cookieTheme = request()->cookie('cw_theme');
    $initialTheme = ($cookieTheme && $cookieTheme !== 'system')
        ? ($cookieTheme === 'midnight' ? 'dark' : $cookieTheme)
        : 'light';
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="{{ $initialTheme }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Installation Wizard') · Clockwork Control</title>
    <script>
        (function () {
            try {
                var theme = null;
                try {
                    theme = localStorage.getItem('cw_theme');
                } catch (e) {}
                if (!theme) {
                    var match = document.cookie.match(/(?:^|; )cw_theme=([^;]*)/);
                    theme = match ? decodeURIComponent(match[1]) : 'system';
                }
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
<body class="min-h-screen bg-[var(--color-surface)] text-[var(--color-ink)] flex flex-col justify-between antialiased">
    <!-- Header -->
    <header class="border-b border-[var(--color-border-light)] bg-[var(--color-surface)]/80 backdrop-blur sticky top-0 z-30">
        <div class="max-w-4xl mx-auto px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-clock text-xl text-[var(--color-brand)]"></i>
                <span class="font-display text-lg font-semibold tracking-tight text-[var(--color-ink-strong)]">Clockwork Control</span>
                <span class="text-xs px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] font-medium border border-[var(--color-border-light)]">Installer</span>
            </div>
            <div class="text-xs text-[var(--color-ink-soft)] font-medium flex items-center gap-1.5">
                <i class="fa-solid fa-shield-check text-[var(--color-brand)]"></i>
                Pre-Flight Setup
            </div>
        </div>
    </header>

    <!-- Main Wizard Content -->
    <main class="max-w-4xl mx-auto px-6 py-8 flex-1 w-full">
        @php
            $hasExistingSystem = false;
            try {
                $hasExistingSystem = ! app()->runningUnitTests()
                    && \Illuminate\Support\Facades\Schema::hasTable('users')
                    && \App\Models\User::query()->whereNull('revoked_at')->exists();
            } catch (\Throwable) {}
        @endphp

        @if ($hasExistingSystem && (!isset($step) || $step !== 9))
            <div class="mb-6 p-4 rounded-xl bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="w-8 h-8 rounded-full bg-[var(--color-status-green)]/15 text-[var(--color-status-green)] flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-circle-check text-base"></i>
                    </span>
                    <div>
                        <div class="text-sm font-semibold text-[var(--color-ink-strong)]">Existing Clockwork Control database detected</div>
                        <div class="text-xs text-[var(--color-ink-muted)]">Your <code class="font-mono text-[11px] bg-[var(--color-surface)] px-1 py-0.5 rounded border border-[var(--color-border-light)]">.env</code> and database are already configured. Forms are pre-filled below, or you can unlock immediately.</div>
                    </div>
                </div>
                <form method="POST" action="{{ route('install.unlock') }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-lg bg-[var(--color-brand)] hover:bg-[var(--color-brand-hover)] text-white text-xs font-semibold whitespace-nowrap transition-all shadow-sm flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-lock-open"></i> Unlock & Go to Login
                    </button>
                </form>
            </div>
        @endif

        @if (isset($step) && $step <= 8)
            @include('install._stepper', ['currentStep' => $step])
        @endif

        <div class="mt-6"
             x-data
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0">
            @yield('content')
        </div>
    </main>

    <!-- Footer -->
    <footer class="border-t border-[var(--color-border-light)] py-4 text-center text-xs text-[var(--color-ink-soft)]">
        Clockwork Control Installer · Self-hosted WordPress fleet management
    </footer>
</body>
</html>
