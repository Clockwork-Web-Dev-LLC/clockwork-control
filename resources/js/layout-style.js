// resources/js/layout-style.js
// Handles client-side layout selection: 'modern' (Clean SaaS Top-Nav) vs 'command-center' (Collapsible Sidebar Rail & Dense HUD).
// Persists preference to localStorage and cookie for zero-FOUC initial renders.

export function layoutStylePicker() {
    return {
        style: 'modern',

        init() {
            try {
                const stored = localStorage.getItem('cw_layout_style');
                if (stored && ['modern', 'command-center'].includes(stored)) {
                    this.style = stored;
                } else {
                    const match = document.cookie.match(/(?:^|; )cw_layout_style=([^;]*)/);
                    if (match && ['modern', 'command-center'].includes(decodeURIComponent(match[1]))) {
                        this.style = decodeURIComponent(match[1]);
                    }
                }
            } catch (e) {}

            this.applyStyle();
        },

        toggleLayoutStyle() {
            this.setLayoutStyle(this.style === 'command-center' ? 'modern' : 'command-center');
        },

        setLayoutStyle(newStyle) {
            if (!['modern', 'command-center'].includes(newStyle)) return;
            this.style = newStyle;
            this.applyStyle();

            try {
                localStorage.setItem('cw_layout_style', newStyle);
                document.cookie = `cw_layout_style=${encodeURIComponent(newStyle)}; path=/; max-age=31536000; SameSite=Lax`;
            } catch (e) {}

            window.dispatchEvent(new CustomEvent('layout-style-changed', {
                detail: { style: newStyle }
            }));
        },

        applyStyle() {
            document.documentElement.setAttribute('data-layout-style', this.style);
        }
    };
}

export function initLayoutStyleSystem(Alpine) {
    window.layoutStylePicker = layoutStylePicker;
    Alpine.data('layoutStylePicker', layoutStylePicker);
}
