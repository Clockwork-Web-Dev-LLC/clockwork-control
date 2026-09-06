# Architecture Plan: Accidental `noindex` & Pre-Flight Launch Watchdog

*Module Target*: `modules/LaunchWatchdog` (or `app/Services/Seo/`)  
*Author*: Clockwork Architectural Research  
*Status*: Draft Plan (Ready for Implementation)

---

## 1. Problem Statement & Motivation
In web agencies, the single most financially damaging mistake during a website launch or redesign is the **Accidental `noindex` Survival**:
1. During staging and development, WordPress sites are configured with *"Discourage search engines from indexing this site"* enabled (`blog_public = 0`), which injects `<meta name="robots" content="noindex, nofollow">`.
2. Staging servers frequently have a blanket `Disallow: /` in `/robots.txt` or an `X-Robots-Tag: noindex` HTTP header.
3. The site is migrated to production (or staging is promoted live).
4. The developer forgets to uncheck the box in wp-admin Settings &rarr; Reading, or the staging `robots.txt` is copied over.
5. Googlebot visits, drops every index page from search rankings, and client organic leads crash.
6. The agency doesn't find out until weeks later when the furious client calls about their vanished revenue.

A lightweight, automated sentinel running in Clockwork Control can detect this within minutes of a launch.

---

## 2. Technical Design & Detection Vectors

The watchdog checks **four distinct layers** of indexability:

### Vector 1: HTML `<meta name="robots">` Tag
- Probe fetches homepage HTML via HTTP GET.
- Inspects DOM / regex for:
  - `<meta name="robots" content=".*noindex.*">`
  - `<meta name="googlebot" content=".*noindex.*">`

### Vector 2: HTTP Response Header `X-Robots-Tag`
- Many caching layers (Cloudflare, Nginx, W3 Total Cache) or staging environments send HTTP headers:
  - `X-Robots-Tag: noindex, nofollow`
- Probe inspects headers of the HTTP GET response.

### Vector 3: `/robots.txt` File Analysis
- Probe fetches `https://{domain}/robots.txt`.
- Parses User-agent blocks:
  - If `User-agent: *` contains `Disallow: /` (and no overrides for `/`), the entire site is blocked from crawlers.

### Vector 4: Companion Plugin State (`blog_public`)
- For WordPress sites with the Clockwork Companion mu-plugin installed:
  - Companion returns `get_option('blog_public')` in its telemetry snapshot (`1` = public, `0` = discouraged).
  - This provides an instant internal confirmation before or in tandem with external HTTP probes.

---

## 3. Architecture & Data Model

### Database Changes
Add a migration to `sites` table:
```php
Schema::table('sites', function (Blueprint $table) {
    $table->boolean('seo_indexable')->default(true)->after('care_plan_enabled');
    $table->string('seo_blocked_reason')->nullable()->after('seo_indexable'); // meta_noindex, header_noindex, robots_disallow_all, wp_blog_public_zero
    $table->timestamp('seo_checked_at')->nullable()->after('seo_blocked_reason');
    $table->text('seo_blocked_snippet')->nullable()->after('seo_checked_at'); // Raw line or tag triggering the block
    $table->boolean('seo_monitoring_enabled')->default(true)->after('seo_blocked_snippet');
});
```

### Exclusions & Scopes
- **Staging / Inactive Exclusions**:
  - Only sites where `is_inactive = false` and where domain does not match staging tags (e.g. `staging.*`, `dev.*`, `*.test`, or explicitly marked staging) trigger critical alarms.
  - For deliberate staging environments, a `noindex` is expected and marked with a neutral green "Protected from Search" badge instead of an alarm.

---

## 4. Scheduled Sentinel & Instant Verification

### 1. Scheduled Background Job
- Command: `php artisan clockwork:check-search-indexability`
- Schedule: Runs every 6 hours across the active fleet in `routes/console.php`.
- Execution time: ~100ms per site using Guzzle HTTP async client or queued jobs.

### 2. Event-Driven Triggers
- Automatically triggered when:
  - A site is first imported or added.
  - A site is toggled from `staging` to `production`.
  - A Companion sync occurs and `blog_public` flips to `0`.

### 3. On-Demand "Pre-Flight Launch Check"
- Site detail page includes an instant button: **"Run Pre-Flight Launch Check"**.
- Runs in 2 seconds, checking SSL, DNS, HTTP response, `noindex`, and `robots.txt`, outputting a green "Ready for Traffic" report.

---

## 5. UI & Alert Experience

### 1. The Critical Alarm Banner (`/issues`)
If a production care-plan site has `noindex` detected:
- Surfaced at the top of `/issues` in **Critical Red**:
  > 🚨 **CRITICAL: Production Site Blocking Search Engines**  
  > `example.com` has `<meta name="robots" content="noindex">` enabled. Google will drop this site from search results.  
  > *Source*: `<meta name="robots" content="noindex, follow">` detected at 2026-09-05 22:30.  
  > [Open in WP-Admin Settings &rarr; Reading] &bull; [Re-check Now]

### 2. Fleet Listing (`/sites`)
- Add an SEO column icon:
  - 🟢 Search Indexable
  - 🔴 Blocking Search Engines (`noindex`)
  - ⚪ Staging Protected

### 3. Immediate Chat Notifications
- Fires instant high-priority webhook to Slack / Discord / Pushover:
  > *"🚨 P0 ALERT: example.com was detected with noindex enabled in production! Fix immediately in wp-admin Settings > Reading."*

---

## 6. Implementation Steps (Phased)

### Phase 1: Indexability Inspector Service
- Create `App\Services\Seo\IndexabilityInspector` (or `Modules\LaunchWatchdog\Services\IndexabilityInspector`).
- Implement methods:
  - `inspect(string $url): IndexabilityResult`
  - `checkMetaTag(string $html): ?string`
  - `checkHeaders(array $headers): ?string`
  - `checkRobotsTxt(string $robotsTxt): ?string`

### Phase 2: Migration & Model
- Migration adding `seo_indexable`, `seo_blocked_reason`, `seo_checked_at`, `seo_blocked_snippet`.
- Model methods on `Site.php`:
  - `isSearchBlocked(): bool`
  - `seoState(): string` (`clean`|`blocked`|`staging_protected`)

### Phase 3: Artisan Command & Controller Endpoint
- `App\Console\Commands\CheckSearchIndexability`.
- AJAX endpoint on `SitesController`: `POST /sites/{site}/pre-flight-check`.

### Phase 4: Blade Views & Issue Counter
- Wire into `IssueCounter::total()` so any blocked production site raises a badge counter.
- Add alert card to `resources/views/dashboard/issues.blade.php`.
- Add Pre-Flight status card to `resources/views/dashboard/sites.blade.php`.

---

## 7. Verification & Test Plan

1. **Unit Tests**:
   - Test HTML parser with various `<meta name="robots">` variations (`noindex`, `none`, whitespace, uppercase `NOINDEX`).
   - Test `X-Robots-Tag` header parsing.
   - Test `robots.txt` parser with full disallow vs partial disallow.
2. **Feature Tests**:
   - Command flags blocked site and ignores legitimate staging sites.
   - Dispatches emergency notification on status transition (`clean` &rarr; `blocked`).
   - Re-check endpoint clears the issue once `noindex` is removed.
