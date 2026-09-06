<div class="card overflow-hidden" id="bans-active-card">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between gap-4 flex-wrap">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Active</h2>
        <form method="GET" action="{{ route('bans.active') }}" id="bans-search-form" class="flex items-center gap-2 flex-1 max-w-md">
            <div class="relative flex-1">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[var(--color-ink-soft)]"></i>
                <input type="search" name="q" id="bans-search" value="{{ $q }}" placeholder="Search IP, server, or reason"
                    autocomplete="off"
                    class="w-full pl-8 pr-8 py-2 text-sm rounded-md border border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)]">
                <button type="button" id="bans-search-clear" class="{{ $q !== '' ? '' : 'hidden' }} absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] w-5 h-5 flex items-center justify-center text-xs" aria-label="Clear search">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            @if ($q !== '')
                <a href="{{ route('bans.active') }}" id="bans-clear-fallback" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">Clear</a>
            @endif
        </form>
        <span class="text-sm text-[var(--color-ink-soft)]" id="bans-total-count">{{ number_format($active->total()) }} total</span>
    </div>

    <div id="bans-no-match" class="p-10 text-center text-[var(--color-ink-soft)] {{ $active->isEmpty() ? '' : 'hidden' }}">
        <i class="fa-solid fa-shield-halved text-3xl mb-2"></i>
        @if ($q !== '')
            <div>No banned IPs match "{{ $q }}".</div>
        @else
            <div>No IPs are currently banned.</div>
        @endif
    </div>

    @if ($active->isNotEmpty())
        <table class="w-full text-sm" id="bans-table" x-data="sortableTable({ defaultKey: 'banned', defaultDir: 'desc' })">
            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                <tr>
                    <x-sort-th key="ip"      class="px-5 py-3">IP</x-sort-th>
                    <x-sort-th key="server"  class="px-5 py-3">Server</x-sort-th>
                    <x-sort-th key="source"  class="px-5 py-3">Source</x-sort-th>
                    <x-sort-th key="verdict" class="px-5 py-3">Verdict</x-sort-th>
                    <x-sort-th key="reason"  class="px-5 py-3">Reason</x-sort-th>
                    <x-sort-th key="banned"  class="px-5 py-3">Banned</x-sort-th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]" id="bans-tbody">
                @foreach ($active as $ban)
                    <tr class="ban-row"
                        data-search="{{ strtolower($ban->ip . ' ' . ($ban->server->display_name ?? $ban->server->name ?? '') . ' ' . $ban->reason . ' ' . $ban->source . ' ' . ($ban->llm_verdict ?? '')) }}"
                        data-sort-ip="{{ $ban->ip }}"
                        data-sort-server="{{ $ban->server?->name ?? '' }}"
                        data-sort-source="{{ $ban->source }}"
                        data-sort-verdict="{{ $ban->llm_verdict ?? '' }}"
                        data-sort-reason="{{ $ban->reason }}"
                        data-sort-banned="{{ $ban->banned_at?->getTimestamp() ?? '' }}">
                        <td class="px-5 py-3 text-[var(--color-ink-strong)]"><x-ip-link :ip="$ban->ip" /></td>
                        <td class="px-5 py-3">
                            @if ($ban->server)
                                <a href="{{ route('servers.show', $ban->server) }}" class="text-[var(--color-primary-600)] hover:underline" title="{{ $ban->server->name }}">{{ $ban->server->display_name }}</a>
                            @else
                                <span class="text-[var(--color-ink-soft)]">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-xs text-[var(--color-ink-muted)]">{{ $ban->source }}</td>
                        <td class="px-5 py-3 text-xs text-[var(--color-ink-muted)]">{{ $ban->llm_verdict ?? '—' }}</td>
                        <td class="px-5 py-3 text-xs text-[var(--color-ink-muted)] truncate max-w-md">{{ $ban->reason }}</td>
                        <td class="px-5 py-3 text-xs text-[var(--color-ink-muted)]">{{ $ban->banned_at?->diffForHumans() }}</td>
                        <td class="px-5 py-3 text-right">
                            <form method="POST" action="{{ route('blocked-ips.unban', $ban) }}" class="inline">
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
        @if ($active->hasPages())
            <div class="px-5 py-3 border-t border-[var(--color-border-light)]" id="bans-pagination">
                {{ $active->links() }}
            </div>
        @endif
    @endif
