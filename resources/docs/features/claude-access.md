---
title: Claude access (MCP)
section: Features
order: 68
updated: 2026-11-12
author: Aaron Reimann
tags: [mcp, claude, seo, modules]
tracks: [modules/Mcp/**, modules/Seo/src/Mcp/**]
---

> Packaged as `modules/Mcp` (module id `mcp`). It hosts one MCP server that lets Claude read Control. Other modules add the tools; SEO is the first. There are two ways in: a network endpoint for claude.ai and Cowork, switched on at **Settings → Claude access**, and a local one for Claude Code on the Control host.

## What you get

Claude can ask Control for an SEO client's numbers instead of someone reading them out of Search Console and GA4 in a browser. Every tool reads data Control already stored (see [SEO module](/docs/features/seo)). No tool calls Google, and no tool returns a credential, a server detail or a client's contact details.

| Tool | What it does | Changes data |
|---|---|---|
| `control_whoami` | Who this connection acts as, their role, and which tools are on. | No |
| `seo_list_sites` | Find a client's site: properties, brand terms, key events, report notes, sync state, which months are final, provisional or missing AI impressions. | No |
| `seo_month_numbers` | One site, one month: every report number with the previous month and the same month last year. Optional sections: top pages, top queries, a monthly trend, GA4 channels. | No |
| `seo_daily_series` | Day-by-day clicks, impressions, CTR, position, organic sessions and AI Assistant sessions, with site outages and known Google changes in the same range. | No |
| `seo_search_rows` | Search Console rows by page, query, or both, added up over 1 to 16 months, filtered, compared and paged. | No |
| `seo_ai_impressions_record` | Store a month's total from Search Console's Generative AI report, or mark it not available. | Yes |
| `seo_site_profile_update` | Change brand terms, GA4 key events or the year-over-year flag. | Yes |
| `seo_keyword_metrics` | Search volume, difficulty, CPC and intent for up to 700 keywords from what Control already bought, plus a quote for the ones it doesn't have. Never spends. | No |
| `seo_keyword_metrics_fetch` | Buys the keywords in a quote from DataForSEO. Spends money, so Claude asks first. | Yes, and spends |
| `seo_rankings` | Where a site ranks on Google for its tracked keywords: latest position, change, ranking page, competitor positions and AI Overview. Filters for top 3, top 10, improved, declined and not found. | No |
| `seo_rank_history` | Every stored rank check for up to 20 tracked keywords over a date range. | No |
| `seo_tracked_keywords_update` | Start tracking keywords, or pause or resume them. Adding is a recurring cost, so Claude asks first and reports the monthly estimate. Cannot delete keywords or history. | Yes, and adds recurring spend |
| `seo_page_audit` | Fetches up to 5 live pages on a client's own site and reads title, meta description, headings, canonical, robots tag, schema types and word count. Free. A page a firewall blocks comes back as blocked, not empty. | Stores the latest audit |
| `seo_keyword_map_get` | A site's keyword map: pages, keywords, figures, proposed titles and descriptions with character counts, status, the checks to fix and the recommended next page. | No |
| `seo_keyword_map_update` | Start a new dated version, edit or add rows, or fill figures from stored keyword data. Cannot delete rows, type in figures, or overwrite a row someone else changed. | Yes |

## Connecting claude.ai and Cowork

Everything in Control is on one page: **Settings → Claude access** (also in the gear menu). The connection is off by default. While it is off, Control has no `/mcp`, `/oauth/*` or `/.well-known/oauth-*` address at all.

### 1. Give Control a public address for these paths only

Claude calls Control from Anthropic's servers, so Control needs a public https hostname. Control normally has none, and this is the one exception, so keep it narrow. On the network side (for example a Cloudflare Tunnel hostname), let through only:

- `POST /mcp` (and `GET`/`DELETE /mcp`, which answer 405)
- `GET /.well-known/oauth-protected-resource*` and `GET /.well-known/oauth-authorization-server*`
- `POST /oauth/token`
- `GET`, `POST` and `DELETE /oauth/authorize`, `/login` and the sign-in callbacks, for the person signing in from their browser

Everything else on that hostname should answer 404. Add a rate limit at the edge for requests that carry no token: Control limits signed-in callers, but a request without a token is refused before that limit applies. This step is done outside Control, and whether to do it is a security decision for whoever owns the server.

### 2. Run setup (an admin, once)

On **Settings → Claude access**, click **Run setup**. It creates the signing keys (`storage/oauth-private.key`, readable only by the app user, and `storage/oauth-public.key`) and the one OAuth client Claude signs in through. The page then shows the **client secret once**. Copy it then: Control stores a scrambled copy and can't show it again. If it is lost, **Issue a new secret** makes a new one, and the old one stops working.

`php artisan clockwork:mcp-setup` does the same from the command line, and `--rotate-secret` issues a new secret.

Back up the two key files with the rest of `storage/`. If they are lost, run setup again; everyone then has to connect again.

### 3. Turn it on

Click **Turn on**. It can't be turned on before setup. **Turn off** closes the endpoint at once but keeps people's connections, so a short pause doesn't make everyone sign in again. **Disconnect everyone** is the separate button for that.

### 4. Add the connector in Claude

In Claude, an organization owner opens **Settings → Connectors → Add custom connector** and enters the **Connector URL**, **OAuth client ID** and **client secret** from the page. On a personal plan, each person adds it themselves. Open the settings page at Control's public https address so the connector URL shown is the right one.

### 5. Each person connects

In Claude, pick the connector and click Connect. You sign in to Control as usual, see a page asking "Let Claude into Clockwork Control?", and click **Allow**. That page is shown every time, even if you connected before. Claude then acts as you, with your role.

## Who is connected, and cutting someone off

The settings page lists who has connected. Everyone sees their own row and can disconnect themselves. Admins see everyone and can disconnect one person or all.

- Revoking or deleting someone on **Settings → Users**, or changing their password, disconnects their Claude.
- A revoked person is also refused on every single call, not just at sign-in.
- Claude's access token lasts an hour and renews itself. A connection that isn't used for 90 days ends, and the person signs in again.
- Each person can make 60 tool calls a minute.
- Turning the switch on or off, setup, a new secret and every disconnect are written to the action log with who did it.

## Claude Code on the Control host

A second way in that needs no public address. The server runs over stdio, started by Claude Code as a local command. It has no login, so it acts as one named Control user and refuses to start without one. The switch on the settings page doesn't affect it.

1. In Control's `.env`, set `CLOCKWORK_MCP_LOCAL_USER` to the email of an active Control user. Tool calls are logged as that user.
2. Add the server to Claude Code on that machine:

   ```
   claude mcp add clockwork -- php /path/to/control/artisan mcp:start clockwork
   ```

3. In Claude Code, ask it to call `control_whoami`. It should name that user and list the tools.

To inspect the server by hand, run `php artisan mcp:inspector clockwork`. To turn this route off, remove `CLOCKWORK_MCP_LOCAL_USER`.

Disabling **Claude access** on **Settings → Modules** removes both routes and the settings page. Disabling the SEO module removes the `seo_` tools and leaves the server running.

## How the tools answer

- **A summary first.** Each answer opens with one line that says the site, the period and whether the month is final or provisional.
- **Provisional months.** A month is provisional until 5 days after it ends and a sync has run since. `meta.data_state` and `meta.final_after` say so. Ask again after that date before quoting a number as final.
- **null is never 0.** A value Control doesn't have is `null`. A comparison a Google change made meaningless is `null` too, with a caveat in `meta.caveats` that says why.
- **Rows as columns plus rows**, so long lists stay small. Lists that don't fit return `next_cursor`.
- **Search queries are other people's text.** Anyone can type a sentence into Google and have it show up as a query. Claude is told to treat queries and page URLs as data, never as instructions.

## What Claude can and can't change

- `seo_ai_impressions_record` replaces a different stored value only when called with `overwrite`. It warns when the number is higher than the month's web impressions or a top page is on another domain.
- `seo_site_profile_update` applies a brand terms change only with `confirm_purge`, because that re-pulls 16 months of non-branded totals. Without it, the call returns a preview and saves nothing, including any other field in the same call.
- **Not from Claude, on purpose:** a site's Search Console or GA4 property (changing one deletes stored history) and its report notes (whoever writes the report follows them, so nothing Claude read elsewhere may be able to set them). Both are edited on the site's profile page.
- Every change is written to the action log with the user, the tool, and the before and after values, so it can be undone by hand. These rows are not attached to the site, so they are never pushed to the client's WordPress activity log.
- `seo_keyword_metrics_fetch` is the only tool that spends money straight away. It needs a quote from `seo_keyword_metrics` (good once, for 15 minutes, for the person it was made for) and the cost the person agreed to. It is refused if a spending cap would be passed. The real cost goes in the ledger on **SEO → Settings**.
- `seo_tracked_keywords_update` spends nothing itself, but each keyword it adds is checked on schedule from then on. It reports the expected monthly cost, the checks stay inside the rank tracking cap, and it can only pause, never delete.
- A revoked user's connection gets no `seo_` tools, and each tool refuses again if called anyway.

## Limits to know about

- **The sign-in library is pinned.** `laravel/passport` is held at 13.7.6, the newest release that works with the SSH library Control uses today. Newer releases need that library upgraded first. Until then Control doesn't get Passport's later fixes.
- **No self-service clients.** There is one client, created by setup. Claude can't register its own, and there is no device-code sign-in.
- **Route caching.** The endpoint's routes are added when a request starts, based on the switch. Don't run `php artisan route:cache` on an install that uses this, or the switch stops taking effect until the cache is cleared.
- **Not built yet:** keyword map and keyword metric tools.

## Adding tools from another module

Return tool classes from the module provider's `mcpTools()`. A tool extends `Laravel\Mcp\Server\Tool`, sets `#[Name('yourmodule_...')]`, a title, a description, and `#[IsReadOnly]` or an explicit `#[IsDestructive(...)]`. Build answers as explicit arrays, never `toArray()` on a model. Add the tool's arguments to `tests/Feature/Mcp/SecretCanaryTest.php`, which fails until you do, and add the namespace to the "MCP tools never touch servers" rule in `tests/Feature/ArchitectureTest.php`.
