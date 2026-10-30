# Plan: Grow the SEO module so the agency can cancel Semrush

> **Status (2026-10-30):** plan only, nothing built. Branch `feature/seo`. Written by Claude for Aaron
> from four research passes run the same day (codebase map, Google APIs, paid data providers, what
> Semrush does for us today), each followed by a verification pass whose corrections are applied
> here, then revised the same day after a 26-point review.
> Source tags: **[docs]** official vendor page, **[secondary]** third-party source, **[inferred]**
> our own reasoning or arithmetic. The research session's network allowlist blocked the Google,
> DataForSEO and Semrush doc sites, so most [docs] items were read from search excerpts of the
> official page or (for Google) from the live API discovery documents. Re-check prices and quotas on
> the live pages before buying anything.

## Context

**Goal.** Aaron wants Clockwork Control to replace Semrush for the agency's SEO clients. Today
`modules/Seo` has one page, `/seo`, a fleet indexability overview that reads the `seo_*` columns the
core watchdog in `app/Services/Seo/` writes. It has no migrations, credentials, commands or schedule,
and nothing in the app talks to Search Console, GA4 or a keyword data source.

**How the team works today.** Two Claude skills drive the work, both by hand in Chrome:

1. **Monthly SEO report** (`monthly-seo-report`). Per client: look up recipients, plan doc, plan
   month, Teamwork tasklist, GSC property, GA4 property and Semrush domain in the "Clockwork SEO
   Client Roster" sheet. Pull Search Console month vs. previous month (clicks, impressions, CTR,
   position), top pages, daily dips, non-branded, YoY for seasonal clients and the Generative AI
   report (AI impressions, AI share) by building URLs and scraping `innerText`. Pull GA4 one month at
   a time (organic sessions, engaged sessions, engagement rate, AI Assistant channel, key events
   `generate_lead`, `form_submit`, `contact_link_click`). Read Semrush Domain Overview's organic
   keyword count from an aria-label. Apply the data caveats from memory. Draft a Gmail email, never
   sent.
2. **Keyword mapping doc** (`create-keyword-mapping-doc`). Per client: build on the existing sheet if
   there is one (new dated tabs, old tabs untouched, ship status kept); scrape 3 months of GSC pages
   and queries; paste up to 100 keywords at a time into Semrush Keyword Overview (clicked by
   coordinate) for Volume, KD%, CPC and Intent; fetch each live page for title, meta, H1, JSON-LD
   types and word count; build one row per page (primary and secondary keywords, proposed title and
   meta with lengths, schema, ship status, client notes); paste it into the client's Google Sheet as
   systems@ with a DataTransfer hack and format 16 columns by hand.

**What Semrush actually gives us.** Two things: the organic keyword count (one number per client per
month) and the bulk Keyword Overview lookup. Everything else in both workflows is Search Console, GA4
and our own page reads, all free.

**What it costs in manual time.** Neither skill records time per step, so these are estimates
[inferred]; decision 10 asks for a check against Teamwork timelogs.
- Report numbers (roster lookup, GSC, Generative AI report, GA4, Semrush, re-pull after backfill):
  about 30 to 45 minutes per client per month. At 15 clients, 7.5 to 11 hours a month.
- Keyword map refresh: about 3 to 5 hours per client per refresh, roughly half of it the mechanical
  GSC scrape, Semrush lookup and Sheets paste and format.
- The steps are fragile: coordinate clicks in Semrush, the `/u/1` "Verify it's you" wall, paste
  hacks, numbers read from `innerText` and aria-labels.

Removing that work is worth more than the subscription saving (see Data sources and costs).

**Client count.** The roster isn't in the repo. All cost math assumes **15 SEO clients**, the
research's working figure (decision 2).

## Key architectural decisions

1. **Free Google data is the backbone; pay only for keyword metrics.** The Search Console API and the
   GA4 Data API cover about 90% of the monthly report [inferred]. A paid source is needed only for
   Volume, Difficulty, CPC and Intent, plus a third-party organic keyword count only if decision 5
   picks one.
2. **Store first, read from the database.** A nightly sync stores GSC and GA4 data in module tables.
   Pages, widgets and reports read the database, never a live API. This also keeps our own history
   past GSC's 16-month window [docs].
3. **Daily grain for totals, monthly grain for pages, queries and GA4 month figures.** GSC site
   totals are stored per day (any date range, outage overlays, YoY). Page, query and page×query rows
   are stored per calendar month, because every consumer (report top pages, the keyword map's 3-month
   view, the organic keyword count) is month-based. For 40 properties that cuts the 16-month backfill
   from the verified 58k to 96k calls (daily page and query rows, 16 to 27 hours of paced calls) to
   about 2,600; for 15 sites it is about 1,000 [inferred]. GSC clicks and impressions sum exactly
   across months; position combines as an impression-weighted average, which is how GSC averages it
   [inferred]. GA4 month figures are never summed from days (see GA4 below): each month is its own
   month-range request.
4. **An agency Google connection owned by `modules/Seo`, separate from login.** `modules/AuthGoogle`
   is login only and throws the token away. The data access the team uses belongs to
   systems@clockworkwd.com, and a background sync needs one stable grant, not whoever logged in last.
   So: OAuth with an **Internal** consent screen, connected once as systems@, refresh token stored
   encrypted. Internal apps need no Google verification and don't hit the 7-day Testing expiry
   [docs]. One connection row. If a service account is ever needed, its migration adds
   `credential_type` and a per-profile `google_connection_id`.
5. **Laravel `Http`, no Google SDK.** The repo already calls Google REST APIs with `Http::`
   (`modules/PageSpeedInsights/src/ChromeUxReportClient.php`). The OAuth code exchange and refresh
   are two form posts, and the access token is cached the way `modules/Pressable/src/PressableClient.php`
   caches its token. Don't add `google/apiclient` (hundreds of generated services) or
   `google/analytics-data` (hard-requires the grpc and protobuf Composer packages) [docs]. If a
   service account is ever needed, add `google/auth` then: it accepts the `firebase/php-jwt` ^7
   already in `composer.lock` and adds one package [docs].
6. **Per-capability provider drivers.** Keyword metrics sit behind a small contract in
   `modules/Seo/src/Contracts/` with a Null implementation, the same shape as
   `modules/Core/src/Contracts/SmsNotifier.php` plus `NullSmsNotifier`. DataForSEO is the first
   driver; swapping is a setting. Domain analytics gets the same treatment only if decision 5 picks a
   third-party count.
