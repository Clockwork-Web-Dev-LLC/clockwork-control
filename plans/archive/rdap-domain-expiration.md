# Architecture Plan: RDAP Domain Expiration & Registrar Module

*Module Target*: `modules/DomainExpiration` (or `app/Services/Domains/`)  
*Author*: Clockwork Architectural Research  
*Status*: Draft Plan (Ready for Implementation)

---

## 1. Problem Statement & Motivation
Every agency managing a fleet of client websites faces the "Lapsed Domain Catastrophe":
- A client registered their domain with GoDaddy, Namecheap, or Network Solutions years ago.
- The credit card on file expires, auto-renew fails, or renewal notification emails land in an unmonitored inbox.
- The domain expires, slips into the 30-day Grace Period, and eventually enters Redemption or goes dark.
- When the website and client email stop working, the client calls the agency in panic, blaming the hosting provider.

WHOIS scraping is notorious for brittle regex, CAPTCHAs, and IP bans. **RDAP (Registration Data Access Protocol)** is the modern, standardized JSON successor mandated by ICANN.

---

## 2. Technical Design & The Free API

### Primary Endpoint
- **URL**: `https://rdap.org/domain/{domain}`
- **Protocol**: HTTP GET following 302 redirects to authoritative TLD registries (e.g., Verisign for `.com`, PIR for `.org`, Nominet for `.co.uk`).
- **Cost**: 100% Free, zero API keys, public JSON.
- **Normalization**: Standardized IETF RFC 7483 / 9083 payload.

### Example RDAP Response Structure
```json
{
  "handle": "2138514_DOMAIN_COM-VRSN",
  "ldhName": "EXAMPLE.COM",
  "status": [
    "clientTransferProhibited",
    "clientUpdateProhibited"
  ],
  "entities": [
    {
      "roles": ["registrar"],
      "vcardArray": [
        "vcard",
        [
          ["version", {}, "text", "4.0"],
          ["fn", {}, "text", "RESERVED-Internet Assigned Numbers Authority"]
        ]
      ]
    }
  ],
  "events": [
    {
      "eventAction": "registration",
      "eventDate": "1995-08-14T04:00:00Z"
    },
    {
      "eventAction": "expiration",
      "eventDate": "2026-11-20T05:00:00Z"
    },
    {
      "eventAction": "last changed",
      "eventDate": "2025-11-15T12:00:00Z"
    }
  ]
}
```

---

## 3. Architecture & Data Model

### Database Changes
Add a migration to `sites` table:
```php
Schema::table('sites', function (Blueprint $table) {
    $table->timestamp('domain_expires_at')->nullable()->after('cert_expires_at');
    $table->string('domain_registrar')->nullable()->after('domain_expires_at');
    $table->string('domain_rdap_status')->nullable()->after('domain_registrar'); // ok, redemptionPeriod, pendingDelete
    $table->timestamp('domain_rdap_checked_at')->nullable()->after('domain_rdap_status');
    $table->string('domain_rdap_error')->nullable()->after('domain_rdap_checked_at');
});
```

### Domain Extraction Logic
Sites in Clockwork may have subdomains (e.g., `shop.client.com` or `app.agency.dev`).
The client must query the registrable root domain (e.g., `client.com`), using PHP's Public Suffix List or a lightweight regex parser.

### Rate Limiting & Registry Polling Policy
- **Weekly Baseline**: Domains with expiration > 60 days are checked once every 7 days.
- **Urgent Baseline**: Domains with expiration < 30 days are checked every 24 hours.
- **Pacing**: In jobs, requests sleep for 1,000ms between calls to avoid hitting registry rate-limits.
- **Backoff**: On HTTP 429 or 503, pause queries for that registry TLD for 2 hours.

---

## 4. Alert Thresholds & User Experience

### Thresholds
- **Green**: > 30 days until expiration.
- **Yellow (Warning)**: &le; 30 days until expiration.
- **Orange (Urgent)**: &le; 14 days until expiration.
- **Red (Critical)**: &le; 7 days until expiration, or status includes `redemptionPeriod` or `pendingDelete`.

### UI Placement
1. **`/issues` Page**:
   - New Section: **Domain Expiration Alerts**.
   - Displays: Site domain, days remaining, registrar name, and direct registrar login link.
2. **`/sites` Fleet View**:
   - Column `Domain Exp.` with color-coded badge.
3. **Site Detail Page**:
   - "Domain & DNS" card displaying Registrar name, creation date, last updated, and expiration date with "Check Now" button.
4. **Chat & Webhook Alerts**:
   - Triggers warning at 30, 14, and 7 days.

---

## 5. Implementation Steps (Phased)

### Phase 1: RDAP Client & Service
- Create `App\Services\Domains\RdapClient` (or `Modules\DomainExpiration\RdapClient`).
- Implement methods:
  - `lookup(string $domain): ?RdapDomainResult`
  - `parseExpirationDate(array $rdapJson): ?Carbon`
  - `parseRegistrar(array $rdapJson): ?string`
  - `extractRootDomain(string $domain): string`

### Phase 2: Migration & Site Model
- Create migration adding `domain_expires_at`, `domain_registrar`, `domain_rdap_checked_at`.
- Add helper methods to `Site.php`:
  - `domainExpiresSoon(): bool`
  - `domainExpirationState(): string` (`green`|`yellow`|`orange`|`red`)

### Phase 3: Scheduled Command & Job
- Create `App\Console\Commands\CheckDomainExpirations`.
- Add to `routes/console.php` on a weekly schedule with daily follow-ups for expiring domains.

### Phase 4: Frontend & Alerts
- Add domain expiration section to `resources/views/dashboard/issues.blade.php`.
- Add column toggle or pill to `resources/views/dashboard/sites.blade.php`.
- Wire into `IssueCounter::total()` for the main sidebar badge.

---

## 6. Verification & Test Plan

1. **Unit Tests**:
   - Mock RDAP JSON responses (Verisign, PIR, Nominet).
   - Test expiration extraction, registrar parsing, and malformed responses.
   - Test sub-domain root extraction (`sub.domain.co.uk` &rarr; `domain.co.uk`).
2. **Feature Tests**:
   - Test `CheckDomainExpirations` command updates site timestamps.
   - Test expired/expiring domains increment `IssueCounter`.
   - Test HTTP 404/429 handles gracefully without failing the job queue.
