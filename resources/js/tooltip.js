// resources/js/tooltip.js
// USWDS-style accessible, high-performance floating tooltip manager.
// Renders a single top-oriented floating tooltip with caret pointer for [data-tooltip] elements.

const TOOLTIP_ID = 'cw-global-tooltip';

export function initTooltipSystem() {
    if (typeof document === 'undefined') return;

    let tooltipEl = document.getElementById(TOOLTIP_ID);
    if (!tooltipEl) {
        tooltipEl = document.createElement('div');
        tooltipEl.id = TOOLTIP_ID;
        tooltipEl.setAttribute('role', 'tooltip');
        tooltipEl.setAttribute('aria-hidden', 'true');
        tooltipEl.className = 'cw-tooltip-bubble-global';
        tooltipEl.innerHTML = '<span class="cw-tooltip-text"></span><div class="cw-tooltip-arrow"></div>';
        document.body.appendChild(tooltipEl);
    }

    const textEl = tooltipEl.querySelector('.cw-tooltip-text');
    const arrowEl = tooltipEl.querySelector('.cw-tooltip-arrow');
    let activeTarget = null;

    function getTooltipText(target) {
        if (!target) return null;
        let text = target.getAttribute('data-tooltip');
        if (!text && target.hasAttribute('title')) {
            text = target.getAttribute('title');
            target.setAttribute('data-tooltip', text);
            target.removeAttribute('title'); // Prevent native delayed tooltip collision
        }
        return text ? text.trim() : null;
    }

    function positionTooltip(target) {
        if (!activeTarget || !tooltipEl) return;

        const rect = target.getBoundingClientRect();
        const tooltipWidth = tooltipEl.offsetWidth;
        const tooltipHeight = tooltipEl.offsetHeight;
        const spacing = 7; // Distance between trigger and arrow point

        // Center horizontally over the trigger element
        const targetCenter = rect.left + (rect.width / 2);
        let left = targetCenter - (tooltipWidth / 2);

        // Clamp inside the viewport with 8px buffer
        const minLeft = 8;
        const maxLeft = window.innerWidth - tooltipWidth - 8;
        const clampedLeft = Math.max(minLeft, Math.min(maxLeft, left));

        // Position arrow directly over the target center
        const arrowLeft = targetCenter - clampedLeft;

        // Default: Top position (USWDS Top Tooltip)
        let top = rect.top - tooltipHeight - spacing;
        let isFlipped = false;

        // If clipped off top of viewport, flip to bottom
        if (top < 8) {
            top = rect.bottom + spacing;
            isFlipped = true;
        }

        tooltipEl.style.left = `${clampedLeft + window.scrollX}px`;
        tooltipEl.style.top = `${top + window.scrollY}px`;

        if (arrowEl) {
            arrowEl.style.left = `${Math.max(8, Math.min(tooltipWidth - 8, arrowLeft))}px`;
        }

        if (isFlipped) {
            tooltipEl.classList.add('is-bottom');
        } else {
            tooltipEl.classList.remove('is-bottom');
        }
    }

    function isStillInside(related) {
        return related instanceof Node
            && (activeTarget.contains(related) || tooltipEl.contains(related));
    }

    function showTooltip(target) {
        const text = getTooltipText(target);
        if (!text) return;

        if (activeTarget && activeTarget !== target) {
            activeTarget.removeAttribute('aria-describedby');
        }

        activeTarget = target;
        target.setAttribute('aria-describedby', TOOLTIP_ID);
        textEl.textContent = text;
        tooltipEl.setAttribute('aria-hidden', 'false');
        tooltipEl.classList.add('is-visible');

        positionTooltip(target);
    }

    function hideTooltip() {
        if (!activeTarget) return;
        activeTarget.removeAttribute('aria-describedby');
        activeTarget = null;
        tooltipEl.classList.remove('is-visible');
        tooltipEl.setAttribute('aria-hidden', 'true');
    }

    // Event delegation on document so dynamic items/rows work seamlessly
    document.addEventListener('mouseover', (e) => {
        const target = e.target.closest('[data-tooltip]');
        if (target) {
            showTooltip(target);
        }
    });

    document.addEventListener('mouseout', (e) => {
        if (!activeTarget) return;
        if (isStillInside(e.relatedTarget)) return;
        hideTooltip();
    });

    document.addEventListener('focusin', (e) => {
        const target = e.target.closest('[data-tooltip]');
        if (target) {
            showTooltip(target);
        }
    });

    document.addEventListener('focusout', (e) => {
        if (!activeTarget) return;
        if (isStillInside(e.relatedTarget)) return;
        hideTooltip();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && activeTarget) {
            hideTooltip();
        }
    });

    window.addEventListener('scroll', () => {
        if (activeTarget) {
            positionTooltip(activeTarget);
        }
    }, { passive: true });

    window.addEventListener('resize', () => {
        if (activeTarget) {
            positionTooltip(activeTarget);
        }
    }, { passive: true });
}
