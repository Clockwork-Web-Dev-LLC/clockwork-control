/**
 * Clockwork Control — Quick Jump / Command Palette System
 *
 * Handles ⌘K / Ctrl+K keyboard palette:
 * - Two-letter jump codes with immediate navigation (e.g. GS -> /, GT -> /sites, etc.)
 * - Fuzzy / substring filtering across labels, codes, sections, and keywords
 * - Real-time async search across fleet servers (name, IP) and sites (domain)
 * - Arrow up/down keyboard navigation and Enter to follow link
 */

export const DEFAULT_QUICK_JUMP_ITEMS = [
    {
        id: 'servers',
        section: 'Quick Jump',
        label: 'Servers Fleet',
        code: 'GS',
        kbd: 'G S',
        icon: 'fa-solid fa-server text-[var(--color-brand)]',
        url: '/',
        keywords: ['servers', 'fleet', 'droplets', 'boxes', 'vps', 'host'],
    },
    {
        id: 'sites',
        section: 'Quick Jump',
        label: 'Sites Directory',
        code: 'GT',
        kbd: 'G T',
        icon: 'fa-solid fa-globe text-[var(--color-brand)]',
        url: '/sites',
        keywords: ['sites', 'domains', 'websites', 'wordpress'],
    },
    {
        id: 'issues',
        section: 'Quick Jump',
        label: 'Issues Console',
        code: 'GI',
        kbd: 'G I',
        icon: 'fa-solid fa-triangle-exclamation text-[var(--color-status-yellow)]',
        url: '/issues',
        keywords: ['issues', 'alerts', 'warnings', 'down', 'problems'],
    },
    {
        id: 'monitoring',
        section: 'Quick Jump',
        label: 'Uptime Monitoring',
        code: 'GM',
        kbd: 'G M',
        icon: 'fa-solid fa-heart-pulse text-[var(--color-status-green)]',
        url: '/monitoring',
        keywords: ['monitoring', 'uptime', 'status', 'health', 'response time'],
    },
    {
        id: 'updates',
        section: 'Quick Jump',
        label: 'Updates Manager',
        code: 'GU',
        kbd: 'G U',
        icon: 'fa-solid fa-rotate text-[var(--color-brand)]',
        url: '/updates',
        keywords: ['updates', 'plugins', 'themes', 'core', 'wordpress updates'],
    },
    {
        id: 'security',
        section: 'Quick Jump',
        label: 'Security Scans',
        code: 'GX',
        kbd: 'G X',
        icon: 'fa-solid fa-shield-halved text-[var(--color-brand)]',
        url: '/security/scans',
        keywords: ['security', 'scans', 'malware', 'checksums', 'blacklist', 'sucuri'],
    },
    {
        id: 'ai-remedy',
        section: 'Features',
        label: 'AiRemedy Triage & Forensics',
        code: 'GA',
        kbd: 'G A',
        icon: 'fa-solid fa-wand-magic-sparkles text-indigo-500',
        url: '/ai-remedy',
        keywords: [
            'airemedy',
            'ai remedy',
            'ai',
            'remedy',
            'triage',
            'incidents',
            'forensics',
            'shadow mode',
            'spike',
            'auto-heal',
            'healing',
        ],
    },
    {
        id: 'ai-remedy-settings',
        section: 'Configuration',
        label: 'AiRemedy Settings (OpenRouter)',
        code: null,
        kbd: null,
        icon: 'fa-solid fa-brain text-indigo-400',
        url: '/ai-remedy/settings',
        keywords: ['airemedy settings', 'openrouter', 'ai config', 'api key', 'claude', 'gpt'],
    },
    {
        id: 'gatekeeper',
        section: 'Security',
        label: 'Gatekeeper Login Protection',
        code: 'GG',
        kbd: 'G G',
        icon: 'fa-solid fa-user-shield text-emerald-500',
        url: '/settings/gatekeeper',
        keywords: ['gatekeeper', 'llar', 'lockouts', 'login protection', 'brute force', 'ip bans'],
    },
    {
        id: 'feedback',
        section: 'Operations',
        label: 'Feedback Backlog & Notes',
        code: 'GF',
        kbd: 'G F',
        icon: 'fa-solid fa-comment-dots text-purple-500',
        url: '/feedback',
        keywords: ['feedback', 'bugs', 'tweaks', 'notes', 'claude prompt', 'pins', 'collaboration'],
    },
    {
        id: 'capacity',
        section: 'Operations',
        label: 'Capacity Dashboard',
        code: null,
        kbd: null,
        icon: 'fa-solid fa-gauge-high text-[var(--color-ink-muted)]',
        url: '/capacity',
        keywords: ['capacity', 'ram', 'cpu', 'disk', 'resources', 'sizing'],
    },
    {
        id: 'server-updates',
        section: 'Operations',
        label: 'Fleet OS Updates',
        code: null,
        kbd: null,
        icon: 'fa-solid fa-cube text-[var(--color-ink-muted)]',
        url: '/operations/server-updates',
        keywords: ['server updates', 'os updates', 'apt', 'ubuntu', 'packages', 'reboot'],
    },
    {
        id: 'maintenance-history',
        section: 'Operations',
        label: 'Maintenance History',
        code: null,
        kbd: null,
        icon: 'fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)]',
        url: '/maintenance-history',
        keywords: ['maintenance history', 'logs', 'audit', 'completed updates'],
    },
    {
        id: 'credentials',
        section: 'Operations',
        label: 'Bulk SSH Passwords',
        code: null,
        kbd: null,
        icon: 'fa-solid fa-key text-[var(--color-ink-muted)]',
        url: '/servers/credentials',
        keywords: ['credentials', 'ssh', 'passwords', 'keys'],
    },
    {
        id: 'scheduled-jobs',
        section: 'Operations',
        label: 'Scheduled Jobs & Heartbeat',
        code: 'GJ',
        kbd: 'G J',
        icon: 'fa-solid fa-clock text-amber-500',
        url: '/settings/scheduled-jobs',
        keywords: ['scheduled jobs', 'cron', 'heartbeat', 'scheduler', 'background tasks'],
    },
    {
        id: 'review-queue',
        section: 'Security',
        label: 'Security Review Queue',
        code: 'GQ',
        kbd: 'G Q',
        icon: 'fa-solid fa-shield-virus text-rose-500',
        url: '/review',
        keywords: ['review queue', 'threats', 'security', 'blocked ips', 'bans', 'fail2ban'],
    },
    {
        id: 'settings',
        section: 'Configuration',
        label: 'Global Settings Hub',
        code: null,
        kbd: null,
        icon: 'fa-solid fa-sliders text-[var(--color-ink-muted)]',
        url: '/settings',
        keywords: ['settings', 'preferences', 'configuration', 'config'],
    },
    {
        id: 'users',
        section: 'Configuration',
        label: 'Users & Access Control',
        code: null,
        kbd: null,
        icon: 'fa-solid fa-users text-[var(--color-ink-muted)]',
        url: '/settings/users',
        keywords: ['users', 'team', 'roles', 'admin', 'operator', 'accounts', 'allowlist'],
    },
    {
        id: 'modules',
        section: 'Configuration',
        label: 'Module Directory',
        code: null,
        kbd: null,
        icon: 'fa-solid fa-cubes text-[var(--color-ink-muted)]',
        url: '/settings/modules',
        keywords: ['modules', 'plugins', 'directory', 'catalog', 'extensions', 'features'],
    },
    {
        id: 'docs',
        section: 'Configuration',
        label: 'Documentation & Runbooks',
        code: 'GD',
        kbd: 'G D',
        icon: 'fa-solid fa-book-bookmark text-[var(--color-brand)]',
        url: '/docs',
        keywords: ['documentation', 'docs', 'runbooks', 'api', 'help', 'guides'],
    },
];

