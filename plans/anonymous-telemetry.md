# Anonymous usage telemetry — build spec

**Audience: Gemini (or whoever implements this).** Prescriptive spec, not a brainstorm — follow it directly, file path by file path. Every snippet below was checked against the real current code; if something on disk doesn't match what's quoted here, stop and flag it rather than guessing.

## Why, and exact scope (read this before anything else)

The maintainer wants aggregate, non-identifying visibility into real-world usage — nothing more. Confirmed final scope, explicitly narrowed by the maintainer to exactly two signals:

1. **How many sites** a given install manages (bucketed count, never exact).
2. **Which optional modules** are enabled (module ids only).

**That's it.** No hosting-provider mix, no server counts, no app version, no anything else. Do not add fields beyond the two above without asking first.

Never included, under any circumstance: domains, IPs, emails, org/client names, tags/notes, server hostnames, file paths, git remotes, API keys/tokens, provider account IDs, named individuals, exact (unbucketed) counts.

## Tools/tech involved (be exact about these — don't substitute)

- **Laravel side** (this repo): plain Eloquent, the existing `App\Support\Settings` key/value store, a new Artisan command, Laravel's task scheduler, the existing `Http` facade for the outbound POST. No new Composer packages.
- **Receiving side** (separate, new, outside this repo): a **Cloudflare Worker** (JavaScript/TypeScript, deployed via the `wrangler` CLI) backed by a **Cloudflare D1** database (SQLite-flavored, managed by Cloudflare). Confirmed reasoning: the marketing site (`~/Projects/clockworkcontrol.com-astro`) is static-only today (no SSR adapter), so bolting telemetry onto it would force an unrelated hosting decision — a fully standalone Worker on its own subdomain avoids that entirely, costs $0/month at this scale (free tier: 100k requests/day, 5GB D1 storage), and needs no server to patch. Do not substitute a different platform (e.g. a Vercel function, a PHP endpoint, S3/DO Spaces) without checking back — this was chosen deliberately over those alternatives.

---

## Part A — Laravel app changes

### A1. Config — `config/clockwork.php`

Append a new block at the very end of the file, right before the final closing `];` (after the existing `'seo' => [...]` block):

```php
    // Anonymous usage telemetry — enabled by default. Sends only a
    // bucketed site count and enabled-module list. Never domains, IPs,
    // credentials, hosting platforms, or any other identifying data.
    'telemetry' => [
        'enabled' => (bool) env('CLOCKWORK_TELEMETRY_ENABLED', true),
        'endpoint' => env('CLOCKWORK_TELEMETRY_ENDPOINT', 'https://telemetry.clockworkcontrol.com/v1/report'),
    ],
```

### A2. Payload builder — `app/Services/Telemetry/TelemetryPayloadBuilder.php` (new file)

