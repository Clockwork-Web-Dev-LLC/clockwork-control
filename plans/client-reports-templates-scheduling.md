# Client Reports: Templates & Scheduling

Drafted 2026-09-07 by Claude, after Aaron flagged that the reports history table's Actions
column was cramped (fixed separately, small commit) and shared screenshots of a ManageWP-style
"Client Report" panel with four tabs — Reports, Templates, Custom Work, Scheduling — asking for
"templates" and "scheduled reporting" here. Not yet implemented — this is a plan only.

## What already exists (and what's actually broken)

The Client Reports module (`modules/ClientReports/`) shipped in v1.2.0 with manual, one-shot
report generation only — `ClientReportsController::generate()` compiles every section via
`ClientReportCompiler::compile()` and stores the result on demand. That part works (and just got
its first test coverage + a real bug fix in v1.2.3 — see `resources/docs` git history / CHANGELOG).

But there's more scaffolding here than it looks like from the UI, and most of it is dead:

- **`client_report_schedules` table + `ClientReportSchedule` model already exist** — `site_id`,
  `frequency` (comment says `weekly, monthly, quarterly`), `recipients` (json array of emails),
  `is_enabled`, `last_sent_at`, `next_run_at`, and a later migration added `delivery_mode`
  (`auto`/`draft`, default `auto`).
- **`delivery_mode` was never wired into the model.** `ClientReportSchedule::$fillable` and
  `casts()` don't mention it at all — the column exists in the DB but nothing reads or writes it.
- **`php artisan clockwork:send-client-reports` already exists** and can generate + email a
  report per schedule, using last month's date range. But it:
  - **Ignores `next_run_at` and `frequency` entirely** — it processes every `is_enabled` schedule
    unconditionally on every invocation, with no due-date check and no advancement of
    `next_run_at` afterward. Running it twice in a row emails the client twice.
  - **Ignores `delivery_mode` entirely** — always sends immediately, `draft` mode does nothing.
- **The command isn't registered anywhere.** Nothing in `routes/console.php` schedules it — even
  if all of the above were fixed, it would never run on its own.
- **There is no UI at all** to create, edit, or view a `ClientReportSchedule` row. The only way
  one could exist today is a direct DB insert.
- **"Templates" don't exist in any form.** `ClientReportCompiler::compile()` always compiles all
  seven sections (`updates`, `uptime`, `security`, `performance`, `forms`, `traffic`, `backups`);
  there's no concept of a named, reusable subset.

So "add templates and scheduling" is really: **finish a half-built, currently-inert scheduling
feature, and add a genuinely new templates feature** — not two clean net-new builds.

## Explicitly out of scope for this plan

- **"Custom Work" tab** from the screenshots — reads as a separate ManageWP feature (itemized
  billable work log), unrelated to report content/scheduling. Not part of this plan; would be its
  own separate feature request if wanted.
