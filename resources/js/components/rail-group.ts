/**
 * Collapsible group in the Command Center rail (Operations / Configuration / Modules).
 *
 * Collapsed by default. A group holding the current page opens itself so the active
 * item is never hidden. The operator's own open/close choice is remembered per group
 * in localStorage (wrapped in try/catch — storage can be blocked).
 *
 * Collapsing only applies to the expanded rail: when the rail is shrunk to icons
 * the markup ignores `open` so every icon stays reachable.
 */
export function railGroup(key: string, containsActive: boolean): Record<string, any> {
    const storageKey = `cw_rail_group_${key}`;

    return {
        open: containsActive,
        init() {
            if (containsActive) {
                return;
            }
            try {
                this.open = localStorage.getItem(storageKey) === 'true';
            } catch (_e) {}
        },
        toggleGroup() {
            this.open = !this.open;
            try {
                localStorage.setItem(storageKey, String(this.open));
            } catch (_e) {}
        },
    };
}
