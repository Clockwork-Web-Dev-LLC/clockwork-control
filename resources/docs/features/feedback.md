---
title: Visual Feedback & Collaboration
section: Features
order: 85
updated: 2026-09-28
author: Aaron Reimann
tags: [feedback, collaboration, annotations, claude, prompts, modules]
tracks: [modules/Feedback/**]
---

The **Feedback** module provides in-app visual annotations, point-and-click comment pins, threaded discussions, and AI prompt generation for rapid design and bugfix turnarounds.

Instead of maintaining disjointed spreadsheets or Slack threads where employees report UI issues, operators and team members can right-click anywhere in the application to place a numbered pin, describe what needs to be changed or fixed, discuss the request, and generate a pre-formatted Claude/Grok prompt with all relevant technical context.

## How It Works

### 1. In-App Visual Pinning

When the Feedback module is enabled, an unobtrusive floating widget appears in the lower-right corner of the application:

* **Mode Toggle**: Clicking the pill toggles Feedback mode (`ON` / `OFF`).
* **Pin Placement & Right-Click Inspection**: Right-clicking anywhere on any page brings up an in-app context menu displaying:
  * Current page path and route name (e.g. `feedback.index`, `monitoring.index`)
  * Target element tag and text snippet (e.g. `<DIV> "Total Submissions"`)
  * Nearest section heading and parent container (e.g. `Section: Feedback & Backlog · Card Container`)
  * Blade view template file hint (e.g. `modules/Feedback/resources/views/index.blade.php`)
  * Exact click coordinates, CSS selector path, viewport dimensions, and user agent
* **Visual Number Badges**: Placed feedback items render as circular numbered badges (`①`, `②`, `③`...) anchored dynamically to the target DOM element's bounding rect on the live page. Clicking a pin opens its discussion drawer inline.

### 2. Threaded Discussions & Approval Workflow

Every feedback item supports back-and-forth discussion threads:

* Team members can reply to items to ask clarifying questions, propose alternative implementations, or confirm fixes.
* **Approval State**: Operators can mark items as **Approved** with a single click (`POST /feedback/{id}/approve`) without full page reloads via asynchronous JSON requests. Approved items are queued for the next Claude/AI implementation batch.
* Status tracking: Items transition between **Open**, **Approved**, **In Progress**, **Resolved**, and **Dismissed**.
* **Automatic Pin Removal on Resolve**: As soon as an item is marked **Resolved** or **Dismissed** (via the live pin drawer, the backlog card, the batch resolver, or CLI), the pin badge immediately disappears from the live screen overlay. Pins only render for active, unresolved items.
* Operators can mark feedback resolved directly from the live page overlay drawer, the central triage dashboard (`/feedback`), or via the batch action bar.

### 3. Central Working List (`/feedback`)

The working list at **`/feedback`** aggregates all feedback across the application:

* **Clean Header Actions**: Primary **Generate Approved Prompt** button with live item count badge, **Download .md**, and **Batch Actions** dropdown (`Mark In Progress`, `Mark All Resolved`).
* Filterable by status pills (All, Open, Approved, In Progress, Resolved) and by page/route category.
* Direct link back to the target page to view the pin in its live context.
* Detail view with full threaded discussion history.
* **Streamlined Card Footers**: A clean 2-sided action bar with thread toggle and View Pin link on the left, and 1-click **Approve** (for open items), status selector dropdown, **Copy Prompt**, and delete on the right.
* **Automatic Overlay Suppression**: The floating bottom-right feedback widget is suppressed on `/feedback` itself to avoid obscuring card controls and filters.

### 4. Claude / AI Batch Prompt Generator & Scheduled Exports

Each feedback item includes a **Copy Prompt** button in its card footer. Furthermore, all currently approved items are automatically bundled into an **Implementation Batch** via the page header:

* **One-Click Generate & Copy**: Generate the complete master prompt for all approved items directly from the header action button.
* **Direct File Download (`.md`)**: Download `clockwork-claude-approved-prompt-YYYY-MM-DD.md` straight from the header actions (or download an individual item's markdown file).
* **Batch State Advancement**: Clicking "Mark in Progress" immediately moves all approved items to `in_progress` once handed off to Claude.
* **Scheduled Generation (`clockwork:feedback-prompt`)**: The scheduler automatically runs `clockwork:feedback-prompt` daily at 09:00 UTC, compiling all approved items into `storage/app/prompts/latest-feedback-prompt.md`.

```markdown
# Task: Implement Approved Feedback & Feature Requests

The following 4 feedback items have been reviewed, discussed, and Approved...

## Executive Summary & Batch Breakdown
- Total Approved Tasks: 4 (2 Bugs, 1 Tweak, 1 Feature)
...
```

This eliminates the back-and-forth ambiguity between team member feedback and developer implementation.

### 5. CLI Tooling & Artisan Commands

For operators and automated CI/CD deployment pipelines:

* **Generate Prompt**: `php artisan clockwork:feedback-prompt`
  * Options: `--status=approved`, `--mark-in-progress`, `--mark-resolved`, `--output=<path>`.
  * Example: `php artisan clockwork:feedback-prompt --mark-resolved` generates the prompt and instantly marks the items as solved.
* **Resolve Items**: `php artisan clockwork:feedback-resolve`
  * Options:
    * `--approved`: Mark all approved items as resolved (e.g. after committing changes).
    * `--id=<id>`: Mark specific item(s) as resolved (e.g. `--id=1 --id=2`).
    * `--all`: Mark all active items as resolved.
    * `--dry-run`: Preview matching items without changing database state.

## Module Administration & Toggling

Feedback is a self-contained module located in `modules/Feedback/` that can be toggled on or off at will:

* **Settings Hub**: Accessible via **Settings → Integrations & Alerts → Feedback** (`/settings/feedback`) or **Settings → Module Directory** (`/settings/modules`).
* **Live Toggle Switch**: Flipping the toggle switch sends `POST /settings/modules/toggle` (or updates `clockwork.feedback.enabled` setting), immediately turning the module on or off without command-line intervention.
* **Zero Overhead When Disabled**: When disabled, no feedback script tags, overlays, or stylesheets are injected into the page layout, ensuring zero performance overhead for production environments.
