# Liability disclaimer — build spec

**Audience: Gemini (or whoever implements this).** This is a prescriptive spec, not a brainstorm — follow it directly. Every file path, snippet, and tool named below was verified against the actual current codebase; if something doesn't match what you find on disk, stop and flag it rather than guessing.

## Why

Clockwork Control stores real fleet credentials (SSH keys, DB passwords, API tokens) and can take real, consequential automated actions (IP bans, WP core/plugin updates, WAF/firewall changes). The MIT `LICENSE` already disclaims warranty, but that text sits in a file almost nobody reads. We need a second, plain-English disclaimer that's actually seen — in the repo, in the installer, and on the marketing site — plus a real `SECURITY.md` (currently missing) for vulnerability reporting, which is a *separate* concern from the liability disclaimer.

Confirmed current state (do not re-verify, just build on this):
- `LICENSE` (repo root): verbatim standard MIT, copyright "Clockwork Web Dev LLC".
- No `SECURITY.md`, no `DISCLAIMER.md`, no risk language anywhere in the repo.
- `README.md` has a license badge/section at the bottom (`## License`) but nothing else relevant.

## Tools/tech involved

Nothing new — this is plain Markdown files, one Blade template edit, one PHP controller edit, and edits to a separate Astro static site already on disk at `~/Projects/clockworkcontrol.com-astro`. No new packages, no new services.

---

## Step 1 — `DISCLAIMER.md` (new file, repo root)

Create `/Users/aaronr/Development/clockwork-control/DISCLAIMER.md` with exactly this content:

```markdown
# Security, Support & Liability Disclaimer

Clockwork Control is provided to you free, under the MIT license, with no warranty of any kind — not that it works, not that it's fit for your particular setup, not that it's free of bugs. That's not boilerplate we added on top of the license; it's the actual deal. If you use this software, you're accepting it exactly as it exists in the repository, bugs and all, and you're responsible for deciding whether it's safe and appropriate for your own fleet before you rely on it.

Free community support is offered on a best-effort basis, with no guaranteed response time or fix timeline. Paid support arrangements may be available for operators who want dedicated help — see [clockworkcontrol.com](https://clockworkcontrol.com) for current offerings — but that's a separate arrangement, not something this disclaimer or the software itself promises.

This is not a passive dashboard. Clockwork Control stores real credentials for your fleet — SSH keys, database passwords, API tokens — and it can take real, consequential actions on your behalf: banning IPs, running WordPress core/plugin/theme updates, changing WAF or firewall rules, rebooting servers. Any of these can go wrong in ways that take a site offline, lock out an admin, or damage something you care about. Before you enable any automated or autonomous feature, it's on you to actually read what it does, understand the blast radius if it misfires, and test it somewhere that isn't your most important client's production site.

To be unambiguous: the maintainer is not liable for damage, downtime, data loss, or a security breach on any site or server you manage through this software — including if you believe the root cause was a bug, a missing safeguard, or some other gap in Clockwork Control itself. You are the one operating the tool against real infrastructure, and you carry the outcomes of that, the same way you would if you'd written the automation yourself.

A few things worth keeping in mind:

- **No warranty.** Provided "as is," full stop — see the [MIT license](LICENSE) in this repository for the exact legal language.
- **Community support is best-effort.** Bug reports and questions are welcome, but nothing here promises a fix, a reply, or a timeline on the free/community side. Paid support may be available separately.
- **You hold real credentials and real power.** SSH keys, DB passwords, and API tokens for your fleet live in this app, and its automated actions can genuinely break things. Review a feature before you turn it loose.
- **You're liable for your own use.** Compromises, misconfigurations, or damage to sites/servers you manage through this software are your responsibility — including cases where the software itself is the alleged cause.
- **Not legal advice.** This disclaimer explains the practical deal, not a substitute for counsel. If you need a binding opinion about your own liability exposure or your business's obligations to your clients, talk to your own lawyer.

---

For how to report a security vulnerability (different from the above), see [SECURITY.md](SECURITY.md).
```

