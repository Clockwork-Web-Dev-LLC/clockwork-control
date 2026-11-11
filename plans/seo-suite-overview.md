# SEO suite: how it all fits together

> **Status (2026-11-11).** A short map of the whole SEO suite: what each part does, how the parts
> connect, what is built, and what order the rest goes in. Written by Claude for Aaron. The detail
> (file lists, table columns, test lists, research sources) lives in
> [`seo-semrush-replacement.md`](./seo-semrush-replacement.md), referred to here as "the full plan".
> Where the two disagree, the full plan's dated status notes win, and this file should be corrected.

## The goal

Cancel Semrush ($1,027.70 a month) and take the hand work out of two jobs:

1. **The monthly SEO report**: today someone reads numbers out of Search Console, GA4 and Semrush in
   Chrome for each client.
2. **The keyword map**: today someone scrapes Search Console, pastes keywords into Semrush 100 at a
   time, and pastes and formats the result in Google Sheets.

When the suite is finished, Control holds the numbers and the maps, and Claude does both jobs by
asking Control. People still review everything before it goes to a client.

## The picture

```
                 FREE                                PAID (metered)
   Google Search Console      GA4                    DataForSEO
            |                  |                          |
            |  nightly sync    |                          |  only when a person
            v                  v                          v  approves a quoted cost
   +---------------------------------------------------------------+
   |                     CLOCKWORK CONTROL                         |
   |                                                               |
   |  Stored search data          Keyword metrics (30-day cache)   |
   |  (daily totals, monthly      Spend ledger + budget cap        |
   |   pages and queries,                                          |
   |   GA4 month figures,         Keyword maps  <--- one-time      |
   |   AI impressions typed in)   Page audits        import from   |
   |                                   |             each client's |
   |  Per-site SEO profile             |             Google Sheet  |
   |  (properties, brand terms,        |                           |
   |   key events, report notes)       |                           |
   +-------+---------------+-----------+---------------+-----------+
           |               |           |               |
           v               v           v               v
     Pages in Control   Tools for    Export to       Client Reports
     (/seo, site        Claude       the client's    "Search performance"
     numbers, profile,  (MCP)        Google Sheet    section (care-plan
     settings)             |         as dated tabs   sites)
                           v
              Claude: claude.ai, Cowork, Claude Code
              (each person signs in as themselves)
                           |
                           v
              The two skills: monthly-seo-report,
              create-keyword-mapping-doc
              (Gmail draft, Teamwork, Drive, PDF stay in the skills)
```

Three rules hold the whole thing together:

- **Store first, read from storage.** Pages, tools and reports read what Control stored. Nothing a
  person or Claude opens calls Google. That keeps it fast, keeps history past Search Console's 16
  months, and means the page and Claude can never show different numbers.
- **A missing value is "not available", never 0.** A comparison a Google change broke is left out
  with a note saying why.
- **Nothing costs money or reaches a client without a person saying yes.** Paid lookups need an
  approved quote. Reports are drafts. Sheet exports are admin only.

## The parts