- **Report language selector** — no i18n infrastructure exists anywhere in this app. Skip.
- **Conditional delivery triggers** ("wait for confirmation if site is currently down" / "latest
  security check is positive") — real ManageWP feature, but requires evaluating live site
  status at send-time and is meaningfully more complex than the rest of this plan. Proposed as a
  **Phase 4 stretch** below, not core scope. Ship auto/draft delivery modes first.

## Architecture decisions

### Templates

- New table `client_report_templates`: `id`, `name`, `sections` (json array of section keys —
  subset of `['updates','uptime','security','performance','forms','traffic','backups']`),
  `is_default` (bool), timestamps. A migration seeds one `is_default = true` row ("Default
  template", all seven sections) so both the generate form and the scheduling form always have
  at least one valid option, matching the screenshots' always-present "-- Default template --".
- `ClientReportCompiler::compile()` gains an optional `?array $sections = null` param — `null`
  keeps today's behavior (compile everything), so every existing caller (manual generate, the
  send command before it's updated) keeps working unmodified. When given, skip building any
  section not in the list. `meta`, `site`, and `branding` are never optional — the view
  unconditionally reads them.
- `client_reports` and `client_report_schedules` both gain a nullable `template_id` FK
  (`nullOnDelete` — deleting a template shouldn't cascade-delete reports that already used it,
  it should just leave existing history alone with `template_id = null`).
- New `ClientReportTemplate` model + a `TemplatesController` (index/store/update/destroy) under
  the same `web+auth` route group as the rest of the module. A template can't be deleted while
  `is_default` — swap the default to another row first (mirrors how `EnforceInstallerGate`-style
  guards protect against locking an operator out).

### Scheduling

- Fix `ClientReportSchedule::$fillable`/`casts()` to include `delivery_mode` (string) and add
  `template_id` (nullable int).
- Fix `SendScheduledClientReports`:
  - Only process rows where `next_run_at` is null (never run yet) or `<= now()`, unless `--force`
    is passed (existing flag, currently ignored — wire it up to bypass the due-date check, for
    manual testing/support use exactly like the option's docblock already implies).
  - After a successful send, advance `next_run_at` from `frequency` (`weekly` → `+1 week`,
    `monthly` → `+1 month`) rather than leaving it untouched forever.
  - Respect `delivery_mode`: `auto` sends immediately (today's only real behavior); `draft`
    creates the `ClientReport` row (`status = 'generated'`) and **stops** — no `Mail::send()`,
    no `last_sent_at`/`next_run_at` update yet. It shows up in `/client-reports` for the operator
    to review and click the existing "Send" action manually, which already exists and already
    correctly sets `status = 'sent'` + `sent_at`. `next_run_at` should still advance on the draft
    generation itself (the schedule fired on time; whether the operator manually forwards it is
    a separate concern), not on the eventual manual send.
  - Pass `template_id`'s `sections` through to `compiler->compile()` when set.
- Register on the actual scheduler: `Schedule::command('clockwork:send-client-reports')
  ->dailyAt('06:00')` in `routes/console.php`, matching every other command's pattern there
  (cheap no-op most days since almost nothing will be due).
- New `SchedulesController` (or fold into `ClientReportsController` — decide at implementation
  time based on file size) for CRUD on `ClientReportSchedule`, one row per site (existing
  `unique('site_id')` DB constraint already enforces "one schedule per site" — the UI should
  reflect that as an edit-in-place per site, not an open-ended list).
- **Frequency mismatch to resolve during implementation**: the migration's own comment says
  `weekly, monthly, quarterly`, but the ManageWP screenshot shows `Off / Weekly / Bi-weekly /
  Monthly`, and the command above only has real interval math for `weekly`/`monthly`. Recommend
  settling on **Off / Weekly / Monthly** only for v1 (drop bi-weekly and quarterly from the UI
  rather than half-implementing interval math for options nothing else uses) — flagging this
  explicitly rather than silently picking one.

## Phased build order

**Phase 1 — Fix what's already broken.** Small, and everything else depends on it being correct
first: `delivery_mode`/`template_id` on the model, due-date gating + `next_run_at` advancement in
the command, `--force` actually doing something, scheduler registration. Tests: a schedule due
today sends and advances `next_run_at`; a schedule not yet due is skipped; `--force` bypasses
that; `draft` mode generates without emailing.

**Phase 2 — Templates.** Migration (`client_report_templates` + the two `template_id` FKs +
seed the default row), model, compiler's `$sections` param, `TemplatesController` + routes +
views (list/create/edit/delete), a template picker added to the existing manual generate() form.
Tests: compiling with a restricted section list only includes those sections in `sections_data`;
`template_id` round-trips onto the created `ClientReport`; can't delete the last/default template.

**Phase 3 — Scheduling UI.** `SchedulesController` + routes + views (one form per site: site
picker if none exists yet, frequency, recipients, template picker, delivery mode radio), plus a
shared tab-nav partial (Reports | Templates | Scheduling) reused across all three pages — follow
the Settings Hub's existing secondary-tab-nav pattern (`resources/views/settings/`) rather than
inventing a new one. Tests: full CRUD round-trip; a site already having a schedule shows the
edit form, not a duplicate-create option (DB unique constraint should never be the thing the user
hits first).

**Phase 4 — stretch, not core scope.** Conditional delivery ("hold for confirmation if the site
is currently down / latest security scan flagged something"). Needs a live status check at
send-time against `Site`'s existing uptime/security-scan state — genuinely useful, but real
added complexity. Revisit only after Phases 1–3 are live and used for a cycle.

## Verification plan

- Pest coverage per phase (see test notes above) — this module had zero tests before v1.2.3;
  keep building on that baseline rather than letting new controllers ship untested again.
- `./vendor/bin/pint --test` + `./vendor/bin/phpstan analyse --memory-limit=2G` clean, matching
  every other change in this repo.
- Manual: create a template with a reduced section list, create a schedule against a real site,
  run `php artisan clockwork:send-client-reports --force` and confirm the generated report only
  contains the template's sections, then confirm a second run without `--force` does nothing
  (due-date respected) and a real due run correctly advances `next_run_at`.
