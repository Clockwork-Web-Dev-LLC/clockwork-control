// resources/js/table-scroll.js
//
// Scroll-edge hints for wide tables. Every horizontally scrolling wrapper that holds a
// table (`.overflow-x-auto` or `.cw-table-scroll`) gets:
//   .cw-table-scroll   - shared scroller styles (see app.css "Table scrollers")
//   .is-overflowing    - the table is wider than the wrapper
//   .is-scroll-start   - scrolled fully to the left
//   .is-scroll-end     - scrolled fully to the right
// CSS uses those to fade the clipped edge(s) so it is obvious there is more to see.
//
// Only classes are toggled; no nodes are moved, so Alpine-managed markup is untouched.

const SELECTOR = '.overflow-x-auto, .cw-table-scroll';
const tracked = new WeakSet();

function update(el) {
    const max = el.scrollWidth - el.clientWidth;
    const overflowing = max > 1;
    el.classList.toggle('is-overflowing', overflowing);
    el.classList.toggle('is-scroll-start', !overflowing || el.scrollLeft <= 1);
    el.classList.toggle('is-scroll-end', !overflowing || el.scrollLeft >= max - 1);
}

function track(el, resizeObserver) {
    if (tracked.has(el) || !el.querySelector('table')) {
        return;
    }
    tracked.add(el);
    el.classList.add('cw-table-scroll');
    el.addEventListener('scroll', () => update(el), { passive: true });
    resizeObserver?.observe(el);
    const table = el.querySelector('table');
    if (table) {
        resizeObserver?.observe(table);
    }
    update(el);
}

export function initTableScrollHints() {
    if (typeof document === 'undefined') {
        return;
    }

    const resizeObserver =
        typeof ResizeObserver === 'function'
            ? new ResizeObserver((entries) => {
                  for (const entry of entries) {
                      const el = entry.target.matches(SELECTOR) ? entry.target : entry.target.closest(SELECTOR);
                      if (el) {
                          update(el);
                      }
                  }
              })
            : null;

    const scan = (root) => {
        if (!(root instanceof Element) && root !== document) {
            return;
        }
        if (root instanceof Element && root.matches(SELECTOR)) {
            track(root, resizeObserver);
        }
        for (const el of root.querySelectorAll(SELECTOR)) {
            track(el, resizeObserver);
        }
        // A table rendered later (x-if / x-for) inside an existing wrapper.
        if (root instanceof Element && root.tagName === 'TABLE') {
            const wrapper = root.closest(SELECTOR);
            if (wrapper) {
                track(wrapper, resizeObserver);
            }
        }
    };

    const start = () => {
        scan(document);
        if (typeof MutationObserver === 'function') {
            let pending = false;
            const queue = [];
            new MutationObserver((mutations) => {
                for (const m of mutations) {
                    for (const node of m.addedNodes) {
                        if (node instanceof Element) {
                            queue.push(node);
                        }
                    }
                }
                if (!pending && queue.length) {
                    pending = true;
                    requestAnimationFrame(() => {
                        pending = false;
                        for (const node of queue.splice(0)) {
                            if (node.isConnected) {
                                scan(node);
                            }
                        }
                    });
                }
            }).observe(document.body, { childList: true, subtree: true });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
}
