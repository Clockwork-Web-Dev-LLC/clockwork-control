@extends('layouts.install')

@section('title', 'Installation Error')

@section('content')
<div class="card p-8 md:p-12 shadow-md text-center max-w-xl mx-auto">
    <div class="w-14 h-14 rounded-full bg-[var(--color-status-red-bg)] text-[var(--color-status-red)] inline-flex items-center justify-center text-2xl mb-4">
        <i class="fa-solid fa-triangle-exclamation"></i>
    </div>

    <h1 class="font-display text-2xl font-bold tracking-tight text-[var(--color-ink-strong)]">
        Installation Issue Encountered
    </h1>

    <div class="my-6 p-4 rounded-xl border border-[var(--color-status-red)]/30 bg-[var(--color-status-red-bg)] text-xs text-[var(--color-status-red)] text-left font-mono">
        {{ $message ?? 'An unexpected error occurred during installation.' }}
    </div>

    <div class="flex items-center justify-center gap-3">
        <a href="{{ route('install.welcome') }}"
           class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full border border-[var(--color-border)] bg-[var(--color-surface-alt)] text-xs font-semibold text-[var(--color-ink-strong)] hover:bg-[var(--color-border-light)] transition-all">
            <i class="fa-solid fa-rotate-left"></i>
            <span>Restart Wizard</span>
        </a>

        <button type="button"
                onclick="window.history.back()"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-[var(--color-brand)] text-white text-xs font-semibold hover:bg-[var(--color-brand)]/90 transition-all">
            <i class="fa-solid fa-arrow-left"></i>
            <span>Return to Previous Step</span>
        </button>
    </div>
</div>
@endsection
