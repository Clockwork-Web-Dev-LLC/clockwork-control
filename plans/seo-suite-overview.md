# SEO suite: how it all fits together

> **Scope (widened 2026-11-12).** The suite was first planned to replace only two paid lookups
> (bulk keyword metrics and an organic keyword count). It now aims to cover nearly everything an
> agency uses a paid SEO suite such as Semrush for: Position Tracking, Site Audit, competitor
> research, Backlinks, the Local and AI Visibility add-ons, and a true ranking-keyword count. All of
> it moves to DataForSEO; a client's own clicks, impressions and sessions stay on Search Console
> and GA4. What DataForSEO can do and what it costs was researched for that scope; the detail is in
> [`seo-dataforseo-full-scope.md`](./seo-dataforseo-full-scope.md). **The "$2 to $19 a month" cost
> and the short timeline in the full plan were written for the narrow scope and no longer hold.**
>
> **Status (2026-11-12).** Checked on 2026-11-12 by three independent reviews (against the code,
> against the full plan, and for gaps between the parts); their 25 corrections are applied below.
> This is a short map of the whole SEO suite: what each part does, how the parts connect, what is
> built, and what order the rest goes in. Start here. The detail (file lists, table columns, test
> lists, research sources) lives in [`seo-semrush-replacement.md`](./seo-semrush-replacement.md),
> referred to here as "the full plan". Where the two disagree, the full plan's dated status notes
> win, and this file should be corrected.

## The goal

Let an agency replace a paid SEO suite such as Semrush with data it holds in its own Control
install, and take the hand work out of the team's SEO jobs. Each install plugs in its own Google
OAuth client and its own DataForSEO API login. The features an agency typically uses a suite for:

| Semrush feature | Moves to | Can DataForSEO replace it |
|---|---|---|
| Bulk keyword lookup (volume, difficulty, CPC, intent) | DataForSEO | Yes |
| Keyword ideas and research | DataForSEO | Mostly |
| Position Tracking | DataForSEO | Mostly: ranks yes; visibility and share of voice we compute ourselves |
| Ranking-keyword count, competitor research, keyword gap | DataForSEO | Mostly: the count will read lower than Semrush's (smaller database) |
| Site Audit | DataForSEO crawl | Mostly: we write the issue rules and the trend ourselves |
| Backlinks | DataForSEO | Mostly: no disavow upload or outreach tool |
| AI Visibility add-on | DataForSEO | Mostly: ChatGPT, Gemini, Google AI Mode and AI Overviews; small samples |
| Local add-on | DataForSEO for map rank and reading reviews | **Partly. It cannot sync listings, reply to reviews or post.** See below |
| A client's own clicks, impressions, sessions | Search Console and GA4 (free, built) | Not needed |

Two jobs in particular lose their hand work: the **monthly SEO report** (numbers read out of Search
Console, GA4 and the paid suite in a browser) and the **keyword map** (Search Console scraped,
keywords pasted into the suite in batches, results pasted and formatted in a spreadsheet).

When the suite is finished, Control holds the numbers, rankings, audits and maps, and Claude does
almost all of the work by asking Control. One read per client per month stays by hand (the AI
impressions number, below). People still review everything before it goes to a client.

### What DataForSEO cannot do

DataForSEO sells data. It reads; it does not act. So these need something else:

- **Listing sync** (pushing a business's name, address and phone to directories). Needs a listing
  vendor, about $50 to $199 a month for 5 to 12 locations depending on the vendor and whether
  continuous sync is needed. This is the one Semrush cancel that can change what customers see:
  Semrush says listings stay in the directories after cancelling but are no longer protected.
- **Replying to reviews, Google Business Profile posts and owner-side insights.** Google's own
  Business Profile API can do these, at no charge as far as the research found. It needs Google to
  approve the agency (about two weeks, can be refused) and each client to add the agency as a
  profile manager. Until then they are done by hand in Google.
- **Semrush's past data.** Daily rank history, the audit trend and AI Visibility history can't be
  recreated. Export them from Semrush before cancelling.
- **Semrush's own scores** (Keyword Difficulty %, Authority Score, Toxicity, Site Health,
  visibility %). DataForSEO has its own versions under other names. Every trend line restarts, so
  clients need a one-time note, an overlapping month and the exported history.