| Part | What it does | Status |
|---|---|---|
| Indexability watchdog and `/seo` page | Flags sites that block search engines | Built (before this plan) |
| Google connection | One read-only sign-in as the agency account; every property it can see becomes available | Built |
| Per-site SEO profile | Which Search Console and GA4 properties, brand terms, key events, report notes, year-over-year flag | Built |
| Nightly sync (`clockwork:seo-sync-google`, 08:10 UTC) | Copies Search Console and GA4 data into Control; re-pulls the last 5 days and every month not yet final | Built |
| Report numbers (`SearchNumbers`) | A month against the previous month and last year, with caveats | Built |
| Site numbers page (`/seo/sites/{site}`) | A plain table of those numbers | Built (plain table only) |
| AI impressions | Typed in per month, by a person or by Claude, because Google has no API for that report | Built |
| Claude access module | One server Claude talks to; other modules add tools | Built |
| Six SEO tools | List sites, month numbers, daily series, pages and queries, record AI impressions, update profile | Built |
| Claude access settings page and network endpoint | The switch, one-click setup, who is connected, per-person sign-in | Built, switched off, not yet reachable from outside |
| **Public address for Claude** | A hostname that lets only the Claude paths through | **Not done: outside the code, Aaron R's decision** |
| **The two skills rewritten to call the tools** | The actual removal of the hand work | **Not done: outside the repo** |
| DataForSEO connection, ledger, budget cap | Buys keyword volume, difficulty, CPC and intent | Not built (Phase 2) |
| Keyword metric tools | Quote, then buy | Not built (Phase 2) |
| Keyword maps | Imported from each client's sheet, edited through Claude, checked on every edit | Not built (Phase 2) |
| Page audit | Reads a live page's title, meta, H1s, schema and word count | Not built (Phase 2) |
| Sheets import and export | Reads the existing map; writes new dated tabs in the existing format | Not built (Phase 2) |
| Nicer site page, `/seo` columns, site widget, GA4 property picker | Tiles, chart, top pages and queries | Not built (Phase 1b, optional) |
| In-app map editor | For editing maps without Claude | Not built (Phase 2b, optional) |
| Ship verification and drop alerts | Checks daily that shipped titles and metas are live; weekly alert when a target page drops | Not built (Phase 3) |
| Report wording and Client Reports section | House wording for numbers; a "Search performance" section in client reports | Not built (Phase 4) |
| Rank tracking, competitors, backlinks, Bing | Only if a client needs one | Not planned to build (Phase 5) |

## How the two jobs will run

### Monthly report (works after Phase 1 is reachable; complete after Phase 2)

1. Claude calls `seo_list_sites` to find the client and read its report notes.
2. Claude calls `seo_month_numbers` for the month. It gets every number, the comparisons, and the
   caveats. If the month is still provisional, the answer says until when.
3. For a dip, Claude calls `seo_daily_series` (which lists outages and Google changes on the same
   dates) and `seo_search_rows` (which pages or queries lost the most).
4. **Still in Chrome:** the Search Console Generative AI number. Claude reads it there and stores it
   with `seo_ai_impressions_record`.
5. **Needs Phase 2:** checking that shipped titles and metas are live (`seo_page_audit`). Until then
   that check stays in Chrome.
6. Claude drafts the Gmail email. Recipients, the plan doc, Teamwork and the PDF stay in the skill
   and the roster sheet. A person reviews and sends.

### Keyword map (needs all of Phase 2)

1. An admin imports the client's existing sheet into Control, once.
2. Claude reads the current map (`seo_keyword_map_get`) and the last 3 months of non-branded page
   and query rows (`seo_search_rows`).
3. Claude asks what the missing keyword metrics would cost (`seo_keyword_metrics`), shows the person
   the keywords, the cost and the month's spend, and buys only on a yes
   (`seo_keyword_metrics_fetch`).
4. Claude audits the live pages (`seo_page_audit`).
5. Claude makes a new dated map from the current one and edits rows. Control counts title and meta
   lengths, warns when two pages target the same keyword, and rejects em dashes.
6. An admin exports the map to the client's sheet as new dated tabs, or downloads CSV. Old tabs are
   never touched.
7. Control names the recommended next page to work on, with the one line of data behind it.

## What depends on what

```
Phase 1 data (built) ──► Claude tools (built) ──► settings page + endpoint (built, off)
                                                        │
                              needs: public address ────┤  (decision 11, Aaron R)
                              needs: Claude plan / who adds the connector (decision 13)
                                                        ▼
                                          skills call the tools  ──► report numbers come from Control
                                                        │
Phase 2: DataForSEO + ledger + cap ──► metric tools ────┤
         map tables + import ──► map tools ─────────────┤──► keyword map job runs through Control
         page audit ────────────────────────────────────┤
         Sheets import/export (needs a one-time Google reconnect for the extra permission)
                                                        ▼
                                   two clean month-end reports + maps refreshed ──► cancel Semrush

Phase 3 needs Phase 2's maps and page audits.     Phase 4 needs Phase 1 only (and Client Reports).
Phase 1b needs Phase 1 only.                      Phase 2b needs Phase 2's maps.
```

- **Phase 2 does not wait for the public address.** It can be built and used from Claude Code on the
  Control host. The skills working from Cowork do wait for it.
