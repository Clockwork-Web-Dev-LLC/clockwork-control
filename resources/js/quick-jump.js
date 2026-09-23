/**
 * Clockwork Control — Quick Jump / Command Palette System
 *
 * Handles ⌘K / Ctrl+K keyboard palette:
 * - Two-letter jump codes with immediate navigation (e.g. GS -> /, GT -> /sites, etc.)
 * - Fuzzy / substring filtering across labels, codes, and sections
 * - Arrow up/down keyboard navigation and Enter to follow link
 */

export const DEFAULT_QUICK_JUMP_ITEMS = [
    { id: 'servers', section: 'Quick Jump', label: 'Servers Fleet', code: 'GS', kbd: 'G S', icon: 'fa-solid fa-server text-[var(--color-brand)]', url: '/' },
    { id: 'sites', section: 'Quick Jump', label: 'Sites Directory', code: 'GT', kbd: 'G T', icon: 'fa-solid fa-globe text-[var(--color-brand)]', url: '/sites' },
    { id: 'issues', section: 'Quick Jump', label: 'Issues Console', code: 'GI', kbd: 'G I', icon: 'fa-solid fa-triangle-exclamation text-[var(--color-status-yellow)]', url: '/issues' },
    { id: 'monitoring', section: 'Quick Jump', label: 'Uptime Monitoring', code: 'GM', kbd: 'G M', icon: 'fa-solid fa-heart-pulse text-[var(--color-status-green)]', url: '/monitoring' },
    { id: 'updates', section: 'Quick Jump', label: 'Updates Manager', code: 'GU', kbd: 'G U', icon: 'fa-solid fa-rotate text-[var(--color-brand)]', url: '/updates' },
    { id: 'security', section: 'Quick Jump', label: 'Security Scans', code: 'GX', kbd: 'G X', icon: 'fa-solid fa-shield-halved text-[var(--color-brand)]', url: '/security/scans' },
    { id: 'capacity', section: 'Operations', label: 'Capacity Dashboard', code: null, kbd: null, icon: 'fa-solid fa-gauge-high text-[var(--color-ink-muted)]', url: '/capacity' },
    { id: 'server-updates', section: 'Operations', label: 'Fleet OS Updates', code: null, kbd: null, icon: 'fa-solid fa-cube text-[var(--color-ink-muted)]', url: '/operations/server-updates' },
    { id: 'maintenance-history', section: 'Operations', label: 'Maintenance History', code: null, kbd: null, icon: 'fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)]', url: '/maintenance-history' },
    { id: 'credentials', section: 'Operations', label: 'Bulk SSH Passwords', code: null, kbd: null, icon: 'fa-solid fa-key text-[var(--color-ink-muted)]', url: '/servers/credentials' },
    { id: 'settings', section: 'Configuration', label: 'Global Settings Hub', code: null, kbd: null, icon: 'fa-solid fa-sliders text-[var(--color-ink-muted)]', url: '/settings' },
    { id: 'docs', section: 'Configuration', label: 'Documentation & Runbooks', code: 'GD', kbd: 'G D', icon: 'fa-solid fa-book-bookmark text-[var(--color-brand)]', url: '/docs' },
];

