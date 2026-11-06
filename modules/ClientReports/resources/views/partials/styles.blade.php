{{-- A few module-only styles on top of the app's palette (status-* pills,
     CSS variables), so they follow light/dark like everything else. In the
     components layer, so Tailwind utilities and the status-* classes still
     win over them. --}}
<style>
@layer components {
    .cr-pill-blue { background: var(--color-primary-50); color: var(--color-primary-700); }
    :root[data-theme="dark"] .cr-pill-blue, :root[data-theme="high-contrast"] .cr-pill-blue { color: var(--color-primary-light); }
    .cr-pill-purple { background: rgba(124, 58, 237, .1); color: #6d28d9; }
    :root[data-theme="dark"] .cr-pill-purple, :root[data-theme="high-contrast"] .cr-pill-purple { background: rgba(139, 92, 246, .18); color: #c4b5fd; }
    .cr-chip {
        display: inline-flex; align-items: center; gap: 4px; padding: 1px 7px; border-radius: 9999px;
        font-size: 10px; font-weight: 600; line-height: 1.6; white-space: nowrap;
        background: var(--color-surface-alt); color: var(--color-ink-muted); border: 1px solid var(--color-border);
    }
    .cr-chip i { font-size: 9px; opacity: .8; }
    .cr-pill { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 9999px; font-size: 11px; font-weight: 600; line-height: 1.5; white-space: nowrap; }
    .cr-pill i { font-size: 9px; }

    /* History strip: one solid cell per month, so the state reads at a glance. */
    .cr-cell {
        display: inline-flex; align-items: center; justify-content: center; width: 2.4rem; height: 1.6rem;
        border-radius: 6px; font-size: 10px; font-weight: 700; line-height: 1; white-space: nowrap;
        border: 1px solid transparent; transition: transform .1s ease, box-shadow .1s ease; color: #fff;
    }
    a.cr-cell:hover { transform: translateY(-1px); box-shadow: var(--shadow-card); }
    .cr-cell-sent { background: var(--color-status-green); }
    .cr-cell-needs_review { background: var(--color-status-orange); }
    .cr-cell-outdated { background: #7c3aed; }
    :root[data-theme="dark"] .cr-cell-outdated, :root[data-theme="high-contrast"] .cr-cell-outdated { background: #8b5cf6; }
    .cr-cell-partly_sent { background: repeating-linear-gradient(135deg, var(--color-status-green) 0 6px, var(--color-status-yellow) 6px 12px); }
    .cr-cell-ready { background: var(--color-primary-600); }
    .cr-cell-superseded { background: var(--color-status-unknown); }
    .cr-cell-none { border: 1px dashed var(--color-border); color: var(--color-ink-soft); background: transparent; font-weight: 500; }
    .cr-cell-skipped { background: var(--color-status-unknown-bg); color: var(--color-status-unknown); text-decoration: line-through; font-weight: 500; }

    .cr-input {
        font-size: 12px; padding: 6px 10px; border-radius: 10px; border: 1px solid var(--color-border);
        background: var(--color-surface); color: var(--color-ink-strong);
    }
    .cr-input:focus { outline: none; border-color: var(--color-brand); }
    .cr-btn {
        display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 9999px;
        font-size: 11px; font-weight: 600; line-height: 1.5; white-space: nowrap; cursor: pointer;
        background: var(--color-nav-bg); color: var(--color-ink-strong); border: 1px solid transparent;
        transition: background .15s ease, color .15s ease;
    }
    .cr-btn:hover { background: var(--color-nav-hover); }
    .cr-btn i { font-size: 10px; }
    .cr-btn-primary { background: var(--color-btn-primary-bg); color: var(--color-btn-primary-ink); }
    .cr-btn-primary:hover { background: var(--color-btn-primary-hover); }
    .cr-btn[disabled], .cr-btn.is-disabled { opacity: .45; cursor: not-allowed; }
    .cr-btn-send { color: var(--color-primary-700); }
    :root[data-theme="dark"] .cr-btn-send, :root[data-theme="high-contrast"] .cr-btn-send { color: var(--color-primary-light); }
    .cr-filter-link { display: block; border-radius: 12px; padding: 10px 12px; border: 1px solid var(--color-border-light); background: var(--color-surface); transition: border-color .15s ease; }
    .cr-filter-link:hover { border-color: var(--color-border); }
    .cr-filter-link.is-active { border-color: var(--color-ink-strong); }
    .cr-count { display: inline-flex; align-items: center; justify-content: center; min-width: 1.25rem; height: 1.25rem; padding: 0 5px; border-radius: 9999px; font-size: 10px; font-weight: 700; }
}
</style>