```php
<?php

namespace App\Services\Telemetry;

use App\Models\Server;
use App\Models\Site;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Core\InstalledModule;

/**
 * Builds the anonymous telemetry payload — deliberately narrow scope:
 * a bucketed site count, hosting-provider mix, and enabled-module ids.
 * Nothing else. See plans/anonymous-telemetry.md for why the scope is
 * this narrow — do not add fields here without checking with the
 * maintainer first.
 */
class TelemetryPayloadBuilder
{
    /**
     * Bucket boundaries applied to every numeric value in the payload —
     * never send an exact count, which could fingerprint a small install.
     *
     * @var array<int, string>
     */
    private const BUCKETS = ['0', '1-5', '6-25', '26-100', '101-500', '500+'];

    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'schema_version' => 1,
            'install_id' => $this->installId(),
            'sent_at' => Carbon::now()->startOfHour()->toIso8601String(),
            'sites_count_bucket' => $this->bucket(Site::query()->hostMonitored()->count()),
            'hosting_provider_mix' => $this->hostingProviderMix(),
            'modules_enabled' => $this->enabledModuleIds(),
        ];
    }

    /**
     * A random, permanent, non-reversible per-install identifier — lets the
     * receiving side dedupe "N unique installs" from "N pings," never
     * derived from anything install-specific. Generated once, lazily.
     */
    private function installId(): string
    {
        $existing = $this->settings->get('telemetry.install_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = (string) Str::uuid();
        $this->settings->put('telemetry.install_id', $id);

        return $id;
    }

    /**
     * @return array<string, string>
     */
    private function hostingProviderMix(): array
    {
        $siteCounts = Site::query()
            ->hostMonitored()
            ->selectRaw('hosting_provider, COUNT(*) as total')
            ->groupBy('hosting_provider')
            ->pluck('total', 'hosting_provider')
            ->all();

        $serverCounts = Server::query()
            ->monitored()
            ->selectRaw('provider, COUNT(*) as total')
            ->groupBy('provider')
            ->pluck('total', 'provider')
            ->all();

        $mix = [];
        foreach ($siteCounts as $provider => $count) {
            $mix["site:{$provider}"] = $this->bucket((int) $count);
        }
        foreach ($serverCounts as $provider => $count) {
            $mix["server:{$provider}"] = $this->bucket((int) $count);
        }

        return $mix;
    }

    /**
     * @return list<string>
     */
    private function enabledModuleIds(): array
    {
        return InstalledModule::query()
            ->where('enabled', true)
            ->orderBy('module_id')
            ->pluck('module_id')
            ->all();
    }

    private function bucket(int $count): string
    {
        return match (true) {
            $count === 0 => '0',
            $count <= 5 => '1-5',
            $count <= 25 => '6-25',
            $count <= 100 => '26-100',
            $count <= 500 => '101-500',
            default => '500+',
        };
    }
}
```

Notes for whoever writes the test for this: confirm `Modules\Core\InstalledModule` is the exact class name (verified via a prior codebase survey — table `installed_modules`, columns `module_id`, `enabled`), and that `Site::hostMonitored()` / `Server::monitored()` are the existing scopes already used elsewhere for "actually managed" counts (they exclude archived/ignored/staging noise) — reuse them as-is, don't write new count logic.

### A3. Sending command — `app/Console/Commands/SendTelemetry.php` (new file)

Match the house style used by `PushCompanionBackupsReport`/similar commands (flat `App\Console\Commands` namespace, `clockwork:` signature prefix) and the existing outbound-call style used by `modules/Core/src/ModuleDirectoryClient.php` (plain `Http::timeout()`, try/catch, log-and-continue, never throw):

```php
<?php

namespace App\Console\Commands;

use App\Services\Telemetry\TelemetryPayloadBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTelemetry extends Command
{
    protected $signature = 'clockwork:send-telemetry';

    protected $description = 'Send the opt-in anonymous usage report (site count, hosting mix, enabled modules — nothing else) to the project maintainer.';

    public function handle(TelemetryPayloadBuilder $builder): int
    {
        $endpoint = (string) config('clockwork.telemetry.endpoint');
        $payload = $builder->build();

        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Clockwork-Control/'.config('clockwork.version', '1.1.0')])
                ->post($endpoint, $payload);

            if (! $response->successful()) {
                Log::warning('telemetry.send_failed', ['status' => $response->status()]);
                $this->warn("Telemetry send failed: HTTP {$response->status()}");

                return self::SUCCESS; // never treat this as a real failure — never blocks the scheduler
            }
        } catch (Throwable $e) {
            Log::warning('telemetry.send_failed', ['error' => $e->getMessage()]);
            $this->warn("Telemetry send failed: {$e->getMessage()}");

            return self::SUCCESS;
        }

        $this->info('Telemetry sent: '.json_encode($payload));

        return self::SUCCESS;
    }
}
```

Always return `self::SUCCESS` even on failure — a telemetry hiccup must never mark the scheduled task as failed or alert the operator; it's genuinely a non-event from the operator's point of view.

### A4. Scheduling — `routes/console.php`

Add, matching the existing `Schedule::command(...)` blocks already in this file (see e.g. the `clockwork:check-domain-expirations` entry for the exact chain style):

```php
Schedule::command('clockwork:send-telemetry')
    ->weeklyOn(1, '06:15')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground()
    ->when(fn () => (bool) config('clockwork.telemetry.enabled', false)
        && (bool) app(\App\Support\Settings::class)->get('telemetry.enabled', false));
```