export function quickJumpPicker() {
    return {
        paletteOpen: false,
        paletteQuery: '',
        paletteSelectedIndex: 0,
        paletteNavigating: false,

        get paletteItemsList() {
            if (typeof window !== 'undefined' && Array.isArray(window.cwQuickJumpItems) && window.cwQuickJumpItems.length > 0) {
                return window.cwQuickJumpItems;
            }
            return DEFAULT_QUICK_JUMP_ITEMS;
        },

        get filteredPaletteItems() {
            const raw = (this.paletteQuery || '').trim();
            const all = this.paletteItemsList;
            if (!raw) return all;

            const q = raw.toLowerCase();
            const normalizedCode = raw.toUpperCase().replace(/\s+/g, '');

            return all.filter(item => {
                if (item.code) {
                    const c = item.code.toUpperCase();
                    if (c === normalizedCode || c.startsWith(normalizedCode)) {
                        return true;
                    }
                    if (item.kbd && item.kbd.toLowerCase().includes(q)) {
                        return true;
                    }
                }
                if (item.label.toLowerCase().includes(q)) {
                    return true;
                }
                if (item.section.toLowerCase().includes(q)) {
                    return true;
                }
                return false;
            });
        },

        get groupedPaletteItems() {
            const items = this.filteredPaletteItems;
            const groups = [];
            const sectionOrder = ['Quick Jump', 'Operations', 'Configuration'];

            for (const sec of sectionOrder) {
                const secItems = items.filter(i => i.section === sec);
                if (secItems.length > 0) {
                    groups.push({ section: sec, items: secItems });
                }
            }

            const knownSecs = new Set(sectionOrder);
            const otherItems = items.filter(i => !knownSecs.has(i.section));
            if (otherItems.length > 0) {
                groups.push({ section: 'Other', items: otherItems });
            }

            return groups;
        },

        getItemGlobalIndex(item) {
            return this.filteredPaletteItems.findIndex(i => i.id === item.id);
        },

        openPalette() {
            this.paletteOpen = true;
            this.paletteQuery = '';
            this.paletteSelectedIndex = 0;
            this.paletteNavigating = false;
            this.focusPaletteInput();
        },

        closePalette() {
            this.paletteOpen = false;
            this.paletteNavigating = false;
        },

        focusPaletteInput() {
            const focus = () => {
                if (this.$refs && this.$refs.paletteInput) {
                    this.$refs.paletteInput.focus();
                    this.$refs.paletteInput.select();
                }
            };
            if (typeof this.$nextTick === 'function') {
                this.$nextTick(focus);
            }
            setTimeout(focus, 50);
            setTimeout(focus, 150);
        },

        onPaletteInput() {
            const raw = (this.paletteQuery || '').trim();
            const normalized = raw.toUpperCase().replace(/\s+/g, '');

            // Immediate jump: normalized query equals a 2-character jump code
            if (normalized.length >= 2) {
                const match = this.paletteItemsList.find(i => i.code && i.code.toUpperCase() === normalized);
                if (match) {
                    this.navigateTo(match.url);
                    return;
                }
            }

            this.paletteSelectedIndex = 0;
        },

        paletteDown() {
            const total = this.filteredPaletteItems.length;
            if (total === 0) return;
            this.paletteSelectedIndex = (this.paletteSelectedIndex + 1) % total;
            this.scrollSelectedIntoView();
        },

        paletteUp() {
            const total = this.filteredPaletteItems.length;
            if (total === 0) return;
            this.paletteSelectedIndex = (this.paletteSelectedIndex - 1 + total) % total;
            this.scrollSelectedIntoView();
        },

        selectCurrent() {
            const raw = (this.paletteQuery || '').trim();
            const normalized = raw.toUpperCase().replace(/\s+/g, '');

            if (normalized.length >= 2) {
                const match = this.paletteItemsList.find(i => i.code && i.code.toUpperCase() === normalized);
                if (match) {
                    this.navigateTo(match.url);
                    return;
                }
            }

            const items = this.filteredPaletteItems;
            if (items.length > 0 && this.paletteSelectedIndex >= 0 && this.paletteSelectedIndex < items.length) {
                this.navigateTo(items[this.paletteSelectedIndex].url);
            }
        },

        scrollSelectedIntoView() {
            const scroll = () => {
                const el = document.getElementById('cw-palette-item-' + this.paletteSelectedIndex);
                if (el) {
                    el.scrollIntoView({ block: 'nearest' });
                }
            };
            if (typeof this.$nextTick === 'function') {
                this.$nextTick(scroll);
            } else {
                setTimeout(scroll, 10);
            }
        },

        navigateTo(url) {
            if (this.paletteNavigating) return;
            this.paletteNavigating = true;
            this.paletteOpen = false;
            window.location.href = url;
        },

        initQuickJump() {
            this.$watch('paletteOpen', (open) => {
                if (open) {
                    this.paletteQuery = '';
                    this.paletteSelectedIndex = 0;
                    this.paletteNavigating = false;
                    this.focusPaletteInput();
                }
            });
        }
    };
}
