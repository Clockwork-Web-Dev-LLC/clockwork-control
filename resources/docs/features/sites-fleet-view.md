---
title: Sites (fleet view)
section: Features
order: 15
updated: 2026-09-12
author: Aaron Reimann
tags: [sites, fleet, pressable, spinupwp, hosting, standalone, companion]
tracks: [app/Http/Controllers/SitesController.php, resources/views/sites/create.blade.php, resources/views/dashboard/sites.blade.php, app/Services/HostingProvider/HostingProviderRegistry.php]
---

`/sites` is the fleet-wide site list that works across every hosting provider you have enabled. It exists because [the main dashboard](/docs/features/dashboard) is server-first — every card is a server — and Pressable or standalone custom sites have no server to hang a card off of.

## Adding Standalone Sites (+ Add Site)

A **+ Add Site** button in the header toolbar opens the onboarding modal:
- Supports standalone WordPress sites on WP Engine, Kinsta, or any unmanaged host where you do not have server/API access.
- Operators download the companion plugin (`/companion/download`), activate it in WordPress, and copy the base64 **Connection Key** from **Tools → Clockwork**.
- Pasting the key into the modal verifies the HMAC handshake and enrolls the site under the `custom` provider. A failed handshake never writes the secret or Connection Key back into session old-input or the Alpine form — only the domain (via `@js()`) is safe to echo.

## What you see

Paginated (50/page), searchable by domain, filterable by hosting provider with a live count on each filter chip — but only for providers whose module is currently enabled. Run just GridPane + Vultr? You'll only ever see **All / GridPane** as filter options, never SpinupWP or Pressable tabs, even if old `sites.hosting_provider` rows for a since-disabled panel still exist in the database (the "All" tab still counts and shows those rows — enablement only hides the filter/tab UI, not real infrastructure). With one or zero hosting-provider modules enabled, the tab bar doesn't render at all — there's no meaningful choice to offer. See `App\Services\HostingProvider\HostingProviderRegistry::all()`, which is already enablement-gated via `ModuleRegistry` and is what `SitesController::index()` builds tabs/counts/the `?provider=` filter from. Each row shows:

**Search filters instantly as you type** — no need to hit Enter. It's a client-side filter over the currently-loaded page's rows (matching against a lowercased `domain + server name` string baked into each row's `data-search` attribute), with a small clear (×) button that appears once you've typed something. Since it only filters what's already on the page, finding a match outside the visible 50 still needs a real server-side query (Enter, or the search icon). The same instant-filter pattern is also on [Features → Review queue](/docs/features/review-queue) (bans) and [Features → Contact form testing](/docs/features/contact-form-testing) (forms).

- Domain (with WordPress or Globe icon)
- Provider — the server's name for a SpinupWP site, a **Pressable** pill otherwise (`data-tooltip="Host: Pressable"` or `data-tooltip="Server: [name]"`)
- Uptime status pill (`data-tooltip="Uptime Online"`, `Site Down`, or `In Maintenance`)
- SSL certificate status pill (`data-tooltip="SSL Valid"`, `SSL Expiring Soon`, or `SSL Expired`)
- Companion plugin indicator pill (`data-tooltip="Companion Plugin Active"`)
- Care-plan indicator pill (`data-tooltip="Care Plan Active"`)
- A crescent-moon **Inactive** pill when `site.is_inactive` is set (`data-tooltip="Site Inactive"`). See [Features → Inactive sites](/docs/features/inactive-sites) for what setting that flag does — it doesn't hide the row here, only from Issues/nav/alerts.

Every status pill uses the centralized [Reference → Tooltip system](/docs/reference/tooltip-system) with instant, top-positioned USWDS floating tooltips and caret indicators on hover.

Same visual language as the existing per-server sites-tab list, just fleet-wide and provider-agnostic.

## List view vs. Visual grid view

A **List / Grid** toggle sits top-right of the toolbar. The choice is remembered per-browser (`localStorage['clockwork_sites_view']`, Alpine.js state), not per-user server-side, so it doesn't follow you across devices.

- **List** is the row layout described above.
- **Grid** renders one card per site (`sites-grid`, responsive 1–6 columns) with a homepage screenshot thumbnail, a health-colored accent bar (green/yellow/red from `Site::healthColor()` — red on `uptime_state === 'down'` or a red SSL state, yellow on unknown uptime or a yellow SSL state, green otherwise), the domain, a Pressable/server-name badge, and a warning icon when health isn't green. An `Inactive` badge overlays the thumbnail for sites with `is_inactive` set.

Both views share the same instant-filter search and the same server-side pagination/search fallback described above — the client-side filter script matches rows and cards in parallel and the AJAX search response swaps both `#sites-list-card` and `#sites-grid-container` at once, so switching views mid-search doesn't lose your query.

### Screenshots

Grid-view thumbnails come from `Site::screenshotUrl()`: if a locally cached screenshot exists (`screenshot_path` on the `public` disk) it's served directly, otherwise the view falls back live to Automattic's free mShots renderer (`https://s0.wp.com/mshots/v1/...`) so a card never shows blank while waiting on a first capture. A broken image (`onerror`) swaps in a placeholder globe icon with the domain underneath.

Caching is populated two ways:
- **On site creation** — `Site::booted()` dispatches `CaptureSiteScreenshotJob` automatically for every new site (skipped under tests).
- **On a schedule** — `clockwork:capture-site-screenshots` runs daily at 04:45, queued and backgrounded (`Schedule::command(...)->dailyAt('04:45')->withoutOverlapping(30)->onOneServer()->runInBackground()`), picking up to `--limit=50` sites at a time, prioritizing sites with no screenshot or one older than 7 days. `--site=<domain-or-id>` targets one site, `--force` re-captures regardless of age, `--sync` runs inline instead of queueing (useful for manual backfills). `SiteScreenshotService::capture()` fetches from mShots, rejects mShots' known "still generating" placeholder image (by a hardcoded MD5) and any response under 100 bytes, and only then writes `screenshots/{id}.jpg` to the `public` disk and stamps `screenshot_captured_at`.

## Why a separate page instead of extending the dashboard

The dashboard's whole layout is "one card per server, health-sorted." Pressable has nothing to sort by at that level — no CPU/memory/disk, no SSH status. Retrofitting the dashboard to also show a flat site list would have muddied a page that's deliberately about server health. A dedicated list keeps both pages doing one job well.

## Install Companion, provider-aware

The per-site Settings tab's "Install Companion" button routes through `SitesController::installCompanion()`, which dispatches via `$site->host()->companionInstaller()` — the `HostingProvider` contract, not a direct `Site::isPressable()` branch — so it works the same way regardless of which of the 6 hosting-provider modules (SpinupWP, Pressable, Cloudways, Kinsta, WP Engine, GridPane) the site is on. All installers converge on the same result shape (`companion_installed`, `companion_version`, error detail on failure).

`companionInstaller()` is nullable by contract: a provider client in **View-Only mode** (GridPane/Cloudways/Kinsta/WP Engine default to this until an operator confirms live write access under `/settings/integrations`; SpinupWP/Pressable default to write-enabled) returns no installer at all, since installing Companion is a write operation. The button itself checks `supports(HostingProvider::CAP_COMPANION)` and doesn't render for a View-Only site — it shows an explanatory "Install unavailable (View-Only)" note instead. The controller also guards the null case directly (422, not a crash) as a backstop for any other caller that skips the capability check.

## Site-detail pages are null-server-safe

`Site::server` is null for every Pressable site — and for a SpinupWP site whose `server_id` was deliberately cleared before archiving/decommissioning. Anywhere the site-detail UI used to assume a server exists — most visibly `dashboard/site/header.blade.php`'s server-name backlink — now shows a "← Sites" link plus a **Pressable** pill instead. If you're extending a site-detail Blade partial and it reads `$site->server->...`, guard it: that will throw a 500 otherwise. `SitesController::archive()` safely falls back to `sites.index` when there is no server.


## What's NOT here

- **No provider-specific bulk actions.** Bulk Companion install, bulk care-plan toggle, etc. are per-site-page actions, not batchable from this list yet.
- **No server-health equivalent for Pressable.** There's genuinely nothing to show — no CPU/memory/disk metrics exist for a Pressable site the way they do for a droplet.

See [Concepts → Server, Site, Care plan, Hosting tier](/docs/concepts/server-site-care-plan) for the `hosting_provider` model this page is built on, and [Integrations → Pressable](/docs/integrations/pressable) for what is and isn't reachable per site.