7. **Stay inside the module.** New code lives under `Modules\Seo\`, already autoloaded (root
   `composer.json:275`), so no Composer change. Core and other-module files touched, by phase:
   - Phase 1: the `seo` block of `config/clockwork.php` (env fallbacks), `.env.example`,
     `resources/js/app.js` plus the new `resources/js/charts/seo-search-chart.ts`, new
     `tests/Fixtures/*` files, and the diagnostic-check list in
     `tests/Feature/Modules/ModuleRegistryAggregationTest.php`. The disconnect alert calls the
     existing `ChatNotifier::send()`, with no core edit.
   - Phase 1b: one include in `resources/views/dashboard/site/widgets/_widget-seo.blade.php`;
     `ModuleCatalog::categorize()` in `modules/Core/src/ModuleCatalog.php`.
   - Phase 2: the new `resources/js/components/keyword-map-editor.ts` registered in
     `resources/js/app.js`; more `tests/Fixtures/*`; `.env.example` (DataForSEO).
   - Phase 3: `app/Services/Chat/ChatNotifier.php` (`EVENTS` and the interface),
     `app/Services/Chat/ChatNotifierDispatcher.php`, `modules/Core/src/Support/WebhookChatNotifier.php`,
     `modules/ClientSlack/src/ClientSlackNotifier.php`, and the double, `gatedMethods` dataset (around
     L273) and call-through tests in `tests/Feature/Chat/ChatNotifierGatingTest.php`.
   - Phase 4: the new `modules/Core/src/Contracts/ReportSectionProvider.php`; in ClientReports,
     `ClientReportTemplate::SECTIONS`, `ClientReportCompiler::compile()` and `SCHEMA_VERSION`,
     `ReportReviewFlags::reasons()`, `templates/form.blade.php` and four views
     (`emails/report.blade.php`, `emails/report-text.blade.php`, `reports/show.blade.php`,
     `reports/email.blade.php`). The optional OpenRouter draft adds `"optional_requires":
     ["ai-remedy"]` to `modules/Seo/module.json` (declarative, as in ClientReports' `module.json`;
     no core code reads it) and checks `ModuleStateResolver::isEnabled('ai-remedy')` and
     `class_exists(OpenRouterClient::class)` before using
     `modules/AiRemedy/src/Services/OpenRouterClient.php`.
8. **Never guess missing data.** What the APIs can't give (GSC Generative AI report, GSC branded
   filter) gets a manual or regex path, and an unavailable value shows "not available", never 0.

## Scope matrix

"Now" means needed before Semrush is cancelled (Phases 1 and 2). "Later" means after cancellation,
when a client needs it.

| Semrush feature | Used today | Verdict | Data source in Control | Phase |
|---|---|---|---|---|
| Domain Overview: organic keyword count | Yes, monthly | Replace now | GSC distinct queries with impressions (free); a DataForSEO `domain_rank_overview` count only if decision 5 picks a third-party count | 1 (2 if third-party) |
| Keyword Overview bulk: Volume, KD, CPC, Intent | Yes, keyword maps | Replace now | DataForSEO Labs `keyword_overview`; city volume from DataForSEO Keywords Data (Google Ads) | 2 |
| Keyword Manager (keyword lists) | No, lists live in Sheets | Replace now | Keyword map tables in Control, existing sheets imported | 2 |
| Organic Research for our own sites | No, GSC used | Replace now | GSC API | 1 |
| Organic Traffic Insights (GSC + GA together) | No | Replace now | GSC + GA4 APIs | 1 |
| Position Tracking | No | Later: GSC average position for tracked queries first; real SERP rank only on demand | GSC; DataForSEO SERP API | 3, 5 |
| On Page SEO Checker, Site Audit (subset) | No | Later, mapped pages only | Own page fetch, indexability watchdog | 2, 3 |
| My Reports | No (Gmail + CW PDF) | Later | Report draft page, Client Reports `seo` section | 4 |
| AI Visibility toolkit | Indirectly (team uses the GSC Gen AI report and GA4 AI channel) | Later, our own measures | GSC Gen AI report by manual entry or export upload; GA4 AI Assistant sessions | 1, 4 |
| Keyword Magic Tool | No | Later, on demand | DataForSEO keyword ideas + GSC queries | 5 |
| Keyword Gap, Backlink Gap, competitor research | No | Drop (later only if a client asks) | DataForSEO Labs | 5 |
| Backlink Analytics, Audit, Link Building | No | Drop | DataForSEO Backlinks if ever | 5 |
| Local toolkit (listings, reviews, map rank) | No | Drop | | |
| Traffic & Market, Content, Social, Advertising, AI PR, Keyword Strategy Builder | No | Drop (Claude skills cover content and clustering) | | |

## Data sources and costs

### Google Search Console API (free)

- `searchAnalytics.query` per property. `siteUrl` is the exact URL-prefix (`https://www.example.com/`)
  or `sc-domain:example.com`, URL-encoded. Access is per property: a URL-prefix user can't read the
  sc-domain property and the reverse [inferred; matches the skill's "systems@ often lacks sc-domain
  access"].
- Dimensions `date`, `page`, `query`, `country`, `device`, `searchAppearance`. Filters include
  `includingRegex` and `excludingRegex` (RE2, 4,096 characters max). `rowLimit` up to 25,000 with a
  zero-based `startRow`. `dataState` `final` (default) or `all` [docs].
- At most 50,000 rows per day per search type per property [docs]. Anonymized queries never appear
  as rows but are in the totals, so a filtered "non-branded" figure covers known queries only [docs].
  Headline totals come from a request without `page` grouping so they aggregate by property and
  match the UI [docs].
- `sites.list` returns every property the connected account can see, with `permissionLevel` [docs].
- Quotas: Search Analytics allows 1,200 queries per minute (QPM) per site and per user, and 30M
  queries per day (QPD) and 40k QPM per project, plus a load quota in 10-minute and 1-day windows
  where page and query grouping cost most; on overage, wait 15 minutes [docs, search excerpt]. All
  sites share one user's quota; our load is about 6 calls per site per night [inferred].
- 16 months retention [docs]. Today the window starts 2025-06-30, and the last data from before the
  num=100 change rolls off around 2027-01-14 [inferred, verified arithmetic].
- Days are Pacific Time. Final data lags about 2 days and late-month data keeps filling in [docs].

### GA4 Data API and Admin API (free)

- `properties/{id}:runReport` and `:batchRunReports` (up to 5 reports, same property) [docs].
  Metrics `sessions`, `engagedSessions`, `keyEvents` (renamed from `conversions` in 2024)
  [docs/secondary].
- **Month figures come from a month-range request.** GA4 estimates sessions with HyperLogLog++
  [docs: Google Analytics developer blog, "Unique count approximation", 2022, search excerpt], and a
  session that crosses midnight counts on both days [inferred], so summed daily rows don't equal the
  month figure in the UI. The sync asks for each calendar month as one date range with no `date`
  dimension. Engagement rate is engaged sessions ÷ sessions from that response.
- **Channel grouping.** The UI Traffic acquisition report groups by Session primary channel group,
  which equals the default channel group unless the property has a custom primary group
  [secondary]. `sessionPrimaryChannelGroup` has been in the Data API since 2024-03-25 [docs,
  changelog excerpt]. The profile has a "custom primary channel group" flag; when it is set, the sync
  queries `sessionPrimaryChannelGroup` instead of `sessionDefaultChannelGroup`.
- **Time zone.** GA4 dates are in the property's reporting time zone, not Pacific [inferred]. A
  calendar-month date range is read in that zone, so it matches the UI for that property.
- **Thresholding.** GA4 can withhold small rows, often key events; `metadata.subjectToThresholding`
  in the response says when a report is subject to it [docs, ResponseMetaData reference, search
  excerpt]. Stored per month row and shown as a caveat.
- Per-event counts use `keyEvents` with dimension `eventName`. The `keyEvents:<event_name>` metric
  form is unverified; don't build on it.
- AI Assistant channel: added to the Default Channel Group on 2026-05-13 for medium `ai-assistant`
  [docs]; not retroactive [secondary]. Sources disagree on the label ("AI Assistant" vs. "AI
  Assistants"), so the daily AI rows match `sessionMedium = ai-assistant`, and the month tile matches
  either label. For history and YoY, also match `sessionSource` against a configurable AI-referrer
  regex (starting list: chatgpt.com, chat.openai.com, perplexity.ai, gemini.google.com,
  copilot.microsoft.com, claude.ai, chat.deepseek.com, grok.com, meta.ai, you.com, chat.mistral.ai
  [inferred]).
- Quotas (standard property): 200,000 core tokens per property per day, 40,000 per hour, 14,000 per
  project per property per hour, 10 concurrent requests; most requests cost 10 tokens or fewer [docs,
  confirmed]. Our nightly batches are about 50 tokens per property [inferred]. `returnPropertyQuota:
  true` returns the live balance [docs].
- Admin API `accountSummaries.list` lists every account and property the connection can see, page
  size up to 200 [docs]. Used for the GA4 property picker in Phase 1b; Phase 1 takes the property ID
  typed in, since the roster already lists it.

### Not available by API, and the fallback

| Data | Status | Fallback |
|---|---|---|
| GSC Generative AI report (AI impressions, top AI pages) | UI-only. The live Search Console discovery document's `type` enum is web, image, video, news, discover, googleNews only [docs]; no AI `searchAppearance` value or BigQuery column [secondary]. Data starts 2026-05-18 [secondary] | Per site per month: type the number or upload the report's export. Performance exports are a ZIP (or Sheet) with one CSV per tab, such as Dates, Pages, Countries and Devices, plus Filters [docs, Search Central blog 2020, search excerpt]; the AI report is assumed to export the same way [inferred; check one real export]. The month total comes from the Dates rows, because the Pages table stops at 1,000 rows [inferred]. "~" and "-" become 0 [docs]. A "not available" state for sites where the report doesn't show. The app computes AI share and keeps the trend |
| GSC branded queries filter | No API dimension or filter | Per-site brand regex with `excludingRegex`, labelled "non-branded (known queries)" |
| GSC multimodal split (UI since 2026-09-24) | No multimodal `type` in the API; sources disagree on whether the API web total includes it [secondary] | Phase 1 check on one property before trusting the web total as the AI-share denominator |
| GA4 AI Assistant channel before 2026-05-13 | Not retroactive [secondary] | AI-referrer regex on `sessionSource`, labelled as an estimate |
| URL Inspection mobile usability | Deprecated in the current schema [docs] | Not used |
| Semrush KD% | Proprietary | DataForSEO difficulty, shown as "Difficulty (0-100)" with a band, never "KD %" |

### Auth recommendation

Agency OAuth connection, Internal consent screen, connected once as systems@clockworkwd.com:
- GCP project owned by the clockworkwd.com Workspace organization; consent screen user type
  **Internal**; Search Console API, Google Analytics Data API and Google Analytics Admin API enabled;
  one Web OAuth client with redirect URI `https://<control host>/seo/google/callback`.
- Scopes: `openid email`, `https://www.googleapis.com/auth/webmasters.readonly`,
  `https://www.googleapis.com/auth/analytics.readonly`. Phase 2 adds
  `https://www.googleapis.com/auth/spreadsheets` for the Sheets import and export, with one
  reconnect, and only once Sheets is turned on in `/seo/settings` (see Risks).
- `access_type=offline` and `prompt=consent` on connect only. Internal apps skip verification (up to
  10 business days for an External app [docs]) and the 7-day Testing expiry [docs].
- A refresh token dies if revoked, if unused for 6 months (the nightly sync prevents that), or when
  the account passes 100 tokens for one client and the oldest is silently dropped [docs]. So don't
  reconnect casually, and alert on `invalid_grant`.
- No per-client setup: whatever systems@ sees in GSC and GA4 today shows up.
- Service account (not built now): it must be added as a user on each GSC and GA4 property, which
  only owners and admins can do (often the client), and Google Cloud orgs created on or after
  2024-05-03 block key creation by default [docs]. Keep it for the odd client systems@ can't access.

### Paid keyword data: DataForSEO

Recommendation: **DataForSEO**, pay-as-you-go, $50 minimum top-up, no monthly fee [docs]. It is the
only option found that gives volume, CPC, difficulty, intent and a domain keyword count from one
account at metered prices. Fallback vendors are in Appendix A.

Prices used [docs via search excerpts unless noted; every DataForSEO response carries a `cost` field,
so one test call settles each]:
- Labs (`keyword_overview`, `domain_rank_overview`): about $0.012 per task + $0.00012 per item after
  the July 1, 2026 increase of about 20% [secondary, consistent with the official pre-increase
  $0.01 + $0.0001].
- `keyword_overview` takes up to 700 keywords per call and returns volume, CPC, competition and
  monthly trend [docs]; the verification pass says KD and intent come in the same call. If they
  don't, add `bulk_keyword_difficulty` and `search_intent` calls (cost triples, shown below).
- Keywords Data, Google Ads search volume (the only city-level volume; Labs is country-only [docs]):
  $0.06 Standard / $0.09 Live per task of up to 1,000 keywords [docs]; possibly $0.072 / $0.108 if
  the July increase applied [unresolved].
- SERP API (Phase 5 only): $0.0006 per 10 results, Standard queue [docs].

Monthly cost at 15 clients for what replaces today's Semrush use. 300 keywords per client per month
is a generous upper bound; real map refreshes are less frequent.

| Line | Arithmetic | $/month |
|---|---|---|
| Keyword metrics, one `keyword_overview` call | (0.012 + 300 × 0.00012) = 0.048 × 15 | 0.72 |
| Same, worst case 3 calls (overview + KD + intent) | 0.048 × 3 × 15 | 2.16 |
| City volume for local clients, 1 Live task | 0.09 to 0.108 × 15 | 1.35 to 1.62 |
| Organic keyword count, `domain_rank_overview` (only with a third-party count, decision 5) | (0.012 + 0.00012) × 15 | 0.18 |
| **Metered total** | | **2.07 to 3.96** |
| Ad hoc research buffer, 10 keyword-idea calls of 1,000 items per client | 10 × (0.012 + 0.12) × 15 | 19.80 |
| **Worst case with buffer** | | **about 24** |

Budget cap: **$75/month**, enforced in code. Phase 5 costs, if it is ever built, are in Appendix B.

**Against Semrush.** Clockwork's actual plan is unknown (decision 1). Today's workflow (Domain
Overview plus bulk Keyword Overview, no Position Tracking) fits Pro / "SEO" at $139.95/month (about
$117 billed annually). Guru is $249.95, Semrush One Pro+ $299, extra seats $45 to $100 each
[secondary].

| Compared with | Semrush $/month | DataForSEO $/month | Saving $/month |
|---|---|---|---|
| Pro, today's Semrush use | 139.95 | about 2 to 24 | about 116 to 138 |
| Guru, today's Semrush use | 249.95 | about 2 to 24 | about 226 to 248 |
| Pro, spending the full $75 cap | 139.95 | 75 | about 65 |

The subscription saving is real but modest. The manual time (7.5 to 11 hours a month on report
numbers alone) is the bigger win.

### Driver interface

New, in `modules/Seo/src/Contracts/`. Only keyword metrics is needed before cancellation;
`DomainAnalyticsProvider` is built only if decision 5 picks a third-party count, and `SerpProvider`
and `BacklinkProvider` come in Phase 5 if ever.

```php
namespace Modules\Seo\Contracts;

interface SeoDataDriver
{
    public function id(): string;          // 'dataforseo', 'google_ads', 'null', ...
    public function isConfigured(): bool;
}

interface KeywordMetricsProvider extends SeoDataDriver
{
    public function maxKeywordsPerCall(): int; // 700 for Labs keyword_overview

    /** @param list<string> $keywords @return array<string, KeywordMetrics> keyed by normalized keyword */
    public function keywordMetrics(array $keywords, Market $market): array;

    /** City-level volume only, no difficulty or intent. @return array<string, KeywordMetrics> */
    public function localVolume(array $keywords, Market $market): array;
}

