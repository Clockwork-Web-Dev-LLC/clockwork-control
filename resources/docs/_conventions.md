---
title: Conventions (not rendered)
section: Internal
---

This file is excluded from the docs index (filename starts with `_`). It documents the conventions every other doc file should follow.

## Frontmatter

Every published page needs YAML frontmatter at the top. Required fields:

- `title` — the human title rendered as the H1
- `section` — one of: `Getting Started`, `Concepts`, `Features`, `Runbooks`
- `order` — integer, sorts pages within a section (lower = earlier)
- `updated` — ISO date `YYYY-MM-DD`. Bump this when you edit.
- `author` — display name shown as the byline. Default to `Aaron Reimann`.
- `tags` — array of lowercase strings. Drives the "Related" block.

Example:

```yaml
---
title: Some feature
section: Features
order: 50
updated: YYYY-MM-DD
author: Aaron Reimann
tags: [feature-name, area]
---
```

## File layout

- One feature per file
- Filenames: kebab-case, lowercase, `.md` extension
- File path drives URL: `resources/docs/features/foo.md` → `/docs/features/foo`
- Section subdirectories are optional but recommended (matches the URL structure)
- Files starting with `_` are excluded from the manifest

## Tone

Casual, first-person ("you'll see this on the dashboard", "we got burned by this once"). Not enterprise-doc style. Write like you're explaining to a coworker.

## Length

Aim for ~800 words max. If a topic is bigger, split into multiple pages with cross-links.

## Code

Triple-backtick blocks with language hints:

````
```php
$site = Site::find(62);
```
````

## Screenshots

Stored in `resources/docs/_assets/<section>/<page-slug>/`. Reference with markdown image syntax pointing to the relative path.

## When to write a doc

When you ship a feature touching the monitoring app, update or create the matching `resources/docs/features/<slug>.md`. Bump `updated:`. The team lookup table is the docs — if it's not in there, it doesn't exist for them.
