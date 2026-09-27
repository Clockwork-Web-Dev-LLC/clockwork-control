export interface DocsSearchResult {
    title: string;
    section: string;
    category?: string;
    excerpt?: string;
    url?: string;
}

export function docsSidebar(initialOpen: Record<string, boolean> = {}) {
    return {
        openSections: initialOpen,
        toggleSection(name: string) {
            this.openSections[name] = !this.openSections[name];
        },
        isSectionOpen(name: string) {
            return !!this.openSections[name];
        },
    };
}

export function docsSearch(index: DocsSearchResult[] = []) {
    return {
        query: '',
        open: false,
        index,
        get results() {
            const q = this.query.trim().toLowerCase();
            if (q.length < 2) return [];
            return this.index
                .filter(
                    (p) =>
                        p.title.toLowerCase().includes(q) ||
                        p.section.toLowerCase().includes(q) ||
                        p.category?.toLowerCase().includes(q) ||
                        p.excerpt?.toLowerCase().includes(q),
                )
                .slice(0, 10);
        },
    };
}