// Only if decision 5 picks a third-party count.
interface DomainAnalyticsProvider extends SeoDataDriver
{
    public function domainOverview(string $domain, Market $market): DomainOverview;
}
```

Value objects (new, `modules/Seo/src/Data/`):
- `Market` (an input): country, language, optional city location name, device.
- `KeywordMetrics`: `?int volume` (null means "too small to measure", never 0), `monthly`, `?float
  cpc`, `?float paidCompetition`, `?int difficulty` plus `difficultyScale` (e.g. `dataforseo-kd`),
  `?string intent`, `isLocalVolume`.
- `DomainOverview` (third-party count only): `organicKeywords`, position buckets, `countMethod`.

Result objects (`KeywordMetrics`, `DomainOverview`) carry `provider` and `fetchedAt`; cost is
recorded per call in `seo_api_usage`.

`SeoDataManager` (new) resolves each capability from `app_settings` (`seo.drivers.keywords`, plus
`seo.drivers.domain` if built) and falls back to the Null driver when unset or unconfigured.
`BudgetGuard` (new) refuses a paid call when month-to-date ledger cost plus the call's estimate would
pass the cap.

## Architecture inside `modules/Seo`

### Files

New unless marked. Paths under `modules/Seo/`. Phase 1 unless marked. "3rd-party" means built only
if decision 5 picks a third-party organic keyword count.

```
module.json                                  (edit: description, version 1.1.0; Phase 4 optional_requires)
routes/web.php                               (edit)
database/migrations/                         (new folder; loadMigrationsFrom in boot(), unconditional)
resources/views/
  index.blade.php                            (edit: site links, revoked banner; columns in 1b)
  _nav.blade.php                             Overview | Keyword maps (Phase 2) | Settings (ClientReports _nav pattern)
  settings.blade.php
  sites/show.blade.php                       per-site search dashboard
  sites/profile.blade.php
  widgets/search-summary.blade.php           Phase 1b, included by the core SEO widget
  keyword-maps/{index,edit,import}.blade.php Phase 2
src/
  SeoServiceProvider.php                     (edit: migrations, commands, schedule, credentialFields,
                                              diagnosticCheck; view composer in 1b)
  Contracts/   SeoDataDriver, KeywordMetricsProvider (Phase 2); DomainAnalyticsProvider (3rd-party)
  Data/        Market, KeywordMetrics, ApiResult (Phase 2); DomainOverview (3rd-party)
  Google/      GoogleOAuth, GoogleTokenProvider, SearchConsoleClient, AnalyticsDataClient,
               PropertyDiscovery; AnalyticsAdminClient (1b); SheetsClient (Phase 2)
  Drivers/     DataForSeo/{DataForSeoClient, DataForSeoKeywordMetrics}, NullKeywordMetrics (Phase 2);
               DataForSeo/DataForSeoDomainAnalytics, NullDomainAnalytics (3rd-party)
  Services/    GoogleSyncService, SearchNumbers, BrandRegex, AiReportExportParser;
               SeoDataManager, BudgetGuard, PageAuditor, KeywordMapImporter, SheetsExporter (Phase 2)
  Models/      see Tables
  Console/     SyncGoogleData; AuditMappedPages, WatchTargets (Phase 3); SnapshotDomains (3rd-party)
  Jobs/        SyncSiteGoogleDataJob, RefreshGooglePropertiesJob; FetchKeywordMetricsJob (Phase 2)
  Http/Controllers/  SeoController (edit), GoogleConnectionController, SeoSettingsController,
               SiteSearchController, SiteProfileController, AiImpressionsController;
               KeywordMapController, KeywordMapImportController (Phase 2)
  Diagnostics/ GoogleConnectionCheck
  View/        SeoWidgetComposer (1b)
