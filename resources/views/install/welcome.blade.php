@extends('layouts.install')

@section('title', 'Welcome & Requirements')

@section('content')
<div class="card p-6 md:p-8 shadow-sm">
    <div class="text-center max-w-xl mx-auto mb-8">
        <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-[var(--color-brand)]/10 text-[var(--color-brand)] text-2xl mb-4">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
        </span>
        <h1 class="font-display text-2xl md:text-3xl font-bold tracking-tight text-[var(--color-ink-strong)]">
            Welcome to Clockwork Control
        </h1>
        <p class="text-sm text-[var(--color-ink-muted)] mt-2">
            This wizard will guide you through connecting your database, configuring application identity, setting up Google OAuth, and provisioning your first administrator account.
        </p>
    </div>

    <div class="border-t border-[var(--color-border-light)] pt-6 mb-6">
        <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-4 flex items-center gap-2">
            <i class="fa-solid fa-clipboard-check text-[var(--color-brand)]"></i>
            Pre-flight Environment Check
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- PHP Version -->
            <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/50 flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium text-[var(--color-ink-strong)]">PHP Version</div>
                    <div class="text-xs text-[var(--color-ink-muted)]">Requires 8.2 or newer (Detected: {{ $phpVersion }})</div>
                </div>
                <div>
                    @if ($phpOk)
                        <span class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-full bg-[var(--color-status-green-bg)] text-[var(--color-status-green)]">
                            <i class="fa-solid fa-check"></i> Passed
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-full bg-[var(--color-status-red-bg)] text-[var(--color-status-red)]">
                            <i class="fa-solid fa-xmark"></i> Failed
                        </span>
                    @endif
                </div>
            </div>

            <!-- Required Extensions -->
            <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/50">
                <div class="text-sm font-medium text-[var(--color-ink-strong)] mb-2">Required Extensions</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($extensions as $ext => $ok)
                        <span class="inline-flex items-center gap-1 text-[11px] font-mono px-2 py-0.5 rounded-full {{ $ok ? 'bg-[var(--color-status-green-bg)] text-[var(--color-status-green)]' : 'bg-[var(--color-status-red-bg)] text-[var(--color-status-red)]' }}">
                            <i class="fa-solid {{ $ok ? 'fa-check' : 'fa-xmark' }} text-[9px]"></i> {{ $ext }}
                        </span>
                    @endforeach
                </div>
            </div>

            <!-- Directory Permissions -->
            <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/50 md:col-span-2">
                <div class="text-sm font-medium text-[var(--color-ink-strong)] mb-2">Writable Directories</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @foreach ($permissions as $path => $ok)
                        <div class="flex items-center justify-between text-xs p-2 rounded bg-[var(--color-surface)] border border-[var(--color-border-light)]">
                            <span class="font-mono text-[var(--color-ink-muted)]">{{ $path }}</span>
                            @if ($ok)
                                <span class="text-[var(--color-status-green)] font-semibold flex items-center gap-1">
                                    <i class="fa-solid fa-check"></i> Writable
                                </span>
                            @else
                                <span class="text-[var(--color-status-red)] font-semibold flex items-center gap-1">
                                    <i class="fa-solid fa-xmark"></i> Not writable
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    @if (! $canProceed)
        <div class="p-4 rounded-xl border border-[var(--color-status-red)]/30 bg-[var(--color-status-red-bg)] text-xs text-[var(--color-status-red)] mb-6">
            <i class="fa-solid fa-triangle-exclamation mr-1.5"></i>
            Please resolve the missing requirements or directory permissions above before continuing with the installation.
        </div>
    @endif

    <div class="flex items-center justify-end pt-4 border-t border-[var(--color-border-light)]">
        <a href="{{ route('install.database') }}"
           class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-[var(--color-brand)] text-white font-medium text-sm hover:bg-[var(--color-brand)]/90 transition-all shadow-sm {{ ! $canProceed ? 'opacity-50 pointer-events-none' : '' }}">
            <span>Get Started</span>
            <i class="fa-solid fa-arrow-right text-xs"></i>
        </a>
    </div>
</div>
@endsection
