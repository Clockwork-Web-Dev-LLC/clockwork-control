/**
 * Monitoring Dashboard Component
 *
 * Mounted on `/monitoring` (`resources/views/monitoring/index.blade.php`):
 *   <div id="monitoring-page-root" x-data="monitoringPage()">
 *
 * Features:
 * - WordPress-style Screen Options drawer with customizable items per page (default: 50).
 * - Client-side interactive pagination (Previous, Next, page windowing).
 * - Real-time client-side search filtering across domains, servers, and states.
 * - Integration with live uptime re-check without disrupting pagination state.
 * - Outage classification modal for marking downtime as "Not Our Fault" (SLA exempt).
 * - Legacy window globals for backwards compatibility.
 */

import Alpine from 'alpinejs';

export interface MonitoringPageState {
    screenOptionsOpen: boolean;
    perPage: number | 'all';
    perPageInput: number;
    currentPage: number;
    totalPages: number;
    totalSites: number;
    totalFilteredSites: number;
    pageStart: number;
    pageEnd: number;
    searchQuery: string;

    // Outage modal state
    modalOpen: boolean;
    siteId: number | null;
    domain: string;
    reason: string;
    notes: string;
    actionUrl: string;

    // Computed page list
    readonly pagesList: (number | '...')[];

    // Methods
    init(): void;
    setPerPage(val: number | 'all'): void;
    applyPerPage(): void;
    resetScreenOptions(): void;
    goToPage(p: number): void;
    prevPage(): void;
    nextPage(): void;
    onSearchInput(): void;
    clearSearch(): void;
    filterSearch(term: string): void;
    updateDisplay(): void;
    openModal(id: number, dom: string, r?: string, n?: string): void;
    closeModal(): void;
    initRecheckButtons(): void;
}