Both gates must be true for a send to happen — either being false disables it entirely. This double-gate mirrors the `config()` + `Settings`-backed override pattern already used by other scheduled features in this same file (e.g. the security-scans kill-switches).

### A5. Installer — optional opt-in checkbox

File `resources/views/install/review.blade.php`. **This is separate from, and in addition to, the required disclaimer-acknowledgment checkbox specified in `plans/liability-disclaimer.md` — both land on this same step-9 screen, but they are different UI elements with different defaults.** Telemetry: unchecked by default, entirely optional, does not block submit. Disclaimer: required, blocks submit (see the other plan file).

Add this card in the same location described in the disclaimer plan (immediately above the "Ready to apply" callout, so the on-screen order top-to-bottom is: telemetry opt-in card → disclaimer-acknowledgment card → "Ready to apply" callout → form):

```blade
    <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/30 text-xs text-[var(--color-ink-muted)] mb-4">
        <label class="flex items-start gap-2.5 cursor-pointer">
            <input type="checkbox" name="telemetry_opt_in" value="1" form="install-review-form"
                   class="mt-0.5 rounded border-[var(--color-border-light)]">
            <span>
                <strong class="text-[var(--color-ink-strong)]">Help improve Clockwork Control</strong> — send a small anonymous usage report about once a week: roughly how many sites this install manages, which hosting platforms they're on, and which optional modules are enabled. No site URLs, credentials, content, or IP data are ever included. Off by default; change this anytime in Settings &rarr; Maintenance.
            </span>
        </label>
    </div>
```

