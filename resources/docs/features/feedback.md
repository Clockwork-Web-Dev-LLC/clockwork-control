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
* **Pin Placement**: When active, right-clicking (or regular clicking in pinning mode) anywhere on any page captures:
  * Exact click coordinates (`pageX`, `pageY`, relative percentage)
  * Target DOM selector path (e.g. `main > div:nth-child(2) > table`)
  * Current page URL and route
  * Browser viewport dimensions and screen resolution
  * User Agent string
* **Visual Number Badges**: Placed feedback items render as circular numbered badges (`1`, `2`, `3`...) anchored at their exact coordinates on the live page. Clicking a pin opens its discussion card inline.

### 2. Threaded Discussions

Every feedback item supports back-and-forth discussion threads:

* Team members can reply to items to ask clarifying questions, propose alternative implementations, or confirm fixes.
* Status tracking: Items transition between **Open**, **In Progress**, and **Resolved**.
* Operators can mark feedback resolved directly from the live page overlay or the central triage dashboard.

### 3. Central Working List (`/feedback`)

The working list at **`/feedback`** aggregates all feedback across the application:

* Filterable by status (Open, In Progress, Resolved) and by page/route.
* Shows submitter, timestamp, target page, and comment excerpt.
* Direct link back to the target page to view the pin in its live context.
* Detail view with the full threaded discussion history.

### 4. Claude / AI Prompt Generator

Each feedback item includes an automatic **Generate Claude Prompt** action. Clicking this formats a complete, structured prompt ready to copy and paste into Claude or Grok:

```markdown
### Bug Report / Feature Request
- **Page URL**: /monitoring
- **Selector**: #fleet-metrics-table
- **Reported By**: Employee Name
- **Description**: Add pagination with 50 items per page default and an options drawer.

### Context & Technical Specs
- **Viewport**: 1920x1080
- **User Agent**: Mozilla/5.0 ...

### Discussion History
- [2026-09-28 14:15] Employee: "Can we have an options tab like WordPress?"
- [2026-09-28 14:20] Aaron: "Yes, option A with live page count."

### Instructions for Claude
Please implement the requested changes in the codebase...
```

This eliminates the back-and-forth ambiguity between team member feedback and developer implementation.

## Module Administration & Toggling

Feedback is a self-contained module located in `modules/Feedback/` that can be toggled on or off at will:

* **Settings Hub**: Accessible via **Settings → Integrations & Alerts → Feedback** (`/settings/feedback`) or **Settings → Module Directory** (`/settings/modules`).
* **Live Toggle Switch**: Flipping the toggle switch sends `POST /settings/modules/toggle` (or updates `clockwork.feedback.enabled` setting), immediately turning the module on or off without command-line intervention.
* **Zero Overhead When Disabled**: When disabled, no feedback script tags, overlays, or stylesheets are injected into the page layout, ensuring zero performance overhead for production environments.
