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
import { initThemeSystem, themePicker } from './theme.js';
import { initFontScaleSystem } from './font-scale.js';
import { initLayoutStyleSystem, layoutStylePicker } from './layout-style.js';
import { initTooltipSystem } from './tooltip.js';
import { initConfirmModalSystem } from './confirm-modal.js';
import { quickJumpPicker } from './quick-jump.js';

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

Alpine.data('siteDashboardReorder', ({ updateUrl, csrf, order = [], isCustom = false } = {}) => ({
    order: [...order],
    previousOrder: [...order],
    isCustom: isCustom,
    draggedWidget: null,
    dragOverWidget: null,
    saving: false,
    savedToast: false,
    errorToast: false,
    errorMessage: '',

    onDragStart(event, widget) {
        this.draggedWidget = widget;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', widget);
    },

    onDragEnd() {
        this.draggedWidget = null;
        this.dragOverWidget = null;
    },

    onDragOver(event, widget) {
        if (this.draggedWidget && this.draggedWidget !== widget) {
            this.dragOverWidget = widget;
        }
    },

    onDragLeave() {
        // Handled dynamically
    },

    async onDrop(event, targetWidget) {
        const sourceWidget = this.draggedWidget || event.dataTransfer.getData('text/plain');
        if (!sourceWidget || sourceWidget === targetWidget) {
            this.draggedWidget = null;
            this.dragOverWidget = null;
            return;
        }

        const grid = document.getElementById('site-dashboard-grid');
        if (!grid) return;

        const sourceEl = grid.querySelector(`[data-widget="${sourceWidget}"]`);
        const targetEl = grid.querySelector(`[data-widget="${targetWidget}"]`);
        if (!sourceEl || !targetEl) return;

        const fromIndex = this.order.indexOf(sourceWidget);
        const toIndex = this.order.indexOf(targetWidget);

        if (fromIndex !== -1 && toIndex !== -1) {
            this.previousOrder = [...this.order];
            this.order.splice(fromIndex, 1);
            this.order.splice(toIndex, 0, sourceWidget);

            if (fromIndex < toIndex) {
                grid.insertBefore(sourceEl, targetEl.nextSibling);
            } else {
                grid.insertBefore(sourceEl, targetEl);
            }

            this.isCustom = true;
            await this.saveLayout();
        }

        this.draggedWidget = null;
        this.dragOverWidget = null;
    },

    async saveLayout() {
        this.saving = true;
        this.savedToast = false;
        this.errorToast = false;
        try {
            const response = await fetch(updateUrl, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ layout: this.order }),
            });
            if (response.ok) {
                this.previousOrder = [...this.order];
                this.savedToast = true;
                setTimeout(() => {
                    this.savedToast = false;
                }, 3000);
            } else {
                let msg = 'Failed to save layout';
                try {
                    const data = await response.json();
                    if (data && data.message) {
                        msg = data.message;
                    }
                } catch (_) {}
                this.showError(msg);
                this.rollbackDom();
            }
        } catch (err) {
            console.error('Failed saving dashboard layout', err);
            this.showError('Network error saving layout');
            this.rollbackDom();
        } finally {
            this.saving = false;
        }
    },

    async resetLayout() {
        const ok = await window.confirmModal({
            title: 'Reset Dashboard Layout?',
            message: 'Reset dashboard cards to the default layout?',
            confirmText: 'Reset Layout',
            variant: 'warning'
        });
        if (!ok) return;
        this.saving = true;
        this.savedToast = false;
        this.errorToast = false;
        try {
            const response = await fetch(updateUrl, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ reset: true }),
            });
            if (response.ok) {
                window.location.reload();
            } else {
                let msg = 'Failed to reset layout';
                try {
                    const data = await response.json();
                    if (data && data.message) {
                        msg = data.message;
                    }
                } catch (_) {}
                this.showError(msg);
            }
        } catch (err) {
            console.error('Failed resetting dashboard layout', err);
            this.showError('Network error resetting layout');
        } finally {
            this.saving = false;
        }
    },

    showError(message) {
        this.errorMessage = message;
        this.errorToast = true;
        setTimeout(() => {
            this.errorToast = false;
        }, 5000);
    },

    rollbackDom() {
        const grid = document.getElementById('site-dashboard-grid');
        if (!grid) return;
        this.order = [...this.previousOrder];
        this.order.forEach((widgetKey) => {
            const el = grid.querySelector(`[data-widget="${widgetKey}"]`);
            if (el) {
                grid.appendChild(el);
            }
        });
    },
}));

