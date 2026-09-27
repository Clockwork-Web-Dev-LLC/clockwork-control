export interface SortableTableOptions {
    defaultKey?: string | null;
    defaultDir?: 'asc' | 'desc';
}

/**
 * Reusable sortable-table component.
 * Apply with:
 *   <table x-data="sortableTable({ defaultKey: 'mem', defaultDir: 'asc' })">
 * Each <th> uses <x-sort-th key="mem">…</x-sort-th> and each <tr> carries
 * data-sort-<key> attributes. Numeric values sort numerically when parseable,
 * otherwise lexicographic. Empty strings sort last regardless of direction.
 */
export function sortableTable({ defaultKey = null, defaultDir = 'asc' }: SortableTableOptions = {}) {
    return {
        sortKey: defaultKey,
        sortDir: defaultDir,
        tableEl: null as HTMLElement | null,
        init() {
            this.tableEl = (this as any).$el as HTMLElement;
            if (this.sortKey) this.applySort();
        },
        sortBy(key: string) {
            if (this.sortKey === key) {
                this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                // Numeric columns feel right starting desc (biggest first); text cols asc.
                const probe = this.tableEl?.querySelector('tbody tr')?.getAttribute(`data-sort-${key}`);
                const isNumeric = probe !== null && probe !== '' && !Number.isNaN(parseFloat(probe || ''));
                this.sortKey = key;
                this.sortDir = isNumeric ? 'desc' : 'asc';
            }
            this.applySort();
        },
        applySort() {
            if (!this.tableEl) return;
            const tbody = this.tableEl.querySelector('tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.children).filter((el) => el.tagName === 'TR');
            if (rows.length === 0) return;
            const sign = this.sortDir === 'asc' ? 1 : -1;
            const key = this.sortKey;
            if (!key) return;

            rows.sort((a, b) => {
                const av = a.getAttribute(`data-sort-${key}`) ?? '';
                const bv = b.getAttribute(`data-sort-${key}`) ?? '';
                if (av === '' && bv === '') return 0;
                if (av === '') return 1;
                if (bv === '') return -1;
                const an = parseFloat(av);
                const bn = parseFloat(bv);
                if (!Number.isNaN(an) && !Number.isNaN(bn)) return (an - bn) * sign;
                return av.localeCompare(bv) * sign;
            });
            rows.forEach((r) => {
                tbody.appendChild(r);
            });
        },
        indicator(key: string) {
            if (this.sortKey !== key) return 'fa-sort';
            return this.sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
        },
        isActive(key: string) {
            return this.sortKey === key;
        },
    };
}
