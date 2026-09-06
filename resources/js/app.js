import Alpine from 'alpinejs';
import * as echarts from 'echarts/core';
import {
    LineChart,
    BarChart,
    HeatmapChart,
} from 'echarts/charts';
import {
    GridComponent,
    TooltipComponent,
    LegendComponent,
    TitleComponent,
    DataZoomComponent,
    CalendarComponent,
    VisualMapComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { initThemeSystem } from './theme.js';

echarts.use([
    LineChart,
    BarChart,
    HeatmapChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    TitleComponent,
    DataZoomComponent,
    CalendarComponent,
    VisualMapComponent,
    CanvasRenderer,
]);

window.echarts = echarts;
window.Alpine = Alpine;

// Reusable sortable-table component. Apply with:
//   <table x-data="sortableTable({ defaultKey: 'mem', defaultDir: 'asc' })">
// Each <th> uses <x-sort-th key="mem">…</x-sort-th> and each <tr> carries
// data-sort-<key> attributes. Numeric values sort numerically when parseable,
// otherwise lexicographic. Empty strings sort last regardless of direction.
//
// Caches the table element on init() — depending on $el reactively inside
// applySort() left us in a state where the indicator state would update but
// the DOM mutation didn't take.
Alpine.data('sortableTable', ({ defaultKey = null, defaultDir = 'asc' } = {}) => ({
    sortKey: defaultKey,
    sortDir: defaultDir,
    tableEl: null,
    init() {
        this.tableEl = this.$el;
        if (this.sortKey) this.applySort();
    },
    sortBy(key) {
        if (this.sortKey === key) {
            this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            // Numeric columns feel right starting desc (biggest first); text cols asc.
            const probe = this.tableEl?.querySelector('tbody tr')?.getAttribute(`data-sort-${key}`);
            const isNumeric = probe !== null && probe !== '' && !isNaN(parseFloat(probe));
            this.sortKey = key;
            this.sortDir = isNumeric ? 'desc' : 'asc';
        }
        this.applySort();
    },
    applySort() {
        if (!this.tableEl) return;
        const tbody = this.tableEl.querySelector('tbody');
        if (!tbody) return;
        const rows = Array.from(tbody.children).filter(el => el.tagName === 'TR');
        if (rows.length === 0) return;
        const sign = this.sortDir === 'asc' ? 1 : -1;
        const key = this.sortKey;
        rows.sort((a, b) => {
            const av = a.getAttribute(`data-sort-${key}`) ?? '';
            const bv = b.getAttribute(`data-sort-${key}`) ?? '';
            if (av === '' && bv === '') return 0;
            if (av === '') return 1;
            if (bv === '') return -1;
            const an = parseFloat(av);
            const bn = parseFloat(bv);
            if (!isNaN(an) && !isNaN(bn)) return (an - bn) * sign;
            return av.localeCompare(bv) * sign;
        });
        rows.forEach(r => tbody.appendChild(r));
    },
    indicator(key) {
        if (this.sortKey !== key) return 'fa-sort';
        return this.sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
    },
    isActive(key) {
        return this.sortKey === key;
    },
}));

// Reusable column-visibility toggle. Apply via the <x-column-toggle> Blade
// component, which renders the dropdown UI and provides the column config.
// Persists per-table preferences in localStorage. Companion table needs
//   data-column-toggle="<id>"
//   data-hidden-cols="<space-separated keys>"   (initial state, SSR-baked)
// and each <th>/<td> needs data-col="<key>" so the per-id CSS rules can hide it.
Alpine.data('columnToggle', ({ id, columns, initialHidden }) => ({
    open: false,
    columns,
    visible: {},
    storageKey: 'column-toggle:' + id,
    init() {
        // Defaults from column config (default: visible).
        for (const col of this.columns) {
            this.visible[col.key] = col.default !== false;
        }
        // Overlay user-saved preferences.
        try {
            const saved = JSON.parse(localStorage.getItem(this.storageKey) || 'null');
            if (saved && typeof saved === 'object') {
                for (const k in saved) {
                    if (k in this.visible) this.visible[k] = !!saved[k];
                }
            }
        } catch (_) {}
        this.apply();
    },
    toggle(key) {
        this.visible[key] = !this.visible[key];
        this.persist();
        this.apply();
    },
    reset() {
        // Wipe saved prefs and re-derive from defaults.
        localStorage.removeItem(this.storageKey);
        for (const col of this.columns) {
            this.visible[col.key] = col.default !== false;
        }
        this.apply();
    },
    persist() {
        localStorage.setItem(this.storageKey, JSON.stringify(this.visible));
    },
    apply() {
        const table = document.querySelector(`[data-column-toggle="${id}"]`);
        if (!table) return;
        const hidden = Object.entries(this.visible)
            .filter(([_k, v]) => !v)
            .map(([k]) => k)
            .join(' ');
        table.setAttribute('data-hidden-cols', hidden);
    },
}));

Alpine.data('docsSidebar', (initialOpen = {}) => ({
    openSections: initialOpen,
    toggleSection(name) {
        this.openSections[name] = !this.openSections[name];
    },
    isSectionOpen(name) {
        return !!this.openSections[name];
    },
    toggleAll(expand) {
        Object.keys(this.openSections).forEach(k => {
            this.openSections[k] = expand;
        });
    },
}));

Alpine.data('docsSearch', (index = []) => ({
    query: '',
    open: false,
    index,
    get results() {
        const q = this.query.trim().toLowerCase();
        if (q.length < 2) return [];
        return this.index.filter(p =>
            p.title.toLowerCase().includes(q) ||
            p.section.toLowerCase().includes(q) ||
            (p.category && p.category.toLowerCase().includes(q)) ||
            (p.excerpt && p.excerpt.toLowerCase().includes(q))
        ).slice(0, 10);
    },
}));

initThemeSystem(Alpine);

Alpine.start();
