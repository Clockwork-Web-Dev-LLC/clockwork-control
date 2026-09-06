<div class="card p-5 mb-10">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
        <div>
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-ban text-[var(--color-ink-soft)] mr-1"></i>
                Banned IPs
            </h2>
            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                Active fail2ban entries attributed to <span class="font-data">{{ $site->domain }}</span>.
            </p>
        </div>
        @if ($bannedIps->isNotEmpty())
            <form method="POST" action="{{ route('sites.bans.unban-all', $site) }}"
                  onsubmit="return confirm('Unban {{ $bannedIps->total() }} IP{{ $bannedIps->total() === 1 ? '' : 's' }} for {{ $site->domain }}? This removes them from fail2ban on the server.');">
                @csrf
                <button type="submit" class="text-xs px-3 py-1.5 rounded-full border border-[var(--color-border-light)] text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]">
                    <i class="fa-solid fa-rotate-left"></i>
                    Unban all ({{ $bannedIps->total() }})
                </button>
            </form>
        @endif
    </div>

    @if ($bannedIps->isEmpty())
        <div class="text-center py-6 text-sm text-[var(--color-ink-soft)]">
            <i class="fa-solid fa-shield-halved text-2xl mb-2 block"></i>
            No active bans for this site.
        </div>
    @else
        <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'banned', defaultDir: 'desc' })">
            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                <tr>
                    <x-sort-th key="ip"     class="px-4 py-2">IP</x-sort-th>
                    <x-sort-th key="source" class="px-4 py-2">Source</x-sort-th>
                    <x-sort-th key="reason" class="px-4 py-2">Reason</x-sort-th>
                    <x-sort-th key="banned" class="px-4 py-2">Banned</x-sort-th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]">
                @foreach ($bannedIps as $ban)
                    <tr
                        data-sort-ip="{{ $ban->ip }}"
                        data-sort-source="{{ $ban->source }}"
                        data-sort-reason="{{ $ban->reason }}"
                        data-sort-banned="{{ $ban->banned_at?->getTimestamp() ?? '' }}">
                        <td class="px-4 py-2 text-[var(--color-ink-strong)]"><x-ip-link :ip="$ban->ip" /></td>
                        <td class="px-4 py-2 text-xs text-[var(--color-ink-muted)]">{{ $ban->source }}</td>
                        <td class="px-4 py-2 text-xs text-[var(--color-ink-muted)] truncate max-w-md">{{ $ban->reason }}</td>
                        <td class="px-4 py-2 text-xs text-[var(--color-ink-muted)]">{{ $ban->banned_at?->diffForHumans() }}</td>
                        <td class="px-4 py-2 text-right">
                            <form method="POST" action="{{ route('sites.bans.unban', [$site, $ban]) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-status-green)]">
                                    <i class="fa-solid fa-rotate-left"></i> Unban
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Page footer: "Showing X–Y of Z" + Laravel's paginator. Hidden
             when there's only one page (paginator returns empty markup
             when total ≤ perPage). The Alpine sortable above sorts only
             within the current page; that's fine at 50/page. --}}
        @if ($bannedIps->hasPages())
            <div class="mt-4 flex items-center justify-between text-xs text-[var(--color-ink-muted)] flex-wrap gap-3">
                <span>
                    Showing <span class="font-data text-[var(--color-ink-strong)]">{{ $bannedIps->firstItem() }}</span>–<span class="font-data text-[var(--color-ink-strong)]">{{ $bannedIps->lastItem() }}</span>
                    of <span class="font-data text-[var(--color-ink-strong)]">{{ $bannedIps->total() }}</span>
                </span>
                {{ $bannedIps->onEachSide(1)->links() }}
            </div>
        @else
            <div class="mt-3 text-xs text-[var(--color-ink-soft)]">
                Showing all {{ $bannedIps->total() }} active ban{{ $bannedIps->total() === 1 ? '' : 's' }}.
            </div>
        @endif
    @endif
</div>