export function quickJumpPicker() {
    return {
        paletteOpen: false,
        paletteQuery: '',
        paletteSelectedIndex: 0,
        paletteNavigating: false,
        remoteResults: [],
        searchLoading: false,
        searchDebounceTimer: null,

        get paletteItemsList() {
            if (
                typeof window !== 'undefined' &&
                Array.isArray(window.cwQuickJumpItems) &&
                window.cwQuickJumpItems.length > 0
            ) {
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

            const localMatches = all.filter((item) => {
                if (item.code) {
                    const c = item.code.toUpperCase();
                    if (c === normalizedCode || c.startsWith(normalizedCode)) {
                        return true;
                    }
                    if (item.kbd?.toLowerCase().includes(q)) {
                        return true;
                    }
                }
                if (item.label?.toLowerCase().includes(q)) {
                    return true;
                }
                if (item.sublabel?.toLowerCase().includes(q)) {
                    return true;
                }
                if (item.section?.toLowerCase().includes(q)) {
                    return true;
                }
                if (Array.isArray(item.keywords)) {
                    return item.keywords.some((k) => k.toLowerCase().includes(q) || q.includes(k.toLowerCase()));
                }
                if (typeof item.keywords === 'string') {
                    return item.keywords.toLowerCase().includes(q);
                }
                return false;
            });

            if (this.remoteResults.length > 0) {
                return [...localMatches, ...this.remoteResults];
            }

            return localMatches;
        },

        get groupedPaletteItems() {
            const items = this.filteredPaletteItems;
            const groups = [];
            const sectionOrder = [
                'Quick Jump',
                'Features',
                'Servers',
                'Sites',
                'Security',
                'Operations',
                'Configuration',
            ];

            for (const sec of sectionOrder) {
                const secItems = items.filter((i) => i.section === sec);
                if (secItems.length > 0) {
                    groups.push({ section: sec, items: secItems });
                }
            }

            const knownSecs = new Set(sectionOrder);
            const otherItems = items.filter((i) => !knownSecs.has(i.section));
            if (otherItems.length > 0) {
                groups.push({ section: 'Other', items: otherItems });
            }

            return groups;
        },

        getItemGlobalIndex(item) {
            return this.filteredPaletteItems.findIndex((i) => i.id === item.id);
        },

        openPalette() {
            this.paletteOpen = true;
            this.paletteQuery = '';
            this.paletteSelectedIndex = 0;
            this.paletteNavigating = false;
            this.remoteResults = [];
            this.searchLoading = false;
            this.focusPaletteInput();
        },

        closePalette() {
            this.paletteOpen = false;
            this.paletteNavigating = false;
            this.remoteResults = [];
            this.searchLoading = false;
            if (this.searchDebounceTimer) {
                clearTimeout(this.searchDebounceTimer);
                this.searchDebounceTimer = null;
            }
        },

        focusPaletteInput() {
            const focus = () => {
                if (this.$refs?.paletteInput) {
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
            if (normalized.length === 2) {
                const match = this.paletteItemsList.find((i) => i.code && i.code.toUpperCase() === normalized);
                if (match) {
                    this.navigateTo(match.url);
                    return;
                }
            }

            this.paletteSelectedIndex = 0;

            if (this.searchDebounceTimer) {
                clearTimeout(this.searchDebounceTimer);
                this.searchDebounceTimer = null;
            }

            if (raw.length < 2) {
                this.remoteResults = [];
                this.searchLoading = false;
                return;
            }

            this.searchLoading = true;
            this.searchDebounceTimer = setTimeout(async () => {
                try {
                    const res = await fetch(`/search/global?q=${encodeURIComponent(raw)}`, {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (res.ok) {
                        const data = await res.json();
                        if ((this.paletteQuery || '').trim() === raw) {
                            const servers = Array.isArray(data.servers) ? data.servers : [];
                            const sites = Array.isArray(data.sites) ? data.sites : [];
                            this.remoteResults = [...servers, ...sites];
                        }
                    }
                } catch {
                    // Non-fatal
                } finally {
                    if ((this.paletteQuery || '').trim() === raw) {
                        this.searchLoading = false;
                    }
                }
            }, 120);
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

            if (normalized.length === 2) {
                const match = this.paletteItemsList.find((i) => i.code && i.code.toUpperCase() === normalized);
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
                const el = document.getElementById(`cw-palette-item-${this.paletteSelectedIndex}`);
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
                    this.remoteResults = [];
                    this.searchLoading = false;
                    this.focusPaletteInput();
                } else {
                    this.remoteResults = [];
                    this.searchLoading = false;
                    if (this.searchDebounceTimer) {
                        clearTimeout(this.searchDebounceTimer);
                        this.searchDebounceTimer = null;
                    }
                }
            });
        },
    };
}