Alpine.data('issuesDashboard', ({
    initialTotals = {},
    categories = {},
    initialLevels = {},
    defaultLevels = {},
    updateLevelUrl = '',
    updateAllLevelsUrl = '',
    resetLevelsUrl = '',
    csrfToken = '',
} = {}) => ({
    tierTab: 'all',
    priorityFilter: 'all', // 'all', 'emergency', 'pressing', 'not_pressing', 'off'
    categoriesOpen: false,
    prioritiesModalOpen: false,
    screenOptionsOpen: false,
    jumpMenuOpen: false,
    activePriorityMenu: null,
    savingLevels: false,
    feedbackToast: null,
    hiddenCategories: {},
    collapsedSections: {},
    totals: initialTotals,
    categoryMeta: categories,
    categoryLevels: { ...initialLevels },
    defaultLevels: { ...defaultLevels },
    updateLevelUrl,
    updateAllLevelsUrl,
    resetLevelsUrl,
    csrfToken,

    init() {
        try {
            const savedTab = localStorage.getItem('cw_issues_tier_tab');
            if (savedTab && ['all', 'critical', 'infrastructure', 'routine'].includes(savedTab)) {
                this.tierTab = savedTab;
            }
        } catch (_) {}

        try {
            const savedPriority = localStorage.getItem('cw_issues_priority_filter');
            if (savedPriority && ['all', 'emergency', 'pressing', 'not_pressing', 'off'].includes(savedPriority)) {
                this.priorityFilter = savedPriority;
            }
        } catch (_) {}

        try {
            const savedHidden = JSON.parse(localStorage.getItem('cw_issues_hidden_categories') || '{}');
            if (savedHidden && typeof savedHidden === 'object') {
                this.hiddenCategories = savedHidden;
            }
        } catch (_) {}

        try {
            const savedCollapsed = JSON.parse(localStorage.getItem('cw_issues_collapsed_sections') || '{}');
            if (savedCollapsed && typeof savedCollapsed === 'object') {
                this.collapsedSections = savedCollapsed;
            }
        } catch (_) {}
    },

    setTier(tier) {
        this.tierTab = tier;
        try {
            localStorage.setItem('cw_issues_tier_tab', tier);
        } catch (_) {}
    },

    setPriorityFilter(filter) {
        this.priorityFilter = filter;
        try {
            localStorage.setItem('cw_issues_priority_filter', filter);
        } catch (_) {}
    },

    getCategoryLevel(key) {
        return this.categoryLevels[key] || this.defaultLevels[key] || 'not_pressing';
    },

    isCategoryEnabled(key) {
        return this.getCategoryLevel(key) !== 'off';
    },

    isCategoryEmergency(key) {
        return this.getCategoryLevel(key) === 'emergency';
    },

    isCategoryPressing(key) {
        return this.getCategoryLevel(key) === 'pressing';
    },

    isCategoryNotPressing(key) {
        return this.getCategoryLevel(key) === 'not_pressing';
    },

    isCategoryOff(key) {
        return this.getCategoryLevel(key) === 'off';
    },

    isCategoryUrgent(key) {
        return this.isCategoryEmergency(key) || this.isCategoryPressing(key);
    },

    isCategoryVisible(key) {
        // If filter is explicitly 'off', show only muted categories
        if (this.priorityFilter === 'off') {
            return this.isCategoryOff(key) && !this.hiddenCategories[key];
        }

        // Categories turned completely off are muted fleet-wide
        if (!this.isCategoryEnabled(key)) {
            return false;
        }

        // Priority filter (all vs emergency only vs pressing only vs not pressing only)
        if (this.priorityFilter === 'emergency' && !this.isCategoryEmergency(key)) {
            return false;
        }
        if (this.priorityFilter === 'pressing' && !this.isCategoryPressing(key)) {
            return false;
        }
        if (this.priorityFilter === 'not_pressing' && !this.isCategoryNotPressing(key)) {
            return false;
        }

        // Local visibility toggle
        return !this.hiddenCategories[key];
    },

    async setCategoryLevel(category, level) {
        const prevLevel = this.categoryLevels[category];
        this.categoryLevels = { ...this.categoryLevels, [category]: level };
        this.activePriorityMenu = null;
        this.savingLevels = true;

        try {
            const res = await fetch(this.updateLevelUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({ category, level }),
            });

            if (!res.ok) {
                throw new Error(`Server returned HTTP ${res.status}`);
            }

            const data = await res.json();
            if (data.levels) {
                this.categoryLevels = data.levels;
            }

            const label = this.categoryMeta[category]?.label || category;
            const levelNames = {
                emergency: 'Emergency (Critical)',
                pressing: 'Pressing (Urgent)',
                not_pressing: 'Not Pressing (Routine)',
                off: 'Turned Off'
            };
            this.showFeedback(`${label} set to ${levelNames[level] || level}`);
        } catch (err) {
            this.categoryLevels = { ...this.categoryLevels, [category]: prevLevel };
            await window.alertModal({
                title: 'Save Failed',
                message: 'Failed to save category priority: ' + err.message,
                variant: 'danger'
            });
        } finally {
            this.savingLevels = false;
        }
    },

    restoreCategoryLevel(category) {
        const defaultLevel = this.defaultLevels[category] || 'pressing';
        this.setCategoryLevel(category, defaultLevel);
    },

    toggleCategoryTier(tierKey, enable) {
        const next = { ...this.categoryLevels };
        Object.entries(this.categoryMeta).forEach(([catKey, meta]) => {
            if (meta.tier === tierKey) {
                if (enable) {
                    if (next[catKey] === 'off') {
                        next[catKey] = this.defaultLevels[catKey] || 'not_pressing';
                    }
                } else {
                    next[catKey] = 'off';
                }
            }
        });
        this.saveAllCategoryLevels(next);
    },

    turnAllOn() {
        const next = { ...this.categoryLevels };
        Object.keys(this.categoryMeta).forEach(catKey => {
            if (next[catKey] === 'off') {
                next[catKey] = this.defaultLevels[catKey] || 'not_pressing';
            }
        });
        this.saveAllCategoryLevels(next);
    },

    turnAllOff() {
        const next = { ...this.categoryLevels };
        Object.keys(this.categoryMeta).forEach(catKey => {
            next[catKey] = 'off';
        });
        this.saveAllCategoryLevels(next);
    },

    async saveAllCategoryLevels(newLevels = this.categoryLevels) {
        this.savingLevels = true;
        try {
            const res = await fetch(this.updateAllLevelsUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({ levels: newLevels }),
            });

            if (!res.ok) {
                throw new Error(`Server returned HTTP ${res.status}`);
            }

            const data = await res.json();
            if (data.levels) {
                this.categoryLevels = data.levels;
            }
            this.showFeedback('Category priorities updated.');
        } catch (err) {
            await window.alertModal({
                title: 'Save Failed',
                message: 'Failed to save category priorities: ' + err.message,
                variant: 'danger'
            });
        } finally {
            this.savingLevels = false;
        }
    },

    async resetCategoryLevels() {
        const ok = await window.confirmModal({
            title: 'Reset Priorities?',
            message: 'Reset all alert category priorities to system defaults?',
            confirmText: 'Reset Defaults',
            variant: 'warning'
        });
        if (!ok) return;

        this.savingLevels = true;
        try {
            const res = await fetch(this.resetLevelsUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
            });

            if (!res.ok) {
                throw new Error(`Server returned HTTP ${res.status}`);
            }

            const data = await res.json();
            if (data.levels) {
                this.categoryLevels = data.levels;
            }
            this.showFeedback('Priorities reset to system defaults.');
        } catch (err) {
            await window.alertModal({
                title: 'Reset Failed',
                message: 'Failed to reset alert priorities: ' + err.message,
                variant: 'danger'
            });
        } finally {
            this.savingLevels = false;
        }
    },

    async muteRoutineCategories() {
        const routineKeys = ['plugins_outdated', 'plugins_closed', 'wp_admins'];
        const next = { ...this.categoryLevels };
        routineKeys.forEach(k => {
            next[k] = 'off';
        });
        await this.saveAllCategoryLevels(next);
    },

    showFeedback(msg) {
        this.feedbackToast = msg;
        setTimeout(() => {
            if (this.feedbackToast === msg) {
                this.feedbackToast = null;
            }
        }, 3500);
    },

    toggleCategory(key) {
        this.hiddenCategories = {
            ...this.hiddenCategories,
            [key]: !this.hiddenCategories[key],
        };
        this.persistHidden();
    },

    hideRoutine() {
        this.hiddenCategories = {
            ...this.hiddenCategories,
            'plugins_outdated': true,
            'plugins_closed': true,
            'wp_admins': true,
        };
        this.persistHidden();
    },

    showOnlyCritical() {
        const next = {};
        Object.entries(this.categoryMeta).forEach(([k, meta]) => {
            if (meta.tier !== 'critical') {
                next[k] = true;
            }
        });
        this.hiddenCategories = next;
        this.persistHidden();
    },

    showOnlyUrgent() {
        const next = {};
        Object.keys(this.categoryMeta).forEach(k => {
            if (!this.isCategoryUrgent(k)) {
                next[k] = true;
            }
        });
        this.hiddenCategories = next;
        this.persistHidden();
    },

    showAllCategories() {
        this.hiddenCategories = {};
        this.persistHidden();
    },

    jumpToCategory(htmlId) {
        const el = document.getElementById(htmlId);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            el.classList.add('ring-2', 'ring-[var(--color-brand)]', 'transition-all');
            setTimeout(() => {
                el.classList.remove('ring-2', 'ring-[var(--color-brand)]');
            }, 2000);
        }
    },

    resetCategories() {
        this.hiddenCategories = {};
        try {
            localStorage.removeItem('cw_issues_hidden_categories');
        } catch (_) {}
    },

    persistHidden() {
        try {
            localStorage.setItem('cw_issues_hidden_categories', JSON.stringify(this.hiddenCategories));
        } catch (_) {}
    },

    matchesTier(tier) {
        if (this.tierTab === 'all') return true;
        return this.tierTab === tier;
    },

    matchesTab(tier) {
        return this.matchesTier(tier);
    },

    isSectionCollapsed(key) {
        return !!this.collapsedSections[key];
    },

    toggleSection(key) {
        this.collapsedSections = {
            ...this.collapsedSections,
            [key]: !this.collapsedSections[key],
        };
        this.persistCollapsed();
    },

    get isAllCollapsed() {
        const activeKeys = Object.keys(this.categoryMeta).filter(k => (this.totals[k] ?? 0) > 0 && this.isCategoryVisible(k));
        if (activeKeys.length === 0) return false;
        return activeKeys.every(k => this.collapsedSections[k]);
    },

    toggleCollapseAll() {
        const shouldCollapse = !this.isAllCollapsed;
        const next = { ...this.collapsedSections };
        Object.keys(this.categoryMeta).forEach(k => {
            next[k] = shouldCollapse;
        });
        this.collapsedSections = next;
        this.persistCollapsed();
    },

    persistCollapsed() {
        try {
            localStorage.setItem('cw_issues_collapsed_sections', JSON.stringify(this.collapsedSections));
        } catch (_) {}
    },

    get disabledCategories() {
        return Object.keys(this.categoryMeta).filter(k => this.isCategoryOff(k));
    },

    get disabledCategoriesCount() {
        return this.disabledCategories.length;
    },

    get disabledItemsCount() {
        return this.disabledCategories.reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
    },

    get emergencyCategories() {
        return Object.keys(this.categoryMeta).filter(k => this.isCategoryEmergency(k));
    },

    get emergencyItemsCount() {
        return this.emergencyCategories.reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
    },

    get pressingCategories() {
        return Object.keys(this.categoryMeta).filter(k => this.isCategoryPressing(k));
    },

    get pressingItemsCount() {
        return this.pressingCategories.reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
    },

    get notPressingCategories() {
        return Object.keys(this.categoryMeta).filter(k => this.isCategoryNotPressing(k));
    },

    get notPressingItemsCount() {
        return this.notPressingCategories.reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
    },

    get activeItemsCount() {
        return Object.keys(this.categoryMeta)
            .filter(k => this.isCategoryEnabled(k))
            .reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
    },

    get hiddenCategoriesCount() {
        return Object.keys(this.hiddenCategories)
            .filter(k => this.hiddenCategories[k] && this.isCategoryEnabled(k) && (this.totals[k] ?? 0) > 0)
            .length;
    },

    get hiddenItemsCount() {
        return Object.keys(this.hiddenCategories)
            .filter(k => this.hiddenCategories[k] && this.isCategoryEnabled(k))
            .reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
    },

    get visibleItemsCount() {
        return Object.keys(this.categoryMeta)
            .filter(k => {
                if (!this.isCategoryVisible(k)) return false;
                if (this.tierTab !== 'all' && this.categoryMeta[k]?.tier !== this.tierTab) return false;
                return true;
            })
            .reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
    },

    hasVisibleCategoryInTier(tier) {
        return Object.entries(this.categoryMeta).some(([k, meta]) => {
            return meta.tier === tier && (this.totals[k] ?? 0) > 0 && this.isCategoryVisible(k);
        });
    },
}));

