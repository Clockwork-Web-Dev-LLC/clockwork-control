<!DOCTYPE html>
<html lang="en" data-theme="{{ request()->cookie('cw_theme') && request()->cookie('cw_theme') !== 'system' ? request()->cookie('cw_theme') : 'light' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in · Clockwork</title>
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
<body class="min-h-screen bg-[var(--color-surface)] text-[var(--color-ink)] flex items-center justify-center">
    <div class="w-full max-w-md px-6">
        <div class="flex flex-col items-center mb-8">
            <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-[var(--color-brand)] text-white mb-3">
                <i class="fa-solid fa-clock text-2xl"></i>
            </span>
            <h1 class="font-display text-2xl font-semibold tracking-tight text-[var(--color-ink-strong)]">Clockwork</h1>
            <p class="text-sm text-[var(--color-ink-muted)] mt-1">Sign in to continue</p>
        </div>

        @if (session('login_denial') || $denial)
            <div class="card p-4 mb-4 status-red text-sm flex items-start gap-2">
                <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                <div>{{ session('login_denial') ?? $denial }}</div>
            </div>
        @endif

        @if (session('login_status'))
            <div class="card p-4 mb-4 status-green text-sm flex items-center gap-2">
                <i class="fa-solid fa-circle-check"></i>
                {{ session('login_status') }}
            </div>
        @endif

        @if (!empty($providers))
            <div class="flex flex-col gap-3">
                @foreach ($providers as $provider)
                    <a href="{{ $provider->redirectUrl() }}"
                       class="card p-4 flex items-center justify-center gap-3 hover:bg-[var(--color-surface-alt)] transition-colors text-[var(--color-ink-strong)]">
                        <i class="{{ $provider->icon() }} text-lg text-[var(--color-brand)]"></i>
                        <span class="font-medium">{{ $provider->buttonLabel() }}</span>
                    </a>
                @endforeach
            </div>
        @else
            <div class="card p-4 text-center text-sm text-[var(--color-ink-muted)]">
                <i class="fa-solid fa-lock text-xl mb-2 text-[var(--color-ink-muted)] block"></i>
                <p class="font-medium text-[var(--color-ink-strong)]">No authentication provider configured.</p>
                <p class="mt-1 text-xs">Enable Google, GitHub, or Microsoft authentication in <code>/setup</code> or your environment.</p>
            </div>
        @endif

        <p class="text-xs text-[var(--color-ink-muted)] text-center mt-6">
            Clockwork employees only. Ask an administrator to add your email if you don't have access yet.
        </p>
    </div>
</body>
</html>
