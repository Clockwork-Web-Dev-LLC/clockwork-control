---
title: Mailgun
section: Integrations
order: 70
updated: 2026-09-05
author: Aaron Reimann
tags: [integrations, mailgun, email, notifications]
tracks: [config/mail.php, app/Mail/**, app/Console/Commands/EmailFormTestSummaries.php]
---

Mailgun handles outbound email — contact-form failure / recovery alerts to the operator and monthly summary receipts to clients. Default driver in dev is `log` (writes to `storage/logs/laravel.log`); production flips to `mailgun`.

## Why we use it

The contact-form testing add-on ($9/mo) needs to email two audiences:

- The operator when a form goes silent (real time, so they can investigate).
- The client at month-end with a summary of "your forms were tested N times, all worked." This is the receipt that justifies the subscription on quiet months.

Mailgun is fast, has a clean API, and the EU region matches some clients' compliance needs. SES would also work; Mailgun was chosen because the operator already has an account.

## Setup

1. In Mailgun: Sending → Domains. Use a real subdomain (`mg.your-domain.com`), not the sandbox.
2. **Set SPF + DKIM on the sending domain BEFORE going live.** Bounces from a fresh sender will tank deliverability fleet-wide.
3. Composer pull (Symfony Mailgun transport isn't installed by default):

   ```bash
   composer require symfony/mailgun-mailer symfony/http-client
   ```

4. Set in `.env`:

   ```
   MAIL_MAILER=mailgun
   MAILGUN_DOMAIN=mg.your-domain.com
   MAILGUN_SECRET=key-XXXX
   MAILGUN_ENDPOINT=api.mailgun.net   # or api.eu.mailgun.net for the EU region
   MAIL_FROM_ADDRESS=alerts@your-domain.com
   MAIL_FROM_NAME=Clockwork
   ```

5. Test by triggering any remaining mailable from tinker.

## Auth

Mailgun API key (`MAILGUN_SECRET`). Used by Symfony's Mailgun transport via Laravel's mail facade.

## Endpoints we call

The Symfony transport handles the wire format. We don't open-code Mailgun calls anywhere.

## Mailables

Contact-form alerts moved to Mattermost-only (see [Features → Contact form testing](/docs/features/contact-form-testing)). The three contact-form mailables that used to live in `app/Mail/` are gone. Other features (SiteVulnerabilityReportMail, etc.) still use this transport.

## Files

- `config/mail.php` — driver + from address.
- `app/Mail/*.php` — remaining mailable classes.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| daily 06:00 | `clockwork:test-contact-forms` — runs the per-site form tests; fires failure/recovery mail on transitions. |
| 1st of month 08:00 | `clockwork:email-form-test-summaries` — per-client monthly summaries for the calendar month that just ended. |

## Dev mode

`MAIL_MAILER=log` writes the rendered email to `storage/logs/laravel.log` instead of sending. That's the default — useful for previewing layouts without hitting Mailgun.

## Gotchas

- **No SPF/DKIM = bounces tank fleet deliverability.** Configure both before flipping `MAIL_MAILER=mailgun`.
- **EU vs US Mailgun region** — `MAILGUN_ENDPOINT` differs (`api.eu.mailgun.net` vs `api.mailgun.net`). Match the region the domain was created in or every send 401s.
- **Symfony transport isn't installed by default.** First production deploy needs the `composer require` step.
- **`MAIL_FROM_ADDRESS` must be on a domain Mailgun controls.** A bare `gmail.com` from address gets rejected — even with valid Mailgun creds.
