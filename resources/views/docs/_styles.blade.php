<style>
.docs-shell {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 32px;
    align-items: start;
}
@media (max-width: 768px) {
    .docs-shell { grid-template-columns: 1fr; }
}
.docs-sidebar {
    position: sticky;
    top: 80px;
    max-height: calc(100vh - 100px);
    overflow-y: auto;
    border-right: 1px solid var(--color-border-light);
    padding-right: 16px;
}
.docs-sidebar__home {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: var(--color-ink-strong);
    text-decoration: none;
    margin-bottom: 16px;
    font-size: 13px;
}
.docs-sidebar__home:hover { color: var(--color-primary-600); }

.docs-sidebar__search { position: relative; margin-bottom: 18px; }
.docs-sidebar__search-input-wrap { position: relative; }
.docs-sidebar__search-icon {
    position: absolute;
    left: 9px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 11px;
    color: var(--color-ink-soft, var(--color-ink-muted));
    pointer-events: none;
}
.docs-sidebar__search-input {
    width: 100%;
    box-sizing: border-box;
    padding: 6px 10px 6px 28px;
    font-size: 13px;
    border: 1px solid var(--color-border-light);
    border-radius: 6px;
    background: var(--color-surface, #fff);
    color: var(--color-ink-strong);
}
.docs-sidebar__search-input:focus {
    outline: none;
    border-color: var(--color-primary-600);
}
.docs-sidebar__search-results {
    position: absolute;
    z-index: 20;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    max-height: 320px;
    overflow-y: auto;
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border-light);
    border-radius: 6px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
    padding: 4px;
}
.docs-sidebar__search-empty {
    padding: 10px;
    font-size: 12px;
    color: var(--color-ink-muted);
}
.docs-sidebar__search-result {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
    padding: 6px 8px;
    border-radius: 4px;
    text-decoration: none;
    font-size: 13px;
}
.docs-sidebar__search-result:hover {
    background: var(--color-surface-alt);
}
.docs-sidebar__search-result-title {
    color: var(--color-ink-strong);
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.docs-sidebar__search-result-section {
    color: var(--color-ink-muted);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    flex-shrink: 0;
}

.docs-sidebar__section { margin-bottom: 10px; }
.docs-sidebar__section-title {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--color-ink-muted);
    margin: 0 0 6px;
    font-weight: 600;
}
.docs-sidebar__category-title {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--color-ink-soft, var(--color-ink-muted));
    padding: 6px 8px 3px 8px;
    margin-top: 6px;
    border-bottom: 1px dashed var(--color-border-light);
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.docs-sidebar__list { list-style: none; padding: 0; margin: 0; }
.docs-sidebar__link {
    display: block;
    padding: 4px 8px;
    border-radius: 4px;
    color: var(--color-ink-muted);
    text-decoration: none;
    font-size: 12.5px;
    line-height: 1.4;
    transition: all 0.15s ease;
}
.docs-sidebar__link:hover {
    color: var(--color-ink-strong);
    background: var(--color-surface-alt);
}
.docs-sidebar__link--active {
    color: var(--color-primary-600);
    background: color-mix(in srgb, var(--color-primary-500) 12%, var(--color-surface));
    font-weight: 600;
}

.docs-prose {
    max-width: 760px;
    color: var(--color-ink-strong);
    line-height: 1.65;
}
.docs-prose h1 { font-size: 24px; font-weight: 700; margin: 28px 0 12px; }
.docs-prose h2 { font-size: 20px; font-weight: 600; margin: 28px 0 10px; padding-top: 8px; border-top: 1px solid var(--color-border-light); }
.docs-prose h2:first-child { border-top: none; padding-top: 0; }
.docs-prose h3 { font-size: 16px; font-weight: 600; margin: 20px 0 8px; }
.docs-prose p { margin: 0 0 14px; }
.docs-prose ul, .docs-prose ol { margin: 0 0 14px 24px; }
.docs-prose li { margin-bottom: 4px; }
.docs-prose a { color: var(--color-primary-600); text-decoration: underline; }
.docs-prose a:hover { color: var(--color-primary-700); }
.docs-prose code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.9em;
    background: var(--color-surface-alt);
    padding: 1px 5px;
    border-radius: 3px;
}
.docs-prose pre {
    background: #0f172a;
    color: #e2e8f0;
    padding: 12px 16px;
    border-radius: 6px;
    overflow-x: auto;
    margin: 0 0 16px;
    font-size: 13px;
}
.docs-prose pre code {
    background: transparent;
    padding: 0;
    color: inherit;
    font-size: inherit;
}
.docs-prose blockquote {
    border-left: 4px solid var(--color-primary-500);
    padding: 8px 14px;
    background: var(--color-surface-alt);
    margin: 0 0 14px;
    color: var(--color-ink-soft);
    font-style: italic;
}
.docs-prose table {
    width: 100%;
    border-collapse: collapse;
    margin: 0 0 16px;
    font-size: 13px;
}
.docs-prose th, .docs-prose td {
    text-align: left;
    padding: 6px 10px;
    border-bottom: 1px solid var(--color-border-light);
}
.docs-prose th {
    font-weight: 600;
    background: var(--color-surface-alt);
}
.docs-prose hr {
    border: 0;
    border-top: 1px solid var(--color-border-light);
    margin: 20px 0;
}
.docs-prose img { max-width: 100%; border-radius: 6px; }
</style>