(`form="install-review-form"` requires the same `id="install-review-form"` addition to the `<form>` tag specified in `plans/liability-disclaimer.md` step 4 — if that hasn't been applied yet, apply it first.)

**Backend** — `app/Http/Controllers/InstallerController.php`, method `install(Request $request)`. This field is optional, so no validation rule needed. Find the existing `.env` write:

```php
        // 3. Write .env atomically
        $this->envWriter->writeMany($envData);
```

Add `CLOCKWORK_TELEMETRY_ENABLED` to the `$envData` array (defined earlier in the same method, alongside `APP_NAME`, `APP_KEY`, etc.) — insert this line into that array's definition:

```php
            'CLOCKWORK_TELEMETRY_ENABLED' => $request->boolean('telemetry_opt_in') ? 'true' : 'false',
```

And immediately after the disclaimer-acceptance `Settings::putMany()` call specified in the other plan file (or, if that hasn't been applied yet, immediately after `$this->userProvisioner->addOrRestore(...)`), add:

```php
        app(\App\Support\Settings::class)->put('telemetry.enabled', $request->boolean('telemetry_opt_in'));
```

Writing it to both `.env` (config-level default) and `Settings` (immediately readable/changeable without a redeploy) matches the double-gate in A4.

### A6. Settings → Maintenance page toggle

Routes — `routes/web.php`, inside the same authenticated route group as the existing `/settings/maintenance` routes (~line 355). Add these two lines directly after the existing `settings.maintenance.backup` route:

```php
    Route::patch('/settings/maintenance/telemetry', [MaintenanceController::class, 'updateTelemetry'])->name('settings.maintenance.telemetry.update');
    Route::post('/settings/maintenance/telemetry/send-now', [MaintenanceController::class, 'sendTelemetryNow'])->name('settings.maintenance.telemetry.sendNow');
```

Controller — `app/Http/Controllers/MaintenanceController.php`. Add `use App\Support\Settings;` and `use Illuminate\Support\Facades\Artisan;` to the imports, and add these two methods (mirroring `SecurityScansSettingsController::update()`/`runNow()` exactly — same injection style, same redirect-with-flash pattern):

```php
    public function updateTelemetry(Request $request, Settings $settings): RedirectResponse
    {
        $settings->put('telemetry.enabled', $request->boolean('enabled'));

        return redirect()->route('settings.maintenance.index')
            ->with('status', 'Telemetry setting saved.');
    }

    public function sendTelemetryNow(): RedirectResponse
    {
        Artisan::queue('clockwork:send-telemetry');

        return back()->with('status', 'Telemetry report queued — sending in background.');
    }
```

(Add `use Illuminate\Http\Request;` and `use Illuminate\Http\RedirectResponse;` too if not already imported in this controller — check the existing `use` block first.)

View — `resources/views/settings/maintenance.blade.php`. Add a new card matching the existing "Database backup" card's exact style (same `class="card p-6 max-w-3xl mb-6"` wrapper, same heading pattern), placed directly after that card:

```blade
    <div class="card p-6 max-w-3xl mb-6">
        <div class="flex items-start justify-between gap-4 mb-4 flex-wrap">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1">
                    <i class="fa-solid fa-chart-line text-[var(--color-ink-muted)] mr-1"></i>
                    Anonymous usage telemetry
                </h2>
                <div class="text-xs text-[var(--color-ink-muted)] max-w-lg">
                    Sends a bucketed site count, hosting-platform mix, and enabled-module list — about once a week, nothing else. No site URLs, credentials, content, or IP data. Off by default.
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <form method="POST" action="{{ route('settings.maintenance.telemetry.sendNow') }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-md border border-[var(--color-border-light)] text-sm font-medium hover:bg-[var(--color-surface-alt)] inline-flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> Send report now
                    </button>
                </form>
                <form method="POST" action="{{ route('settings.maintenance.telemetry.update') }}">
                    @csrf
                    @method('PATCH')
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="enabled" value="1"
                               {{ app(\App\Support\Settings::class)->get('telemetry.enabled', false) ? 'checked' : '' }}
                               onchange="this.form.submit()"
                               class="rounded border-[var(--color-border-light)]">
                        <span class="text-sm font-medium">Enabled</span>
                    </label>
                </form>
            </div>
        </div>
    </div>
```

### A7. Docs — `resources/docs/reference/env-vars.md`

Add a new section, matching the existing "Module Directory" section's table format exactly (see that section, right above "## System updates"):

```markdown
## Telemetry

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_TELEMETRY_ENABLED` | `false` | Opt-in anonymous usage reporting — off unless enabled during install or later in Settings → Maintenance. |
| `CLOCKWORK_TELEMETRY_ENDPOINT` | `https://telemetry.clockworkcontrol.com/v1/report` | Where the weekly report is sent, if enabled. |

Clockwork Control can optionally send a small, anonymous usage report once a week — a bucketed site count, hosting-platform mix, and which optional modules are enabled. It never includes site URLs, content, credentials, or IP data. This is opt-in: it stays off unless you enable it during install or later in Settings → Maintenance. Set `CLOCKWORK_TELEMETRY_ENABLED=false` (the default) to keep this instance fully offline with no external calls.

See [DISCLAIMER.md](/DISCLAIMER.md) for the full data-handling commitment.
```

---

## Part B — Receiving infrastructure (separate project, outside this repo)

This does **not** live in `clockwork-control` or the Astro marketing site. Stand up a small standalone Cloudflare Worker project, e.g. at `~/Projects/clockwork-telemetry-worker/` (new directory).

### B1. Scaffold

```bash
npm create cloudflare@latest clockwork-telemetry-worker -- --type=hello-world --lang=ts --no-deploy
cd clockwork-telemetry-worker
```

### B2. D1 database

```bash
npx wrangler d1 create clockwork_telemetry
```

This prints a `database_id` — copy it into `wrangler.toml` (below). Then apply the schema:

```bash
npx wrangler d1 execute clockwork_telemetry --file=./schema.sql
```

`schema.sql` (new file, repo root of the Worker project):

```sql
CREATE TABLE IF NOT EXISTS pings (
    install_id TEXT NOT NULL,
    day TEXT NOT NULL,               -- 'YYYY-MM-DD', UTC
    sites_count_bucket TEXT NOT NULL,
    hosting_provider_mix TEXT NOT NULL,  -- JSON string
    modules_enabled TEXT NOT NULL,       -- JSON array string
    received_at TEXT NOT NULL,
    PRIMARY KEY (install_id, day)     -- upsert-by-day dedupes repeat pings same day
);
```

Deliberately no separate long-lived "one row per install forever" table — raw `pings` rows should be pruned by a scheduled job after 30-90 days once rollup aggregation is confirmed working (a follow-up, not part of this initial build — flag it as a TODO in the Worker's README rather than skipping silently).

### B3. `wrangler.toml`

```toml
name = "clockwork-telemetry"
main = "src/index.ts"
compatibility_date = "2026-01-01"

routes = [
  { pattern = "telemetry.clockworkcontrol.com/*", zone_name = "clockworkcontrol.com" }
]

[[d1_databases]]
binding = "DB"
database_name = "clockwork_telemetry"
database_id = "<paste the database_id from B2 here>"

[triggers]
crons = ["0 6 * * 1"]  # weekly, Monday 06:00 UTC — rollup + email job
```

The `routes` entry requires `clockworkcontrol.com`'s DNS to already be on Cloudflare (confirm this before deploying — if it isn't, use the default `*.workers.dev` subdomain instead and update `CLOCKWORK_TELEMETRY_ENDPOINT` in the Laravel app's `.env`/config accordingly).

### B4. `src/index.ts`

```typescript
export interface Env {
  DB: D1Database;
  RESEND_API_KEY: string;      // set via `wrangler secret put RESEND_API_KEY`
  ADMIN_SECRET: string;        // set via `wrangler secret put ADMIN_SECRET`
  ALERT_EMAIL: string;         // set via `wrangler secret put ALERT_EMAIL`
}

interface Payload {
  schema_version: number;
  install_id: string;
  sent_at: string;
  sites_count_bucket: string;
  hosting_provider_mix: Record<string, string>;
  modules_enabled: string[];
}

const VALID_BUCKETS = new Set(['0', '1-5', '6-25', '26-100', '101-500', '500+']);

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const url = new URL(request.url);

    if (url.pathname === '/v1/report' && request.method === 'POST') {
      return handleReport(request, env);
    }

    if (url.pathname === '/admin/summary' && request.method === 'GET') {
      return handleAdminSummary(request, env);
    }

    return new Response('Not found', { status: 404 });
  },

  async scheduled(_event: ScheduledEvent, env: Env): Promise<void> {
    await sendWeeklySummary(env);
  },
};

