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
* **Approval State**: Operators can mark items as **Approved** with a single click (`POST /feedback/{id}/approve`). Approved items are queued for the next Claude implementation batch.
* Status tracking: Items transition between **Open**, **Approved**, **In Progress**, **Resolved**, and **Dismissed**.
* Operators can mark feedback resolved directly from the live page overlay or the central triage dashboard.

### 3. Central Working List (`/feedback`)

The working list at **`/feedback`** aggregates all feedback across the application:

* Filterable by status (Open, Approved, In Progress, Resolved) and by page/route.
* Shows submitter, timestamp, target page, and comment excerpt.
* Direct link back to the target page to view the pin in its live context.
* Detail view with the full threaded discussion history.
* 1-click **Approve** button on every open issue.

### 4. Claude / AI Batch Prompt Generator & Scheduled Exports

Each feedback item includes an automatic **Generate Claude Prompt** action. Furthermore, all currently approved items are automatically bundled into an **Implementation Batch**:

* **One-Click Copy**: Copy the entire aggregated master prompt for all approved items to clipboard.
* **Direct File Download (`.md`)**: Download `clockwork-claude-approved-prompt-YYYY-MM-DD.md` straight from the browser (or download an individual item's markdown file).
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

## Module Administration & Toggling

Feedback is a self-contained module located in `modules/Feedback/` that can be toggled on or off at will:

* **Settings Hub**: Accessible via **Settings → Integrations & Alerts → Feedback** (`/settings/feedback`) or **Settings → Module Directory** (`/settings/modules`).
* **Live Toggle Switch**: Flipping the toggle switch sends `POST /settings/modules/toggle` (or updates `clockwork.feedback.enabled` setting), immediately turning the module on or off without command-line intervention.
* **Zero Overhead When Disabled**: When disabled, no feedback script tags, overlays, or stylesheets are injected into the page layout, ensuring zero performance overhead for production environments.