</div>

<p class="mt-4 text-xs text-[var(--color-ink-soft)]">
    Looking for the recently-unbanned audit trail? It's now under <a href="{{ route('bans.history') }}" class="text-[var(--color-primary-600)] hover:underline">History</a>, merged with queue decisions.
</p>

<script>
    (function () {
        const input = document.getElementById('bans-search');
        const clearBtn = document.getElementById('bans-search-clear');
        const form = document.getElementById('bans-search-form');
        const card = document.getElementById('bans-active-card');
        let abortCtrl = null;
        let debounceTimer = null;

        function applyLocalFilter(q) {
            const query = (q || '').trim().toLowerCase();
            if (clearBtn) {
                clearBtn.classList.toggle('hidden', query.length === 0);
            }

            const rows = card ? card.querySelectorAll('.ban-row') : [];
            let visibleCount = 0;

            rows.forEach(row => {
                const searchData = row.dataset.search || '';
                const matched = query.length === 0 || searchData.includes(query);
                row.style.display = matched ? '' : 'none';
                if (matched) visibleCount++;
            });

            const noMatch = document.getElementById('bans-no-match');
            const table = document.getElementById('bans-table');
            if (noMatch) {
                noMatch.classList.toggle('hidden', visibleCount > 0 || (rows.length === 0 && !query));
            }
            if (table) {
                table.style.display = visibleCount > 0 ? '' : 'none';
            }
        }

        function fetchServerResults(q) {
            if (abortCtrl) {
                abortCtrl.abort();
            }
            abortCtrl = new AbortController();

            const url = new URL(form.action, window.location.origin);
            const trimmed = (q || '').trim();
            if (trimmed) {
                url.searchParams.set('q', trimmed);
            } else {
                url.searchParams.delete('q');
            }
            url.searchParams.delete('page');

            window.history.replaceState(null, '', url.toString());

            fetch(url.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: abortCtrl.signal
            })
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newCard = doc.getElementById('bans-active-card');

                if (newCard && card) {
                    card.innerHTML = newCard.innerHTML;
                }

                // Re-bind listener for clear button if card was replaced
                const newClearBtn = document.getElementById('bans-search-clear');
                if (newClearBtn) {
                    newClearBtn.addEventListener('click', handleClear);
                }

                applyLocalFilter(input ? input.value : '');
            })
            .catch(err => {
                if (err.name !== 'AbortError') {
                    console.error('Error fetching bans:', err);
                }
            });
        }

        function handleClear() {
            if (input) {
                input.value = '';
                applyLocalFilter('');
                clearTimeout(debounceTimer);
                fetchServerResults('');
                input.focus();
            }
        }

        if (input) {
            input.addEventListener('input', e => {
                const val = e.target.value;
                applyLocalFilter(val);

                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    fetchServerResults(val);
                }, 250);
            });

            input.addEventListener('keydown', e => {
                if (e.key === 'Escape') {
                    handleClear();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(debounceTimer);
                    fetchServerResults(input.value);
                }
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', handleClear);
        }

        if (form) {
            form.addEventListener('submit', e => {
                e.preventDefault();
                clearTimeout(debounceTimer);
                fetchServerResults(input ? input.value : '');
            });
        }

        // "/" shortcut
        document.addEventListener('keydown', e => {
            if (e.key === '/' && input && document.activeElement !== input
                    && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
                e.preventDefault();
                input.focus();
                input.select();
            }
        });
    })();
</script>
