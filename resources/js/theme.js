// resources/js/theme.js
// Handles client-side theme selection, OS preference resolution, live switching,
// cookie/backend persistence, and ECharts dynamic color updating.

export function themePicker() {
    return {
        // Active preference: 'system' | 'light' | 'dark' | 'high-contrast'
        current: 'system',
        schemes: [
            { key: 'light', label: 'Light', surface: '#ffffff', brand: '#1456f0', ink: '#222222' },
            { key: 'dark', label: 'Dark', surface: '#181e25', brand: '#1456f0', ink: '#e6e8eb' },
            { key: 'high-contrast', label: 'High Contrast', surface: '#000000', brand: '#1456f0', ink: '#ffffff' },
        ],
        saving: false,

        init() {
            // Read active preference from localStorage, cookie, or documentElement
            let storedTheme = null;
            try {
                storedTheme = localStorage.getItem('cw_theme');
            } catch (e) {}

            if (!storedTheme) {
                const cookieMatch = document.cookie.match(/(?:^|; )cw_theme=([^;]*)/);
                if (cookieMatch) {
                    storedTheme = decodeURIComponent(cookieMatch[1]);
                }
            }

            if (storedTheme) {
                this.current = storedTheme === 'midnight' ? 'dark' : storedTheme;
            } else {
                const htmlTheme = document.documentElement.getAttribute('data-theme');
                if (htmlTheme && ['light', 'dark', 'high-contrast'].includes(htmlTheme)) {
                    this.current = htmlTheme;
                } else {
                    this.current = 'system';
                }
            }

            // Listen for OS scheme changes when in system mode
            if (window.matchMedia) {
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                    if (this.current === 'system') {
                        this.applyResolvedTheme('system');
                    }
                });
            }
        },

        get isDark() {
            if (this.current === 'system') {
                return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            }
            return this.current === 'dark' || this.current === 'high-contrast';
        },

        toggleDark() {
            if (this.isDark) {
                this.setTheme('light');
            } else {
                this.setTheme('dark');
            }
        },

        applyResolvedTheme(theme) {
            let resolved = theme;
            if (resolved === 'system') {
                resolved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
                    ? 'dark'
                    : 'light';
            }
            document.documentElement.setAttribute('data-theme', resolved);
            window.dispatchEvent(new CustomEvent('theme-changed', {
                detail: { theme, resolved }
            }));
        },

        async setTheme(theme) {
            this.current = theme;
            this.applyResolvedTheme(theme);

            // Update cookie & localStorage synchronously for immediate future requests
            try {
                localStorage.setItem('cw_theme', theme);
                document.cookie = `cw_theme=${encodeURIComponent(theme)}; path=/; max-age=31536000; SameSite=Lax`;
            } catch (e) {}

            // Persist to server if authenticated
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                || document.querySelector('input[name="_token"]')?.value;

            try {
                this.saving = true;
                await fetch('/settings/appearance', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken || '',
                    },
                    body: JSON.stringify({ theme }),
                });
            } catch (err) {
                // Silently handle offline/guest mode
            } finally {
                this.saving = false;
            }
        },
    };
}

export function initThemeSystem(Alpine) {
    window.themePicker = themePicker;
    Alpine.data('themePicker', themePicker);

    // ECharts theme observer: dynamically updates all rendered charts on theme change
    window.addEventListener('theme-changed', () => {
        if (!window.echarts) return;
        const styles = getComputedStyle(document.documentElement);
        const inkMuted = styles.getPropertyValue('--color-ink-muted').trim() || '#64748b';
        const borderColor = styles.getPropertyValue('--color-border').trim() || '#334155';

        // Query all DOM elements that have echarts instances attached
        const chartElements = document.querySelectorAll('[_echarts_instance_]');
        chartElements.forEach((el) => {
            const chart = window.echarts.getInstanceByDom(el);
            if (!chart) return;

            chart.setOption({
                textStyle: {
                    color: inkMuted,
                },
                legend: {
                    textStyle: { color: inkMuted },
                },
                xAxis: {
                    axisLine: { lineStyle: { color: borderColor } },
                    axisLabel: { color: inkMuted },
                },
                yAxis: {
                    axisLine: { lineStyle: { color: borderColor } },
                    axisLabel: { color: inkMuted },
                    splitLine: { lineStyle: { color: borderColor, opacity: 0.3 } },
                },
            });
        });
    });
}
