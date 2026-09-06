---
title: Contact form testing
section: Features
order: 70
updated: 2026-09-04
author: Aaron Reimann
tags: [contact-forms, companion, testing, care-plan, slack, modules]
tracks: [modules/ContactForms/src/Commands/{TestContactForms,DetectContactForms,SyncCompanionFormSubscriptions}.php, modules/ContactForms/src/ContactFormsServiceProvider.php, modules/ContactForms/src/ContactFormTester.php, modules/ContactForms/src/FormsController.php, app/Http/Controllers/FormsController.php, app/Models/ContactFormTest.php, resources/views/dashboard/forms/index.blade.php]
---

A care-plan benefit that confirms a site's contact forms are still firing. Companion injects a marker and triggers the form; we verify the receiving email lands. Failures and recoveries fire a chat alert. Care-plan sites can configure up to **3 forms per site**, each on its own schedule. Packaged as the `clockwork/contact-forms` module (`modules/ContactForms`) in the **Maintenance & QA** category.

## Module architecture & enablement

Contact Form Testing is decoupled into a standalone module (`modules/ContactForms`):
- **Package**: `clockwork/contact-forms`, managed via `Modules\ContactForms\ContactFormsServiceProvider`.
- **Category**: `maintenance` ("Maintenance & QA") alongside the ManageWP replacement suite.
- **Module state**: Gated by `ModuleStateResolver::isEnabled('contact-forms')`. When disabled via `/settings/modules`, the top-nav `/forms` link, per-site Forms tab, and failing forms badge are cleanly omitted from view.
- **Backward compatibility**: Legacy class paths (`App\Services\Forms\ContactFormTester`, `App\Services\Forms\ContactFormDetector`, `App\Services\Forms\MonthlyStats`, `App\Http\Controllers\FormsController`, and console commands) are preserved via class aliases.

## How it works

1. The site is on a **care plan** (gate enforced at the service, command, and controller).
2. Companion is installed on the site with the `'contact-form-test'` capability advertised.
3. Operator opens the per-site **Forms** tab and adds up to 3 form-tests (form ID, frequency: weekly default, optional URL).
4. Daily at 06:00 UTC, `clockwork:test-contact-forms` walks every `contact_form_tests` row whose frequency makes it due (weekly ≥ 7 days since last run; daily ≥ 1 day) and POSTs to Companion's `/test-contact-form`.
5. Companion submits the form with a marker string in the message body. We watch the destination inbox for the marker. If the marker arrives → pass. If not within the timeout → fail.

Each run writes a row to `contact_form_test_runs` keyed by `contact_form_test_id` so per-form history is preserved. Per-form state (pass/fail/streak/last_test_at) lives on the `contact_form_tests` row.

## Where to look

- **`/forms`** — fleet-wide table of every configured form-test across every care-plan site (top-nav). Its search box filters instantly as you type, no need to hit Enter — same client-side pattern as [Features → Sites (fleet view)](/docs/features/sites-fleet-view#what-you-see); see that page for how it works and its "current page only" limitation.
- **`/sites/{id}` → Forms tab** — per-site cards (one per configured form), state, history, Test-now button.
- **Companion → Tools → Clockwork → Forms** (separate codebase) — client-visible state.
- **Chat alerts** — operators get failure / recovery pings on Mattermost and/or Slack (no email; the failure case might be a broken mail path). If the client has configured a Slack webhook in their own wp-admin, they get a plain-English version of the same two events too — see [Integrations → Slack](/docs/integrations/slack).

## Setup per site

Per-site Forms tab:

1. Make sure the site is on a care plan (Settings → Billing card).
2. Click **Install Companion** on the Settings tab if not already.
3. **Detect contact forms** runs daily — Companion scans for active form plugins and caches what it finds.
4. On the Forms tab, **+ Add form**: pick a form ID from the dropdown (or paste one), URL optional, frequency = Weekly.
5. **Test now** to verify end-to-end before relying on the schedule.

Repeat for up to 3 forms. If a client needs more than 3 or a daily cadence, they contact support — daily is exposed via the `?admin=1` query string on the Forms tab.

## Frequency

- **Weekly** is the self-serve default (every 7 days).
- **Daily** is an admin-only escalation. The Forms tab dropdown shows it only when the page is loaded with `?admin=1`. The controller accepts either value unconditionally, so `tinker` / direct DB edits work too.
- Monthly is intentionally not supported — too coarse for an early-warning system.

## Alerts

Through `ChatNotifier` (Mattermost + Slack + the per-site client Slack channel, if configured — see [Integrations → Mattermost](/docs/integrations/mattermost) for the fan-out mechanics):

- `contactFormTestFailed()` fires when a form-test's failure streak hits 2 (one-off blips don't wake the channel).
- `contactFormTestRecovered()` fires when a previously-failing form starts passing again.

Email used to be a secondary channel but was removed — if the form's own mail send is broken, an email alert through the same SMTP path would be silently broken too.

## Companion install

Form testing requires the Companion mu-plugin. Per-site install button on Settings (one-click; runs `php artisan clockwork:install-companion --site=<id>` under the hood). Companion install is **not** scheduled fleet-wide — mirror of the LLAR pattern. See [Architecture → Companion plugin](/docs/architecture/companion-plugin).

The HMAC secret can be rotated from the Settings tab too — useful when offboarding a contractor or replacing the laptop.

## Why this is care-plan-only

Form testing is non-trivial to run reliably (Companion install per site, HMAC rotation, marker round-trip via real SMTP). Bundling it with the care plan keeps the operations surface area aligned with the customers who already opted into managed maintenance, rather than spinning up a separate billing line.

## What off-plan clients see (Companion 1.21.5+)

Off-plan clients still see the **Forms** admin page in their `Tools → Clockwork` menu — it's a deliberate sales surface. Detection runs for everyone, so they can see Clockwork already inventoried their contact forms. The Monitor toggles are visually present but locked with a "Care plan" pill; the upsell banner at the top names the specific features they'd get (weekly tests, email-delivery verification, the Mattermost ping when a form silently breaks). Re-detect still works so they can demo it. If the site previously had a care plan and has subscriptions, those render as "Forms you were monitoring" with a "Care plan paused" pill — Stop-monitoring still works for cleanup, Test-now is gated.

This is the same upsell pattern Performance / Security / Backups use — see [decision #27 in the architecture notes](/docs/architecture/clockwork-decisions) for the underlying principle ("show what they're missing, don't hide the feature"). Defense-in-depth: the `wp_ajax_clockwork_companion_subscribe_form` and `wp_ajax_clockwork_companion_test_form_now` endpoints refuse with `403 + error: care_plan_required` when off-plan, so a clever admin can't bypass the disabled UI.

## Gotchas

- **Care-plan flag is the master gate.** Removing the care plan on a site stops its form tests immediately (next scheduler pass skips the rows). The rows themselves stay — re-enabling the care plan resumes testing without re-configuration.
- **Companion install is intentionally manual.** Each site gets the plugin via the per-site button.
- **Detect + test commands are no-ops** on sites without Companion. Safe to leave running fleet-wide.
- **The marker must round-trip to a real inbox.** If the form's destination email is bounced or the box is unreachable, we'll see the form as failing even though the form plugin itself is fine.
- **Schema migration safety net:** the seven `sites.contact_form_test_*` columns are still in the table for one release cycle as a backup; they're no longer read. A follow-up migration drops them.