initThemeSystem(Alpine);
initFontScaleSystem(Alpine);
initLayoutStyleSystem(Alpine);
initTooltipSystem();
initConfirmModalSystem(Alpine);

// One Alpine component for the app shell. Spreading layoutStylePicker() and
// themePicker() into an object literal would collide on init() — Alpine only
// calls the last one, so layout state stayed "modern" after a FOUC restore.
export function appChrome() {
    const layout = layoutStylePicker();
    const theme = themePicker();
    const quickJump = quickJumpPicker();
    const layoutInit = layout.init;
    const themeInit = theme.init;
    const quickJumpInit = quickJump.initQuickJump;

    const chrome = {
        sidebarOpen: typeof localStorage !== 'undefined' && localStorage.getItem('cw_cc_sidebar') !== 'false',
        mobileNavOpen: false,
        userMenuOpen: false,
        sidebarUserMenuOpen: false,
        toggleSidebar() {
            this.sidebarOpen = !this.sidebarOpen;
            try {
                localStorage.setItem('cw_cc_sidebar', this.sidebarOpen);
            } catch (e) {}
        },
        closeMobileNav() {
            this.mobileNavOpen = false;
        },
        ...layout,
        ...theme,
        init() {
            layoutInit.call(this);
            themeInit.call(this);
            if (typeof quickJumpInit === 'function') {
                quickJumpInit.call(this);
            }

            this.$watch('mobileNavOpen', (open) => {
                document.body.classList.toggle('cw-mobile-nav-open', open);
            });

            if (typeof window.matchMedia === 'function') {
                const desktop = window.matchMedia('(min-width: 768px)');
                const dismiss = (event) => {
                    if (event.matches) {
                        this.mobileNavOpen = false;
                    }
                };
                if (typeof desktop.addEventListener === 'function') {
                    desktop.addEventListener('change', dismiss);
                } else if (typeof desktop.addListener === 'function') {
                    desktop.addListener(dismiss);
                }
            }
        },
    };

    Object.defineProperties(chrome, Object.getOwnPropertyDescriptors(quickJump));
    return chrome;
}

window.appChrome = appChrome;
Alpine.data('appChrome', appChrome);

Alpine.start();
