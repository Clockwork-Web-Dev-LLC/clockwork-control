<style>
:root {
    --docs-sidebar-w: 268px;
    --docs-header-offset: 7.75rem;
    --docs-code-bg: #0b1120;
    --docs-code-text: #e2e8f0;
    --docs-code-border: rgba(255, 255, 255, 0.08);
    --docs-code-header-bg: #070b14;
    --docs-table-stripe: rgba(0, 0, 0, 0.015);
}

[data-layout-style="modern"] {
    --docs-header-offset: 7.75rem;
}

[data-layout-style="command-center"] {
    --docs-header-offset: 4.75rem;
}

:root[data-theme="dark"],
:root[data-theme="midnight"] {
    --docs-code-bg: #0d121c;
    --docs-code-text: #f1f5f9;
    --docs-code-border: var(--color-border);
    --docs-code-header-bg: #07090e;
    --docs-table-stripe: rgba(255, 255, 255, 0.02);
}

:root[data-theme="high-contrast"] {
    --docs-code-bg: #000000;
    --docs-code-text: #ffffff;
    --docs-code-border: #ffffff;
    --docs-code-header-bg: #000000;
    --docs-table-stripe: transparent;
}

/* ==========================================================================
   Documentation Shell Layout
   ========================================================================== */
.docs-shell {
    display: grid;
    grid-template-columns: var(--docs-sidebar-w) minmax(0, 1fr);
    gap: 2rem;
    align-items: start;
    width: 100%;
}

@media (max-width: 960px) {
    .docs-shell {
        grid-template-columns: minmax(0, 1fr);
        gap: 1.5rem;
    }
}

.docs-main {
    min-width: 0;
}

/* ==========================================================================
   Sticky Sidebar & Interactive Navigation
   ========================================================================== */
.docs-sidebar {
    position: sticky;
    top: var(--docs-header-offset);
    max-height: calc(100vh - var(--docs-header-offset) - 1.5rem);
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: 0.5rem;
    scrollbar-width: thin;
    scrollbar-color: var(--color-border) transparent;
}

.docs-sidebar::-webkit-scrollbar {
    width: 4px;
}
.docs-sidebar::-webkit-scrollbar-track {
    background: transparent;
}
.docs-sidebar::-webkit-scrollbar-thumb {
    background: var(--color-border);
    border-radius: 4px;
}

.docs-sidebar__inner {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.docs-sidebar__home {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.55rem 0.75rem;
    border-radius: 0.5rem;
    font-weight: 600;
    color: var(--color-ink-strong);
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    text-decoration: none;
    font-size: 0.8125rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
    transition: all 0.15s ease;
}

.docs-sidebar__home:hover {
    color: var(--color-brand);
    border-color: var(--color-border);
    background: var(--color-surface-alt);
    transform: translateY(-1px);
}

/* ==========================================================================
   Interactive Search Bar & Instant Autocomplete Dropdown
   ========================================================================== */
.docs-sidebar__search {
    position: relative;
    margin-bottom: 0.875rem;
}

.docs-sidebar__search-input-wrap {
    position: relative;
}

.docs-sidebar__search-icon {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.75rem;
    color: var(--color-ink-soft);
    pointer-events: none;
}

.docs-sidebar__search-input {
    width: 100%;
    box-sizing: border-box;
    padding: 0.5rem 2rem 0.5rem 2.125rem;
    font-size: 0.8125rem;
    border: 1px solid var(--color-border);
    border-radius: 0.5rem;
    background: var(--color-surface);
    color: var(--color-ink-strong);
    transition: all 0.15s ease;
}

.docs-sidebar__search-input:focus {
    outline: none;
    border-color: var(--color-brand);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-brand) 15%, transparent);
}

.docs-sidebar__search-results {
    position: absolute;
    z-index: 40;
    top: calc(100% + 0.35rem);
    left: 0;
    right: 0;
    max-height: 22rem;
    overflow-y: auto;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 0.625rem;
    box-shadow: 0 16px 36px -4px rgba(0, 0, 0, 0.2), 0 4px 12px rgba(0, 0, 0, 0.08);
    backdrop-filter: blur(12px);
    padding: 0.375rem;
}

.docs-sidebar__search-empty {
    padding: 0.875rem 0.75rem;
    font-size: 0.75rem;
    color: var(--color-ink-muted);
    text-align: center;
}

.docs-sidebar__search-result {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.5rem 0.625rem;
    border-radius: 0.375rem;
    text-decoration: none;
    font-size: 0.8125rem;
    transition: background 0.12s ease;
}

.docs-sidebar__search-result:hover {
    background: var(--color-surface-alt);
}