export function monitoringPage() {
    let initialPerPage: number | 'all' = 50;
    try {
        if (typeof localStorage !== 'undefined') {
            const saved = localStorage.getItem('cw_monitoring_per_page');
            if (saved === 'all') {
                initialPerPage = 'all';
            } else if (saved) {
                const parsed = Number.parseInt(saved, 10);
                if (!Number.isNaN(parsed) && parsed > 0) {
                    initialPerPage = Math.min(500, parsed);
                }
            }
        }
    } catch (_) {
        // Fallback to 50 if localStorage is restricted
    }

    return {
        screenOptionsOpen: false,
        perPage: initialPerPage,
        perPageInput: initialPerPage === 'all' ? 50 : initialPerPage,
        currentPage: 1,
        totalPages: 1,
        totalSites: 0,
        totalFilteredSites: 0,
        pageStart: 0,
        pageEnd: 0,
        searchQuery: '',

        modalOpen: false,
        siteId: null as number | null,
        domain: '',
        reason: 'client_dns',
        notes: '',
        actionUrl: '',

        get pagesList(): (number | '...')[] {
            const total = this.totalPages;
            const current = this.currentPage;

            if (total <= 1) return [1];
            if (total <= 7) {
                return Array.from({ length: total }, (_, i) => i + 1);
            }

            if (current <= 4) {
                return [1, 2, 3, 4, 5, '...', total];
            }

            if (current >= total - 3) {
                return [1, '...', total - 4, total - 3, total - 2, total - 1, total];
            }

            return [1, '...', current - 1, current, current + 1, '...', total];
        },

        init() {
            // Read initial search query from URL parameter if present
            try {
                const params = new URLSearchParams(window.location.search);
                const q = params.get('q');
                if (q) {
                    this.searchQuery = q;
                }
            } catch (_) {}

            // Setup global shortcuts and legacy hooks
            this.setupShortcuts();
            this.exposeLegacyGlobals();
            this.initRecheckButtons();

            // Initial DOM indexing and slice
            Alpine.nextTick(() => {
                this.updateDisplay();
            });
        },

        setPerPage(val: number | 'all') {
            this.perPage = val;
            this.perPageInput = val === 'all' ? 50 : val;
            this.currentPage = 1;

            try {
                if (typeof localStorage !== 'undefined') {
                    localStorage.setItem('cw_monitoring_per_page', String(val));
                }
            } catch (_) {}

            this.updateDisplay();
        },

        applyPerPage() {
            let val = Number.parseInt(String(this.perPageInput), 10);
            if (Number.isNaN(val) || val < 1) val = 1;
            if (val > 500) val = 500;
            this.setPerPage(val);
        },

        resetScreenOptions() {
            this.setPerPage(50);
        },

        goToPage(p: number) {
            const target = Math.max(1, Math.min(p, this.totalPages));
            if (target !== this.currentPage) {
                this.currentPage = target;
                this.updateDisplay();

                // Smooth scroll to table if page scrolled far down
                const tableCard = document.getElementById('monitoring-sites-card');
                if (tableCard && window.scrollY > tableCard.offsetTop) {
                    tableCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        },

        prevPage() {
            if (this.currentPage > 1) {
                this.goToPage(this.currentPage - 1);
            }
        },

        nextPage() {
            if (this.currentPage < this.totalPages) {
                this.goToPage(this.currentPage + 1);
            }
        },

        onSearchInput() {
            this.currentPage = 1;
            this.updateDisplay();
        },

        clearSearch() {
            this.searchQuery = '';
            this.currentPage = 1;
            this.updateDisplay();
            const input = document.getElementById('monitoring-search') as HTMLInputElement | null;
            if (input) {
                input.focus();
            }
        },

        filterSearch(term: string) {
            this.searchQuery = term;
            this.currentPage = 1;
            this.updateDisplay();
            const input = document.getElementById('monitoring-search') as HTMLInputElement | null;
            if (input) {
                input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                input.focus();
            }
        },

        updateDisplay() {
            const siteRows = Array.from(document.querySelectorAll('.site-row')) as HTMLElement[];
            const eventRows = Array.from(document.querySelectorAll('.event-row')) as HTMLElement[];
            const countSpan = document.getElementById('monitoring-sites-count');
            const noMatchRow = document.getElementById('monitoring-no-match');
            const noMatchQuery = document.getElementById('monitoring-no-match-query');
            const eventsNoMatchRow = document.getElementById('monitoring-events-no-match');

            this.totalSites = siteRows.length;
            const q = (this.searchQuery || '').trim().toLowerCase();
            const isQuery = q.length > 0;

            // Filter matching sites
            const matchingRows: HTMLElement[] = [];
            for (const row of siteRows) {
                const text = (row.dataset.search || '').toLowerCase();
                const matched = !isQuery || text.includes(q);
                if (matched) {
                    matchingRows.push(row);
                }
            }
            this.totalFilteredSites = matchingRows.length;

            // Calculate pagination
            if (this.perPage === 'all' || this.perPage <= 0) {
                this.totalPages = 1;
                this.currentPage = 1;
            } else {
                this.totalPages = Math.max(1, Math.ceil(this.totalFilteredSites / this.perPage));
                if (this.currentPage > this.totalPages) {
                    this.currentPage = this.totalPages;
                }
                if (this.currentPage < 1) {
                    this.currentPage = 1;
                }
            }

            // Slicing visible rows for current page
            let startIndex = 0;
            let endIndex = matchingRows.length;

            if (this.perPage !== 'all' && this.perPage > 0 && matchingRows.length > 0) {
                startIndex = (this.currentPage - 1) * this.perPage;
                endIndex = Math.min(startIndex + this.perPage, matchingRows.length);
                this.pageStart = startIndex + 1;
                this.pageEnd = endIndex;
            } else {
                this.pageStart = matchingRows.length > 0 ? 1 : 0;
                this.pageEnd = matchingRows.length;
            }

            const visibleSet = new Set(matchingRows.slice(startIndex, endIndex));
            for (const row of siteRows) {
                row.style.display = visibleSet.has(row) ? '' : 'none';
            }

            // Update sites count label
            if (countSpan) {
                if (isQuery) {
                    if (this.perPage === 'all' || this.totalFilteredSites <= (this.perPage as number)) {
                        countSpan.textContent = `Showing ${this.totalFilteredSites} of ${this.totalSites} monitored`;
                    } else {
                        countSpan.textContent = `Showing ${this.pageStart}–${this.pageEnd} of ${this.totalFilteredSites} matching (${this.totalSites} total)`;
                    }
                } else {
                    if (this.perPage === 'all' || this.totalSites <= (this.perPage as number)) {
                        countSpan.textContent = `${this.totalSites} monitored`;
                    } else {
                        countSpan.textContent = `Showing ${this.pageStart}–${this.pageEnd} of ${this.totalSites} monitored`;
                    }
                }
            }

            // Handle no matching sites row
            if (noMatchRow) {
                noMatchRow.classList.toggle('hidden', matchingRows.length > 0 || this.totalSites === 0);
                if (noMatchQuery) {
                    noMatchQuery.textContent = this.searchQuery;
                }
            }

            // Filter recent events
            let visibleEvents = 0;
            for (const row of eventRows) {
                const text = (row.dataset.search || '').toLowerCase();
                const matched = !isQuery || text.includes(q);
                row.style.display = matched ? '' : 'none';
                if (matched) visibleEvents++;
            }

            if (eventsNoMatchRow) {
                eventsNoMatchRow.classList.toggle('hidden', visibleEvents > 0 || eventRows.length === 0 || !isQuery);
            }

            // Synchronize URL query parameter smoothly without page reload
            try {
                const url = new URL(window.location.href);
                if (isQuery) {
                    url.searchParams.set('q', this.searchQuery.trim());
                } else {
                    url.searchParams.delete('q');
                }
                window.history.replaceState(null, '', url.toString());
            } catch (_) {}
        },

        setupShortcuts() {
            // Global keydown listeners
            document.addEventListener('keydown', (e: KeyboardEvent) => {
                const activeTag = (document.activeElement?.tagName || '').toUpperCase();
                const isFormInput = ['INPUT', 'TEXTAREA', 'SELECT'].includes(activeTag);

                // "/" shortcut focuses search from anywhere
                if (e.key === '/' && !isFormInput) {
                    const input = document.getElementById('monitoring-search') as HTMLInputElement | null;
                    if (input && document.activeElement !== input) {
                        e.preventDefault();
                        input.focus();
                        input.select();
                    }
                }
            });

            const searchInput = document.getElementById('monitoring-search') as HTMLInputElement | null;
            if (searchInput) {
                searchInput.addEventListener('keydown', (e: KeyboardEvent) => {
                    if (e.key === 'Escape') {
                        this.clearSearch();
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        const firstSite = document.querySelector(
                            '.site-row:not([style*="display: none"]) a',
                        ) as HTMLAnchorElement | null;
                        if (firstSite) {
                            firstSite.click();
                        }
                    }
                });
            }
        },

        openModal(id: number, dom: string, r?: string, n?: string) {
            this.siteId = id;
            this.domain = dom;
            this.reason = r || 'client_dns';
            this.notes = n || '';
            this.actionUrl = `/monitoring/sites/${id}/classify-outage`;
            this.modalOpen = true;

            Alpine.nextTick(() => {
                const select = document.getElementById('classify-outage-reason') as HTMLSelectElement | null;
                if (select) select.focus();
            });
        },

        closeModal() {
            this.modalOpen = false;
        },

        exposeLegacyGlobals() {
            window.monitoringFilterSearch = (term: string) => {
                this.filterSearch(term);
            };

            window.monitoringOpenClassify = (siteId: number, domain: string, reason = 'client_dns', notes = '') => {
                this.openModal(siteId, domain, reason, notes);
            };

            window.monitoringCloseClassify = () => {
                this.closeModal();
            };
        },

        initRecheckButtons() {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const buttons = document.querySelectorAll('.monitoring-recheck-btn');

            buttons.forEach((b) => {
                const btn = b as HTMLButtonElement;
                if (btn.dataset.boundRecheck === 'true') return;
                btn.dataset.boundRecheck = 'true';

                btn.addEventListener('click', async (e: MouseEvent) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (btn.disabled) return;

                    const origHtml = btn.innerHTML;
                    const row = btn.closest('tr') as HTMLElement | null;
                    const stateCell = row?.querySelector('.site-state-cell');
                    const lastEventCell = row?.querySelector('.site-last-event-cell');
                    const actionsCell = row?.querySelector('.site-actions-cell');

                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-[10px] mr-1"></i> Probing…';

                    try {
                        const url = btn.dataset.url || '';
                        const res = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                Accept: 'application/json',
                            },
                        });
                        const data = await res.json();

                        if (data.ok) {
                            if (data.state === 'up') {
                                btn.innerHTML = `<i class="fa-solid fa-circle-check text-emerald-500 text-[10px] mr-1"></i> Up (${data.status_code || 200})`;

                                if (stateCell) {
                                    stateCell.innerHTML =
                                        '<span class="px-2 py-0.5 rounded-full text-xs font-semibold font-data inline-flex items-center gap-1 bg-[var(--color-status-green)]/15 text-[var(--color-status-green)]">UP</span>';
                                }
                                if (lastEventCell) {
                                    lastEventCell.textContent = 'Up — checked just now';
                                }
                                if (actionsCell) {
                                    actionsCell
                                        .querySelectorAll('.classify-btn, form[action*="classify-outage"]')
                                        .forEach((el) => {
                                            el.remove();
                                        });
                                }
                                if (row) {
                                    row.classList.remove('opacity-60');
                                    if (row.dataset.search) {
                                        row.dataset.search = row.dataset.search.replace(/\bdown\b/g, 'up');
                                    }
                                }

                                const downStat = document.getElementById('monitoring-stat-down');
                                const upStat = document.getElementById('monitoring-stat-up');
                                if (downStat && upStat) {
                                    const currentDown = Number.parseInt(downStat.textContent || '0', 10);
                                    if (!Number.isNaN(currentDown) && currentDown > 0) {
                                        downStat.textContent = String(currentDown - 1);
                                        const currentUp = Number.parseInt(upStat.textContent || '0', 10);
                                        if (!Number.isNaN(currentUp)) {
                                            upStat.textContent = String(currentUp + 1);
                                        }
                                    }
                                }

                                setTimeout(() => {
                                    btn.innerHTML = origHtml;
                                    btn.disabled = false;
                                }, 2500);
                            } else {
                                const code = data.status_code ? `HTTP ${data.status_code}` : 'Down';
                                btn.innerHTML = `<i class="fa-solid fa-triangle-exclamation text-rose-500 text-[10px] mr-1"></i> ${code}`;
                                if (lastEventCell) {
                                    lastEventCell.textContent = 'Down — checked just now';
                                }
                                setTimeout(() => {
                                    btn.innerHTML = origHtml;
                                    btn.disabled = false;
                                }, 3000);
                            }
                        } else {
                            btn.innerHTML = `<i class="fa-solid fa-circle-xmark text-rose-500 text-[10px] mr-1"></i> ${data.message || 'Error'}`;
                            setTimeout(() => {
                                btn.innerHTML = origHtml;
                                btn.disabled = false;
                            }, 3500);
                        }
                    } catch (_) {
                        btn.innerHTML =
                            '<i class="fa-solid fa-circle-xmark text-rose-500 text-[10px] mr-1"></i> Failed';
                        setTimeout(() => {
                            btn.innerHTML = origHtml;
                            btn.disabled = false;
                        }, 3500);
                    }
                });
            });
        },
    };
}
