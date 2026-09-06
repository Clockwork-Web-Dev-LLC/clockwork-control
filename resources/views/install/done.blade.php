@extends('layouts.install')

@section('title', 'Installation Complete')

@section('content')
<div class="card p-8 md:p-12 shadow-md relative overflow-hidden text-center max-w-2xl mx-auto">
    <!-- Confetti Elements -->
    <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
        <span class="confetti-piece bg-[var(--color-brand)] left-[10%] [animation-delay:0s]"></span>
        <span class="confetti-piece bg-[var(--color-brand-sky)] left-[25%] [animation-delay:0.7s]"></span>
        <span class="confetti-piece bg-[var(--color-brand-pink)] left-[40%] [animation-delay:0.3s]"></span>
        <span class="confetti-piece bg-[var(--color-status-green)] left-[55%] [animation-delay:1.1s]"></span>
        <span class="confetti-piece bg-[var(--color-status-yellow)] left-[70%] [animation-delay:0.5s]"></span>
        <span class="confetti-piece bg-[var(--color-brand)] left-[85%] [animation-delay:0.9s]"></span>
        <span class="confetti-piece bg-[var(--color-brand-pink)] left-[95%] [animation-delay:1.4s]"></span>
    </div>

    <div class="relative z-10">
        <div class="w-16 h-16 rounded-full bg-[var(--color-status-green-bg)] text-[var(--color-status-green)] inline-flex items-center justify-center text-3xl mb-4 shadow-sm">
            <i class="fa-solid fa-circle-check"></i>
        </div>

        <h1 class="font-display text-2xl md:text-3xl font-bold tracking-tight text-[var(--color-ink-strong)]">
            Clockwork Control is Installed!
        </h1>
        <p class="text-sm text-[var(--color-ink-muted)] mt-2 max-w-lg mx-auto">
            Your database has been migrated, the environment configured, and your administrator account provisioned.
        </p>

        <!-- Important Post-Install Notes -->
        <div class="my-6 p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-left text-xs text-[var(--color-ink-muted)] space-y-2">
            <div class="font-semibold text-[var(--color-ink-strong)] flex items-center gap-1.5">
                <i class="fa-solid fa-lightbulb text-[var(--color-status-yellow)]"></i>
                Production Deployment Notice
            </div>
            <p>
                If your production deployment uses cached configurations, run the following artisan command or restart your PHP-FPM service:
            </p>
            <div class="p-2 rounded bg-[var(--color-surface)] border border-[var(--color-border-light)] font-mono text-[11px] text-[var(--color-ink-strong)] select-all">
                php artisan config:clear
            </div>
            <p class="text-[11px] text-[var(--color-ink-soft)]">
                The installer route (<code class="font-mono text-[10px]">/install</code>) has been permanently sealed. If you ever need to re-open it in disaster recovery, run <code class="font-mono text-[10px]">php artisan clockwork:installer:reopen</code>.
            </p>
        </div>

        <div>
            <a href="{{ route('login') }}"
               class="inline-flex items-center gap-2 px-8 py-3 rounded-full bg-[var(--color-brand)] text-white font-semibold text-sm hover:bg-[var(--color-brand)]/90 transition-all shadow-md cursor-pointer">
                <span>Go to Sign In</span>
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </a>
        </div>
    </div>
</div>
@endsection