```

Frontend lives in the core build, since modules can't ship their own bundle:
`resources/js/charts/seo-search-chart.ts` (daily clicks, impressions and organic sessions with outage
and annotation bands, disposed on destroy) and, in Phase 2, `resources/js/components/keyword-map-editor.ts`,
both registered in `resources/js/app.js`. No inline `<script>`. Use the existing Blade components
(`x-page-header`, `x-stat-box` with `trend`, `x-status-pill`, `x-card`, `x-sort-th`) and theme tokens
so all four themes work.

No model factories: a `Modules\Seo\Database\Factories\` namespace needs a root `composer.json` psr-4
entry and `dump-autoload`. Tests create rows directly.

### Tables

Phase 1:
- `seo_google_connections`: `id`, `account_email`, `scopes` (json), `refresh_token` (text,
  `encrypted` cast, hidden), `status` (`active` | `revoked` | `error`), `last_refreshed_at`,
  `last_error` (string 480), `last_error_at`, `connected_by_user_id` (FK users, nullOnDelete),
  timestamps. One row.
- `seo_google_properties`: `id`, `connection_id` (FK `seo_google_connections`, cascadeOnDelete),
  `kind` (`gsc` | `ga4`), `property` (GSC `siteUrl` or `properties/123`), `display_name`,
  `permission_level`, `last_seen_at`, timestamps; unique (`connection_id`, `kind`, `property`).
  Phase 1 fills `gsc` rows from `sites.list`; Phase 1b adds `ga4` rows from `accountSummaries`.
- `seo_site_profiles`: `id`, `site_id` (FK sites, unique, cascadeOnDelete), `gsc_site_url`,
  `gsc_permission_level`, `ga4_property_id`, `ga4_property_name`, `ga4_custom_primary_channel`
  (bool), `brand_regex` (string 4096), `key_events` (json, defaults to the three events),
  `compare_yoy` (bool, seasonal clients), `report_notes` (text: tone, angle, things to avoid),
  `semrush_domain` (matches the exported history; dropped after cancellation), `is_enabled` (bool),
  `history_starts_on` (date), `last_gsc_synced_at`, `last_ga4_synced_at`, `last_sync_error` (string
  480), timestamps. All property and text columns nullable.
- `seo_gsc_daily`: `site_id`, `date`, `segment` (`all` | `nonbranded`), `clicks`, `impressions`,
  `ctr` (decimal 8,6), `position` (decimal 7,2), `fetched_at`; unique (`site_id`, `date`, `segment`).
- `seo_gsc_month_rows`: `site_id`, `month` (first of month), `dimension` (`page` | `query` |
  `page_query`), `page` (string 2048, nullable), `query` (string 512, nullable), `row_hash` (char 40,
  sha1 of dimension, page and query), `clicks`, `impressions`, `ctr`, `position`, `fetched_at`;
  unique (`site_id`, `month`, `row_hash`).
- `seo_gsc_months`: `site_id`, `month`, `is_final`, `rows_page`, `rows_query`, `rows_page_query`,
  `possibly_truncated` (set when a dimension pages past 50,000 rows), `synced_at`; unique (`site_id`,
  `month`). Tracks finality and truncation per month.
- `seo_ga4_daily` (chart and the pre-2026-05-13 AI estimate only): `site_id`, `date`, `dimension`
  (`channel` | `ai_source`), `value` (string 191), `sessions`, `engaged_sessions`, `fetched_at`;
  unique (`site_id`, `date`, `dimension`, `value`).
- `seo_ga4_months`: `site_id`, `month`, `channel_group` (string 191), `sessions`, `engaged_sessions`,
  `key_events`, `is_final`, `subject_to_thresholding`, `fetched_at`; unique (`site_id`, `month`,
  `channel_group`). The only source for GA4 tiles, engagement rate and "Copy numbers".
- `seo_ga4_key_event_months`: `site_id`, `month`, `event_name`, `key_events`, `event_count`,
  `is_final`, `subject_to_thresholding`, `fetched_at`; unique (`site_id`, `month`, `event_name`).
  Key events across all channels, as the skill reports them. Replaces the earlier draft's daily
  key-event table, which nothing read day by day.
- `seo_ai_impressions`: `site_id`, `month`, `status` (`entered` | `not_available`), `ai_impressions`
  (nullable), `top_pages` (json, from the export), `source` (`manual` | `export`),
  `entered_by_user_id`, timestamps; unique (`site_id`, `month`).
- `seo_annotations`: `site_id` (null means fleet-wide), `starts_on`, `ends_on`, `kind`
  (`google_change` | `methodology` | `note`), `label`, `suppresses` (json: `position`,
  `impressions`), `created_by_user_id`, timestamps. The migration seeds: num=100 change (2025-09-10
  to 2025-09-14, suppresses position and impressions comparisons), GA4 AI Assistant channel added
  (2026-05-13), GSC Generative AI data starts (2026-05-18), GSC multimodal split (2026-09-24, flags
  impressions).

Phase 1b:
- `seo_number_copies`: `site_id`, `month`, `numbers` (json), `copied_by_user_id`, `copied_at`. The
  latest copy per site and month is compared with the final numbers.

Phase 2:
- `seo_api_usage`: `provider`, `endpoint`, `site_id` (nullable), `items`, `cost_usd` (decimal 10,6),
  `status`, `error` (string 480), `created_at`.
- `seo_keyword_metrics`: `keyword` (normalized), `market_key` (e.g. `US-en`, or with a city
  appended), `provider`, `volume` (nullable), `monthly` (json), `cpc`, `paid_competition`,
  `difficulty`, `difficulty_scale`, `intent`, `secondary_intents` (json), `is_local_volume`,
  `fetched_at`, `cost_usd`; unique (`keyword`, `market_key`, `provider`). Stale after 30 days (Labs
  refreshes monthly [docs]).
- `seo_keyword_maps`: `site_id`, `label` (e.g. "Keyword Map (Dec 2026)"), `status` (`draft` |
  `current` | `archived`), `source` (`app` | `sheet_import` | `csv_import`), `source_tab`,
  `based_on_map_id`, `gsc_months` (json), `exported_at`, `export_sheet_tab`, `created_by_user_id`,
  timestamps.
- `seo_keyword_map_rows`: `keyword_map_id`, `sort`, `page_label`, `url` (2048), `is_new_page`,
  `primary_keyword`, `secondary_keywords` (json: keyword and volume), `intent`, snapshot columns
  `volume`, `difficulty`, `cpc`, `metrics_provider` (`dataforseo` | `semrush` for imported values),
  `proposed_title`, `proposed_meta`, `schema_types` (json), `ship_status` (`not_started` | `drafted`
  | `planned_new` | `live` | `verified`), `ship_note` (e.g. "Page 3, September"), `shipped_on`,
  `verified_at`, `notes` (client-facing "Why: ... Need from you: ..."), `current_state_notes`,
  `needs_review` (bool, set by the import), timestamps.
- `seo_page_audits`: `site_id`, `url`, `url_hash`, `fetch_status` (`ok` | `blocked` | `error`),
  `status_code`, `final_url`, `title`, `meta_description`, `h1s` (json), `canonical`, `meta_robots`,
  `jsonld_types` (json), `word_count`, `error`, `fetched_at`; unique (`site_id`, `url_hash`). Each
  audit overwrites the row; history lives in the map rows' `verified_at` and `drifted_at`.
- Added columns on `seo_site_profiles`: `market_country` (default `US`), `market_language` (default
  `en`), `market_city`, `google_sheet_id`, all nullable.
- 3rd-party only: `seo_domain_snapshots`: `site_id`, `month`, `provider` (`dataforseo` |
  `semrush`), `organic_keywords`, `pos_1`, `pos_2_3`, `pos_4_10`, `pos_11_20`, `pos_21_100`,
  `count_method`, `fetched_at`; unique (`site_id`, `month`, `provider`). `semrush` rows hold the
  imported history.

Phase 3:
- `seo_tracked_query_weeks`: `site_id`, `week_start`, `kind` (`page` | `query`), `page` (string
  2048, nullable), `query` (string 512, nullable), `row_hash` (char 40), `best_page`, `clicks`,
  `impressions`, `position`; unique (`site_id`, `week_start`, `kind`, `row_hash`).
- Added columns: `seo_site_profiles.tracked_queries` (json), `seo_keyword_map_rows.drifted_at`.

Storage: monthly page×query rows for 15 small sites over 16 months is at most a few million rows
[inferred]. Keep everything; it's already aggregated.

### Models

New, in `modules/Seo/src/Models/`: `GoogleConnection`, `GoogleProperty`, `SiteProfile`, `GscDaily`,
`GscMonthRow`, `GscMonth`, `Ga4Daily`, `Ga4Month`, `Ga4KeyEventMonth`, `AiImpressionEntry`,
`Annotation`; Phase 1b `NumberCopy`; Phase 2 `ApiUsage`, `KeywordMetric`, `KeywordMap`,
`KeywordMapRow`, `PageAudit` (and `DomainSnapshot`, 3rd-party only); Phase 3 `TrackedQueryWeek`.
`SiteProfile` belongsTo `App\Models\Site`. No relation is added to core `Site`; module code queries
`SiteProfile` directly.

### Sync, jobs and schedule

`GoogleSyncService::syncSite(SiteProfile $profile, ?CarbonImmutable $from = null)` does the work; the
command and the job both call it. Per site per night:

1. **GSC daily totals.** One `date`-dimension call from the latest stored date minus 5 days (or
   `history_starts_on` on backfill) to yesterday Pacific, `dataState: final`. A second identical call
   with `excludingRegex` on `query` when a brand regex is set. Insert or update into `seo_gsc_daily`.
2. **GSC month rows** for every month not yet final: `page`, `query` and `page,query` calls over the
   calendar month, paged by 25,000 with `startRow`. A month becomes final when synced on or after
   month end + `seo.final_after_days` (default 5).
3. **GA4 daily, for the chart only.** One `batchRunReports` for the trailing 5 days (or the backfill
   range in monthly chunks): (a) `date` and the channel dimension with `sessions, engagedSessions`;
   (b) `date, sessionSource, sessionMedium` filtered to medium `ai-assistant` OR source matching the
   AI regex. No month figure is built from these rows, except the AI estimate for months before
   2026-05-13, which is labelled as an estimate.
4. **GA4 months, for tiles, reports and "Copy numbers".** For every month not yet final, one
   `batchRunReports` whose reports both use the calendar month as the date range and have no `date`
   dimension: (a) the channel dimension with `sessions, engagedSessions, keyEvents` into
   `seo_ga4_months`; (b) `eventName`, filtered to the profile's key events, with `keyEvents,
   eventCount` into `seo_ga4_key_event_months`. Each row stores the response's
   `metadata.subjectToThresholding`. A month turns final on the same `final_after_days` rule as GSC.

The channel dimension is `sessionDefaultChannelGroup`, or `sessionPrimaryChannelGroup` when the
profile's custom-primary-group flag is set.

Backfill: when a profile is first saved, or its GSC or GA4 property changes (see Per-site profile),
`history_starts_on` is set to the oldest date GSC still holds (today minus 16 months) and
`SyncSiteGoogleDataJob` is queued in backfill mode. About 16 months × 3 or 4 GSC month-row calls plus
a few daily-total calls, and 32 GA4 batches, per site: roughly 1,000 GSC calls and 500 GA4 batches
for 15 sites, minutes rather than nights, and well inside GA4's 40,000 hourly tokens per property
[inferred].

Entry points:
- `clockwork:seo-sync-google {--site=} {--backfill} {--from=}` (`src/Console/SyncGoogleData.php`).
  Sequential over enabled profiles, like `app/Console/Commands/CollectFieldMetrics.php`. Exits failed
  if the connection is revoked or any site errored, so the Scheduled Jobs page shows it
  (`app/Listeners/Scheduling/RecordScheduledTaskResult.php`, which reads background exit codes).
- `SyncSiteGoogleDataJob` (`ShouldQueue`, `$tries = 1`, `$timeout = 900`) for "Sync now" and
  backfill, with a per-site `Cache::lock` whose TTL is just above `$timeout` (920 seconds), as
  `app/Jobs/AbstractRunUpdate.php` does. The database queue's `retry_after` is 90 seconds
  (`config/queue.php:43`), so a long backfill can be handed to a second worker; that copy exits at
  once when it can't get the lock, without `release()`. `failed()` never releases the lock (the first
  copy may still be working); the TTL clears one left by a killed run.
- `RefreshGooglePropertiesJob` (queued) runs `PropertyDiscovery` for the "Refresh list" button; the
  nightly command runs it once before the sites.
- `SeoServiceProvider::scheduledTasks()`: `dailyAt('08:10')` UTC
  `->withoutOverlapping(120)->onOneServer()->runInBackground()->when(fn () =>
  GoogleConnection::query()->exists())`. The guard checks that a connection was ever made, not that
  it is active, so a revoked connection still runs and exits failed. Disconnect on `/seo/settings`
  revokes the token and deletes the row, so a deliberate disconnect stops the schedule without
  failing it. 08:10 UTC is after midnight
  Pacific, so yesterday's GSC day has closed, and it sits outside the busy 01:00 to 07:15 window (no
  daily jobs are scheduled between 07:15 and 09:00 UTC).
- **Disconnect alert (Phase 1).** When `GoogleTokenProvider` gets `invalid_grant`, it moves the
  connection from `active` to `revoked` and sends one plain `ChatNotifier::send()` message through
  `ChatNotifierDispatcher` ("SEO: the Google connection for <account> was revoked. Nightly search
  sync is stopped. Reconnect on /seo/settings."). It sends only on that change, so the alert fires
  once, not nightly. `ClientSlackNotifier::send()` already returns false, so client channels never
  get it. Phase 3 replaces the plain message with the typed `seo_google_disconnected` event.
- Commands registered with `$this->commands([...])` in `boot()`, as `ClientReportsServiceProvider`
  does.

### Quotas, pacing and caching

- **Access token** cached per connection for `expires_in` minus 300 seconds; re-fetched once on a 401.
- **GSC:** at most 1 page or query call per second. On a 429, or a 403 whose error reason is
  `quotaExceeded` or `rateLimitExceeded`, stop that site for the night, record `last_sync_error` and
  move on. The trailing window means the next run catches up with no retry logic.
- **GA4:** `returnPropertyQuota: true` on every batch; skip the property when fewer than 1,000 hourly
  tokens remain (our own safety margin, not a Google figure [inferred]); log remaining tokens with the
  run.
- **DataForSEO:** keyword metrics cached 30 days per keyword and market; only missing or stale
  keywords are requested, in batches of 700. Every call writes `seo_api_usage` with the response's
  `cost`. `BudgetGuard` checks before every paid call. Paid actions sit behind `throttle:10,1`.
- **Pages never call an API.** Two places need care. The profile page reads its property lists from
  `seo_google_properties`, which the nightly sync and the queued "Refresh list" button fill.
  `/settings/diagnostics` runs every module check on each load (`DiagnosticsController::index`), so
  `GoogleConnectionCheck` returns skipped when there is no connection, reuses the cached access token
  and makes a single `sites.list` call. Otherwise only connect, the connection test, "Sync now",
  "Refresh list", "Fetch metrics", page audit, map import and export call out, and all but connect
  and the test run as queued jobs.
- **HTTP conventions:** `Http::timeout()->retry(2, 1000, fn ($e) => ! ($e instanceof
  RequestException && in_array($e->response->status(), [403, 429], true)), throw: false)->acceptJson()`,
  so a quota response is never sent again a second later. Failures are returned as result objects
  with errors cut to 480 characters (`modules/PageSpeedInsights/src/PageSpeedInsightsClient.php`).
  Client-page fetches use `App\Support\SsrfGuard::assertPublic()` and https-only redirects, as
  `app/Services/Seo/IndexabilityChecker.php` does.

### Settings and credentials

- `SeoServiceProvider::manifest()` gains `credentialFields`: `google_client_id`,
  `google_client_secret` (secret), and in Phase 2 `dataforseo_login` and `dataforseo_password`
  (secret). Stored encrypted in `integration_credentials` through `App\Support\CredentialResolver`
  as `seo.*` and edited on `/settings/integrations` like every module.
- `CredentialResolver::get('seo.<field>')` falls back to `config('clockwork.seo.<field>')`, so the
  config keys match the field names. The `seo` block in `config/clockwork.php` gains
  `google_client_id`, `google_client_secret`, `dataforseo_login` and `dataforseo_password`, read
  from `CLOCKWORK_SEO_GOOGLE_CLIENT_ID`, `CLOCKWORK_SEO_GOOGLE_CLIENT_SECRET`,
  `CLOCKWORK_SEO_DATAFORSEO_LOGIN` and `CLOCKWORK_SEO_DATAFORSEO_PASSWORD` (the `CLOCKWORK_` prefix
  used across that file), defaulting to null so the chain moves on. All four go in `.env.example`.
- Google client fallback chain: `seo.*` through the resolver, then `auth_google.*` through the
  resolver, then `services.google.*` (where `modules/AuthGoogle/src/GoogleAuthProvider.php` itself
  falls back). Reusing the login client only works if it is in an Internal project and has the extra
  redirect URI.
- `/seo/settings` (admin, modelled on `modules/AiRemedy/src/Http/Controllers/AiRemedySettingsController.php`):
  Google connection card (account, scopes, last refresh, last error, Connect, Reconnect, Disconnect,
  Test), the redirect URI to register, AI-referrer regex, `final_after_days`, login hint (default
  systems@clockworkwd.com). Phase 2 adds the driver per capability, monthly budget cap and
  month-to-date spend, and the Sheets import and export switch (off by default). Non-secret settings
  go in `app_settings` through `App\Support\Settings`.
- `diagnosticCheck()` returns `GoogleConnectionCheck` (skipped with no connection; otherwise the
  cached token and one `sites.list` call), which turns on "Test connection" on
  `/settings/integrations`.

### Per-site profile

`/seo/sites/{site}/profile`, any active user. It holds the GSC, GA4 and Semrush lookups and the notes
from the roster; recipients, plan doc, plan month and Teamwork tasklist stay in the roster.
- **GSC property:** dropdown from `seo_google_properties` (kind `gsc`) with the permission level
  shown, and a "Refresh list" button that queues `RefreshGooglePropertiesJob`. Properties no longer
  seen are flagged. Auto-suggests `https://www.{domain}/`, `https://{domain}/` and
  `sc-domain:{domain}`. Defaults to the URL-prefix property the reports already use, for continuity
  (decision 6).
