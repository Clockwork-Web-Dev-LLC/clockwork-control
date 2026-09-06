@php
    $kindPill = function (string $kind): string {
        return match ($kind) {
            'approved'  => '<span class="status-pill status-red text-xs"><i class="fa-solid fa-ban"></i> Banned</span>',
            'failed'    => '<span class="status-pill status-yellow text-xs"><i class="fa-solid fa-triangle-exclamation"></i> Failed</span>',
            'dismissed' => '<span class="status-pill status-unknown text-xs"><i class="fa-solid fa-xmark"></i> Dismissed</span>',
            'unbanned'  => '<span class="status-pill status-green text-xs"><i class="fa-solid fa-rotate-left"></i> Unbanned</span>',
            default     => '<span class="status-pill status-unknown text-xs">' . e($kind) . '</span>',
        };
    };
@endphp

<div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">History</h2>
            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                Last 50 decisions + unbans across the fleet, newest first.
            </p>
        </div>
        @if ($ipFilter !== '')
            <div class="text-xs">
                Filtered to <code class="bg-[var(--color-surface-alt)] px-1.5 py-0.5 rounded">{{ $ipFilter }}</code>
                <a href="{{ route('bans.history') }}" class="ml-2 text-[var(--color-primary-600)] hover:underline">Clear</a>
            </div>
        @endif
    </div>

    @if ($rows->isEmpty())
        <div class="p-10 text-center text-[var(--color-ink-soft)]">
            <i class="fa-solid fa-clock-rotate-left text-3xl mb-2"></i>
            <div>No history yet.</div>
        </div>
    @else
        <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'when', defaultDir: 'desc' })">
            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                <tr>
                    <x-sort-th key="when"   class="px-5 py-3">When</x-sort-th>
                    <x-sort-th key="kind"   class="px-5 py-3">Action</x-sort-th>
                    <x-sort-th key="ip"     class="px-5 py-3">IP</x-sort-th>
                    <x-sort-th key="server" class="px-5 py-3">Server</x-sort-th>
                    <x-sort-th key="site"   class="px-5 py-3">Site</x-sort-th>
                    <x-sort-th key="actor"  class="px-5 py-3">By</x-sort-th>
                    <th class="px-5 py-3">Reason</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]">
                @foreach ($rows as $row)
                    <tr
                        data-sort-when="{{ $row->when()?->getTimestamp() ?? '' }}"
                        data-sort-kind="{{ $row->kind() }}"
                        data-sort-ip="{{ $row->ip() }}"
                        data-sort-server="{{ $row->server()?->name ?? '' }}"
                        data-sort-site="{{ $row->site()?->domain ?? '' }}"
                        data-sort-actor="{{ $row->actor() }}">
                        <td class="px-5 py-3 text-xs text-[var(--color-ink-muted)] whitespace-nowrap" title="{{ $row->when()?->toDayDateTimeString() }}">
                            {{ $row->when()?->diffForHumans() ?? '—' }}
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap">{!! $kindPill($row->kind()) !!}</td>
                        <td class="px-5 py-3">
                            <a href="{{ route('bans.history', ['ip' => $row->ip()]) }}"
                               class="text-[var(--color-ink-strong)] hover:text-[var(--color-primary-600)]"
                               title="Filter history to this IP">
                                <x-ip-link :ip="$row->ip()" />
                            </a>
                        </td>
                        <td class="px-5 py-3 text-xs">
                            @if ($row->server())
                                <a href="{{ route('servers.show', $row->server()) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $row->server()->name }}</a>
                            @else
                                <span class="text-[var(--color-ink-soft)]">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-xs font-data">
                            @if ($row->site())
                                <a href="{{ route('sites.show', $row->site()) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $row->site()->domain }}</a>
                            @else
                                <span class="text-[var(--color-ink-soft)]">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-xs text-[var(--color-ink-muted)] whitespace-nowrap">{{ $row->actor() }}</td>
                        <td class="px-5 py-3 text-xs text-[var(--color-ink-muted)] truncate max-w-md" title="{{ $row->note() }}">
                            {{ \Illuminate\Support\Str::limit($row->note(), 80) ?: '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