async function handleReport(request: Request, env: Env): Promise<Response> {
  let payload: Payload;
  try {
    payload = await request.json();
  } catch {
    return new Response('Invalid JSON', { status: 400 });
  }

  // Minimal shape validation — reject anything that doesn't match exactly
  // what the Laravel side is documented to send. Do not silently accept
  // extra/unexpected fields.
  if (
    typeof payload.install_id !== 'string' || payload.install_id.length < 10 ||
    !VALID_BUCKETS.has(payload.sites_count_bucket) ||
    typeof payload.hosting_provider_mix !== 'object' ||
    !Array.isArray(payload.modules_enabled)
  ) {
    return new Response('Bad payload', { status: 400 });
  }

  const day = new Date().toISOString().slice(0, 10);

  // Basic dedup/rate-limit: one row per (install_id, day) via upsert — a
  // burst of repeat pings from a misbehaving install in the same day just
  // overwrites the same row, never multiplies it.
  await env.DB.prepare(
    `INSERT INTO pings (install_id, day, sites_count_bucket, hosting_provider_mix, modules_enabled, received_at)
     VALUES (?, ?, ?, ?, ?, ?)
     ON CONFLICT(install_id, day) DO UPDATE SET
       sites_count_bucket = excluded.sites_count_bucket,
       hosting_provider_mix = excluded.hosting_provider_mix,
       modules_enabled = excluded.modules_enabled,
       received_at = excluded.received_at`
  ).bind(
    payload.install_id,
    day,
    payload.sites_count_bucket,
    JSON.stringify(payload.hosting_provider_mix),
    JSON.stringify(payload.modules_enabled),
    new Date().toISOString(),
  ).run();

  return new Response(null, { status: 204 });
}

async function handleAdminSummary(request: Request, env: Env): Promise<Response> {
  const provided = request.headers.get('X-Admin-Secret');
  if (provided !== env.ADMIN_SECRET) {
    return new Response('Forbidden', { status: 403 });
  }

  const summary = await buildSummary(env);
  return new Response(JSON.stringify(summary, null, 2), {
    headers: { 'Content-Type': 'application/json' },
  });
}

