# Clockwork Control — Feature Roadmap & Kanban Board

*Last Updated: 2026-09-05*
*Status: Living Document & Idea Repository*

This board tracks candidate features, API integrations, and new modules discovered during architectural research. Features are categorized into practical stages from ideation to planned architecture.

---

## 📋 Kanban Board Overview

```
┌──────────────────────────────┬──────────────────────────────┬──────────────────────────────┬──────────────────────────────┐
│  💡 BACKLOG & IDEATION       │  📐 PLANNED / SPEC'D         │  🔨 IN PROGRESS / ROADMAP    │  ✅ COMPLETED                │
├──────────────────────────────┼──────────────────────────────┼──────────────────────────────┼──────────────────────────────┤
│ • WP.org Closed Plugin Audit │ • RDAP Domain Expiration     │ • Web Installer (/install)   │ • 5-Pillar Docs Reorg        │
│ • Server DNSBL / Blacklists  │ • Accidental noindex Watcher │ • Theme System (Dark Mode)   │ • Backup Relay Weekly Policy │
│ • PHP / WP End-of-Life Stats │ • Module Submission Workflow │ • Backup Relay Generalize    │ • Pressable Droplet Cadence  │
│ • Visual Fleet Grid (mShots) │                              │ • SemVer & Version Release   │ • Dark/Light Contrast Fixes  │
│ • Green Web Carbon Audit     │                              │                              │                              │
│ • DoH Global Propagation     │                              │                              │                              │
│ • RunCloud Provider Module   │                              │                              │                              │
│ • Ploi.io Provider Module    │                              │                              │                              │
│ • Bunny.net CDN Module       │                              │                              │                              │
└──────────────────────────────┴──────────────────────────────┴──────────────────────────────┴──────────────────────────────┘
```

---

## 📐 Priority 1: Planned Modules & Architecture (Detailed Specs Available)

### 1. RDAP Domain Expiration & Registrar Module (`modules/DomainExpiration`)
- **Full Specification**: [`plans/rdap-domain-expiration.md`](./rdap-domain-expiration.md)
- **Summary**: Queries free ICANN RDAP bootstrap endpoint (`rdap.org`) to continuously monitor domain expiration dates and registrar information across all client sites.
- **Key Advantage**: 100% free, zero API keys, eliminates registrar scraping, catches domain lapses before sites go dark.
- **Alert Tiers**: Yellow at 30 days, Orange at 14 days, Red at 7 days or redemption period.

### 2. Accidental `noindex` & Pre-Flight Launch Watchdog (`modules/LaunchWatchdog`)
- **Full Specification**: [`plans/accidental-noindex-watchdog.md`](./accidental-noindex-watchdog.md)
- **Summary**: Automated sentinel that probes production sites for search engine blockers (`<meta name="robots" content="noindex">`, `X-Robots-Tag: noindex`, or `Disallow: /` in `/robots.txt`).
- **Key Advantage**: Solves the single most expensive post-launch mistake agencies make (leaving staging `noindex` enabled on production).
- **Severity**: Immediate Critical P0 alert on `/issues` and chat channels.

### 3. Community Module Submission Workflow (`clockworkcontrol.com` & `/settings/modules`)
- **Full Specification**: [`plans/module-submission-workflow.md`](./module-submission-workflow.md)
- **Summary**: Intake pipeline for third-party and community module authors: GitHub Issue template (`submit_module.yml`), automated JSON schema validation for `api/modules.json`, trust tier audit standards (`official`, `verified`, `community`), and in-app submission guidance modal.
- **Key Advantage**: Closes the broken intake loop where the in-app "Submit a Module" button currently has no destination or automated ingestion pipeline.

---

## 💡 Backlog & Ideation (High-Value Candidate Features)

### A. Security & Threat Feeds
1. **WordPress.org Closed / Zombieware Plugin Audit**:
   - **API**: `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]={slug}` (Free, no key).
   - **Problem**: When a plugin is closed on WP.org for a critical security zero-day or author abandonment, WordPress issues no update alert. Sites stay silently vulnerable.
   - **Mechanism**: Deduplicate all plugins across the fleet, poll WP.org weekly, flag any plugin where `closed: true`.
2. **CISA Known Exploited Vulnerabilities (KEV) Catalog**:
   - **API**: `https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json` (Free federal feed).
   - **Mechanism**: Cache daily; cross-reference core and plugin versions to highlight exploits actively weaponized in the wild.
