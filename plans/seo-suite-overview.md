# SEO suite: how it all fits together

> **Status (2026-11-12).** Checked on 2026-11-12 by three independent reviews (against the code,
> against the full plan, and for gaps between the parts); their 25 corrections are applied below.
> A short map of the whole SEO suite: what each part does, how the parts
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

When the suite is finished, Control holds the numbers and the maps, and Claude does almost all of
both jobs by asking Control. One read per client per month stays by hand (the AI impressions number,
below). People still review everything before it goes to a client.

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
              (each person signs in as themselves, once
               the public address exists)
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
| Google connection | One read-only sign-in as the agency account. Every Search Console property it can see becomes available to pick; the GA4 property number is typed in by hand for now. Read-only until Sheets export is switched on (see Money and safety rails) | Built |
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

**"Built" means finished and tested on the `feature/seo` branch.** It is not merged or released
yet, and the full code check (`composer gate`, which includes PHPStan) still has to pass first.

## Step 0: set up each client once (not done yet, and nothing works for a client without it)

For each SEO client (8 today), someone opens the site's SEO profile in Control and:

- picks the Search Console property and types the GA4 property ID from the roster sheet;
- enters brand terms, key events, report notes and the seasonal flag.

A client whose site is not in Control yet (for example one that is not on a managed server) is first
added as a custom-host site. Saving the profile starts a 16-month backfill. Until this is done for a
client, Claude gets no numbers for it, and without brand terms there is no non-branded figure.

**Check access first.** The agency account can open most client properties, not all. On each
profile, confirm the client's Search Console property is in the list and the GA4 numbers arrive
after the first sync. For any it cannot see, ask the client to add the agency account as a user.
There is no other way in today, and one missing client holds up the cancel, because the cancel rule
needs every active client's numbers to come from Control for two reports.

**Do it now, not at report time.** Search Console only keeps 16 months, so a day of old history is
lost every day until the sync is running on the real install with that client's profile saved. Owner
and date: to be named.

## How the two jobs will run

### Monthly report (works after Phase 1 is reachable; complete after Phase 2)

1. Claude calls `seo_list_sites` to find the client and read its report notes.
2. Claude calls `seo_month_numbers` for the month. It gets every number, the comparisons, and the
   caveats. If the month is still provisional, the answer says until when.
3. For a dip, Claude calls `seo_daily_series` (which lists outages and Google changes on the same
   dates) and `seo_search_rows` sorted by biggest drops first, which includes pages and queries that
   fell to nothing. (That sort was missing when this was first checked; it was added 2026-11-12.)
4. **Still in Chrome, for good:** the Search Console Generative AI number, one read per client per
   month, until Google offers it another way. Claude reads it there and stores it with
   `seo_ai_impressions_record`. It needs a browser Claude can use: in Cowork that means the person's
   Claude desktop app is open, and the account Owner must leave the browser feature on (part of
   decision 13). The first time, the previous month is read as well, so the month-over-month line
   is not empty. With no browser available, the report goes out without AI impressions and says so.
5. **Needs Phase 2:** checking that shipped titles and metas are live (`seo_page_audit`). Until then
   that check stays in Chrome.
6. Claude drafts the Gmail email. Recipients, the plan doc, Teamwork and the PDF stay in the skill
   and the roster sheet. A person reviews and sends.

**The "organic keywords" number changes meaning.** It now comes from Search Console (queries that
got impressions), not Semrush, and it will be a different figure. For the first 1 to 2 months the
report shows it next to the Semrush count, and the client is told once that the method changed
(decision 5). Control does not hold the Semrush number: it is read from the history exported from
Semrush, in the client's Drive folder.