- Also not covered: Yelp and Facebook review monitoring, answering Google Q&A (Google closed that
  route), the disavow file upload (a person uploads the file Control makes), link outreach email.

## The picture

```
                 FREE                                PAID (metered)
   Google Search Console      GA4                    DataForSEO
            |                  |                          |
            |  nightly sync    |          scheduled jobs  |  one-off lookups only
            v                  v          within a cap    v  on an approved quote
   +---------------------------------------------------------------+
   |                     CLOCKWORK CONTROL                         |
   |                                                               |
   |  Stored search data          Keyword metrics (30-day cache)   |
   |  (daily totals, monthly      Rankings, site audits, backlinks,|
   |   pages and queries,         map rank, AI visibility          |
   |                              Spend ledger + caps per feature  |
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
              The agency's own Claude skills or prompts:
              monthly report, keyword map
              (email draft, task tracker, Drive, PDF stay there)
```

Three rules hold the whole thing together:

- **Store first, read from storage.** Pages, tools and reports read what Control stored. Nothing a
  person or Claude opens calls Google. That keeps it fast, keeps history past Search Console's 16
  months, and means the page and Claude can never show different numbers.
- **A missing value is "not available", never 0.** A comparison a Google change broke is left out
  with a note saying why.
- **Nothing costs money or reaches a client without a person saying yes.** A one-off paid lookup
  needs an approved quote. A scheduled job (nightly rank tracking, weekly crawls) is approved once,
  with its monthly cost, and then runs inside a cap for that feature. Reports are drafts. Sheet
  exports are admin only.

## The parts

| Part | What it does | Status |
|---|---|---|
| Indexability watchdog and `/seo` page | Flags sites that block search engines | Built (before this plan) |
| Google connection | One read-only sign-in as a shared agency Google account, through the install's own Google OAuth client. Every Search Console property it can see becomes available to pick; the GA4 property number is typed in by hand for now. Read-only until Sheets export is switched on (see Money and safety rails) | Built |
| Per-site SEO profile | Which Search Console and GA4 properties, brand terms, key events, report notes, year-over-year flag | Built |
| Nightly sync (`clockwork:seo-sync-google`, 08:10 UTC) | Copies Search Console and GA4 data into Control; re-pulls the last 5 days and every month not yet final | Built |
| Report numbers (`SearchNumbers`) | A month against the previous month and last year, with caveats | Built |
| Site numbers page (`/seo/sites/{site}`) | A plain table of those numbers | Built (plain table only) |
| AI impressions | Typed in per month, by a person or by Claude, because Google has no API for that report | Built |
| Claude access module | One server Claude talks to; other modules add tools | Built |
| Six SEO tools | List sites, month numbers, daily series, pages and queries, record AI impressions, update profile | Built |
| Claude access settings page and network endpoint | The switch, one-click setup, who is connected, per-person sign-in | Built, switched off by default, not reachable from outside until the install publishes it |
| **Public address for Claude** | A hostname that lets only the Claude paths through | **Not part of the code: each install's server admin sets it up** |
| **The agency's report and keyword-map workflows changed to call the tools** | The actual removal of the hand work | **Not part of the repo: each agency's own Claude skills or prompts** |
| DataForSEO connection, spend ledger, caps per feature, task runner, settings card | The plumbing every paid feature uses | Built 2026-11-12 (build step 1). Not yet run against the live API |
| Keyword metrics and its two tools | Stored volume, difficulty, CPC, intent; quote, then buy; 30-day cache; city volume | Built 2026-11-12 (build step 2). Not yet run against the live API |
| Keyword maps | Imported from each client's sheet, edited through Claude, checked on every edit | Not built (Phase 2) |
| Page audit | Reads a live page's title, meta, H1s, schema and word count | Not built (Phase 2) |
| Sheets import and export | Reads the existing map; writes new dated tabs in the existing format | Not built (Phase 2) |
| Nicer site page, `/seo` columns, site widget, GA4 property picker | Tiles, chart, top pages and queries | Not built (Phase 1b, optional) |
| In-app map editor | For editing maps without Claude | Not built (Phase 2b, optional) |
| Ship verification and drop alerts | Checks daily that shipped titles and metas are live; weekly alert when a target page drops | Not built (Phase 3) |
| Report wording and Client Reports section | House wording for numbers; a "Search performance" section in client reports | Not built (Phase 4) |
| **Position Tracking** | Nightly or weekly Google rank for each tracked keyword, competitors on the same keywords, visibility and share of voice | **Not built. Required. Large** |
| **Ranking-keyword count, competitor research, keyword gap, keyword ideas** | What a site and its competitors rank for | **Not built. Required. Medium** |
| **Site Audit** | Crawls a client's site, lists problems by severity, tracks the trend | **Not built. Required. Large** |
| **Backlinks** | Monthly summary, new and lost links, referring domains, gap against competitors | **Not built. Required. Medium** |
| **AI Visibility** | Weekly checks of tracked prompts in ChatGPT, Gemini and Google AI Mode; AI Overview presence | **Not built. Required. Medium to large** |
| **Local** | Map-rank grid, reading reviews, profile changes (DataForSEO); replies, posts, insights (Google); listing sync (a vendor) | **Not built. Required. Large, partly outside the code** |
| Bing | Only if a client needs it | Not planned |

