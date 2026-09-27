export interface ColumnToggleColumn {
    key: string;
    label?: string;
    default?: boolean;
}

export interface ColumnToggleOptions {
    id: string;
    columns: ColumnToggleColumn[];
    initialHidden?: string;
}

/**
 * Reusable column-visibility toggle. Apply via the <x-column-toggle> Blade
 * component, which renders the dropdown UI and provides the column config.
 * Persists per-table preferences in localStorage.
 */
export function columnToggle({ id, columns, initialHidden: _initialHidden }: ColumnToggleOptions) {
    return {
        open: false,
        columns,
        visible: {} as Record<string, boolean>,
        storageKey: `column-toggle:${id}`,
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
        toggle(key: string) {
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
    };
}
