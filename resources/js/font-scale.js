// resources/js/font-scale.js
// Handles root font-size scaling across the application (+ / - stepper),
// persisting to localStorage and cookie for zero-FOUC rendering.

export function initFontScaleSystem(Alpine) {
    Alpine.data('fontScaler', () => ({
        scale: 100,
        min: 85,
        max: 125,
        step: 5,

        init() {
            try {
                const stored = localStorage.getItem('cw_font_scale');
                if (stored) {
                    const parsed = parseInt(stored, 10);
                    if (!isNaN(parsed) && parsed >= this.min && parsed <= this.max) {
                        this.scale = parsed;
                    }
                }
            } catch (e) {}

            this.applyScale();
        },

        increase() {
            if (this.scale < this.max) {
                this.setScale(this.scale + this.step);
            }
        },

        decrease() {
            if (this.scale > this.min) {
                this.setScale(this.scale - this.step);
            }
        },

        reset() {
            this.setScale(100);
        },

        setScale(val) {
            this.scale = Math.min(this.max, Math.max(this.min, val));
            this.applyScale();

            try {
                localStorage.setItem('cw_font_scale', this.scale);
                document.cookie = `cw_font_scale=${this.scale}; path=/; max-age=31536000; SameSite=Lax`;
            } catch (e) {}
        },

        applyScale() {
            document.documentElement.style.fontSize = this.scale + '%';
        }
    }));
}
