---
title: Care plans + billing
section: Features
order: 100
updated: 2026-08-29
author: Aaron Reimann
tags: [care-plan, billing, bill-com, pressable]
tracks: [app/Services/BillCom/**, app/Console/Commands/SyncBill*.php]
---

Care-plan sites get the premium tier of monitoring. The flag (`sites.care_plan_enabled`) drives which scans run, which Companion admin pages show real data, and how the Updates tab is labelled. Bill.com sync flips the flag automatically based on actual invoicing — with a manual override per site for edge cases.

## What care plan unlocks

| Feature | Hosting (default) | Care plan |
|---|---|---|
| Backups | 30-day retention (DO Spaces) — SpinupWP sites only; Pressable sites get whatever window Pressable's own API exposes (~36-38h observed), unaffected by care-plan tier | 90-day retention — SpinupWP only, see note above |
| Uptime monitoring | ✅ | ✅ |
| Domain blacklist scan | ✅ daily | ✅ daily |
| Sucuri SiteCheck malware scan | — | ✅ daily |
| WordPress core checksums | — | ✅ daily |
| Lighthouse performance scan (GTmetrix, or Pressable's own report for Pressable sites) | — | ✅ nightly |
| Managed plugin / theme / core updates | — | ✅ |
| Companion Performance + Security pages | shows what care plan would add | full data |

The hosting tier is the default for every site this operator hosts. Care plan is a per-site boolean on top, and applies the same way regardless of hosting provider — the retention-window caveat above is the one place a Pressable site's care-plan benefit genuinely looks different from a SpinupWP site's.

## How the flag flips

Two paths can change `care_plan_enabled`:

1. **Bill.com sync** (`clockwork:sync-bill-care-plans`, daily 01:30) — flips the flag based on whether the Bill.com customer linked to the site has a recent invoice with a care-plan-classified line item. Default care-plan-Item regex is `/care plan/i`; tunable via `CLOCKWORK_BILL_COM_CARE_PLAN_ITEM_REGEX`.
2. **Manual override** — the per-site Settings → Billing block. "Mark on care plan" / "Mark off care plan" sets `care_plan_override` (bool); "Let Bill.com decide" clears it back to NULL.

**`care_plan_override` is the gate:**

- `NULL` → Bill.com sync may write `care_plan_enabled` freely.
- `true` / `false` → manual override, sync MUST NOT touch the column.

## Per-site Settings → Billing

Three controls:

- **Linked customer** — read-only display of `bill_com_customer_name` + `bill_com_linked_via_invoice` (which invoice proved the link). Renders "Not linked" when no Bill.com row matches.
- **Care plan toggle** — Mark on / off care plan (sets the override) or Let Bill.com decide (clears it).
- **Linked-by-invoice audit** — shows the last sync timestamp.

## /settings/bill-com

The fleet-wide Bill.com control panel:

- Credentials configured / not.
- Last customer-sync run + summary.
- Last care-plan sync run + summary.
- Manual "Run sync now" buttons for both syncs.

If credentials aren't configured, the syncs no-op silently — the page shows "Bill.com integration disabled."

## How linking works

Bill.com's API has very thin metadata — no custom fields, no notes. We can't directly match a customer to a site. What works for our dataset is two operator-side conventions:

1. **Every line item description contains the site domain.** `"Website hosting for example-client.com"`, `"http://www.example-client.com — Plugin updates, backups..."`. We extract the first plausible domain per line item.
2. **The care-plan offering uses a consistent Item name.** Default regex `/care plan/i`; override via env if you rename.

Both syncs walk recent invoices (1095 days for customer linking, 400 days for care-plan detection — catches both monthly and annual invoicing cadences). Pick the most-recent-invoice when a site appears in multiple, so the audit trail reflects the freshest evidence.

See [Integrations → Bill.com](/docs/integrations/bill-com) for the full mechanics.

## Override use cases

- **Customer pays out-of-band.** Cash, Stripe, whatever — they're not in Bill.com but they're on care plan. Mark on care plan.
- **Customer is in Bill.com but not yet billed.** Onboarded today, first invoice next month. Mark on care plan until the first invoice clears.
- **Customer cancelled, but you want to keep care-plan features active for a few days.** Mark on care plan. Remember to clear the override when you're ready.
- **Bill.com has the wrong domain in the invoice description.** Wrong site got linked. Mark off care plan on the wrong one and on for the right one until the next invoice fixes the audit trail.

## Disabling Bill.com sync entirely

Set `CLOCKWORK_BILL_COM_ENABLED=false`. The daily syncs become no-ops. All `care_plan_enabled` values stay where they are. Manual toggles still work. Useful when migrating accounting systems.

## Gotchas

- **Sync respects override per-site.** You can override one site without disabling sync globally.
- **`care_plan_override` is `nullable boolean`.** Three values matter — NULL, true, false. Don't write `0` or `''`.
- **Read-only.** We never push to Bill.com. If we ever need to (auto-create an invoice from work performed), that's a separate plan.
