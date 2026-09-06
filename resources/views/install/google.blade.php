@extends('layouts.install')

@section('title', 'Google OAuth Setup')

@section('content')
<div class="card p-6 md:p-8 shadow-sm">
    <div class="mb-6">
        <div class="flex items-center gap-2 mb-1">
            <span class="w-6 h-6 rounded-full bg-red-500/10 text-red-500 inline-flex items-center justify-center text-xs">
                <i class="fa-brands fa-google"></i>
            </span>
            <h1 class="font-display text-2xl font-bold tracking-tight text-[var(--color-ink-strong)]">
                Step 5: Google OAuth Authentication
            </h1>
        </div>
        <p class="text-sm text-[var(--color-ink-muted)]">
            Clockwork Control uses Google OAuth to authenticate operators against a strict allowlist. This step is required for any user to log in.
        </p>
    </div>

    @if ($errors->any())
        <div class="p-4 rounded-xl border border-[var(--color-status-red)]/30 bg-[var(--color-status-red-bg)] text-xs text-[var(--color-status-red)] mb-6">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
            {{ $errors->first() }}
        </div>
    @endif

    <!-- Helper Guide Box -->
    <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/60 text-xs mb-6 space-y-2">
        <div class="font-semibold text-[var(--color-ink-strong)] flex items-center gap-1.5">
            <i class="fa-solid fa-circle-info text-[var(--color-brand)]"></i>
            How to set up Google Credentials:
        </div>
        <ol class="list-decimal list-inside space-y-1 text-[var(--color-ink-muted)]">
            <li>Open the <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="text-[var(--color-brand)] underline font-medium inline-flex items-center gap-1">Google Cloud Console <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i></a>.</li>
            <li>Create an OAuth 2.0 Client ID with application type <strong>Web Application</strong>.</li>
            <li>Add the Authorized Redirect URI shown below into your Google OAuth client settings:</li>
        </ol>
        <div class="mt-2 p-2 rounded bg-[var(--color-surface)] border border-[var(--color-border-light)] font-mono text-[11px] text-[var(--color-ink-strong)] flex items-center justify-between">
            <span class="truncate">{{ $redirectUri }}</span>
            <button type="button"
                    onclick="navigator.clipboard.writeText('{{ $redirectUri }}')"
                    class="ml-2 px-2 py-1 rounded bg-[var(--color-surface-alt)] hover:bg-[var(--color-border-light)] text-[10px] font-sans font-medium text-[var(--color-ink-strong)] transition-colors cursor-pointer">
                <i class="fa-regular fa-copy"></i> Copy
            </button>
        </div>
    </div>

    <form method="POST" action="{{ route('install.google.save') }}">
        @csrf

        <div class="mb-5">
            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                Google Client ID
            </label>
            <input type="text"
                   name="client_id"
                   value="{{ old('client_id', $data['client_id'] ?? '') }}"
                   required
                   placeholder="1234567890-abcdef.apps.googleusercontent.com"
                   class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
        </div>

        <div class="mb-5">
            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                Google Client Secret
            </label>
            <input type="password"
                   name="client_secret"
                   value="{{ old('client_secret', $data['client_secret'] ?? '') }}"
                   required
                   placeholder="GOCSPX-••••••••••••••••"
                   class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
        </div>

        <div class="mb-6">
            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                Hosted Domain Restriction (Optional)
            </label>
            <input type="text"
                   name="hd"
                   value="{{ old('hd', $data['hd'] ?? '') }}"
                   placeholder="youragency.com"
                   class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
            <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">If specified, only Google Workspace users with this email domain will be permitted to log in.</p>
        </div>

        <div class="flex items-center justify-between pt-5 border-t border-[var(--color-border-light)]">
            <a href="{{ route('install.mail') }}"
               class="inline-flex items-center gap-2 text-xs font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-arrow-left text-[10px]"></i>
                <span>Back</span>
            </a>

            <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-[var(--color-brand)] text-white font-medium text-sm hover:bg-[var(--color-brand)]/90 transition-all shadow-sm cursor-pointer">
                <span>Continue</span>
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </button>
        </div>
    </form>
</div>
@endsection
