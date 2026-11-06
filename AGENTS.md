# Clockwork Control — Agent & Contributor Rules

## 1. Zero Client Information in Repository (CRITICAL)

Clockwork Control is a public/distributable codebase. **Never** include real client-identifying information anywhere in version-controlled files, commit messages, branch names, or pull request descriptions.

### Strictly Prohibited in Code, Comments, Tests, Docs, Plans, and Commits:
- **Client domains or subdomains** (e.g., real `.com`, `.org`, `.net`, `.edu`, `.gov`, or `.mystagingwebsite.com` domains belonging to clients).
- **Client company/organization names** or **contact person names**.
- **Client email addresses** or **phone numbers**.
- **Real managed server hostnames or IPs** (e.g., `spinup*.clockworkwp.com`).
- **Real provider site/server IDs paired with client context** or real API tokens, license keys, and signed download URLs.

### Required Placeholders:
- Always use RFC 2606 / testing domains: `example.com`, `example.org`, `example.net`, `*.example`, `*.test`, or `staging-demo.mystagingwebsite.com`.
- Always use generic names and contacts: `Jane Doe`, `Acme Corp`, `Example Design Group`, `client@example.com`, `+14045550100`.
- When documenting a production bug or regression in code comments, commit messages, `plans/`, or `resources/docs/`, describe the **technical mechanism** (e.g., *"a Pressable site whose primary domain was renamed to `www.example-law.com`"*), never the client's actual domain or name.

### Automated Enforcement:
- `.githooks/pre-commit` and `.githooks/commit-msg` automatically sync and check staged changes and commit messages against `~/.config/clockwork/client-denylist.txt` (populated from the local `sites`, `clients`, and `client_report_schedules` tables).
- Never bypass `.githooks/pre-commit` with `--no-verify` to work around a client-denylist or `gitleaks` block.
