// resources/js/layout-style.js
// Handles client-side layout selection: 'modern' (Clean SaaS Top-Nav) vs 'command-center' (Collapsible Sidebar Rail & Dense HUD).
// Persists preference to localStorage and cookie for zero-FOUC initial renders.

export function layoutStylePicker() {
    return {
        style: 'modern',
        buttonSquish: false,
        iconSpinning: false,
        isMorphing: false,
        morphDirection: null,
        toastVisible: false,
        toastTimer: null,
        toastData: {
            title: 'Modern Studio',
            tag: 'Top Nav Active',
            icon: 'fa-table-columns',
            color: 'text-[var(--color-brand)]',
            badgeBg: 'bg-[var(--color-brand)]/10 text-[var(--color-brand)] border-[var(--color-brand)]/20',
            mode: 'modern',
        },

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
            // Command Center is a desktop rail. Phones/tablets share one chrome
            // (top bar + hamburger sheet); don't morph or toast on a hidden control.
            if (typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 1023px)').matches) {
                return;
            }
            const nextStyle = this.style === 'command-center' ? 'modern' : 'command-center';
            this.animateLayoutSwitch(nextStyle);
        },

        animateLayoutSwitch(newStyle) {
            if (!['modern', 'command-center'].includes(newStyle) || this.isMorphing) return;

            const direction = newStyle === 'command-center' ? 'to-command-center' : 'to-modern';

            // 1. Trigger playful button jelly squish & icon tumble
            this.buttonSquish = true;
            this.iconSpinning = true;

            setTimeout(() => {
                this.buttonSquish = false;
            }, 450);

            setTimeout(() => {
                this.iconSpinning = false;
            }, 550);

            // 2. Trigger cute micro-toast pill
            this.showLayoutToast(newStyle);

            // 3. Mark morphing state on document and component
            this.isMorphing = true;
            this.morphDirection = direction;
            document.documentElement.setAttribute('data-layout-morphing', direction);

            // 4. Update the layout style attribute
            this.style = newStyle;
            this.applyStyle();

            try {
                localStorage.setItem('cw_layout_style', newStyle);
                document.cookie = `cw_layout_style=${encodeURIComponent(newStyle)}; path=/; max-age=31536000; SameSite=Lax`;
            } catch (e) {}

            window.dispatchEvent(new CustomEvent('layout-style-changed', {
                detail: { style: newStyle }
            }));

            // 5. Clean up morphing classes after spring animation finishes
            setTimeout(() => {
                this.isMorphing = false;
                this.morphDirection = null;
                document.documentElement.removeAttribute('data-layout-morphing');
            }, 520);
        },

        showLayoutToast(targetStyle) {
            if (this.toastTimer) {
                clearTimeout(this.toastTimer);
                this.toastTimer = null;
            }

            if (targetStyle === 'command-center') {
                this.toastData = {
                    title: 'Command Center',
                    tag: 'HUD Active',
                    icon: 'fa-gauge-high',
                    color: 'text-[var(--color-brand)]',
                    badgeBg: 'bg-[var(--color-brand)]/10 text-[var(--color-brand)] border-[var(--color-brand)]/20',
                    mode: 'command-center',
                };
            } else {
                this.toastData = {
                    title: 'Modern Studio',
                    tag: 'Top Nav Active',
                    icon: 'fa-table-columns',
                    color: 'text-[var(--color-brand)]',
                    badgeBg: 'bg-[var(--color-brand)]/10 text-[var(--color-brand)] border-[var(--color-brand)]/20',
                    mode: 'modern',
                };
            }

            this.toastVisible = true;
            this.toastTimer = setTimeout(() => {
                this.toastVisible = false;
            }, 1800);
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