.docs-sidebar__search-result-title {
    color: var(--color-ink-strong);
    font-weight: 600;
    line-height: 1.35;
}

.docs-sidebar__search-result-section {
    color: var(--color-ink-soft);
    font-size: 0.625rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    background: var(--color-surface-alt);
    border: 1px solid var(--color-border-light);
    flex-shrink: 0;
    font-family: var(--font-data);
}

/* ==========================================================================
   Accordion Category Cards & Navigation Links
   ========================================================================== */
.docs-sidebar__section {
    margin-bottom: 0.5rem;
    border-radius: 0.625rem;
    border: 1px solid var(--color-border-light);
    background: var(--color-surface);
    overflow: hidden;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.docs-sidebar__section:hover {
    border-color: var(--color-border);
}

.docs-sidebar__category-title {
    font-size: 0.625rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--color-ink-soft);
    padding: 0.45rem 0.5rem 0.25rem 0.5rem;
    margin-top: 0.5rem;
    border-bottom: 1px dashed var(--color-border-light);
    margin-bottom: 0.35rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.docs-sidebar__list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.docs-sidebar__link {
    display: flex;
    align-items: center;
    padding: 0.35rem 0.625rem;
    border-radius: 0.375rem;
    color: var(--color-ink-muted);
    text-decoration: none;
    font-size: 0.78125rem;
    line-height: 1.45;
    position: relative;
    transition: all 0.12s ease;
}

.docs-sidebar__link:hover {
    color: var(--color-ink-strong);
    background: var(--color-surface-alt);
}

.docs-sidebar__link--active {
    color: var(--color-brand);
    background: color-mix(in srgb, var(--color-brand) 10%, var(--color-surface));
    font-weight: 600;
    box-shadow: inset 2px 0 0 0 var(--color-brand);
}

:root[data-theme="dark"] .docs-sidebar__link--active,
:root[data-theme="midnight"] .docs-sidebar__link--active {
    background: rgba(56, 189, 248, 0.12);
    color: #38bdf8;
    box-shadow: inset 2px 0 0 0 #38bdf8;
}

:root[data-theme="high-contrast"] .docs-sidebar__link--active {
    background: #ffffff;
    color: #000000;
    box-shadow: none;
}

/* ==========================================================================
   Documentation Prose Typography (Markdown Article)
   ========================================================================== */
.docs-prose {
    max-width: 820px;
    color: var(--color-ink);
    font-family: var(--font-sans);
    font-size: 0.9375rem;
    line-height: 1.7;
}

.docs-prose h1 {
    font-family: var(--font-display);
    font-size: 1.875rem;
    font-weight: 700;
    letter-spacing: -0.025em;
    color: var(--color-ink-strong);
    margin: 2rem 0 1rem;
    line-height: 1.25;
}

.docs-prose h2 {
    font-family: var(--font-display);
    font-size: 1.375rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--color-ink-strong);
    margin: 2.5rem 0 0.875rem;
    padding-top: 1.125rem;
    border-top: 1px solid var(--color-border-light);
    line-height: 1.35;
}

.docs-prose h2:first-child {
    border-top: none;
    padding-top: 0;
    margin-top: 0;
}

.docs-prose h3 {
    font-family: var(--font-display);
    font-size: 1.125rem;
    font-weight: 600;
    letter-spacing: -0.01em;
    color: var(--color-ink-strong);
    margin: 1.75rem 0 0.625rem;
    line-height: 1.4;
}

.docs-prose h4 {
    font-family: var(--font-display);
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--color-ink-strong);
    margin: 1.25rem 0 0.5rem;
}

.docs-prose p {
    margin: 0 0 1.15rem;
}

.docs-prose ul,
.docs-prose ol {
    margin: 0 0 1.25rem 1.5rem;
    padding: 0;
}

.docs-prose li {
    margin-bottom: 0.35rem;
    line-height: 1.6;
}

.docs-prose a {
    color: var(--color-primary-600);
    text-decoration: underline;
    text-decoration-thickness: 1px;
    text-underline-offset: 2.5px;
    transition: color 0.12s ease;
}

:root[data-theme="dark"] .docs-prose a,
:root[data-theme="midnight"] .docs-prose a {
    color: var(--color-primary-500);
}

.docs-prose a:hover {
    color: var(--color-primary-700);
}

:root[data-theme="dark"] .docs-prose a:hover,
:root[data-theme="midnight"] .docs-prose a:hover {
    color: var(--color-primary-light);
}