3. **Certificate Transparency (CT) Subdomain Discovery**:
   - **API**: `https://crt.sh/?q=%25.{domain}&output=json` (Free).
   - **Mechanism**: Auto-discovers shadow staging environments (`dev.client.com`, `shop.client.com`) created outside agency oversight.

### B. Infrastructure & Email Reputation
4. **Native DNSBL / RBL Server Blacklist Monitor**:
   - **Protocol**: High-speed UDP reverse DNS lookups (`checkdnsrr`) against Spamhaus, Barracuda, SORBS.
   - **Cost**: Zero HTTP requests, zero external keys.
   - **Mechanism**: Daily health check of all server public IPs to ensure transactional email won't bounce.
5. **SPF, DKIM, DMARC & BIMI Health Auditor**:
   - **Protocol**: Native DNS TXT parsing.
   - **Mechanism**: Checks for DMARC enforcement (`p=reject`/`quarantine`), verifies SPF DNS lookup count does not exceed RFC 7208's 10-lookup limit.
6. **Multi-Node DNS-over-HTTPS (DoH) Global Propagation**:
   - **APIs**: Cloudflare DoH (`cloudflare-dns.com`) + Google DoH (`dns.google`).
   - **Mechanism**: On-demand modal showing whether A/AAAA/CNAME records have propagated across US, Europe, and Asia.

### C. Agency UX & Visual QA
7. **Visual Fleet Grid (mShots Integration)**:
   - **API**: `https://s0.wp.com/mshots/v1/{url}?w=600` (Free Automattic screenshot engine).
   - **Mechanism**: Optional card-view on `/sites` showing visual renders of client homepages without running headless Chrome locally.
8. **Pre/Post-Update Visual Regression Smoke Test**:
   - **Mechanism**: Captures homepage before automated updates and after. If the page returns HTTP 500 or blank white screen, triggers automatic rollback.
9. **The Green Web Foundation Carbon & Hosting Audit**:
   - **API**: `https://api.thegreenwebfoundation.org/api/v3/greencheck/{domain}` (Free).
   - **Mechanism**: Adds a "Green Hosting Certified" badge to site views and monthly client report exports.

### D. Software Lifecycle & Edge Modules
10. **PHP & WordPress End-of-Life (EOL) Intelligence**:
    - **API**: `https://endoflife.date/api/v1/products/php.json` (Free, no key).
    - **Mechanism**: Calculates remaining security patch countdowns for PHP versions on `/servers` and `/sites`, generating retainer migration leads.
11. **RunCloud Hosting Provider Module (`modules/RunCloud`)**:
    - **API**: `https://manage.runcloud.io/api/v2/` (Key + Secret Basic Auth).
    - **Scope**: Implements `HostingProvider` and `CloudProvider` to sync servers and web applications.
12. **Ploi.io Hosting Provider Module (`modules/Ploi`)**:
    - **API**: `https://ploi.io/api/` (Bearer Token Auth).
    - **Scope**: Implements `HostingProvider` for Ploi-managed servers and sites.
13. **Bunny.net CDN & Edge Cache Module (`modules/Bunny`)**:
    - **API**: `https://api.bunny.net/`
    - **Scope**: One-click edge cache purges, bandwidth consumption, and pull zone status.

---

## 📊 Evaluation Matrix

| Idea / Module | Free / Open? | Complexity | Agency Urgency | Target Location |
|---|---|---|---|---|
| **Accidental `noindex` Watchdog** | Yes (Native HTTP) | Low (1-2 days) | Critical | `modules/LaunchWatchdog` |
| **RDAP Domain Expiration** | Yes (`rdap.org`) | Low (1-2 days) | High | `modules/DomainExpiration` |
| **WP.org Closed Plugin Audit** | Yes (WP.org API) | Low (1 day) | High | `app/Services/Security` |
| **PHP End-of-Life Tracker** | Yes (`endoflife.date`) | Low (0.5 day) | Medium | `app/Services/Platform` |
| **Server Blacklist Monitor (DNSBL)** | Yes (Native DNS) | Low (1 day) | High | `app/Services/Security` |
| **RunCloud Integration** | Commercial API | Medium (2 days) | Medium | `modules/RunCloud` |
| **Ploi.io Integration** | Commercial API | Medium (2 days) | Medium | `modules/Ploi` |
| **Visual Fleet Grid** | Yes (mShots) | Low (1 day) | Medium | `resources/views/sites` |
