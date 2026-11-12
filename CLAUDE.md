# Clockwork Control — Agent & Contributor Rules

See [`AGENTS.md`](./AGENTS.md) for mandatory workspace rules.

## Zero Client Information in Repository (CRITICAL)

Clockwork Control is a public/distributable codebase. **Never** include real client-identifying information anywhere in version-controlled files, commit messages, branch names, or pull request descriptions:

1. **Never write real client domains, client/organization names, contact names, client emails, phone numbers, or `spinup*.clockworkwp.com` server hostnames** in `app/`, `modules/`, `tests/`, `resources/docs/`, `plans/`, `CHANGELOG.md`, commit messages, or PR descriptions.
2. **Always use generic RFC 2606 placeholders**: `example.com`, `example.org`, `example.net`, `*.example`, `*.test`, `staging-demo.mystagingwebsite.com`, `Jane Doe`, `Acme Corp`, `client@example.com`.
3. **Never bypass `.githooks/pre-commit` or `.githooks/commit-msg`** when they flag a match against `~/.config/clockwork/client-denylist.txt` or `gitleaks`.

