---
title: Sites (fleet view)
section: Features
order: 15
updated: 2026-09-07
author: Aaron Reimann
tags: [sites, fleet, pressable, spinupwp, hosting]
tracks: [app/Http/Controllers/SitesController.php, resources/views/dashboard/sites.blade.php, app/Services/HostingProvider/HostingProviderRegistry.php]
---

`/sites` is the fleet-wide site list that works across every hosting provider you have enabled. It exists because [the main dashboard](/docs/features/dashboard) is server-first — every card is a server — and Pressable sites have no server to hang a card off of. Before this page, Pressable sites were invisible in the nav entirely.

## What you see

Paginated (50/page), searchable by domain, filterable by hosting provider with a live count on each filter chip — but only for providers whose module is currently enabled. Run just GridPane + Vultr? You'll only ever see **All / GridPane** as filter options, never SpinupWP or Pressable tabs, even if old `sites.hosting_provider` rows for a since-disabled panel still exist in the database (the "All" tab still counts and shows those rows — enablement only hides the filter/tab UI, not real infrastructure). With one or zero hosting-provider modules enabled, the tab bar doesn't render at all — there's no meaningful choice to offer. See `App\Services\HostingProvider\HostingProviderRegistry::all()`, which is already enablement-gated via `ModuleRegistry` and is what `SitesController::index()` builds tabs/counts/the `?provider=` filter from. Each row shows:

**Search filters instantly as you type** — no need to hit Enter. It's a client-side filter over the currently-loaded page's rows (matching against a lowercased `domain + server name` string baked into each row's `data-search` attribute), with a small clear (×) button that appears once you've typed something. Since it only filters what's already on the page, finding a match outside the visible 50 still needs a real server-side query (Enter, or the search icon). The same instant-filter pattern is also on [Features → Review queue](/docs/features/review-queue) (bans) and [Features → Contact form testing](/docs/features/contact-form-testing) (forms).

- Domain
- Provider — the server's name for a SpinupWP site, a **Pressable** pill otherwise
- Uptime state
- SSL state
- Companion-installed indicator
- Care-plan indicator
- A crescent-moon **Inactive** pill when `site.is_inactive` is set (tooltip shows the reason, if one was given). See [Features → Inactive sites](/docs/features/inactive-sites) for what setting that flag does — it doesn't hide the row here, only from Issues/nav/alerts.

Same visual language as the existing per-server sites-tab list, just fleet-wide and provider-agnostic.

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