- **The fastest Semrush win inside Phase 2** is the DataForSEO connection plus the two metric tools:
  that alone replaces the Keyword Overview lookup. Maps can stay in Sheets until the rest lands.
- **Phase 3 before Phase 4 is not required.** They are independent; do whichever the team misses
  more.

## Phase 2 in build order

| Step | What | Depends on | Needs from Aaron |
|---|---|---|---|
| 2.1 | DataForSEO connection, spend ledger, budget cap, settings (login, password, $75 cap, $10 per person) | nothing | A DataForSEO account with the $50 top-up |
| 2.2 | `seo_keyword_metrics` (quote) and `seo_keyword_metrics_fetch` (buy), 30-day cache, city volume | 2.1 | A yes to one test call costing a few cents |
| 2.3 | Map tables, CSV import, read-only map pages | nothing | One real map exported as CSV |
| 2.4 | `seo_keyword_map_get`, `_create`, `_update`, with the checks on every edit | 2.3 | |
| 2.5 | Page audit and `seo_page_audit` | 2.3 for the map comparison | |
| 2.6 | Sheets import and export, `seo_keyword_map_export_sheet` | 2.3, and reconnecting Google once for the Sheets permission | A sheet to test against |
| 2.7 | `create-keyword-mapping-doc` skill rewritten | 2.2, 2.4, 2.5, 2.6 | Done outside the repo |

One thing must be settled by the first live DataForSEO call: whether difficulty and intent come back
in the same call as volume. If not, metrics take three calls, and cost about three times as much
(still under $2 a month at 12 clients).

## Money and safety rails

- **Search Console and GA4 are free.** Control's nightly use is far inside their limits.
- **DataForSEO** is pay as you go: about $2 to $19 a month expected at 12 clients, against a hard
  cap of $75 a month enforced in code, and $10 a month per person for non-admins.
- **Every paid lookup is two steps**: a quote that spends nothing, then a buy that names the quoted
  cost. Results are cached for 30 days, so the same keyword isn't bought twice.
- **Claude acts as the person who signed in**, with their role. A revoked person is cut off on their
  next call. Every change Claude makes is logged with before and after values.
- **Claude cannot** change a site's Google properties, edit report notes, see credentials or server
  details, or touch a client's website.
- **Search queries are other people's text.** The tools return them as data, and Claude is told not
  to treat them as instructions.

## Dates

- Semrush renews on the 8th of each month. The plan's earliest cancel date is **2027-01-08**.
- That date needs: Claude reaching the tools for the November and December reports, Phase 2 live in
  December, at least three clients' maps refreshed end to end through the tools, every other
  client's map imported, and nobody logging into Semrush for a full billing cycle.
- Each month a phase slips moves the cancel by a month, about $1,028.
- **The last Semrush exports** (keyword metrics for keywords already in client maps, and each
  client's organic keyword history) are a no-code step that the cancel date depends on.
- The oldest Search Console data from before Google's September 2025 change rolls off around
  2027-01-14. The nightly sync keeps whatever it has already stored.

## Waiting on someone

| What | Who | Blocks |
|---|---|---|
| Public address for the Claude paths (decision 11) | Aaron R | The skills working from Cowork or claude.ai |
| Sign-in library: keep the older pinned version, or upgrade the SSH library first (decision 12) | Aaron R | Nothing today; it works pinned |
| Which Claude plan, and who adds the connector (decision 13) | Aaron R | Connecting from Cowork |
| DataForSEO account and top-up | Aaron | Phase 2 live data (not the build) |
| One real keyword map as CSV | Aaron | The map importer |
| The last Semrush exports | Aaron | The cancel date |
| Seat holders confirm nothing else in Semrush is in use (Position Tracking, Site Audit, Local, AI Visibility) | Aaron, each seat holder | The cancel |
| Rewriting the two skills | Whoever maintains the skills | Any hand work actually going away |

## Not yet checked on the real install

- The numbers against a manual Chrome pull for two clients (the plan's acceptance check).
- Whether Search Console's API "web" total includes what its interface now splits out as multimodal,
  which decides whether AI share uses the right denominator.
- PHPStan on the new code (it can't run where this was built).
- A second person's security review of the sign-in code before the switch is turned on.
