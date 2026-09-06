@extends('layouts.app')

@section('title', 'Bans · Clockwork')

@section('content')
    <x-page-header title="Bans"
        subtitle="Triage the review queue, inspect currently banned IP addresses, and audit recent firewall decisions." />

    {{-- Stats strip — consistent across tabs. The auto-approve toggle from the
         queue page is hoisted up here so it's reachable from any tab. --}}
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4">
        <a href="{{ route('bans.queue') }}" class="card px-4 py-3 hover:bg-[var(--color-surface-alt)] transition-colors">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Bans pending</div>
            <div class="text-2xl font-display font-data {{ ($reviewQueueCount ?? 0) > 0 ? 'text-[var(--color-primary-600)]' : 'text-[var(--color-ink-strong)]' }}">
                {{ number_format($reviewQueueCount ?? 0) }}
            </div>
        </a>
        <a href="{{ route('bans.active') }}" class="card px-4 py-3 hover:bg-[var(--color-surface-alt)] transition-colors">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Active bans</div>
            <div class="text-2xl font-display font-data text-[var(--color-ink-strong)]">{{ number_format($activeBansCount ?? 0) }}</div>
        </a>
        <div class="card px-4 py-3 flex items-center justify-between gap-3">
            <div>
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Auto-approve repeats</div>
                <div class="text-sm mt-1">
                    @if ($autoApproveEnabled ?? false)
                        <span class="status-pill status-green text-xs"><span class="status-dot"></span> On</span>
                        @if (! empty($autoApprovedRecently))
                            <span class="text-xs text-[var(--color-ink-soft)] ml-1 font-data">{{ $autoApprovedRecently }} in last 24h</span>
                        @endif
                    @else
                        <span class="status-pill status-unknown text-xs"><span class="status-dot"></span> Manual</span>
                    @endif
                </div>
            </div>
            @isset($autoApproveEnabled)
                <form method="POST" action="{{ route('review-queue.toggleAutoApprove') }}">
                    @csrf
                    <button type="submit" class="btn-pill-nav text-xs"
                            onclick="return confirm('{{ $autoApproveEnabled ? 'Switch back to manual review for repeat offenders?' : 'Auto-approve repeat offenders? Any IP with 2+ lockouts (same site twice OR multiple servers) will be banned automatically — including pending entries that already qualify.' }}')">
                        <i class="fa-solid {{ $autoApproveEnabled ? 'fa-toggle-on' : 'fa-toggle-off' }}"></i>
                        {{ $autoApproveEnabled ? 'Disable' : 'Enable' }}
                    </button>
                </form>
            @endisset
        </div>
    </div>

    {{-- Shared Security tab strip — Scans / Queue / Active / History. --}}
    @include('security._tabs')

    {{-- Status banners (shared across tabs — both queue and IPs flash to these). --}}
    @if (session('queue_status') || session('ban_status'))
        <div class="card p-3 mb-4 status-green text-sm flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            {{ session('queue_status') ?? session('ban_status') }}
        </div>
    @endif
    @if (session('queue_error') || session('ban_error'))
        <div class="card p-3 mb-4 status-red text-sm flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i>
            {{ session('queue_error') ?? session('ban_error') }}
        </div>
    @endif

    @include($tabPartial)
@endsection
