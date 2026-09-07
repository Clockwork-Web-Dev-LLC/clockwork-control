@extends('layouts.install')

@section('title', 'Administrator Account')

@section('content')
<div class="card p-6 md:p-8 shadow-sm">
    <div class="mb-6">
        <h1 class="font-display text-2xl font-bold tracking-tight text-[var(--color-ink-strong)]">
            Step 6: First Administrator Account
        </h1>
        <p class="text-sm text-[var(--color-ink-muted)] mt-1">
            Specify your primary administrator account. This operator is added to the allowlist with full access.
        </p>
    </div>

    @if ($errors->any())
        <div class="p-4 rounded-xl border border-[var(--color-status-red)]/30 bg-[var(--color-status-red-bg)] text-xs text-[var(--color-status-red)] mb-6">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('install.admin.save') }}">
        @csrf

        <div class="mb-5">
            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                Full Name
            </label>
            <input type="text"
                   name="name"
                   value="{{ old('name', $data['name'] ?? '') }}"
                   required
                   placeholder="Aaron Reimann"
                   class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
        </div>

        <div class="mb-5">
            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                Administrator Email Address
            </label>
            <input type="email"
                   name="email"
                   value="{{ old('email', $data['email'] ?? '') }}"
                   required
                   placeholder="aaron@youragency.com"
                   class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
            <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">
                @if ($isGoogleConfigured)
                    Must match the Google account you will use for Single Sign-On, or your local login email.
                @else
                    This email will be your login username on the allowlist.
                @endif
            </p>
        </div>

        <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/50 mb-6 space-y-4">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-strong)] flex items-center gap-1.5">
                    <i class="fa-solid fa-key text-[var(--color-brand)]"></i>
                    Local Password
                </span>
                @if ($isGoogleConfigured)
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-[var(--color-surface)] text-[var(--color-ink-muted)] border border-[var(--color-border)]">
                        Optional (Recommended Fallback)
                    </span>
                @else
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-[var(--color-brand)]/10 text-[var(--color-brand)] font-semibold">
                        Required
                    </span>
                @endif
            </div>

            <p class="text-xs text-[var(--color-ink-muted)]">
                @if ($isGoogleConfigured)
                    You configured Google OAuth, but setting a local password gives you an emergency fail-safe if Google ever experiences downtime or configuration errors.
                @else
                    Set a secure password (minimum 8 characters) to sign in to Clockwork Control at <code>/login</code>.
                @endif
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[11px] font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1">
                        Password
                    </label>
                    <input type="password"
                           name="password"
                           {{ $isGoogleConfigured ? '' : 'required' }}
                           autocomplete="new-password"
                           placeholder="••••••••••••"
                           class="w-full px-3 py-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1">
                        Confirm Password
                    </label>
                    <input type="password"
                           name="password_confirmation"
                           {{ $isGoogleConfigured ? '' : 'required' }}
                           autocomplete="new-password"
                           placeholder="••••••••••••"
                           class="w-full px-3 py-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between pt-5 border-t border-[var(--color-border-light)]">
            <a href="{{ route('install.google') }}"
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
