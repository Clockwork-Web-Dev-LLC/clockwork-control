export function sitesPage() {
    return {
        view: (typeof localStorage !== 'undefined' ? localStorage.getItem('clockwork_sites_view') : null) || 'list',
        setView(v: string) {
            this.view = v;
            try {
                localStorage.setItem('clockwork_sites_view', v);
            } catch (_) {}
        },
        showAddModal: false,
        addMode: 'key',
        addKey: '',
        addDomain: '',
        addSecret: '',
        addSubmitting: false,

        parseAddKey() {
            const raw = this.addKey.trim();
            if (!raw) return;
            if (raw.startsWith('{')) {
                try {
                    const parsed = JSON.parse(raw);
                    if (parsed.url || parsed.domain) {
                        this.addDomain = (parsed.url || parsed.domain)
                            .replace(/^https?:\/\//i, '')
                            .replace(/\/.*$/, '');
                    }
                    if (parsed.secret) this.addSecret = parsed.secret;
                    return;
                } catch (_) {}
            }
            const b64 = raw.replace(/^cw_/, '');
            try {
                const decoded = atob(b64);
                const parsed = JSON.parse(decoded);
                if (parsed.url || parsed.domain) {
                    this.addDomain = (parsed.url || parsed.domain).replace(/^https?:\/\//i, '').replace(/\/.*$/, '');
                }
                if (parsed.secret) this.addSecret = parsed.secret;
                return;
            } catch (_) {}
            if (raw.length === 64 && /^[0-9a-fA-F]+$/.test(raw)) {
                this.addSecret = raw;
            }
        },

        generateSecret() {
            const array = new Uint8Array(32);
            window.crypto.getRandomValues(array);
            this.addSecret = Array.from(array, (b) => b.toString(16).padStart(2, '0')).join('');
        },

        init() {
            this.initSearch();
        },

        initSearch() {
            const input = document.getElementById('sites-search') as HTMLInputElement | null;
            const clearBtn = document.getElementById('sites-search-clear') as HTMLElement | null;
            const form = document.getElementById('sites-search-form') as HTMLFormElement | null;
            const listCard = document.getElementById('sites-list-card');
            const gridContainer = document.getElementById('sites-grid-container');
            const paginationWrap = document.getElementById('sites-pagination');
            let abortCtrl: AbortController | null = null;
            let debounceTimer: ReturnType<typeof setTimeout> | null = null;

            function applyLocalFilter(q: string) {
                const query = (q || '').trim().toLowerCase();
                if (clearBtn) {
                    clearBtn.classList.toggle('hidden', query.length === 0);
                }

                const rows = listCard ? listCard.querySelectorAll('.site-row') : [];
                const cards = gridContainer ? gridContainer.querySelectorAll('.site-card') : [];
                let visibleRows = 0;
                let visibleCards = 0;

                rows.forEach((r) => {
                    const row = r as HTMLElement;
                    const searchData = row.dataset.search || '';
                    const matched = query.length === 0 || searchData.includes(query);
                    row.style.display = matched ? '' : 'none';
                    if (matched) visibleRows++;
                });

                cards.forEach((c) => {
                    const card = c as HTMLElement;
                    const searchData = card.dataset.search || '';
                    const matched = query.length === 0 || searchData.includes(query);
                    card.style.display = matched ? '' : 'none';
                    if (matched) visibleCards++;
                });

                const noMatch = document.getElementById('sites-no-match');
                if (noMatch) {
                    const hasItems = rows.length > 0 || cards.length > 0;
                    const anyVisible = visibleRows > 0 || visibleCards > 0;
                    noMatch.classList.toggle('hidden', anyVisible || (!hasItems && !query));
                }
            }

            function fetchServerResults(q: string) {
                if (abortCtrl) {
                    abortCtrl.abort();
                }
                abortCtrl = new AbortController();

                if (!form) return;
                const url = new URL(form.action, window.location.origin);
                const providerInput = document.getElementById('sites-provider-filter') as HTMLInputElement | null;
                if (providerInput?.value && providerInput.value !== 'all') {
                    url.searchParams.set('provider', providerInput.value);
                }
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
                    signal: abortCtrl.signal,
                })
                    .then((res) => res.text())
                    .then((html) => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const newCard = doc.getElementById('sites-list-card');
                        const newGrid = doc.getElementById('sites-grid-container');
                        const newPagination = doc.getElementById('sites-pagination');

                        if (newCard && listCard) {
                            listCard.innerHTML = newCard.innerHTML;
                        }
                        if (newGrid && gridContainer) {
                            gridContainer.innerHTML = newGrid.innerHTML;
                        }
                        if (newPagination && paginationWrap) {
                            paginationWrap.innerHTML = newPagination.innerHTML;
                        }

                        if (input) {
                            applyLocalFilter(input.value);
                        }
                    })
                    .catch((err) => {
                        if (err.name !== 'AbortError') {
                            console.error('Error fetching sites:', err);
                        }
                    });
            }

            if (input) {
                input.addEventListener('input', (e) => {
                    const val = (e.target as HTMLInputElement).value;
                    applyLocalFilter(val);

                    if (debounceTimer) clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => {
                        fetchServerResults(val);
                    }, 250);
                });

                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        input.value = '';
                        applyLocalFilter('');
                        if (debounceTimer) clearTimeout(debounceTimer);
                        fetchServerResults('');
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        const firstSite = document.querySelector(
                            '.site-row:not([style*="display: none"]) a, .site-card:not([style*="display: none"]) a',
                        ) as HTMLElement | null;
                        if (firstSite) {
                            firstSite.click();
                        } else {
                            if (debounceTimer) clearTimeout(debounceTimer);
                            fetchServerResults(input.value);
                        }
                    }
                });
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', () => {
                    if (input) {
                        input.value = '';
                        applyLocalFilter('');
                        if (debounceTimer) clearTimeout(debounceTimer);
                        fetchServerResults('');
                        input.focus();
                    }
                });
            }

            if (form) {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    if (debounceTimer) clearTimeout(debounceTimer);
                    fetchServerResults(input ? input.value : '');
                });
            }

            // "/" focuses search from anywhere on the page
            document.addEventListener('keydown', (e) => {
                if (
                    e.key === '/' &&
                    input &&
                    document.activeElement !== input &&
                    !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName || '')
                ) {
                    e.preventDefault();
                    input.focus();
                    input.select();
                }
            });
        },
    };
}