**If the public address is not ready for the November report (early December):** Aaron or Aaron R
runs the report from Claude Code on the Control machine. That route is built. It needs one setting
first (a named Control user in Control's settings file), it acts as that one user for every call
with no personal sign-in, and nobody else on the team can use it. Anyone else can read the month
totals on the plain site page in Control, but top pages, daily dips and which pages lost clicks are
not on that page yet, so those stay in Chrome. Reports done this way count toward the two clean
reports the cancel needs.

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

Phase 3 needs Phase 2's maps and page audits. Its drop panel in the app also needs Phase 1b;
        through Claude it does not.
Phase 4 needs Phase 1 and Client Reports. Its AI visibility view on the site page also needs 1b.
Phase 1b needs Phase 1 only.                      Phase 2b needs Phase 2's maps.
```

- **Phase 2 does not wait for the public address.** It can be built and used from Claude Code on the
  Control host. The skills working from Cowork do wait for it. Used on the host there is no personal
  sign-in: Claude acts as one named Control user set on the server, and everything it changes or
  spends is logged under that name. Per-person sign-in, roles and per-person limits only apply once
  the public address exists.
- **The fastest Semrush win inside Phase 2** is the DataForSEO connection plus the two metric tools:
  that alone replaces the Keyword Overview lookup. Maps can stay in Sheets until the rest lands.
- **Phase 3 before Phase 4 is not required.** They are independent; do whichever the team misses
  more. One thing to know when choosing: Phase 4's client report section adds a monthly hand step.
  Reports compile on the 1st, search numbers are not final until about the 6th, so every report
  with that section is held for review and someone presses Send now after the 6th. Only care-plan
  sites get it.
- **Until the DataForSEO tools are live, do not look up keywords in Semrush for a map refresh.** Any
  Semrush login after the November exports restarts the one-month no-login count and can move the
  cancel by a month. A map refresh that comes due before Phase 2 waits for step 2.2, which is the
  reason to build 2.1 and 2.2 first.

## Phase 2 in build order

| Step | What | Depends on | Needs from Aaron |
|---|---|---|---|
| 2.1 | DataForSEO connection, spend ledger, budget cap, settings (login, password, $75 cap, $10 per person) | nothing | A DataForSEO account with the $50 top-up |
| 2.2 | `seo_keyword_metrics` (quote) and `seo_keyword_metrics_fetch` (buy), 30-day cache, city volume. Difficulty is shown as "Difficulty (0-100)" with a default band (Easy, Medium, Hard, Very hard), not Semrush's KD %, and the two are not comparable | 2.1 | A yes to one test call costing a few cents |
| 2.3 | Map tables, CSV import, read-only map pages | nothing | One real map exported as CSV |
| 2.4 | `seo_keyword_map_get`, `_create`, `_update`, with the checks on every edit, and a "map activity" section in the monthly numbers (what shipped this month, what is planned) | 2.3 | |
| 2.5 | Page audit and `seo_page_audit` | 2.3 for the map comparison | |
| 2.6 | Sheets import and export, `seo_keyword_map_export_sheet` | 2.3, and reconnecting Google once for the Sheets permission | A sheet to test against |
| 2.7 | Both skills updated: `create-keyword-mapping-doc` rewritten, and the live-check step of `monthly-seo-report` | 2.2, 2.4, 2.5, 2.6 | Done outside the repo |

Not built in Phase 2: a third-party "ranking keywords" count from DataForSEO. The report uses Search
Console's count (decision 5). If a client needs a ranking count that lasts, it is a small add-on at
about $0.01 per client per month.

One thing must be settled by the first live DataForSEO call: whether difficulty and intent come back
in the same call as volume. If not, metrics take three calls, and cost about three times as much
(still under $2 a month at 12 clients).

## Money and safety rails

- **Search Console and GA4 are free.** Control's nightly use is far inside their limits.
- **DataForSEO** is pay as you go: about $2 to $19 a month expected at 12 clients, against a hard
  cap of $75 a month enforced in code, and $10 a month per person for non-admins.
- **Every paid lookup is two steps**: a quote that spends nothing, then a buy that names the quoted
  cost. Results are cached for 30 days, so the same keyword isn't bought twice.
- **Through the network endpoint, Claude acts as the person who signed in**, with their role. A
  revoked person is cut off on their next call. Every change Claude makes is logged with before and
  after values.
- **Roles need a look before the switch is turned on.** Accounts are admins by default, and an
  admin can export to client sheets and spend up to the full $75 cap, not $10.
- **Sheets export changes the Google sign-in from read-only to read and write** on every Google
  Sheet the agency account can open. It is switched on once, by an admin reconnecting Google as the
  agency account with every box ticked. That reconnect replaces the sign-in the nightly sync depends
  on, so do it once, check the next morning that the sync still ran, and do not repeat it casually
  (Google keeps a limited number of sign-ins per account and silently drops the oldest). Imports and
  exports are admin only and logged. CSV download works without it.
- **Claude cannot** change a site's Google properties, edit report notes, see credentials or server
  details, or touch a client's website.
- **Search queries are other people's text.** The tools return them as data, and Claude is told not
  to treat them as instructions.

## Dates

- Semrush renews on the 8th of each month. The plan's earliest cancel date is **2027-01-08**, and it
  only holds if **the last Semrush login is before 2026-12-08**.
- **The keyword count side by side decides the date** (decision 5). Pick one:
  - use the exported Semrush history only, or one live Semrush count for the November report pulled
    before 2026-12-08: the cancel stays **2027-01-08**;
  - live Semrush counts in both the November and December reports: the cancel moves to
    **2027-02-08**.
- **The last Semrush exports** (keyword metrics for keywords already in client maps, and each
  client's organic keyword history, saved as CSV in each client's Drive folder) were planned for
  early November. They must be done, with no Semrush login afterwards, before 2026-12-08.
- 2027-01-08 also needs all of these:
  - every client set up (Step 0) and visible to the agency account;
  - both skills rewritten to call the tools (each keeps its old Chrome steps as a fallback until the
    cancel);
  - the public address (decision 11) and the connector (decision 13) live, because the team must
    reach the tools from Cowork before Semrush can be cancelled. Reports run on the Control host
    count toward the two clean reports, but not toward that condition;
  - every active client's November and December numbers coming from Control;
  - Phase 2 live in December, at least three clients' maps refreshed end to end through the tools,
    and every other client's current map imported;
  - seat holders confirming nothing else in Semrush is still in use.
- **The last window is two days.** December's numbers turn final on about 2027-01-06. That leaves
  6 and 7 January to confirm the December reports used final numbers and to cancel before the
  renewal on the 8th. Draft the December reports on provisional numbers in the first days of January
  and re-check them on the 6th.
- Each month a phase slips moves the cancel by a month, about $1,028.
- **Old history is being lost daily.** Search Console only keeps 16 months, so a day drops off every
  day until the sync runs on the real install with each client's profile saved. The last of the data
  from before Google's September 2025 change is gone around 2027-01-14. Once Control has stored a
  day, it keeps it.

## Waiting on someone

| What | Who | Blocks |
|---|---|---|
| Public address for the Claude paths (decision 11) | Aaron R | The skills working from Cowork or claude.ai, and the cancel itself |
| Sign-in library: keep the older pinned version, or upgrade the SSH library first (decision 12) | Aaron R | Nothing today; it works pinned. But the pinned version gets no later security fixes, so the upgrade should follow soon after the endpoint goes public |
| Which Claude plan, who adds the connector, and keeping Claude's browser feature on (decision 13) | Aaron R | Connecting from Cowork; the AI impressions step |
| Step 0: a profile for each of the 8 clients, and access checked | To be named | Any numbers at all for that client; history lost daily until done |
| Keyword count side by side: exported history, or live Semrush counts (decision 5) | Aaron | Whether the cancel is 2027-01-08 or 2027-02-08 |
| DataForSEO account and top-up | Aaron | Phase 2 live data (not the build) |
| One real keyword map as CSV | Aaron | The map importer |
| The last Semrush exports, then no Semrush login | Aaron | The cancel date. Before 2026-12-08 to keep 2027-01-08 |
| Seat holders confirm nothing else in Semrush is in use (Position Tracking, Site Audit, Local, AI Visibility) | Aaron, each seat holder | The cancel |
| Rewrite `monthly-seo-report` to call the tools | To be named | Needed before the November reports are drafted in early December; the cancel rule requires it |
| Rewrite `create-keyword-mapping-doc` | To be named | Needed in December, right after Phase 2, before the three end-to-end map refreshes |

If nobody uses the Local or AI Visibility add-ons, they can be dropped before the 2026-12-08 renewal
without waiting for the rest. Cancelling Local stops listing sync. Anything a seat holder still uses
has to be exported or replaced first, and the $1,027.70 saving assumes none of it needs a paid
replacement.

## Not yet checked on the real install

- The numbers against a manual Chrome pull for two clients (the plan's acceptance check).
- Whether Search Console's API "web" total includes what its interface now splits out as multimodal,
  which decides whether AI share uses the right denominator.
- PHPStan on the new code (it can't run where this was built).
- A second person's security review of the sign-in code before the switch is turned on.

Before the Claude access switch is turned on, also:

- give Control's web server more workers (or move to php-fpm). With one worker, a slow Claude call
  freezes the Control pages for everyone;
- review each person's role (see Money and safety rails);
- finish the scrub of error text the plan asks for;
- run the outside-in check once the public address exists: from outside, only the Claude paths
  answer, and everything else is a 404.
