---
title: Claude access (MCP)
section: Features
order: 68
updated: 2026-11-11
author: Aaron Reimann
tags: [mcp, claude, seo, modules]
tracks: [modules/Mcp/**, modules/Seo/src/Mcp/**]
---

> Packaged as `modules/Mcp` (module id `mcp`). It hosts one MCP server that lets Claude read Control. Other modules add the tools; SEO is the first. Today it works for Claude Code running on the Control host only. There is no network endpoint yet, so claude.ai and Cowork can't reach it.

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

## Turning it on (Claude Code on the Control host)

The server runs over stdio, started by Claude Code as a local command. Stdio has no login, so the server acts as one named Control user and refuses to start without one.

1. In Control's `.env`, set `CLOCKWORK_MCP_LOCAL_USER` to the email of an active Control user. Tool calls are logged as that user.
2. Add the server to Claude Code on that machine:

   ```
   claude mcp add clockwork -- php /path/to/control/artisan mcp:start clockwork
   ```

3. In Claude Code, ask it to call `control_whoami`. It should name that user and list the tools.

To inspect the server by hand, run `php artisan mcp:inspector clockwork`.

To turn it off, remove `CLOCKWORK_MCP_LOCAL_USER` (the server is then not registered at all), or disable **Claude access** on **Settings → Modules**. Disabling the SEO module removes the `seo_` tools and leaves the server running.

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
- A revoked user's connection gets no `seo_` tools, and each tool refuses again if called anyway.

## Not built yet

- The network endpoint at `/mcp` with per-user sign-in, which is what claude.ai and Cowork need. It waits on two decisions in `plans/seo-semrush-replacement.md` (11 and 12): how Control is reached from outside, and the sign-in library upgrade.
- A settings page for Claude access.
- Keyword map and keyword metric tools (Phase 2 of the plan).

## Adding tools from another module

Return tool classes from the module provider's `mcpTools()`. A tool extends `Laravel\Mcp\Server\Tool`, sets `#[Name('yourmodule_...')]`, a title, a description, and `#[IsReadOnly]` or an explicit `#[IsDestructive(...)]`. Build answers as explicit arrays, never `toArray()` on a model. Add the tool's arguments to `tests/Feature/Mcp/SecretCanaryTest.php`, which fails until you do, and add the namespace to the "MCP tools never touch servers" rule in `tests/Feature/ArchitectureTest.php`.