**"Built" means finished and tested on the `feature/seo` branch.** It is not merged or released
yet, and the full code check (`composer gate`, which includes PHPStan) still has to pass first.

## Step 0: set up each client once (nothing works for a client without it)

For each SEO client, someone opens the site's SEO profile in Control and:

- picks the Search Console property and types in the GA4 property ID;
- enters brand terms, key events, report notes and the seasonal flag.

A client whose site is not in Control yet (for example one that is not on a managed server) is first
added as a custom-host site. Saving the profile starts a 16-month backfill. Until this is done for a
client, Claude gets no numbers for it, and without brand terms there is no non-branded figure.

**Check access first.** The shared agency Google account may be able to open most client
properties but not all. On each profile, confirm the client's Search Console property is in the
list and the GA4 numbers arrive after the first sync. For any it cannot see, ask the client to add
the agency account as a user. There is no other way in today. If you plan to cancel a paid suite,
one missing client holds that up, because every active client's numbers should come from Control
for a couple of reports first.

**Do it early, not at report time.** Search Console only keeps 16 months, so a day of old history is
lost every day until the sync is running on the real install with that client's profile saved.

## How the two jobs will run

### Monthly report (works after Phase 1 is reachable; complete after build steps 4, 5 and 11)

1. Claude calls `seo_list_sites` to find the client and read its report notes.
2. Claude calls `seo_month_numbers` for the month. It gets every number, the comparisons, and the
   caveats. If the month is still provisional, the answer says until when.
3. For a dip, Claude calls `seo_daily_series` (which lists outages and Google changes on the same
   dates) and `seo_search_rows` sorted by biggest drops first, which includes pages and queries that
   fell to nothing. (That sort was missing when this was first checked; it was added 2026-11-12.)
4. **Still in a browser, for good:** the Search Console Generative AI number, one read per client
   per month, until Google offers it another way. Claude reads it there and stores it with
   `seo_ai_impressions_record`. It needs a browser Claude can use: in Cowork that means the person's
   Claude desktop app is open, and the Claude account's owner must leave the browser feature on.
   The first time, the previous month is read as well, so the month-over-month line is not empty.
   With no browser available, the report goes out without AI impressions and says so.
5. **Needs build step 4:** checking that shipped titles and metas are live (`seo_page_audit`). Until then
   that check stays in the browser.
6. Claude drafts the report email. Recipients, the client's plan document, task tracking and any
   PDF stay in the agency's own workflow, outside Control. A person reviews and sends.

**The "organic keywords" number changes.** Until step 5 of the build order, Control only has the
Search Console count (queries that got impressions), which is a different thing from Semrush's
"keywords this site ranks for". Step 5 adds a true ranking-keyword count from DataForSEO. It will
read lower than Semrush's, because DataForSEO's keyword database is smaller, so for the first month
or two the report shows the old and new figures side by side and the client is told once that the
method changed. The old figure comes from Semrush while it is still running, then from the exported
history.