.docs-prose code {
    font-family: var(--font-data), ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.85em;
    background: var(--color-surface-alt);
    border: 1px solid var(--color-border-light);
    color: var(--color-ink-strong);
    padding: 0.15em 0.42em;
    border-radius: 0.35rem;
    font-weight: 500;
}

/* Elevated Code Blocks with Copy Feature */
.docs-prose pre {
    position: relative;
    background: var(--docs-code-bg);
    color: var(--docs-code-text);
    border: 1px solid var(--docs-code-border);
    padding: 1.125rem 1.25rem;
    border-radius: 0.625rem;
    overflow-x: auto;
    margin: 0 0 1.5rem;
    font-size: 0.8125rem;
    line-height: 1.6;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.docs-prose pre code {
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    color: inherit !important;
    font-size: inherit !important;
    font-weight: normal;
    display: block;
}

.docs-copy-button {
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.5rem;
    font-size: 0.6875rem;
    font-family: var(--font-sans);
    font-weight: 500;
    background: rgba(255, 255, 255, 0.12);
    color: #cbd5e1;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 0.375rem;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.15s ease, background 0.15s ease, color 0.15s ease;
    user-select: none;
    z-index: 5;
}

.docs-prose pre:hover .docs-copy-button {
    opacity: 1;
}

.docs-copy-button:hover {
    background: rgba(255, 255, 255, 0.22);
    color: #ffffff;
}

/* Blockquotes / Callouts */
.docs-prose blockquote {
    border-left: 3px solid var(--color-brand);
    padding: 0.75rem 1.125rem;
    background: color-mix(in srgb, var(--color-brand) 6%, var(--color-surface-alt));
    margin: 0 0 1.25rem;
    color: var(--color-ink);
    border-radius: 0 0.5rem 0.5rem 0;
    font-size: 0.875rem;
    line-height: 1.6;
}

.docs-prose blockquote p:last-child {
    margin-bottom: 0;
}

/* Markdown Tables */
.docs-prose table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    margin: 1.25rem 0 1.5rem;
    font-size: 0.8125rem;
    border: 1px solid var(--color-border);
    border-radius: 0.5rem;
    overflow: hidden;
}

.docs-prose th,
.docs-prose td {
    text-align: left;
    padding: 0.625rem 0.875rem;
    border-bottom: 1px solid var(--color-border-light);
    vertical-align: top;
}

.docs-prose tr:last-child td {
    border-bottom: none;
}

.docs-prose th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.6875rem;
    letter-spacing: 0.05em;
    background: var(--color-surface-alt);
    color: var(--color-ink-muted);
    border-bottom: 1px solid var(--color-border);
}

.docs-prose tbody tr:nth-child(even) {
    background: var(--docs-table-stripe);
}

.docs-prose tbody tr:hover td {
    background: var(--color-surface-alt);
}

.docs-prose hr {
    border: 0;
    border-top: 1px solid var(--color-border);
    margin: 2rem 0;
}

.docs-prose img {
    max-width: 100%;
    border-radius: 0.5rem;
    border: 1px solid var(--color-border-light);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

/* Mobile Quick Bar */
.docs-mobile-nav {
    display: none;
}

@media (max-width: 960px) {
    .docs-mobile-nav {
        display: block;
        margin-bottom: 1rem;
    }
}
</style>

<script>
    // Copy Code Button for documentation pre blocks
    document.addEventListener('DOMContentLoaded', function() {
        function attachCodeCopyButtons() {
            document.querySelectorAll('.docs-prose pre').forEach(function(pre) {
                if (pre.querySelector('.docs-copy-button')) return;
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'docs-copy-button';
                btn.innerHTML = '<i class="fa-regular fa-copy"></i><span>Copy</span>';
                btn.setAttribute('aria-label', 'Copy code snippet to clipboard');
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var code = pre.querySelector('code');
                    var text = code ? code.innerText : pre.innerText;
                    // Strip the button text if accidentally caught
                    text = text.replace(/^Copy|^Copied!/g, '').trim();
                    navigator.clipboard.writeText(text).then(function() {
                        btn.innerHTML = '<i class="fa-solid fa-check text-emerald-400"></i><span>Copied!</span>';
                        setTimeout(function() {
                            btn.innerHTML = '<i class="fa-regular fa-copy"></i><span>Copy</span>';
                        }, 2000);
                    }).catch(function() {
                        btn.innerHTML = '<i class="fa-solid fa-check text-emerald-400"></i><span>Copied!</span>';
                        setTimeout(function() {
                            btn.innerHTML = '<i class="fa-regular fa-copy"></i><span>Copy</span>';
                        }, 2000);
                    });
                });
                pre.appendChild(btn);
            });
        }
        attachCodeCopyButtons();
    });
</script>