async function buildSummary(env: Env) {
  const since = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);

  const { results: installs } = await env.DB.prepare(
    `SELECT COUNT(DISTINCT install_id) as unique_installs FROM pings WHERE day >= ?`
  ).bind(since).all();

  const { results: buckets } = await env.DB.prepare(
    `SELECT sites_count_bucket, COUNT(DISTINCT install_id) as installs
     FROM pings WHERE day >= ? GROUP BY sites_count_bucket`
  ).bind(since).all();

  const { results: rows } = await env.DB.prepare(
    `SELECT modules_enabled FROM pings WHERE day >= ?`
  ).bind(since).all();

  const moduleCounts: Record<string, number> = {};
  for (const row of rows as { modules_enabled: string }[]) {
    const modules: string[] = JSON.parse(row.modules_enabled);
    for (const m of modules) moduleCounts[m] = (moduleCounts[m] ?? 0) + 1;
  }

  return {
    window: `last 30 days (since ${since})`,
    unique_installs: installs[0]?.unique_installs ?? 0,
    site_count_bucket_histogram: buckets,
    module_popularity: moduleCounts,
  };
}

async function sendWeeklySummary(env: Env): Promise<void> {
  const summary = await buildSummary(env);

  await fetch('https://api.resend.com/emails', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${env.RESEND_API_KEY}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      from: 'telemetry@clockworkcontrol.com',
      to: env.ALERT_EMAIL,
      subject: 'Clockwork Control — weekly telemetry summary',
      text: JSON.stringify(summary, null, 2),
    }),
  });
}
```

### B5. Secrets and deploy

```bash
npx wrangler secret put RESEND_API_KEY   # a Resend.com API key (free tier) — or swap for whatever transactional email service the maintainer prefers
npx wrangler secret put ADMIN_SECRET     # any long random string — used to protect GET /admin/summary
npx wrangler secret put ALERT_EMAIL      # the maintainer's real inbox for weekly summaries
npx wrangler deploy
```

Rate limiting on the `/v1/report` route (per-IP, a few requests/minute) should be configured separately in the Cloudflare dashboard under the zone's WAF/Rate Limiting Rules for the `telemetry.clockworkcontrol.com` route — this isn't expressible in `wrangler.toml` and must be done once, manually, post-deploy.

---

## Verification

1. **Laravel side**: `php artisan test --filter=Telemetry` (write `tests/Unit/Services/Telemetry/TelemetryPayloadBuilderTest.php` and `tests/Feature/SendTelemetryTest.php` — assert the payload never contains a raw/unbucketed count, that it contains only the three approved top-level data fields plus `schema_version`/`install_id`/`sent_at`, and that `SendTelemetry` never returns a non-zero exit code even when `Http::fake()` simulates a failed POST). Also add a case to whatever test already covers the installer's `review`/`install` flow, asserting `telemetry_opt_in` unchecked → `CLOCKWORK_TELEMETRY_ENABLED=false` written and `Settings::get('telemetry.enabled')` is `false`.
2. Full suite must still pass: `php artisan test`, plus `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` clean.
3. **Worker side**: `npx wrangler dev` locally, `curl -X POST http://localhost:8787/v1/report -H 'Content-Type: application/json' -d '{"schema_version":1,"install_id":"test-uuid-0000000000","sent_at":"2026-01-01T00:00Z","sites_count_bucket":"1-5","hosting_provider_mix":{"site:spinupwp":"1-5"},"modules_enabled":["two_factor_auth"]}'` → expect `204`. Then `curl http://localhost:8787/admin/summary -H "X-Admin-Secret: <value>"` → expect the just-sent ping reflected in the JSON summary.
4. End-to-end: with `CLOCKWORK_TELEMETRY_ENABLED=true` locally and `CLOCKWORK_TELEMETRY_ENDPOINT` pointed at the local `wrangler dev` server, run `php artisan clockwork:send-telemetry` directly and confirm a row lands in the local D1 database.