- **GA4 property:** property ID typed in (Phase 1); a dropdown from `seo_google_properties` (kind
  `ga4`) in Phase 1b. A "custom primary channel group" checkbox, ticked when the property has one
  (GA4 Admin, Channel groups); the sync then queries `sessionPrimaryChannelGroup`.
- **Brand terms:** comma list that `BrandRegex` compiles into an RE2 regex (each term escaped,
  `(?i)` prefix, 4,096 characters max, editable, with a preview of which of last month's top queries
  it excludes).
- **Key events** (default the three), seasonal YoY toggle, report notes, Semrush domain (removed
  after cancellation). Phase 2 adds market (country, language, city for local volume) and the Google
  Sheet ID; Phase 3 adds tracked queries.
- **Changing a property.** Saving a new `gsc_site_url` deletes the site's rows in `seo_gsc_daily`,
  `seo_gsc_month_rows` and `seo_gsc_months`, resets `history_starts_on` and queues the backfill job.
  A new `ga4_property_id`, or a change to the custom-primary-group flag, does the same for
  `seo_ga4_daily`, `seo_ga4_months` and `seo_ga4_key_event_months`. The form asks first: "This
  deletes stored history for this site and re-pulls 16 months." Otherwise final months from the old
  property would stay, and month-over-month and YoY would compare two different properties.
- Non-WordPress SEO clients (e.g. Michelli on Astro/Vercel) are `custom`-host `sites` rows with
  `is_wordpress = false`.

### Where it shows up

- **`/seo`** (edit `SeoController`, `index.blade.php`): indexability stays. Phase 1 links each site to
  its search page and shows a red banner when the Google connection is revoked. Phase 1b adds
  per-site columns for last full month's clicks and change, organic sessions and change, and profile
  state (Not set up, Syncing, Sync error, OK).
- **`/seo/sites/{site}`** (new): month picker; tiles for clicks, impressions, CTR, average position
  (hidden with a note when the comparison straddles the num=100 change), organic sessions, engaged
  sessions, engagement rate, AI Assistant sessions, key events, organic keywords (GSC count), AI
  impressions and AI share. GA4 tiles read `seo_ga4_months` and `seo_ga4_key_event_months` only. The
  AI Assistant tile reads the AI Assistant channel row (either label); for months before 2026-05-13
  (YoY) it shows the referrer-regex estimate from `seo_ga4_daily`, labelled as an estimate. Daily
  chart with outage bands from `SiteUptimeEvent` and annotations; top pages and top queries with
  change; non-branded; a caveat panel (provisional month, suppressed comparisons, truncation, GA4
  thresholding); a "Copy numbers" block in the email's highlights format; the AI impressions entry
  form. Phase 1b: "Copy numbers" also saves the copied values with a timestamp, and once the month is
  final the page highlights any number that moved since the copy. That replaces the skill's
  "re-pull before finalizing and update any numbers that moved".
- **Site overview widget** (Phase 1b): the core view
  `resources/views/dashboard/site/widgets/_widget-seo.blade.php` gets one `@isset($seoSearch)
  @include('seo::widgets.search-summary') @endisset`. `SeoWidgetComposer`, registered with
  `View::composer` in `SeoServiceProvider::boot()` only while enabled, supplies last-28-days clicks
  and change for sites with a profile. No core controller change.
- **Client Reports** (Phase 4): an optional "Search performance" section.

## Phased roadmap

Each phase ships on its own. Each ends with `composer gate` (Pint, PHPStan, Biome, tsc, Pest) green
on a machine that can install dependencies; this container can't.

### Phase 1: Google connection, nightly sync and the report numbers

Highest value, lowest risk: free read-only data, no writes to client sites, no paid provider. Start
soon: every day of delay loses a day of the 16-month history, and the last pre-num=100 data rolls off
around 2027-01-14. Scope is only what removes a manual step from the monthly report; everything else
waits for Phase 1b.

Build, in order:
1. GCP setup (docs only): Internal project, three APIs, web client, redirect URI.
2. Connection: `GoogleOAuth` (auth URL with session `state`, `access_type=offline`,
   `prompt=consent`, `include_granted_scopes`, `login_hint`, `hd`; code exchange; userinfo for the
   email; revoke), `GoogleTokenProvider` (with the one-time disconnect alert),
   `GoogleConnectionController` (`GET /seo/google/connect`, `GET /seo/google/callback`,
   `POST /seo/google/disconnect`, all admin), `GoogleConnectionCheck`. Plain `Http` rather than
   Socialite, so tests can fake every call.
3. Discovery and profiles: `PropertyDiscovery` (`sites.list` into `seo_google_properties`),
   `RefreshGooglePropertiesJob`, `SiteProfileController` (including the property-change purge),
   profile view.
4. Sync: `SearchConsoleClient`, `AnalyticsDataClient`, `GoogleSyncService`, `SyncGoogleData`,
   `SyncSiteGoogleDataJob`, schedule entry.
5. Numbers: `SearchNumbers` computes a month vs. the previous month and YoY from stored rows. It
   suppresses position comparisons (and flags impressions comparisons) that straddle a suppressing
   annotation, meaning the baseline starts on or before the annotation's end and the current period
   ends after it; that encodes the skill's "never compare position against Sept 2025 or earlier". It
   marks months provisional until final, reads GA4 month figures from `seo_ga4_months` and
   `seo_ga4_key_event_months` only, counts the GSC organic keyword stand-in (distinct queries with
   impressions), and computes AI share = AI impressions ÷ web impressions.
6. AI impressions: `AiImpressionsController` and `AiReportExportParser`. Inputs: a typed number, the
   report's ZIP export (or its Dates.csv plus Pages.csv), or "not available". `ai_impressions` is the
   sum of Dates rows within the chosen month; `top_pages` (top 10) come from Pages.csv; a file whose
   Dates rows fall outside that month is rejected; when the export carries a comparison range, only
   the current-period column is read. ZIPs are opened with `ZipArchive` (already used by
   `app/Services/Companion/CompanionTarballBuilder.php`), with the upload size, entry count and
   uncompressed size capped. Before building, save one real export as
   `tests/Fixtures/gsc-ai-report-export.zip`.
7. UI: `_nav` (Overview | Settings), `/seo` site links and revoked banner, `/seo/sites/{site}` with
   "Copy numbers", `seo-search-chart.ts`, `/seo/settings`.

Migrations (`modules/Seo/database/migrations/`): `create_seo_google_connections_table`,
`create_seo_google_properties_table`, `create_seo_site_profiles_table`, `create_seo_gsc_daily_table`,
`create_seo_gsc_month_rows_table`, `create_seo_gsc_months_table`, `create_seo_ga4_daily_table`,
`create_seo_ga4_months_table`, `create_seo_ga4_key_event_months_table`,
`create_seo_ai_impressions_table`, `create_seo_annotations_table` (with seed rows).

Tests (Pest, `Http::preventStrayRequests()`, new fixtures `tests/Fixtures/GoogleOAuthFixtures.php`,
`SearchConsoleFixtures.php`, `GoogleAnalyticsFixtures.php`, `gsc-ai-report-export.zip`):
- `tests/Feature/Seo/GoogleConnectionTest.php`: connect URL carries offline, consent, the scopes and a
  session `state`; callback rejects a bad state; refresh token stored encrypted (raw column differs
  from the token) and never rendered; a token response without `refresh_token` is an error;
  non-admins get 403; disconnect calls revoke.
- `tests/Feature/Seo/GoogleTokenProviderTest.php`: access token cached until near expiry;
  `invalid_grant` marks the connection revoked and sends one chat message, and a second failure sends
  none.
- `tests/Feature/Seo/GoogleConnectionCheckTest.php`: skipped with no connection; with a cached token,
  one `sites.list` call and no token request.