**Do not alter the wording above beyond fixing an obvious typo.** It was drafted and already reviewed once; if the maintainer wants a tone change, that happens as a follow-up edit to this exact file, not a rewrite from scratch.

## Step 2 — `SECURITY.md` (new file, repo root)

GitHub gives `SECURITY.md` special UI treatment (a "Report a vulnerability" link in the repo's Security tab) — it is for vulnerability *disclosure process*, not the liability disclaimer above. Keep them fully separate files. Create `/Users/aaronr/Development/clockwork-control/SECURITY.md`:

```markdown
# Security Policy

## Reporting a vulnerability

If you find a security vulnerability in Clockwork Control, please report it privately rather than opening a public GitHub issue — email **security@clockworkcontrol.com** with a description and, if possible, steps to reproduce.

This is a best-effort process (see [DISCLAIMER.md](DISCLAIMER.md) — there's no guaranteed SLA), but security reports are prioritized above general bug reports. You'll get an acknowledgment as soon as possible, and credit in the release notes if you'd like it, once a fix ships.

## Supported versions

Only the latest tagged release is actively maintained. There's no long-term-support branch — if you're running an older version, update before reporting an issue tied to something already fixed.

## Scope

This policy covers the Clockwork Control application itself (this repository). It does not cover the security of your own infrastructure, your own SSH/API credential hygiene, or third-party services (SpinupWP, Pressable, DigitalOcean, etc.) it integrates with.
```

**Flag before merging**: `security@clockworkcontrol.com` is a placeholder address — confirm it actually exists/forwards somewhere before publishing, or swap in whatever address the maintainer actually wants to receive vulnerability reports at.

## Step 3 — `README.md` edit

Add a short pointer near the top of `README.md`, directly after the existing intro paragraph that ends "...so it belongs on infrastructure you control." (the paragraph right before the "## What it does" heading). Insert this new paragraph between that intro and the "## What it does" heading:

```markdown
> **Before you rely on this in production**, read [DISCLAIMER.md](DISCLAIMER.md) — this software holds real credentials and can take real automated actions on your fleet. It's provided under the MIT license with no warranty, and you use it at your own risk.
```

Do not remove or reword anything else in `README.md`.

## Step 4 — Installer: required acknowledgment checkbox

File: `resources/views/install/review.blade.php`. Find this existing block (currently right above the `<form method="POST" action="{{ route('install.run') }}" ...>` element):

```blade
    <div class="p-4 rounded-xl border border-[var(--color-brand)]/20 bg-[var(--color-brand)]/5 text-xs text-[var(--color-ink-muted)] mb-8 flex items-start gap-2.5">
        <i class="fa-solid fa-shield-halved text-[var(--color-brand)] text-base mt-0.5"></i>
        <div>
            <strong>Ready to apply:</strong>
            Submitting below will atomically write these settings to your <code class="px-1 py-0.5 rounded bg-black/5 font-mono">.env</code> file, execute database migrations, provision your administrator account, and permanently seal the installer gate.
        </div>
    </div>
```

Immediately **after** that block (still before the `<form>` tag), add a new required-acknowledgment card:

```blade
    <div class="p-4 rounded-xl border border-[var(--color-status-yellow)]/30 bg-[var(--color-status-yellow-bg)] text-xs text-[var(--color-ink-muted)] mb-8">
        <div class="flex items-start gap-2.5 mb-3">
            <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-yellow)] text-base mt-0.5"></i>
            <div>
                <strong>Before you continue:</strong>
                Clockwork Control stores real fleet credentials and can take real automated actions (bans, updates, firewall changes) on your servers. It's provided under the MIT license — no warranty, no guaranteed support — and you're responsible for reviewing any feature before you enable it. Full text: <a href="https://clockworkcontrol.com/disclaimer" target="_blank" rel="noopener noreferrer" class="text-[var(--color-brand)] underline">Security, Support & Liability Disclaimer</a>.
            </div>
        </div>
        <label class="flex items-center gap-2 pl-6 text-[var(--color-ink-strong)] font-medium cursor-pointer">
            <input type="checkbox" name="disclaimer_accepted" value="1" form="install-review-form" required
                   class="rounded border-[var(--color-border-light)]">
            I understand and accept the terms above.
        </label>
    </div>
```

The existing `<form>` element needs an `id` so the checkbox (which sits outside the form tag, per the markup above) still submits with it — change:
```blade
    <form method="POST" action="{{ route('install.run') }}" @submit="installing = true">
```
to:
```blade
    <form id="install-review-form" method="POST" action="{{ route('install.run') }}" @submit="installing = true">
```

The submit `<button>` inside the form should also be disabled until the box is checked. The surrounding `<div>` already has `x-data="{ installing: false }"` (Alpine.js) — extend it to also track acceptance:
```blade
     x-data="{ installing: false, accepted: false }">
```
and bind the checkbox's `x-model`:
```blade
            <input type="checkbox" name="disclaimer_accepted" value="1" form="install-review-form" required
                   x-model="accepted"
                   class="rounded border-[var(--color-border-light)]">
```
and add `:disabled="installing || !accepted"` to the existing submit `<button>` (alongside its current `:disabled="installing"` — replace, don't duplicate the attribute).

**Backend validation and recording** — file `app/Http/Controllers/InstallerController.php`, method `install(Request $request)` (starts ~line 722). Add validation at the very top of the method, before `$wizard = (array) $request->session()->get(...)`:

```php
$request->validate([
    'disclaimer_accepted' => ['required', 'accepted'],
]);
```

Then, in the same method, right after the existing block:
```php
        $this->userProvisioner->addOrRestore(
            email: $admin['email'],
            name: $admin['name'],
            actor: 'installer',
        );
```
add:
```php
        // Record the disclaimer acknowledgment durably (not just a log line,
        // which can rotate away) — same key/value store already used for
        // other operator-facing settings.
        app(\App\Support\Settings::class)->putMany([
            'disclaimer.accepted_at' => now()->toIso8601String(),
            'disclaimer.accepted_version' => config('clockwork.version', '1.1.0'),
        ]);
```

No new migration needed — `App\Support\Settings::put()`/`putMany()` writes to the existing `app_settings` table (`AppSetting` model, `key`/`value` columns, `value` already JSON-cast) with zero schema changes.

## Step 5 — Marketing site (`~/Projects/clockworkcontrol.com-astro`)

This is a separate Astro repo, static-site-generated (confirmed: no SSR adapter in `astro.config.mjs`, no `wrangler.toml`/`vercel.json`/`netlify.toml`). Plain file edits only.

**New page**: fold the disclaimer into the *existing* `src/pages/intended-usage.astro` page rather than creating a new route — that page already scopes what the tool is/isn't for, and is a natural sibling. Find the closing structure near the end of the file:

```astro
	<!-- Section 5: FAQs -->
	<section class="py-20 bg-brand-darker border-t border-white/10">
		...
	</section>
</Layout>
```

Add a new section immediately **after** the closing `</section>` of the FAQ block and **before** `</Layout>`:

```astro
	<!-- Section 6: Disclaimer -->
	<section id="disclaimer" class="py-20 bg-brand-surface/70 border-t border-white/10">
		<div class="max-w-3xl mx-auto px-6 space-y-5 text-sm text-white/80 leading-relaxed">
			<h2 class="font-display text-2xl font-extrabold text-white mb-2">Security, Support &amp; Liability Disclaimer</h2>
			<p>Clockwork Control is provided free, under the MIT license, with no warranty of any kind. If you use this software, you're accepting it exactly as it exists in the repository, and you're responsible for deciding whether it's safe and appropriate for your own fleet before you rely on it.</p>
			<p>Free community support is best-effort, with no guaranteed response time. Paid support arrangements may be available for operators who want dedicated help.</p>
			<p>This is not a passive dashboard — it stores real credentials for your fleet (SSH keys, database passwords, API tokens) and can take real automated actions (bans, updates, firewall changes). Review any feature before you enable it. The maintainer is not liable for damage, downtime, or a security breach on infrastructure you manage through this software.</p>
			<p class="text-white/60 text-xs">This is not legal advice. Full text in <a href="https://github.com/Clockwork-Web-Dev-LLC/clockwork-control/blob/main/DISCLAIMER.md" target="_blank" rel="noopener noreferrer" class="underline hover:text-white">DISCLAIMER.md</a>.</p>
		</div>
	</section>
</Layout>
```

**Footer link** — file `src/layouts/Layout.astro`. Find the "Resources" column's `<ul>` (~line 543-551):

```astro
					<div>
						<h3 class="text-xs font-semibold uppercase tracking-wider text-brand-lavender-light">Resources</h3>
						<ul class="mt-4 space-y-2.5 text-sm text-white/70">
							<li><a href="/about" class="hover:text-white transition-colors">About / Origin Story</a></li>
							<li><a href="/docs" class="hover:text-white transition-colors">Documentation</a></li>
							<li><a href="/donate" class="hover:text-white transition-colors text-purple-300 font-medium flex items-center gap-1.5"><i class="fa-solid fa-heart text-pink-400 text-xs"></i><span>Donate / Supporter</span></a></li>
							<li><a href="/contributing" class="hover:text-white transition-colors">Contributing &amp; Modules</a></li>
							<li><a href="https://github.com/clockwork-web-dev/clockwork-control-panel" target="_blank" rel="noopener noreferrer" class="hover:text-white transition-colors">GitHub Repository</a></li>
							<li><a href="https://github.com/clockwork-web-dev/clockwork-control-panel/blob/main/CONTRIBUTING.md" target="_blank" rel="noopener noreferrer" class="hover:text-white transition-colors">Contributing Guide</a></li>
							<li><a href="https://github.com/clockwork-web-dev/clockwork-control-panel/blob/main/LICENSE" target="_blank" rel="noopener noreferrer" class="hover:text-white transition-colors">MIT License</a></li>
						</ul>
					</div>
```

Add a new `<li>` for the disclaimer **immediately above** the "MIT License" `<li>`:

```astro
							<li><a href="/intended-usage#disclaimer" class="hover:text-white transition-colors">Disclaimer</a></li>
```

**Separate bug, flag but do not silently expand scope**: every GitHub link in this footer (and the bottom-of-page CTA on `intended-usage.astro`, and the site's own `<head>` license meta tag near line 72) points at `github.com/clockwork-web-dev/clockwork-control-panel` — the **wrong repo slug**. The real repo is `Clockwork-Web-Dev-LLC/clockwork-control` (confirmed via `git remote -v` in the actual codebase). This is a pre-existing bug unrelated to this task. Fix it only in the one new link you add (use the correct URL there), and separately tell the maintainer the rest of the site's GitHub links are stale — don't take it on yourself to rewrite every occurrence as part of this task unless asked.

---

## Verification

1. `cd /Users/aaronr/Development/clockwork-control && vendor/bin/pint --test resources/views/install/review.blade.php app/Http/Controllers/InstallerController.php` — should pass (Blade files aren't Pint-checked, but the PHP controller is).
2. Run the existing installer test suite: `php artisan test --filter=Installer` — find and read whatever test currently covers `InstallerController::install()` (search `tests/Feature/Installer/`) and add/update a case asserting `install()` returns a validation error (422/redirect-with-errors) when `disclaimer_accepted` is missing, and succeeds + writes `disclaimer.accepted_at`/`disclaimer.accepted_version` via `Settings` when it's present. Match the existing test file's style exactly — don't invent a new testing pattern.
3. Manually load `/install` through to step 9 in a browser (or via the existing Dusk/browser test setup if one already drives the installer) and confirm: the submit button is disabled until the checkbox is checked, and checking it enables submit.
4. In the Astro repo: `cd ~/Projects/clockworkcontrol.com-astro && npm run build` — must complete with no errors. Then `npm run preview` and manually check `/intended-usage#disclaimer` renders and the footer's new "Disclaimer" link works and lands on that anchor.
5. Full Laravel test suite must still pass: `php artisan test` (expect ~1702 passing, matching the current baseline — investigate any new failures, don't just re-run and ignore).
