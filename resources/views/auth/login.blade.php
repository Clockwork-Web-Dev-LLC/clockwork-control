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
    <title>Sign in · Clockwork</title>
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
<body class="min-h-screen bg-[var(--color-surface)] text-[var(--color-ink)] flex items-center justify-center">
    <div class="w-full max-w-md px-6 py-12">
        <div class="flex flex-col items-center mb-8">
            <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-[var(--color-brand)] text-white mb-3 shadow-sm">
                <i class="fa-solid fa-clock text-2xl"></i>
            </span>
            <h1 class="font-display text-2xl font-semibold tracking-tight text-[var(--color-ink-strong)]">Clockwork Control</h1>
            <p class="text-sm text-[var(--color-ink-muted)] mt-1">Sign in to manage your fleet</p>
        </div>

        @if (session('login_denial') || $denial)
            <div class="card p-4 mb-5 status-red text-sm flex items-start gap-2.5">
                <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0"></i>
                <div>{{ session('login_denial') ?? $denial }}</div>
            </div>
        @endif

        @if (session('login_status'))
            <div class="card p-4 mb-5 status-green text-sm flex items-center gap-2.5">
                <i class="fa-solid fa-circle-check shrink-0"></i>
                {{ session('login_status') }}
            </div>
        @endif

        {{-- Local email & password login form --}}
        <form method="POST" action="{{ route('login.attempt') }}" class="space-y-4 mb-6">
            @csrf
            <div>
                <label for="email" class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] mb-1.5">Email address</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)] focus:border-transparent transition-all placeholder:text-[var(--color-ink-muted)]"
                       placeholder="you@agency.com">
                @error('email')
                    <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] mb-1.5">Password</label>
                <input type="password" name="password" id="password" required autocomplete="current-password"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)] focus:border-transparent transition-all placeholder:text-[var(--color-ink-muted)]"
                       placeholder="••••••••••••">
                @error('password')
                    <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center justify-between pt-1">
                <label class="flex items-center gap-2 text-xs text-[var(--color-ink-muted)] cursor-pointer select-none">
                    <input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}
                           class="rounded border-[var(--color-border)] text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                    <span>Remember me</span>
                </label>
            </div>

            <button type="submit"
                    class="w-full py-2.5 px-4 rounded-lg bg-[var(--color-brand)] hover:opacity-90 text-white font-medium text-sm transition-opacity shadow-sm flex items-center justify-center gap-2">
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
                <span>Sign in</span>
            </button>
        </form>

        {{-- Single Sign-On options (rendered only when configured) --}}
        @if (!empty($providers))
            <div class="relative flex items-center justify-center my-6">
                <div class="border-t border-[var(--color-border)] w-full"></div>
                <span class="bg-[var(--color-surface)] px-3 text-xs uppercase tracking-wider font-semibold text-[var(--color-ink-muted)] absolute">
                    or continue with
                </span>
            </div>

            <div class="flex flex-col gap-2.5">
                @foreach ($providers as $provider)
                    <a href="{{ $provider->redirectUrl() }}"
                       class="card p-3.5 flex items-center justify-center gap-3 hover:bg-[var(--color-surface-alt)] transition-colors text-[var(--color-ink-strong)] border border-[var(--color-border)]">
                        <i class="{{ $provider->icon() }} text-base text-[var(--color-brand)]"></i>
                        <span class="text-sm font-medium">{{ $provider->buttonLabel() }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        <p class="text-xs text-[var(--color-ink-muted)] text-center mt-8">
            Clockwork operators only. Ask an administrator to add your account if you don't have access yet.
        </p>
    </div>
</body>
</html>
