# Architecture & Implementation Plan: Community Module Submission Workflow

*Target*: `clockworkcontrol.com` (Astro site) & `clockwork-control` (Control Panel)  
*Status*: Planned / Backlog Item  
*Related Views*: `resources/views/settings/modules/index.blade.php`, `CONTRIBUTING.md`  

---

## 1. Problem Statement & Motivation

In the Module Directory (`/settings/modules`), the header banner currently includes a link labeled **"Submit a Module"** pointing to `https://clockworkcontrol.com/contributing`.

However:
1. There is currently no active form, intake webhook, or automated workflow for community developers or agencies to submit new modules.
2. The directory feed at `https://clockworkcontrol.com/api/modules.json` is currently maintained manually without a public schema validation pipeline.
3. Agency developers building custom modules (e.g. for RunCloud, GridPane, Bunny.net, or custom CRM integrations) have no standardized, guided path to get indexed, audited, and listed with community trust tiers.

---

## 2. Proposed Architecture & Workflow

A complete community submission flow spans three distinct areas:

```
┌───────────────────────────┐      ┌───────────────────────────┐      ┌───────────────────────────┐
│   1. IN-APP / DOCS UX     │ ───> │  2. INTAKE & VALIDATION   │ ───> │  3. FEED & DIRECTORY      │
│                           │      │                           │      │                           │
│ • "Submit a Module" Modal │      │ • GitHub Issue Form       │      │ • Auto schema validation  │
│ • Checklist & Contracts   │      │ • Or Web Submission Form  │      │ • Trust tier assignment   │
│ • Local test harness      │      │ • Package & repo audit    │      │ • Published to modules.json
└───────────────────────────┘      └───────────────────────────┘      └───────────────────────────┘
```

---

## 3. Phased Implementation Breakdown

### Phase A: GitHub Issue Form & Repository Standard (Immediate / Zero-Infra)
Create a structured GitHub Issue Form in `.github/ISSUE_TEMPLATE/module_submission.yml`:
- **Module ID**: Unique slug (e.g. `runcloud`, `bunny-cdn`, `hubspot-crm`).
- **Display Name**: Clean human title (e.g. `RunCloud Provider`).
- **Repository URL**: Must be a public GitHub/GitLab repository.
- **Packagist Package**: Optional Composer package name.
- **Contract Implemented**: Checkboxes for `HostingProvider`, `CloudProvider`, `ChatNotifier`, `SmsNotifier`, or standalone tools.
- **Author & Agency**: Author name, agency name, agency URL, GitHub handle.
- **Module Category**: Dropdown matching directory categories (`hosting`, `cloud`, `notifications`, `security`, `performance`, `utilities`).
- **Capabilities & Description**: Short pitch and summary of supported features.
- **Automated Labeling**: Automatically tagged with `module-submission`.

### Phase B: Marketing Site Submission Landing Page (`clockworkcontrol.com-astro`)
Build `/modules/submit` or `/contributing` on the Astro marketing site:
- **Module Author Guide**: Steps to create a module using `Modules\Core\ModuleServiceProvider`.
- **Packaging Rules**: How to configure `composer.json` with PSR-4 autoloading.
- **Submission Form**: A clean web form that either commits to a PR or invokes a GitHub repository dispatch / webhook.
- **Trust Tiers Explained**:
  - `official`: Built, bundled, and maintained by the Clockwork core team.
  - `verified`: Built by a third party, audited by Clockwork for security and PSR standards.
  - `community`: Open-source community submission; tested by the author agency.

### Phase C: Directory Feed Schema & Automated Ingest (`api/modules.json`)
Create a GitHub Actions validation workflow in the website repo:
- Validates submitted `module.json` against `schemas/module-feed.schema.json`.
- Automatically tests:
  - Repository is reachable and public.
  - `composer.json` exists and is valid JSON.
  - Required fields (`id`, `name`, `author`, `category`, `description`, `repository`) are populated.
- Appends the entry to `public/api/modules.json` upon PR approval and merge.

### Phase D: In-App Module Directory Enhancements (`clockwork-control`)
- Update `resources/views/settings/modules/index.blade.php`:
  - When "Submit a Module" is clicked, open an in-app helper modal or route directly to the submission guide:
    - **Step 1: Build & Contract** (`implements HostingProvider`, etc.)
    - **Step 2: Tag Repository** (add topic `clockworkcontrol-module`)
    - **Step 3: Submit for Review** (link directly to pre-filled GitHub Issue form or Astro submit page).
  - Add an informational banner when viewing community or unofficial modules explaining how community vetting works.

---

## 4. Immediate Action Items

1. [ ] Add `plans/module-submission-workflow.md` to roadmap index.
2. [ ] Add `.github/ISSUE_TEMPLATE/submit_module.yml` in `clockwork-control` or the docs repository.
3. [ ] Build the `/contributing` / `/modules/submit` route in `clockworkcontrol.com-astro`.
4. [ ] In `resources/views/settings/modules/index.blade.php`, point "Submit a Module" to the active GitHub Issue template or docs anchor until the Astro submit page is live.