- `tests/Feature/Seo/SearchConsoleClientTest.php`: URL-prefix and `sc-domain:` encoding; paging
  continues while a page is full and stops on a short one; the brand filter uses `excludingRegex`; a
  429 request is sent exactly once and returns an error result, not an exception; a 403
  `quotaExceeded` is treated the same; errors cut to 480 characters.
- `tests/Feature/Seo/SyncGoogleDataTest.php`: inserts and updates are idempotent; the 5-day re-pull
  overwrites changed numbers; a month turns final only after month end + 5 days; one site's quota
  error doesn't stop the next; a revoked connection makes the command exit failed; the property list
  is refreshed and unseen properties keep their old `last_seen_at`.
- `tests/Feature/Seo/Ga4SyncTest.php`: the daily batch carries the medium OR source-regex filter; the
  month batch uses the calendar month as its range with no `date` dimension and carries the key-event
  list; month figures come from the month-range response, not from summed days (fixture where the
  two differ); engagement rate comes from that response; `subjectToThresholding` is stored and shown;
  `sessionPrimaryChannelGroup` is used when the profile flag is set.
- `tests/Feature/Seo/SearchNumbersTest.php`: month, previous month and YoY; position change is null
  when the baseline starts on or before 2025-09-14 (Aug and Sept 2025 baselines suppressed, Oct 2025
  shown); provisional flag; non-branded labelled; AI share; "not available" never becomes 0.
- `tests/Feature/Seo/BrandRegexTest.php`: escapes "A+ Plumbing"; adds `(?i)`; rejects patterns over
  4,096 characters; the preview excludes the right queries.
- `tests/Feature/Seo/SiteProfileTest.php`: save queues the backfill; a GSC property change purges the
  `seo_gsc_*` rows and resets `history_starts_on`; a GA4 property or flag change purges the
  `seo_ga4_*` rows; both ask for confirmation first; the form reads `seo_google_properties`, flags
  unseen properties and makes no HTTP call.
- `tests/Feature/Seo/SeoScheduleTest.php`: the 08:10 entry with `withoutOverlapping` and
  `onOneServer`; it runs, and exits failed, when the connection is revoked; it doesn't run when no
  connection was ever made.
- `tests/Feature/Seo/AnnotationSeedTest.php`: the four seeded annotations, their dates and what each
  suppresses or flags.
- `tests/Unit/Modules/Seo/AiReportExportParserTest.php`: runs against the real ZIP fixture; "~" and
  "-" become 0; the month total comes from Dates, not Pages; dates outside the month are rejected; a
  comparison export uses the current period; bad files rejected.
- `tests/Feature/Seo/SiteSearchDashboardTest.php` and `tests/Feature/Seo/SeoModuleTest.php`
  (extend): pages render with `mockIssueCounterZero()` and make no HTTP calls; the revoked banner
  shows.
- `tests/Feature/Modules/ModuleRegistryAggregationTest.php`: add `GoogleConnectionCheck` to the
  diagnostic-check list (L143-171).

Docs: `resources/docs/features/seo.md` (Google connection setup, profiles, sync, caveats, AI
impressions; bump `updated:`), `CHANGELOG.md` under `[Unreleased]`,
`resources/docs/reference/{artisan-commands,scheduled-jobs,env-vars,web-routes,api-endpoints-we-call}.md`.
Outside the repo: the GSC, GA4 and Semrush lookups in step 0, and step 3, of the `monthly-seo-report`
skill point at `/seo/sites/{site}`; the roster stays for recipients, plan doc, plan month and
Teamwork tasklist.

Acceptance: **the team no longer has to open Search Console or GA4 to get a client's monthly
numbers.** Semrush Domain Overview is no longer needed if decision 5 picks the GSC count; otherwise it
is needed until Phase 2. The only manual pull left is the GSC Generative AI number, typed or
uploaded. Check: for two clients, the app's October 2026 numbers match a manual Chrome pull once
final (GSC clicks and impressions equal, CTR and position within rounding; GA4 month totals equal a
UI pull of the same month and channel grouping; key events equal), and the multimodal question is
answered on one property (API web total vs. UI "Web" and "Web: text"), keeping or deleting the
2026-09-24 annotation accordingly.

### Phase 1b: after the first clean report

