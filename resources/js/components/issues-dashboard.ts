/**
 * Issues Console Dashboard Component
 *
 * Mounted on `/issues` (`resources/views/dashboard/issues.blade.php`):
 *   <div x-data="issuesDashboard(...)">
 *
 * Manages fleet-wide issue tracking and classification:
 * - Tier filtering (all, core, security, health, operations) and priority filtering
 *   (emergency, pressing, not pressing).
 * - Real-time custom severity level overrides per issue category with AJAX persistence.
 * - Batch operations, inline issue resolution, and category management drawer.
 */
export interface IssuesDashboardOptions {
    initialTotals?: Record<string, number>;
    categories?: Record<string, { label?: string; tier?: string }>;
    initialLevels?: Record<string, string>;
    defaultLevels?: Record<string, string>;
    updateLevelUrl?: string;
    updateAllLevelsUrl?: string;
    resetLevelsUrl?: string;
    csrfToken?: string;
}

export function issuesDashboard({
    initialTotals = {},
    categories = {},
    initialLevels = {},
    defaultLevels = {},
    updateLevelUrl = '',
    updateAllLevelsUrl = '',
    resetLevelsUrl = '',
    csrfToken = '',
}: IssuesDashboardOptions = {}) {
    return {
        tierTab: 'all',
        priorityFilter: 'all', // 'all', 'emergency', 'pressing', 'not_pressing', 'off'
        categoriesOpen: false,
        prioritiesModalOpen: false,
        screenOptionsOpen: false,
        jumpMenuOpen: false,
        activePriorityMenu: null as string | null,
        savingLevels: false,
        feedbackToast: null as string | null,
        hiddenCategories: {} as Record<string, boolean>,
        collapsedSections: {} as Record<string, boolean>,
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

        setTier(tier: string) {
            this.tierTab = tier;
            try {
                localStorage.setItem('cw_issues_tier_tab', tier);
            } catch (_) {}
        },

        setPriorityFilter(filter: string) {
            this.priorityFilter = filter;
            try {
                localStorage.setItem('cw_issues_priority_filter', filter);
            } catch (_) {}
        },

        getCategoryLevel(key: string) {
            return this.categoryLevels[key] || this.defaultLevels[key] || 'not_pressing';
        },

        isCategoryEnabled(key: string) {
            return this.getCategoryLevel(key) !== 'off';
        },

        isCategoryEmergency(key: string) {
            return this.getCategoryLevel(key) === 'emergency';
        },

        isCategoryPressing(key: string) {
            return this.getCategoryLevel(key) === 'pressing';
        },

        isCategoryNotPressing(key: string) {
            return this.getCategoryLevel(key) === 'not_pressing';
        },

        isCategoryOff(key: string) {
            return this.getCategoryLevel(key) === 'off';
        },

        isCategoryUrgent(key: string) {
            return this.isCategoryEmergency(key) || this.isCategoryPressing(key);
        },

        isCategoryVisible(key: string) {
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

        async setCategoryLevel(category: string, level: string) {
            const prevLevel = this.categoryLevels[category];
            this.categoryLevels = { ...this.categoryLevels, [category]: level };
            this.activePriorityMenu = null;
            this.savingLevels = true;

            try {
                const res = await fetch(this.updateLevelUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
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
                const levelNames: Record<string, string> = {
                    emergency: 'Emergency (Critical)',
                    pressing: 'Pressing (Urgent)',
                    not_pressing: 'Not Pressing (Routine)',
                    off: 'Turned Off',
                };
                this.showFeedback(`${label} set to ${levelNames[level] || level}`);
            } catch (err: any) {
                this.categoryLevels = { ...this.categoryLevels, [category]: prevLevel };
                await window.alertModal({
                    title: 'Save Failed',
                    message: `Failed to save category priority: ${err.message}`,
                    variant: 'danger',
                });
            } finally {
                this.savingLevels = false;
            }
        },

        restoreCategoryLevel(category: string) {
            const defaultLevel = this.defaultLevels[category] || 'pressing';
            this.setCategoryLevel(category, defaultLevel);
        },

        toggleCategoryTier(tierKey: string, enable: boolean) {
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
            Object.keys(this.categoryMeta).forEach((catKey) => {
                if (next[catKey] === 'off') {
                    next[catKey] = this.defaultLevels[catKey] || 'not_pressing';
                }
            });
            this.saveAllCategoryLevels(next);
        },

        turnAllOff() {
            const next = { ...this.categoryLevels };
            Object.keys(this.categoryMeta).forEach((catKey) => {
                next[catKey] = 'off';
            });
            this.saveAllCategoryLevels(next);
        },

        async saveAllCategoryLevels(newLevels?: Record<string, string>) {
            const levelsToSave = newLevels ?? this.categoryLevels;
            this.savingLevels = true;
            try {
                const res = await fetch(this.updateAllLevelsUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: JSON.stringify({ levels: levelsToSave }),
                });

                if (!res.ok) {
                    throw new Error(`Server returned HTTP ${res.status}`);
                }

                const data = await res.json();
                if (data.levels) {
                    this.categoryLevels = data.levels;
                }
                this.showFeedback('Category priorities updated.');
            } catch (err: any) {
                await window.alertModal({
                    title: 'Save Failed',
                    message: `Failed to save category priorities: ${err.message}`,
                    variant: 'danger',
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
                variant: 'warning',
            });
            if (!ok) return;

            this.savingLevels = true;
            try {
                const res = await fetch(this.resetLevelsUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
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
            } catch (err: any) {
                await window.alertModal({
                    title: 'Reset Failed',
                    message: `Failed to reset alert priorities: ${err.message}`,
                    variant: 'danger',
                });
            } finally {
                this.savingLevels = false;
            }
        },

        async muteRoutineCategories() {
            const routineKeys = ['plugins_outdated', 'plugins_closed', 'wp_admins'];
            const next = { ...this.categoryLevels };
            routineKeys.forEach((k) => {
                next[k] = 'off';
            });
            await this.saveAllCategoryLevels(next);
        },

        showFeedback(msg: string) {
            this.feedbackToast = msg;
            setTimeout(() => {
                if (this.feedbackToast === msg) {
                    this.feedbackToast = null;
                }
            }, 3500);
        },

        toggleCategory(key: string) {
            this.hiddenCategories = {
                ...this.hiddenCategories,
                [key]: !this.hiddenCategories[key],
            };
            this.persistHidden();
        },

        hideRoutine() {
            this.hiddenCategories = {
                ...this.hiddenCategories,
                plugins_outdated: true,
                plugins_closed: true,
                wp_admins: true,
            };
            this.persistHidden();
        },

        showOnlyCritical() {
            const next: Record<string, boolean> = {};
            Object.entries(this.categoryMeta).forEach(([k, meta]) => {
                if (meta.tier !== 'critical') {
                    next[k] = true;
                }
            });
            this.hiddenCategories = next;
            this.persistHidden();
        },

        showOnlyUrgent() {
            const next: Record<string, boolean> = {};
            Object.keys(this.categoryMeta).forEach((k) => {
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

        jumpToCategory(htmlId: string) {
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

        matchesTier(tier: string) {
            if (this.tierTab === 'all') return true;
            return this.tierTab === tier;
        },

        matchesTab(tier: string) {
            return this.matchesTier(tier);
        },

        isSectionCollapsed(key: string) {
            return !!this.collapsedSections[key];
        },

        toggleSection(key: string) {
            this.collapsedSections = {
                ...this.collapsedSections,
                [key]: !this.collapsedSections[key],
            };
            this.persistCollapsed();
        },

        get isAllCollapsed() {
            const activeKeys = Object.keys(this.categoryMeta).filter(
                (k) => (this.totals[k] ?? 0) > 0 && this.isCategoryVisible(k),
            );
            if (activeKeys.length === 0) return false;
            return activeKeys.every((k) => this.collapsedSections[k]);
        },

        toggleCollapseAll() {
            const shouldCollapse = !this.isAllCollapsed;
            const next = { ...this.collapsedSections };
            Object.keys(this.categoryMeta).forEach((k) => {
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
            return Object.keys(this.categoryMeta).filter((k) => this.isCategoryOff(k));
        },

        get disabledCategoriesCount() {
            return this.disabledCategories.length;
        },

        get disabledItemsCount() {
            return this.disabledCategories.reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        get emergencyCategories() {
            return Object.keys(this.categoryMeta).filter((k) => this.isCategoryEmergency(k));
        },

        get emergencyItemsCount() {
            return this.emergencyCategories.reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        get pressingCategories() {
            return Object.keys(this.categoryMeta).filter((k) => this.isCategoryPressing(k));
        },

        get pressingItemsCount() {
            return this.pressingCategories.reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        get notPressingCategories() {
            return Object.keys(this.categoryMeta).filter((k) => this.isCategoryNotPressing(k));
        },

        get notPressingItemsCount() {
            return this.notPressingCategories.reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        get activeItemsCount() {
            return Object.keys(this.categoryMeta)
                .filter((k) => this.isCategoryEnabled(k))
                .reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        get hiddenCategoriesCount() {
            return Object.keys(this.hiddenCategories).filter(
                (k) => this.hiddenCategories[k] && this.isCategoryEnabled(k) && (this.totals[k] ?? 0) > 0,
            ).length;
        },

        get hiddenItemsCount() {
            return Object.keys(this.hiddenCategories)
                .filter((k) => this.hiddenCategories[k] && this.isCategoryEnabled(k))
                .reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        get visibleItemsCount() {
            return Object.keys(this.categoryMeta)
                .filter((k) => {
                    if (!this.isCategoryVisible(k)) return false;
                    if (this.tierTab !== 'all' && this.categoryMeta[k]?.tier !== this.tierTab) return false;
                    return true;
                })
                .reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        hasVisibleCategoryInTier(tier: string) {
            return Object.entries(this.categoryMeta).some(([k, meta]) => {
                return meta.tier === tier && (this.totals[k] ?? 0) > 0 && this.isCategoryVisible(k);
            });
        },
    };
}
