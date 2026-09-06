---
title: Theme System
section: Reference
order: 25
updated: 2026-09-05
author: Aaron Reimann
tags: [reference, frontend, css, themes, ui]
tracks: [resources/css/app.css, resources/js/theme.js, app/Http/Controllers/AppearanceSettingsController.php]
---

Clockwork Control features a multi-scheme theme system built on Tailwind CSS v4 custom property blocks and Alpine.js. Rather than a binary dark/light toggle, the control panel supports named curated palettes with seamless switching, zero flash of unstyled content (FOUC), and dynamic chart re-theming.

## Available Color Schemes

1. **Light (`light`)**: The canonical clean white surface palette (`#ffffff`), tailored ink contrasts, and subtle borders.
2. **Dark (`dark`)**: Built on the native `--color-surface-dark` token (`#181e25`) with deep slate tones and soft inverted text.
3. **Midnight (`midnight`)**: A rich navy / Darcula palette with `#0f172a` deep-slate surface, electric brand accents, and muted ink.
4. **High Contrast (`high-contrast`)**: An accessibility-first high visibility palette utilizing pure `#000000` surface with maximum contrast borders and white ink.
5. **Auto / System (`system`)**: Follows the client operating system's `prefers-color-scheme` media query, automatically switching between light and dark.

## Architecture & How It Works

### 1. CSS Custom Property Palettes (`resources/css/app.css`)

All semantic design tokens are registered in Tailwind v4's `@theme` directive, making utility classes such as `bg-[var(--color-surface)]`, `text-[var(--color-ink)]`, and `border-[var(--color-border-light)]` immediately available.

Each named palette is defined as a scoped selector block on the root element:

```css
:root,
:root[data-theme="light"] {
    --color-surface: #ffffff;
    --color-surface-alt: #f4f5f7;
    --color-border: #e5e7eb;
    --color-border-light: #f2f3f5;
    --color-ink: #222222;
    --color-ink-strong: #18181b;
    --color-ink-muted: #45515e;
    ...
}

:root[data-theme="dark"] {
    --color-surface: #181e25;
    --color-surface-alt: #1e2630;
    --color-border: #333e4e;
    --color-border-light: #252e3a;
    --color-ink: #e6e8eb;
    --color-ink-strong: #f8fafc;
    --color-ink-muted: #94a3b8;
    ...
}
```

Components (`.card`, `.btn-pill-nav`, `.status-pill`, `.cw-switch`) reference these variables directly, instantly adapting to whatever `data-theme` attribute is active on `<html>`.

### 2. Zero-FOUC Head Script (`layouts/app.blade.php`)

To eliminate flashes of incorrect theme before stylesheets and scripts hydrate, an inline blocking script sits in `<head>` prior to the `@vite` compiled CSS bundle:

```html
<script>
    (function () {
        try {
            var match = document.cookie.match(/(?:^|; )cw_theme=([^;]*)/);
            var theme = match ? decodeURIComponent(match[1]) : 'system';
            var resolved = theme;
            if (!resolved || resolved === 'system') {
                resolved = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', resolved);
        } catch (e) {}
    })();
</script>
```

### 3. Persistence Flow

1. When a user selects a scheme in the gear dropdown, the Alpine component `themePicker` immediately sets `data-theme` on `document.documentElement` and dispatches a `theme-changed` custom event.
2. It synchronously sets the `cw_theme` 1-year cookie via `document.cookie` (`SameSite=Lax`), ensuring sub-requests and browser navigation render without delay.
3. It asynchronously sends a `POST /settings/appearance` request to `AppearanceSettingsController`. If authenticated, the preference is saved to `users.theme` in the database.

### 4. Dynamic ECharts Re-Theming

ECharts render via HTML `<canvas>` elements and do not automatically inherit CSS variable changes. `resources/js/theme.js` listens to `theme-changed` window events, resolves current computed colors, and invokes `chart.setOption()` on all active chart instances to update text, axis lines, and grid styling live.

## Adding a New Named Scheme

To add a new theme (e.g. `forest` or `nord`):

1. Open `resources/css/app.css` and add an override block:
   ```css
   :root[data-theme="nord"] {
       --color-surface: #2e3440;
       --color-surface-alt: #3b4252;
       --color-border: #4c566a;
       --color-border-light: #434c5e;
       --color-ink: #eceff4;
       --color-ink-strong: #ffffff;
       --color-ink-muted: #d8dee9;
       ...
   }
   ```
2. Add the scheme key to `App\Http\Controllers\AppearanceSettingsController::VALID_THEMES`.
3. Add the scheme descriptor to `schemes` array in `resources/js/theme.js` so the swatch thumbnail renders in the gear menu.
