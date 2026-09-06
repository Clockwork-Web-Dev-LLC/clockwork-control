---
title: Bill.com
section: Integrations
order: 40
updated: 2026-09-01
author: Aaron Reimann
tags: [integrations, billing, bill-com, care-plan]
tracks: [app/Services/BillCom/**, app/Console/Commands/SyncBill*.php, app/Console/Commands/BillComTest.php, app/Http/Controllers/BillComSettingsController.php]
---

Read-only sync against Bill.com. Two daily commands link sites to customers (via domain extraction from invoice line items) and flip `care_plan_enabled` based on whether the customer has a recent invoice with a care-plan line item.

## Why we use it

Before this, `care_plan_enabled` was a manual toggle. the operator had to remember to flip it for new clients and demote when a client cancelled. The flag drives the Updates tab banner ("included" vs "billable"), the `/maintenance-history` rollup, and which sites get the daily Lighthouse + weekly Sucuri + daily core-checksums scans. A wrong flag is silent revenue leakage on one side and the wrong client experience on the other.

Bill.com is the source of truth for who's actually paying for what. The sync makes the flag follow the invoicing.

## Setup

1. Generate a developer key at `developer.bill.com`.
2. Set in `.env`:

   ```
   CLOCKWORK_BILL_COM_ENABLED=true
   CLOCKWORK_BILL_COM_USERNAME=...
   CLOCKWORK_BILL_COM_PASSWORD=...
   CLOCKWORK_BILL_COM_ORG_ID=...
   CLOCKWORK_BILL_COM_DEV_KEY=...
   ```

3. Test (always works regardless of the `ENABLED` toggle):

```bash
php artisan clockwork:bill-com-test
```

Verifies credentials, lists first 5 customers + items.

## Auth

Session-based. `POST /Login.json` with username/password/orgId/devKey returns a `sessionId`; we cache it in Laravel's cache for 25 min. On 401 we re-login automatically (sessions expire after ~35 min of inactivity).

**API base is v2, not v3.** Bill.com's docs claim v3 lives at `gateway.bill.com` — that hostname has no DNS records. v2 at `api.bill.com/api/v2` is what actually works for standard accounts.

## Endpoints we call

Base URL `https://api.bill.com/api/v2`.

| Method | Path | Purpose |
|---|---|---|
| POST | `/Login.json` | Authenticate, get sessionId. |
| POST | `/List/Customer.json` | Customer list, paginated 100/page. |
| POST | `/List/Item.json` | Product / service catalog. |
| POST | `/List/Invoice.json` | Invoice list with `invoiceDate:gte:YYYY-MM-DD` filter. |

We never write to Bill.com.

## Files

- `app/Services/BillCom/BillComClient.php` — HMAC + session client; generators for `customers()`, `items()`, `invoicesSince()` so callers don't materialise the full result set.
- `app/Services/BillCom/InvoiceDomainExtractor.php` — pure-function regex extractor. Recognises a fixed TLD list (com / org / net / io / co / us / gov / edu).
- `app/Services/BillCom/CustomerSyncService.php` — pulls customers + invoices, links sites by domain.
- `app/Services/BillCom/CarePlanSyncService.php` — classifies items via regex, walks invoices, sets `care_plan_enabled`.
- `app/Console/Commands/{BillComTest,SyncBillCustomers,SyncBillCarePlans}.php`
- `app/Http/Controllers/BillComSettingsController.php` + `resources/views/settings/bill-com.blade.php`
- Config: `config/clockwork.php` → `bill_com` key.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| daily 01:00 | `clockwork:sync-bill-customers` — pull customers + invoices in window, extract domains, link sites. Picks the most-recent invoice when a site appears in multiple. |
| daily 01:30 | `clockwork:sync-bill-care-plans` — classify items, walk invoices, set `care_plan_enabled` on linked sites whose `care_plan_override IS NULL`. |

Both gated on `CLOCKWORK_BILL_COM_ENABLED`. Both write `bill_com_sync` rows to `action_logs` so runs surface on `/maintenance-history`.

## How linking actually works

Bill.com's API has very thin metadata: customers have no custom fields and no notes; invoice line items have no product code, only a free-text `description`. Direct customer-to-site matching is impossible because the billing email rarely matches the site domain.

What works is two specific operator-side conventions:

1. **Every line item description contains the site domain.** Examples observed: `"Website hosting for example-client.com"`, `"http://www.example-client.com - - Plugin updates, backups, security scans, etc."`. The extractor pulls the first plausible domain and we match against `Site::domain`.
2. **The care-plan offering uses a consistent Item name** — default regex `/care plan/i`. Override via `CLOCKWORK_BILL_COM_CARE_PLAN_ITEM_REGEX`.

If either convention breaks (the operator stops including domains, or starts using inconsistent Item names), the relevant half silently degrades. The `bill_com_linked_via_invoice` audit column shows which invoice proved each link.

## `/settings/bill-com` — control panel (`BillComSettingsController`)

The fleet-wide status page for this integration. Everything on it is read from either `config('clockwork.bill_com')` or already-synced local tables — the page never calls the Bill.com API itself except when you click a run button.

What it shows:

- **Configured?** — `credentialsConfigured()` checks all four `CLOCKWORK_BILL_COM_*` values (username, password, org ID, dev key) are non-empty. Credentials themselves are never displayed, only the boolean.
- **Enabled?** — the separate `CLOCKWORK_BILL_COM_ENABLED` flag. You can have valid credentials configured but sync disabled (e.g. mid-migration to another accounting system).
- **Last 10 sync runs** — `ActionLog` rows where `action_type = 'bill_com_sync'`, newest first. Same table `/maintenance-history` reads, so this page and that page never disagree about run history.
- **Linked-site count** — `Site::whereNotNull('bill_com_customer_id')->count()` against the total site count, so you can eyeball how much of the fleet has a Bill.com match.
- **Cached customer count** — size of the local `bill_com_customers` mirror table.
- **Care-plan items** — every row from `bill_com_care_plan_items` where `is_care_plan = true`: the cached, classified Bill.com product/service catalog. Each Item is classified by the `CLOCKWORK_BILL_COM_CARE_PLAN_ITEM_REGEX` regex at sync time (`regex_set = true`), but a human can flip `is_care_plan` by hand for one Item without touching the regex — when `regex_set` is false, the sync won't re-classify that Item on the next run. The page shows the regex string alongside the list so you can see why each Item did or didn't match.

Two buttons, each gated on `credentialsConfigured()` returning true (otherwise a flash error tells you to set `.env` first):

- **Run customer sync now** (`POST /settings/bill-com/run-customer-sync`) — runs `CustomerSyncService::sync()` synchronously, in-request, using the configured `customer_link_window_days`. Flashes a summary: customers cached, sites linked, unmatched-domain count.
- **Run care-plan sync now** (`POST /settings/bill-com/run-care-plan-sync`) — runs `CarePlanSyncService::sync()` synchronously using `care_plan_window_days` + the classification regex. Flashes: sites flipped on, sites flipped off, sites skipped due to a manual `care_plan_override`.

Both run inline rather than queued — at fleet scale (~150 sites, ~50 customers, low hundreds of invoices/year) a full sync finishes in well under a minute, so there's no need for a job + polling UI.

See [Features → Care plans + billing](/docs/features/care-plans-and-billing) for the per-site override UI and the day-to-day operator workflow this page supports.

## Manual override

`sites.care_plan_override`:

- `NULL` → sync may write `care_plan_enabled` freely.
- `true` / `false` → manual override, sync **must not** touch the column.

UI: Settings → Billing on the per-site page. "Mark on/off care plan" sets the override; "Let Bill.com decide" clears it back to NULL.

## Window tuning

| Variable | Default | What it does |
|---|---|---|
| `CLOCKWORK_BILL_COM_CUSTOMER_LINK_WINDOW_DAYS` | `1095` | 3 years of invoice history walked for site↔customer linking. |
| `CLOCKWORK_BILL_COM_CARE_PLAN_WINDOW_DAYS` | `400` | Catches both monthly and annual invoicing. A yearly invoice from 13 months ago still flags the customer. |

## Gotchas

- **Filter syntax is `field:op:value`.** We use `invoiceDate:gte:YYYY-MM-DD`.
- **Pagination cursor is `nextPage`** in the response, passed back as the `nextPage` query parameter.
- **No sandbox in use.** the operator's account doesn't have one; read-only is fine against production.
- **Pagination cap is 200 pages (20k rows)** per endpoint to prevent runaway loops. Adjust if the agency grows past that.