None of this removes a manual step, so it waits until one report cycle has run from the app.
- `/seo` columns (last full month's clicks and change, organic sessions and change, profile state).
- `SeoWidgetComposer` and the one-line include in the core site SEO widget.
- Module directory category: move `seo` out of `misc` in `ModuleCatalog::categorize()` (into
  `performance`) so it shows outside the module directory's All tab.
- `AnalyticsAdminClient`: `accountSummaries.list` into `seo_google_properties` (kind `ga4`),
  refreshed with the GSC list, so the GA4 property becomes a dropdown.
- "Copy numbers" history: `create_seo_number_copies_table`, and the moved-number highlight.

Tests: `tests/Feature/Seo/SeoWidgetComposerTest.php` (the include appears only for a site with a
profile; no composer while the module is disabled), `tests/Feature/Seo/PropertyDiscoveryTest.php`
(GA4 rows from `accountSummaries`, paging at 200), `tests/Feature/Seo/NumberCopyTest.php` (a number
that moved after the copy is highlighted once the month is final), and `SeoModuleTest` extended for
the columns.

### November 2026: last Semrush exports (no code)

In the same early-November sitting as the October reports, export from Semrush and save as CSV files
in each client's Drive folder:
- Volume, KD, CPC and Intent for every keyword already in a client map (Keyword Overview bulk, 100
  keywords per lookup, about five lookups).
- Each client's organic keyword history from Domain Overview.

That makes the October report the last Semrush use, so the no-login billing cycle starts in early
November. Phase 2's map import reads Volume, KD and CPC from the client sheets themselves, so the
keyword CSVs are kept for an optional difficulty calibration later. The history CSV is imported only
if decision 5 picks a third-party count; with the GSC count it stays a file.

### Phase 2: Keyword data and the keyword map

This is the phase that removes the Semrush dependency.

Build:
1. Provider layer: contract, value objects, `DataForSeoClient` (basic auth, records `cost` from each
   response, turns a failed task status into an error result), `DataForSeoKeywordMetrics`
   (`/v3/dataforseo_labs/google/keyword_overview/live`, plus
   `/v3/keywords_data/google_ads/search_volume/live` for city volume), `NullKeywordMetrics`,
   `SeoDataManager`, `BudgetGuard`. Paths come from the docs URLs in the research; confirm on the
   first live call, and confirm whether KD and intent come back from `keyword_overview`. If not, add
   the `bulk_keyword_difficulty` and `search_intent` calls. Difficulty bands are fixed defaults in
   config (`clockwork.seo.difficulty_bands`: 0-29 Easy, 30-49 Medium, 50-69 Hard, 70-100 Very hard),
   labelled "default, not calibrated" wherever they show. Calibrating them against Semrush KD is an
   optional later task, outside the exit criterion.
2. `PageAuditor`: fetch each URL (SSRF guard, https-only redirects, timeout, `UptimeProber::userAgent()`
   as the User-Agent) and parse title, meta description, H1s, canonical, meta robots, JSON-LD `@type`
   values (including inside `@graph`) and word count (text outside script, style and nav), with
   `DOMDocument`/`DOMXPath` as in `app/Services/Seo/IndexabilityInspector.php`. A 401 or 403 (the
   rule in `UptimeProbeResult::isLikelyWafBlock()`) or a challenge page is saved as `fetch_status`
   `blocked`, never as an empty audit. The skill fetched pages from a browser tab on the client's
   site; a server-side fetch can be stopped by a firewall. Fills the Current State view.
3. **Import existing map.** The first step for each client in the parallel run. `KeywordMapImporter`
   reads the newest "Keyword Map (...)" and "Current State (...)" tabs (newest by the date in the tab
   name) with Sheets API `spreadsheets.values.get` (the same `spreadsheets` scope), or from uploaded
   CSVs of the two tabs when Sheets is off. Keyword Map columns A to P go into
   `seo_keyword_map_rows`: # to `sort`; Page; URL, where `/slug/ (NEW)` sets `is_new_page`; Primary
   Keyword; Volume; KD % to `difficulty`; CPC; Secondary Keywords split on ";" with "term (390/mo)"
   split into keyword and volume; Intent; Proposed Title; Proposed Meta (the two Len columns are
   recomputed, not imported); Schema to `schema_types`; Ship Status parsed into `ship_status`,
   `shipped_on` and `ship_note` ("Live 9/24 (title + meta)" is live, 2026-09-24 with the year from the
   tab name, note "title + meta"; "Drafted (Page 3, September)"; "Not started (quick win)"; "Planned
   (new page)" is `planned_new`; anything else is `not_started` with the full text in `ship_note` and
   `needs_review` set); Notes. Current State Notes go to `current_state_notes`, matched by #.
   Imported Volume, KD and CPC keep `metrics_provider = 'semrush'`. The result is saved as a map with
   status `current` and source `sheet_import` or `csv_import`.
4. Keyword maps: `KeywordMapController`, views, `keyword-map-editor.ts`.
   - "Refresh from GSC" builds candidate rows from the last 3 final months of `page_query` rows, with
     the brand regex, a junk filter (quoted strings, search operators) and one-click exclude, into a
     new dated copy of the current map, so imported primary keywords, proposed text, ship status,
     notes and approvals carry over.
   - "Fetch metrics" queues `FetchKeywordMetricsJob`.
   - Live character counts (title 50 to 60, meta 140 to 160, counted with `mb_strlen`), a warning when
     two rows share a primary keyword, with a "pages for this query" panel next to it (every page
     with impressions for that query, from `page_query` rows; the skill's cannibalization check), an
     em-dash lint on notes and proposed text, and GSC impressions next to volume so local terms are
     judged on GSC.
   - "Recommended next page": the Not started row with the most non-branded GSC impressions over the
     3 months, with that one line of data, for a person to confirm (skill step 7).
   - "New dated map" copies the current map; older maps are read-only.
5. Export: `SheetsExporter` adds dated tabs to the client's existing sheet (`google_sheet_id` on the
   profile) through the Sheets API `batchUpdate`: Keyword Map (A to P), Current State (A to I) and the
   optional GSC Queries tab (Page, Query, Clicks, Impressions, CTR, Avg Position, Use as, Notes).
   Title in A1 with its wrap set back to overflow, headers in row 3, bold, wrap, top align, frozen to
   row 3. Widths: Keyword Map A to P 40,170,230,190,80,50,60,300,150,260,45,320,45,170,170,420 (the
   skill's); Current State A to I 40,170,300,45,360,45,260,80,420 (our choice; the skill gives none).
   Column F's header is "Difficulty (0-100)" and its value carries the band, for example "34
   (Medium)". F only ever holds DataForSEO difficulty, so a row still on imported Semrush values
   exports with F blank until "Fetch metrics" runs. A2 of each new tab says: "Difficulty now comes
   from DataForSEO and is not comparable to Semrush KD % in earlier tabs." Existing tabs are never
   touched. CSV download (one file per tab, same layout) is the default; Sheets import and export are
   turned on in `/seo/settings` and need the `spreadsheets` scope (one reconnect). Both are admin-only
   and logged in `action_logs`.
6. Domain counts, only if decision 5 picks a third-party count: `DomainAnalyticsProvider`,
   `DataForSeoDomainAnalytics` (`/v3/dataforseo_labs/google/domain_rank_overview/live`),
   `NullDomainAnalytics`, `seo_domain_snapshots`, `clockwork:seo-snapshot-domains` (monthly on the
   6th at 08:40 UTC), and an import of the November history CSV as provider `semrush` so trend lines
   continue. With the GSC count (the default) none of this is built.

Migrations: `create_seo_api_usage_table`, `create_seo_keyword_metrics_table`,
`create_seo_keyword_maps_table`, `create_seo_keyword_map_rows_table`, `create_seo_page_audits_table`,
`add_market_and_google_sheet_id_to_seo_site_profiles_table`; `create_seo_domain_snapshots_table`
only with a third-party count.

Tests (new fixtures `tests/Fixtures/DataForSeoFixtures.php`, `GoogleSheetsFixtures.php`, and a
Scottsdale-format sheet in `tests/Fixtures/KeywordMapSheetFixtures.php` with matching CSVs):
- `tests/Feature/Seo/DataForSeoKeywordMetricsTest.php`: basic auth header; 1,500 keywords split into
  batches of 700; null volume stays null; cost written to the ledger; difficulty scale set; a failed
  task becomes an error result.
- `tests/Feature/Seo/KeywordMetricsCacheTest.php`: only missing or stale keywords are requested.
- `tests/Feature/Seo/BudgetGuardTest.php`: blocks a call that would pass the cap.
- `tests/Feature/Seo/SeoDataManagerTest.php`: the driver is read from `app_settings`; the Null driver
  is used when the setting is empty or the driver is unconfigured.
- `tests/Feature/Seo/FetchKeywordMetricsJobTest.php`: fills the map rows' snapshot columns with
  `metrics_provider = 'dataforseo'`; a `BudgetGuard` refusal leaves rows untouched and records why.
- `tests/Feature/Seo/PageAuditorTest.php`: SSRF guard consulted; https to http redirect refused;
  `@graph` types found; word count ignores scripts; sends `UptimeProber::userAgent()`; a 403 or a
  challenge page is stored as `blocked`; a second audit overwrites the row.
- `tests/Feature/Seo/KeywordMapImportTest.php`: from the Scottsdale-format fixture, secondaries split
  into keyword and volume; `(NEW)` sets `is_new_page`; the four Ship Status examples parse; unknown
  text is kept in `ship_note` and flagged; Semrush values kept with `metrics_provider = 'semrush'`;
  the map is saved as `current`; the CSV path gives the same rows.
- `tests/Feature/Seo/KeywordMapTest.php`: a refresh creates a new dated map that keeps imported
  primary keywords, proposed text, ship status and notes, and leaves the old map read-only; duplicate
  primary keyword warned with the pages-for-this-query panel; em dash rejected; lengths counted in
  characters.
- `tests/Feature/Seo/SheetsExporterTest.php`: the request adds tabs named with the date, writes row-3
  headers with F3 "Difficulty (0-100)" and banded values, writes the A2 note, sets A1 to overflow,
  freezes 3 rows, sets the 16 Keyword Map and 9 Current State widths, and adds the optional GSC
  Queries tab; existing tabs untouched; non-admins get 403; an action log row is written.
- `tests/Feature/Seo/SemrushHistoryImportTest.php`, only with a third-party count.

Docs: `seo.md` (map import, keyword maps, provider, budget, difficulty bands), `CHANGELOG.md`,
reference docs (env vars, routes, DataForSEO and Sheets endpoints; the snapshot command and schedule
only with a third-party count). Outside the repo: the `create-keyword-mapping-doc` skill uses the app
for steps 1 to 6.

Acceptance: **the team no longer has to run Semrush Keyword Overview bulk lookups, scrape GSC
tables, or paste and format rows into Google Sheets by hand.** Check: one real client's existing map
imported, refreshed end to end in the app and exported to its sheet as new dated tabs that match the
Scottsdale format, with the Difficulty header and the A2 note.

### Phase 3: Ship verification and watching target pages

Build:
- `clockwork:seo-audit-mapped-pages`, daily at 08:30 UTC: audits every URL in each current map,
  overwriting that URL's `seo_page_audits` row. A row moves to `verified` when the live title, meta
  and H1 match the proposed values (whitespace and entities normalized); `drifted_at` is set when a
  verified row stops matching.
- `clockwork:seo-watch-targets`, weekly Monday 08:50 UTC. It makes its own weekly GSC calls: one
  `page` call filtered by an `includingRegex` built from the mapped URLs and one `query` call filtered
  by one built from the tracked queries (split when a regex passes 4,096 characters), for the last 7
  final days. Rows go into `seo_tracked_query_weeks` as kind `page` or `query`. On its first run for a
  site it backfills the previous 5 weeks, so the 4-week average works at once. Alerts past a threshold
  (default: clicks down 30% on a page with at least 20 weekly clicks [inferred default]).
- A drop panel on `/seo/sites/{site}`: branded vs. non-branded split, the pages that lost most, outage
  days (`SiteUptimeEvent`), "Last indexability change: <date>" from `sites.seo_state_changed_at`
  (only the latest change is stored), and plugin, theme and core updates in the window from
  `App\Models\PluginUpdateJob` (its `kind` covers plugin, theme, core and translation).
- Chat events `seo_target_page_drift` and `seo_traffic_drop` (site-scoped, through
  `dispatchForSite()`) and `seo_google_disconnected` (no site; replaces Phase 1's plain message),
  wired the way `plans/seo-indexability-watchdog.md` did: `ChatNotifier::EVENTS` and the interface,
  `app/Services/Chat/ChatNotifierDispatcher.php`, `modules/Core/src/Support/WebhookChatNotifier.php`,
  `modules/ClientSlack/src/ClientSlackNotifier.php` (returns false: internal alerts don't go to client
  channels), and the double in `tests/Feature/Chat/ChatNotifierGatingTest.php`.

Migrations: `create_seo_tracked_query_weeks_table`, `add_tracked_queries_to_seo_site_profiles_table`,
`add_drifted_at_to_seo_keyword_map_rows_table`.

Tests: `tests/Feature/Seo/AuditMappedPagesTest.php` (verified and drift transitions alert once, not
daily); `tests/Feature/Seo/WatchTargetsTest.php` (the drop threshold; the first run backfills 5
weeks; page and query rows keyed by `row_hash`); `tests/Feature/Seo/SeoScheduleTest.php` (extend: the
two new entries); `tests/Feature/Chat/ChatNotifierGatingTest.php` (the three methods on the double,
the two site-scoped ones in the `gatedMethods` dataset, a call-through test for
`seoGoogleDisconnected`, and ClientSlack ignoring all three).

Docs: `seo.md`, `CHANGELOG.md`, reference docs.

Acceptance: **the team no longer has to check by hand that shipped titles and metas are live, or
find out about a target page's drop only at report time.**

### Phase 4: Report drafting and the Client Reports section

Build:
- `/seo/sites/{site}/report/{month}`: the monthly email in house format (Big picture, highlights,
  what we did, for your review, coming up). Numbers, highlights and caveat lines are generated.
  "Coming up" offers keyword-map rows marked Planned or Drafted, labelled "suggestions; check against
  the plan doc", never as final text, since the skill says Coming up lists what is in the plan. "What
  we did" stays manual (Teamwork is the source). An optional "Big picture" draft through
  `modules/AiRemedy/src/Services/OpenRouterClient.php` when that module is enabled (decision 9; key
  architectural decision 7 has the `optional_requires` and runtime checks). The output is "Copy for Gmail"
  (HTML with linked anchors). It never sends.
- AI visibility panel on the site page: AI impressions trend and share, GA4 AI Assistant sessions by
  source.
- Client Reports section `seo` ("Search performance"):
  - Contents: GSC clicks, impressions, CTR and top 5 pages; GA4 organic sessions, engaged sessions
    and key events (from the month tables); the organic keyword count.
  - New `modules/Core/src/Contracts/ReportSectionProvider.php` (`key()`, `compile(Site, Carbon,
    Carbon): ?array`, `reviewReasons(array): array`), collected through a `clockwork.report_sections`
    container tag (precedent: `clockwork.notifiers` in `app/Providers/AppServiceProvider.php`). Seo
    tags its provider in `register()` only while enabled, so ClientReports never imports Seo
    classes.
  - Add `seo` to `ClientReportTemplate::SECTIONS` (hidden in `templates/form.blade.php` when no
    provider is tagged); `ClientReportCompiler::compile()` calls the tagged provider; bump
    `SCHEMA_VERSION` to 4.
  - The section reads stored rows only and carries `has_data`. While the month isn't final it adds a
    `ReportReviewFlags` reason: "Search numbers for <month> are provisional until <month end + 5
    days>. On or after that date, use Send now on the schedule to recompile. Send from history mails
    the provisional numbers." The month is never final at 06:00 UTC on the 1st (still the last day
    of the month in Pacific Time), so every report with this section is held every month.
    `sections_data` is a snapshot from that run: "Send" in report history
    (`ClientReportsController::send`) mails it as is, while "Send now" on the schedule recompiles,
    because `ScheduledReportRunner::resumableReport()` returns null for a held report. The daily run
    doesn't retry a held report, since `next_run_at` has already moved to next month.
  - Scheduled reports run only for care-plan sites (`ScheduledReportRunner::siteIsEligible()`), so an
    SEO client without a care plan never gets this section (decision 8). The report page above works
    for every site with a profile.
  - Later, optional: a per-schedule run day, so SEO templates compile on the 6th after the month is
    final.
  - Views behind `isset($data['seo'])`: `modules/ClientReports/resources/views/emails/report.blade.php`,
    `emails/report-text.blade.php`, `reports/show.blade.php`, `reports/email.blade.php`. Email charts
    are HTML and CSS bars, no JavaScript.
  - Not added to the default template; operators enable it on an "SEO clients" template (decision 8).

Tests: no provider means no section and no error; compile makes no HTTP calls; review reason text
while provisional; recompiling with Send now after the final date removes the reason and updates the
numbers; schema version 4; every view renders with and without the section; the OpenRouter draft is
skipped when `ai-remedy` is disabled; extend `tests/Feature/Modules/ClientReport*Test.php` and
`tests/Unit/ReportReviewFlagsTest.php`.

Docs: `seo.md`, `resources/docs/features/client-reports.md`,
`resources/docs/internal/client-reports-tracker.md` (close the "Google Analytics section" item, since
GA4 is in the section; reword the "SEO Ranking section" item to "keyword rankings: Phase 5" instead
of closing it), `CHANGELOG.md`.

Acceptance: **the team no longer has to type the numbers, highlights and caveats of the monthly SEO
email by hand.**

### Phase 5 (optional, only with a client need and budget)

Each item ships alone when a deliverable needs it: DataForSEO SERP rank tracking (new `SerpProvider`
contract; Standard queue, depth 30, AI Overview flag, Maps for local clients, 4-week smoothing in
reports); competitor ranked keywords and keyword gap; backlink summary; Bing Webmaster API (one agency
API key covers every verified site; OAuth tokens reportedly rejected since 2026-10-14 [secondary]);
AI-crawler hits from `app/Services/Logs/NginxLogParser.php` (only servers whose logs Control already
reads). Costs are in Appendix B.

## Exit criterion: when Semrush can be cancelled

Cancel when all of these are true:
1. Phases 1 and 2 are live (1b is not required).
2. For two consecutive month-end reports, every active SEO client's GSC, GA4 and organic keyword
   numbers came from the app, with no Chrome pulls of GSC, GA4 or Semrush (the GSC Generative AI
   number excepted).
3. Every client whose map refresh falls in the parallel run was refreshed in the app with DataForSEO
   metrics and exported, at least 3 clients end to end, and every other client's current map was
   imported from its sheet.
4. The November exports are saved: Volume, KD, CPC and Intent for keywords already in client maps,
   and each client's organic keyword history (imported only if decision 5 picks a third-party
   count).
5. Nobody logged into Semrush for one full billing cycle, counted from the last login (the October
   reports and exports in early November).

Then cancel at the next renewal date (decision 1). Earliest cancel is the first renewal date that is
at least one full billing cycle after the last Semrush login and after the December report is final
(about 2027-01-06). That is mid-January only if the exports are done in November; otherwise February
2027. It also assumes Phase 1 ships in November, so the November report written in early December
comes from the app, and Phase 2 in December; each month either slips moves the date by a month
[inferred]. Phases 1b and 3 to 5 are not required.

## Risks and data caveats

- **num=100 change (about 2025-09-10 to 14).** Impressions fell and average position "improved"
  mechanically on most sites; clicks were essentially unaffected [secondary]. The seeded annotation
  suppresses position comparisons and flags impressions comparisons that straddle it. Sept 2026 YoY
  is affected; Oct 2026 onward is clean. Reports lead with clicks.
- **Late-month backfill and Pacific Time.** GSC days close in Pacific Time and late data keeps
  arriving for a few days [docs]. GSC and GA4 months stay provisional until month end + 5 days and
  are re-pulled nightly; the Client Reports section holds for review while provisional, and only Send
  now on the schedule recompiles it.
- **GA4 month figures.** A property with a custom primary channel group needs the profile flag, or
  organic sessions won't match the UI. Month-range requests and the thresholding caveat cover the
  rest (see GA4 above).
- **Multimodal split (2026-09-24).** It may add a new impressions series, breaking impressions YoY
  again, and may change the AI-share denominator [secondary]. Checked once in Phase 1; clicks stay
  the lead metric.
- **Generative AI report has no API.** One manual step per client per month remains until Google adds
  one. Its data starts 2026-05-18. The export format is assumed from the Performance report; the
  parser is built against one real export.
- **Anonymized queries.** Non-branded and per-query numbers exclude them, so they never add up to the
  total [docs]. Labelled "known queries".
- **Local volume undercount.** City-level volume comes only from Google Ads data, volume only and
  coarse [docs]. The existing rule stays: for local terms, trust GSC impressions; the map shows both.
- **Difficulty is not Semrush KD.** DataForSEO difficulty is 0 to 100 from the backlink strength of
  the top 10 results [docs]; Semrush KD% is a different model and no correlation study exists.
  Handled in Phase 2 step 5: a "Difficulty (0-100)" header, uncalibrated default bands, an A2 note,
  and old Semrush tabs left alone.
- **Organic keyword count changes meaning.** The GSC count is real Google queries with impressions.
  DataForSEO's count comes from a database of about 8B keywords vs. Semrush's about 28B, so it will
  be lower [secondary]. Switch at a month boundary, restate the previous month on the new method, and
  say "methodology change" once.
- **Quotas.** The GSC load quota is the only realistic limit; pacing and the trailing window handle
  it, and a quota response is never retried. GA4 use is about 50 of 200,000 daily tokens per
  property on a normal night [inferred]. DataForSEO spend is capped in code.
- **OAuth.** Internal only works if the GCP project sits in the clockworkwd.com organization and
  systems@ is a Workspace user. A revoked token stops every sync: the nightly command still runs and
  exits failed on the Scheduled Jobs page, one chat message goes out (Phase 1), and `/seo` shows a
  banner. Reconnecting repeatedly risks the 100-token cap. An External app would need up to 10
  business days of verification [docs].
- **Sheets scope.** `spreadsheets` gives read and write access to every sheet systems@ can open, and
  the refresh token (encrypted) sits in Control's database. Mitigations: Sheets import and export are
  off by default and turned on in `/seo/settings`, and the scope is only requested then; CSV download
  is the default; both actions are admin-only and logged in `action_logs`; Disconnect revokes the
  token at Google.
- **Property access.** systems@ may lack some sc-domain properties; profiles default to the URL-prefix
  property already in use. Switching property deletes the site's stored history and re-pulls it,
  after a confirmation.
- **Server-side page fetches.** A client's firewall may block `PageAuditor` where the skill's browser
  tab got through; those URLs show as "blocked", not as empty pages.
- **Unverified prices.** DataForSEO's extra-page discount and the July 2026 Keywords Data increase are
  unresolved, and the Labs rate came from a secondary source. One test call's `cost` settles each.
  Google Ads API data for no-spend accounts is unconfirmed.
- **GA4 compatibility.** The Data API rejects some dimension and metric combinations; Phase 1's check
  against the UI catches that before any report uses the numbers.

## Decisions needed from Aaron

| # | Decision | Recommended default |
|---|---|---|
| 1 | Semrush plan, seats, monthly or annual billing, renewal date | Needed to time the cancel and state the saving. The plan assumes Pro, monthly |
| 2 | How many SEO clients, and which sites get profiles | SEO-plan clients only (15 assumed); fleet-wide later as a care-plan upsell |
| 3 | Google auth | OAuth, Internal consent screen, connected once as systems@clockworkwd.com; a service account only for a client systems@ can't access |
| 4 | Paid keyword provider and cap | DataForSEO, $50 top-up, $75/month cap |
| 5 | What "organic keywords" means in client reports | GSC "queries with impressions", with a one-time note to clients; the Semrush history kept as a file. A third-party count adds the DataForSEO domain snapshot command, contract, driver and table, and keeps Semrush Domain Overview in use until Phase 2 |
| 6 | GSC property per client | Keep the URL-prefix property the reports already use |
| 7 | Where the keyword map lives | Control is the source of truth; each client's existing sheet imported once; dated tabs exported one way when Sheets is turned on (adds the `spreadsheets` scope), CSV download otherwise |
| 8 | Client Reports SEO section | Opt-in per template; held for review while the month is provisional, then recompiled with Send now. Only care-plan sites get scheduled reports, so SEO-only clients use the Phase 4 report page |
| 9 | LLM drafting with client GSC data (Phase 4) | Yes, with a person reviewing; never auto-send |
| 10 | Manual-time baseline | Pull the last 3 months of Teamwork timelogs on SEO tasks to replace the estimate |

## Not doing

- Teamwork timers and timelogs, plan docs, Drive sharing: they stay in the skills and the Teamwork MCP.
- Recipients, plan doc, plan month and Teamwork tasklist in Control: the roster keeps them.
- Sending email or creating Gmail drafts from Control; the report page only produces copy-ready HTML.
- A client portal or client logins; clients see the email, the PDF and their sheet.
- Writing titles or metas to WordPress (Yoast, Rank Math) through Companion. Linking to the editor is
  a later Companion capability in the separate repo.
- PDF output (already a Client Reports backlog item); the CW PDF skill stays.
- XLSX export: no spreadsheet library is in `composer.json`, and CSV covers the fallback.
- Paid SERP rank tracking, competitor research, backlinks, local listings and map rank, traffic
  analytics, content tools, LLM prompt tracking and Bing: Phase 5 or never.
- A site-wide crawler; audits cover mapped URLs only.
- Country, device or search-appearance breakdowns, and daily page or query rows.
- Any Google SDK beyond `google/auth`, and that only if a service account is added.
- Changes to `modules/AuthGoogle` login scopes.

## Appendix A: fallback keyword-data vendors

Behind the same contracts, none built now:
- Volume: Google Ads API `generateKeywordHistoricalMetrics` (free). Needs Basic access (brand
  verification now mandatory), planning calls are limited to 1 per second per customer ID, it has no
  KD or intent, and whether no-spend accounts get exact numbers through the API is unconfirmed
  [docs/secondary]. Keywords Everywhere ($84/year Bronze) covers volume and CPC [secondary].
- Full suite: Ahrefs Lite ($129/month, API v3 included since March 2026), month to month, emergency
  only [docs/secondary].
- The Semrush API is ruled out: Semrush One Advanced at $549/month plus separately sold units
  [docs/secondary].

## Appendix B: Phase 5 cost

Weekly rank tracking of 75 keywords per client (75 × 4.333 × 15 = 4,875 checks) adds $7.31 to $8.77
at depth 30, or $22.67 to $29.25 at depth 100, depending on whether DataForSEO's 25% extra-page
discount applies [docs, pages conflict]. Everything including competitors, backlinks and AI Overview
checks comes to about $23 to $47 [verified arithmetic], inside the $75/month cap.
