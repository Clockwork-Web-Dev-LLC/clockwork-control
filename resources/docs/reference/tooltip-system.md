---
title: Tooltip System
section: Reference
order: 26
updated: 2026-09-10
author: Aaron Reimann
tags: [reference, frontend, ui, components, tooltips, uswds]
tracks: [resources/js/tooltip.js, resources/js/app.js, resources/css/app.css, resources/views/dashboard/sites.blade.php]
---

Clockwork Control features a centralized, accessible floating tooltip system modeled on the [U.S. Web Design System (USWDS) "Top" Tooltip Component](https://designsystem.digital.gov/components/tooltip/). It provides instant micro-feedback for status pills, action buttons, and icons without causing layout shifts, DOM bloat, or clipping issues inside containers with `overflow: hidden`.

## How to Use Anywhere in the App

To add a tooltip to any element across Blade templates, simply add the `data-tooltip` attribute:

```html
<!-- On an icon or status pill -->
<span class="status-pill status-green" data-tooltip="Uptime Online">
    <i class="fa-solid fa-circle-check"></i>
</span>

<!-- On an action button -->
<button type="button" class="btn btn-secondary" data-tooltip="Refresh Metrics">
    <i class="fa-solid fa-rotate"></i>
</button>

<!-- On server/provider indicators -->
<span class="text-xs font-data" data-tooltip="Server: srv-web-01">
    <i class="fa-solid fa-server"></i> srv-web-01
</span>
```

### Best Practices: Keep it Brief

In accordance with USWDS and accessibility guidelines:
- **Be concise**: Keep tooltips to 2–4 words explaining the meaning of the icon or action (e.g., `Uptime Online`, `SSL Valid`, `Companion Plugin Active`, `Care Plan Active`).
- **Do not duplicate visible text**: Tooltips should clarify ambiguous symbols, abbreviations, or truncated values, not restate obvious text labels.
- **Normal cursor**: Avoid `cursor: help` (which renders a confusing question mark cursor in macOS/WebKit); stick to `cursor: default` or `cursor: pointer`.

---

## Architectural Design

### 1. Global Singleton Container (`#cw-global-tooltip`)
Rather than injecting DOM elements into every table row or list item, `initTooltipSystem()` in [`resources/js/tooltip.js`](file:///Users/aaronr/Development/clockwork-control/resources/js/tooltip.js) maintains a single floating tooltip element attached directly to `document.body`:

```html
<div id="cw-global-tooltip" class="cw-tooltip-bubble-global" role="tooltip" aria-hidden="true">
    <span class="cw-tooltip-text"></span>
    <div class="cw-tooltip-arrow"></div>
</div>
```

**Benefits**:
- **Zero Overflow Clipping**: Table rows and cards with `overflow: hidden` (like `#sites-list-card`) will never clip the floating tooltip.
- **Zero DOM Bloat**: In large datasets (e.g. 200+ site rows with 5 icons each), there are no 1,000 extra hidden tooltip divs polluting the DOM tree.
- **Single Source of Truth**: Only one tooltip is visible at any given time.

### 2. Positioning & Viewport Collision Detection
- **Preferred Placement ("Top")**: The tooltip is horizontally centered directly above the trigger element with a 7px vertical offset.
- **Pointer Caret**: A CSS triangle arrow (`.cw-tooltip-arrow`) points directly down toward the center of the trigger element.
- **Horizontal Clamping**: The tooltip bubble is clamped within viewport boundaries with an 8px safety margin, preventing horizontal page overflow on small screens.
- **Automatic Vertical Flipping**: If the target element is near the top edge of the browser window (`top < 8px`), the tooltip automatically flips to the bottom (`.is-bottom`), and the arrow repositions to point upward.

### 3. Event Delegation & Dynamic Lifecycle
All tooltip events are delegated to the `document` level:
- `mouseover` / `mouseout` for mouse interactions. Hide uses `relatedTarget` plus `activeTarget.contains()` / `tooltipEl.contains()` so moving from an inner icon to its `[data-tooltip]` parent does not flicker the bubble closed.
- `focusin` / `focusout` for keyboard navigation (same related-target check).
- While visible, the trigger gets `aria-describedby="cw-global-tooltip"` (removed on hide). Keyboard users are not given extra `tabindex` on every icon.
- `keydown (Escape)` immediately dismisses the active tooltip.
- `scroll` and `resize` dynamically recalculate position.

Because of document-level delegation, **new elements added dynamically** (e.g., via Alpine.js loops, AJAX live searches, or modal popups) work automatically without needing manual listener registration.

### 4. Styles & Theme Integration (`resources/css/app.css`)
- Charcoal dark background (`#181e25`) with pure white text (`#ffffff`) for maximum AAA contrast in both light and dark modes.
- Micro-transitions: `120ms cubic-bezier(0.16, 1, 0.3, 1)` opacity and translateY animation for a snappy, native feel.
- Compiled as part of the primary production bundle via Vite (`npm run build`).