**If the public address is not ready when a report is due:** an admin with access to the Control
host can run the report from Claude Code on that machine. That route is built. It needs one setting
first (a named Control user in Control's settings file), it acts as that one user for every call
with no personal sign-in, and nobody else on the team can use it. Anyone else can read the month
totals on the plain site page in Control, but top pages, daily dips and which pages lost clicks are
not on that page yet, so those stay in the browser. Reports done this way still count as reports
produced from Control when you judge whether the paid suite can go.

### Keyword map (needs build steps 1, 2 and 4)

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
                              needs: public address ────┤  (the server admin)
                              needs: a Claude plan with the connector added
                                                        ▼
                                  the agency's workflows call the tools

DataForSEO connection + ledger + caps per feature + shared "post tasks, collect later" runner
        │
        ├──► keyword metrics ──► keyword maps, page audit, Sheets export
        ├──► Position Tracking (needs keyword metrics for visibility and share of voice)
        ├──► ranking count, competitors, keyword gap, keyword ideas
        ├──► Site Audit
        ├──► AI Visibility (its AI Overview part arrives with Position Tracking)
        ├──► Backlinks
        └──► Local: map rank and reviews
                     └──► replies and posts need Google's approval; listing sync needs a vendor
```

- **Everything paid sits on one piece of plumbing**, so it is built once and first: the DataForSEO
  connection, the spend ledger, a cap per feature, and a runner that posts work to DataForSEO's
  cheaper queue and collects results later.
- **None of it waits for the public address.** It can be built and used from Claude Code on the
  Control host. Workflows run from Cowork or claude.ai do wait for it. Used on the host there is no
  personal sign-in: Claude acts as one named Control user set on the server, and everything it
  changes or spends is logged under that name.
- **Position Tracking goes early because rank history cannot be bought later.** Every day not
  collected is lost.
- Phase 3 (ship verification and drop alerts) and Phase 4 (report wording, Client Reports section)
  from the full plan still stand and come after. Phase 4 grows: it now also needs blocks for
  rankings, audit, backlinks, local and AI.

## Build order

Sizes: small is a few days, medium about a week, large two weeks or more, each including review and
a first live run. These are estimates, not commitments.

| Step | What | Size | Why here |
|---|---|---|---|
| 0 | **No code, for each install:** open a DataForSEO account ($50 top-up) and run one test call per endpoint to settle every price; read your current suite's invoice line by line; export Position Tracking, Site Audit, backlink, listing and AI Visibility history; apply for Google Business Profile API access; pick a listing vendor | Small | All have waiting time and none needs code |
| 1 | DataForSEO connection, spend ledger, a cap per feature, standing approval for scheduled jobs, the shared runner | Medium | Everything else sits on it |
| 2 | Keyword metrics (quote, then buy), 30-day cache, city volume. Difficulty shown as "Difficulty (0-100)" with a default band, not Semrush's KD % | Small | The keyword-map job needs it; rank tracking needs search volume |
| 3 | **Position Tracking**: tracked keywords, nightly post and collect, stored ranks for the client and competitors, AI Overview flag, Semrush history import, tools for Claude | Large | History can't be bought later; the most-used daily feature |
| 4 | Keyword maps (import, tools, checks on every edit), single-page audit, Sheets export | Large | Both the report and the keyword-map jobs depend on it |
| 5 | Ranking-keyword count, ranked keywords, competitor research, keyword gap, keyword ideas | Medium | Cheap, and completes the monthly report and research work |
| 6 | **Site Audit**: crawl job, rules that turn checks into issues with severity and fix text, crawl-to-crawl trend, tools | Large | Needs at least two crawls per site before the Semrush trend can be let go. After this the base Semrush plan can go |
| 7 | **AI Visibility**: prompts and competitors per site, weekly checks, mention and citation counts | Medium to large | Lets the AI Visibility add-on be dropped. Move it earlier if that add-on is the bigger bill |
| 8 | **Backlinks**: monthly summary, new and lost, referring domains, anchors, quarterly gap, flagged-links list with a disavow file | Medium | Monthly data, under $5 a month, and nothing is lost by starting later |
| 9 | **Local, DataForSEO part**: weekly map-rank grid, reading reviews with an unanswered list, profile changes | Medium | The listing move, not this code, is what gates the Local add-on |
| 10 | **Local, Google part and the listing move**: replies, posts and insights through Google once approved, each one approved by a person; re-submit every client's details through the vendor, wait for them to settle, then cancel Semrush Local | Medium, gated by Google | Depends on Google and on the vendor |
| 11 | Report wording and Client Reports blocks for the new data, with one-time notes about the changed methods | Small to medium | Needs every series to exist; Claude reports from the tools meanwhile |

An agency's own report and keyword-map workflows (Claude skills or prompts, outside the repo) are
changed along the way: the monthly report once the public address works, the keyword map after
step 4.

## Money and safety rails

**Estimated monthly cost with the paid suite fully replaced, for an example install with 12 client
sites** (the detail and every assumption are in the research file):

| Line | Low | High | What moves it |
|---|---|---|---|
| Position Tracking | $10 | $212 | Weekly top 30 at the low end; daily full top 100, which is what Semrush does, at the high end |
| Site Audit | $4 | $47 | Site size and weekly or monthly crawls. About $156 if every site needs JavaScript rendering |
| AI Visibility | $5 | $29 | How many engines and how often |
| Keyword metrics, ideas, ranking count, competitors | $8 | $36 | How much research is done |
| Backlinks | $1 | $4 | |
| Local, DataForSEO part | $3 | $8 | Number of local clients |
| Ad hoc lookups, retries, tests | $2 | $20 | An allowance, not a researched price |
| **DataForSEO total** | **$33** | **$356** | |
| Listing vendor (not DataForSEO, a separate bill) | $50 | $199 | One-time clean-up with a yearly renewal, or continuous sync |
| **Everything** | **$83** | **$555** | Middle case about $266 |

Compare that against your own current subscription to see what you would save. It is a good deal
more than the "$2 to $19 a month" the earlier, narrow-scope plan gave.

- **Prices are not yet confirmed.** No official DataForSEO page could be opened during the research;
  the figures come from search excerpts, and some official pages still showed older prices. One test
  call per endpoint settles each one, since every response carries its real cost.
- **The single $75 cap in the full plan no longer works.** It would stop nightly rank tracking part
  way through the month and leave holes in the history that can never be filled. Caps are per
  feature, around $200 a month in total for the middle case, and a job that hits its cap fails
  loudly.
- **Rank-tracking settings drive the bill.** A wrong setting repeats every night until someone
  notices, so scheduled jobs show their expected monthly cost before a person approves them.
- **Search Console and GA4 stay free.**
- **Every one-off paid lookup is two steps**: a quote that spends nothing, then a buy that names the
  quoted cost. Results are cached, so the same keyword isn't bought twice.
- **Through the network endpoint, Claude acts as the person who signed in**, with their role. A
  revoked person is cut off on their next call. Every change Claude makes is logged with before and
  after values.
- **Roles need a look before the switch is turned on.** Accounts are admins by default.
- **Sheets export changes the Google sign-in from read-only to read and write** on every Google
  Sheet the agency account can open. It is switched on once, by an admin reconnecting Google as the
  agency account with every box ticked. That reconnect replaces the sign-in the nightly sync depends
  on, so do it once and check the next morning that the sync still ran. CSV download works without
  it.
- **Google Business Profile access would be a new Google permission** the plan has so far ruled
  out. It is needed only for review replies, posts and insights.
- **Claude cannot** change a site's Google properties, edit report notes, see credentials or server
  details, or touch a client's website.
- **Search queries, page text, reviews and AI answers are other people's text.** The tools return
  them as data, and Claude is told not to treat them as instructions.
- **AI visibility numbers are small samples** of logged-out answers. Reports must say "in our
  sample", never "your brand appears in X% of AI answers".

## Moving off a paid suite in stages

- **Do not plan a single cancel date around the first features that work.** A full cancel is only
  realistic once every part you rely on has a replacement that has run alongside the paid suite.
  With the build order above, expect that to take several months from the start of step 1. That is
  an estimate, not a researched fact.
- **Keep using the paid suite in the meantime.** While the team still depends on Position Tracking,
  Site Audit, Local or AI Visibility there, keep logging in.
- **Cancel in stages**, each part once its replacement has run for an overlapping month:
  1. extra seats, as soon as fewer people need a login;
  2. the AI Visibility add-on, once prompt tracking has run a month;
  3. the base plan, downgraded or cancelled, once rank tracking, the research tools and Site Audit
     have each run a clean overlapping month;
  4. Local last, only after every client's listings have been re-submitted through the new vendor.
     Check first whether Local is on an annual term.
  Your invoice decides what each stage saves. Add-ons can be a large part of the bill, so they are
  worth targeting early. Removing an add-on may need a request to the vendor rather than a
  self-service button, so allow a few days before your renewal date.
- **Export the suite's history before each part is cancelled**: daily ranks for the tracked
  keywords, the Site Audit trend, backlink audit decisions, listings, AI Visibility history, and the
  keyword metrics and organic keyword history already planned.
- Each month the full cancel slips costs one more month of the subscription, less whatever has been
  dropped in stages.
- **Old Search Console history is being lost daily.** Search Console only keeps 16 months, so a day
  drops off every day until the sync runs on the real install with each client's profile saved. The
  last of the data from before Google's September 2025 change is gone around 2027-01-14.
- **Rank history starts the day tracking starts.** That is the reason Position Tracking is step 3.

## What each install has to supply and decide

| What | Who | What it unlocks or affects |
|---|---|---|
| A Google OAuth client, and a shared agency Google account that can open the clients' Search Console and GA4 properties | An admin | All Search Console and GA4 data |
| A public address for the Claude paths only | The server admin | Using the tools from Cowork or claude.ai; without it only Claude Code on the Control host works |
| Sign-in library: keep the older pinned version, or upgrade the SSH library first | The server admin | Nothing today; it works pinned. But the pinned version gets no later security fixes, so the upgrade should follow soon after the endpoint goes public |
| Which Claude plan, who adds the connector, and keeping Claude's browser feature on | The agency owner | Connecting from Cowork; the AI impressions step |
| Step 0: a profile for each SEO client, and access checked | An SEO team member | Any numbers at all for that client; history lost daily until done |
| A DataForSEO account and top-up, its API login entered in Control, then one test call per endpoint | The agency owner | Every price in this plan; all paid data |
| Your current suite's invoice, line by line (base plan, seats, Local locations and tier, AI Visibility domains, any annual term) | The agency owner | What each staged cancel saves |
| Rank tracking: daily or weekly, top 30 or top 100, how many keywords | The agency owner | $10 to $212 a month in the example above |
| Listings: how many clients have sync on, and is a one-time clean-up enough | The agency owner | $50 to $199 a month, and when a Local add-on can go |
| Apply for Google Business Profile API access, and ask local clients to add the agency as a manager | The agency owner | Review replies, posts and insights from Control |
| Which parts are really used: disavow workflow, link outreach, review requests, Yelp or Facebook reviews, daily AI checks | The SEO team | Each "no" is a feature you can leave switched off |
| Site sizes, whether any site needs JavaScript to show its content, weekly or monthly crawls | An SEO team member | $1 to $156 a month for Site Audit in the example above |
| A standing monthly approval for scheduled jobs, and the caps (about $200 in total for the middle case) | The agency owner | Whether tracking can run unattended |
| One real keyword map as CSV | An SEO team member | Testing the map importer against your own format |
| History exports from the paid suite, before each part is cancelled | An SEO team member | Trend lines surviving the switch |
| The agency's monthly report workflow changed to call the tools | Whoever maintains the agency's Claude skills or prompts | Reports drafted from Control instead of from the paid suite |
| The agency's keyword-map workflow changed to call the tools | The same person | Map refreshes run end to end; needs Phase 2 first |

## Not yet checked on a real install

- The numbers against a manual browser pull for two clients (the plan's acceptance check).
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
