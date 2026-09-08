@php
    $domainExpiresAt = $site->domain_expires_at;
    $daysRemaining = $domainExpiresAt ? (int) now()->diffInDays($domainExpiresAt, false) : null;
    $isIndexable = $site->seo_indexable;
@endphp

<div class="card p-5 flex flex-col justify-between h-full">
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass-chart text-indigo-600"></i>
                SEO &amp; Domain
            </h3>
            @if ($isIndexable === false)
                <span class="status-pill status-red text-[10px]">
                    <span class="status-dot"></span> Blocking Search
                </span>
            @elseif ($isIndexable === true)
                <span class="status-pill status-green text-[10px]">
                    <span class="status-dot"></span> Indexable
                </span>
            @else
                <span class="status-pill status-unknown text-[10px]">Unknown</span>
            @endif
        </div>

        <div class="space-y-2.5 py-1">
            {{-- Domain Registration --}}
            <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs">
                <div class="flex items-center justify-between">
                    <span class="text-[var(--color-ink-muted)] flex items-center gap-1.5">
                        <i class="fa-solid fa-globe text-gray-500"></i> Domain Registration
                    </span>
                    @if ($domainExpiresAt)
                        @if ($daysRemaining !== null && $daysRemaining < 30)
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-100 text-rose-800">
                                Expires in {{ $daysRemaining }}d
                            </span>
                        @else
                            <span class="text-[10px] text-emerald-700 font-medium">
                                {{ $daysRemaining }} days remaining
                            </span>
                        @endif
                    @else
                        <span class="text-[10px] text-[var(--color-ink-muted)]">Not tracked</span>
                    @endif
                </div>
                @if ($domainExpiresAt)
                    <div class="text-[10px] text-[var(--color-ink-muted)] mt-1">
                        Expires {{ $domainExpiresAt->format('M j, Y') }}
                        @if ($site->domain_registrar)
                            · <span class="truncate">{{ $site->domain_registrar }}</span>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Robots & Indexability --}}
            <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs">
                <div class="flex items-center justify-between">
                    <span class="text-[var(--color-ink-muted)] flex items-center gap-1.5">
                        <i class="fa-solid fa-robot text-gray-500"></i> Search Engine Access
                    </span>
                    @if ($isIndexable === true)
                        <span class="text-[10px] text-emerald-700 font-semibold flex items-center gap-1">
                            <i class="fa-solid fa-check text-[9px]"></i> Public (Allowed)
                        </span>
                    @elseif ($isIndexable === false)
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-100 text-rose-800">
                            Disallowed (noindex)
                        </span>
                    @else
                        <span class="text-[10px] text-[var(--color-ink-muted)]">Awaiting probe</span>
                    @endif
                </div>
                @if ($site->seo_blocked_reason)
                    <div class="text-[10px] text-rose-700 mt-1 truncate" title="{{ $site->seo_blocked_reason }}">
                        {{ $site->seo_blocked_reason }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
        <span class="text-[11px] text-[var(--color-ink-muted)]">
            @if ($site->seo_checked_at)
                Checked {{ $site->seo_checked_at->diffForHumans() }}
            @endif
        </span>
        <form method="POST" action="{{ route('sites.seo.preflight', $site) }}" class="inline">
            @csrf
            <button type="submit" class="btn-pill-nav text-xs py-1 px-2.5" title="Run instant pre-flight SEO check">
                <i class="fa-solid fa-bolt text-[10px] mr-1 text-indigo-600"></i> Pre-flight Check
            </button>
        </form>
    </div>
</div>
