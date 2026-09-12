<?php

namespace App\Services\Companion;

use App\Models\Site;
use App\Support\SsrfGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HMAC-signed REST client for the Clockwork Companion mu-plugin.
 *
 * One instance per Site. Each call signs the request payload with the
 * site's stored companion_secret and posts to https://{domain}/wp-json/clockwork/v1/...
 *
 * Signature scheme MUST match src/Auth/HmacVerifier.php in the plugin repo:
 *   payload = METHOD + "\n" + "/wp-json/clockwork/v1<route>" + "\n" + ts + "\n" + body
 *   signature = hex hmac_sha256(payload, secret)
 *
 * Replay window on the plugin side is 5 minutes — clock drift larger than
 * that will cause stale_timestamp 401s. (`time()` is fine.)
 */
class ClockworkCompanionClient
{
    public const ROUTE_NAMESPACE = 'clockwork/v1';

    /**
     * Identifies our HMAC-signed REST calls in nginx logs and gives operators
     * a single string to allowlist in Cloudflare WAF rules when a site's
     * default security posture (Bot Fight Mode, high Security Level, custom
     * tautological WAF rules — we've fixed three of those this week) starts
     * challenging the call. Pair with a Skip rule keyed on this UA or on the
     * `/wp-json/clockwork/v1/` URI prefix; either works.
     *
     * Deliberately no contact email — keeps the string short and avoids
     * publishing a personal address in every site's access log.
     */
    public const USER_AGENT = 'Clockwork-Web-Dev-Companion/1.0';

    public function __construct(
        protected Site $site,
        protected ?int $timeout = null,
    ) {
        if ($this->timeout === null) {
            // WP multisite REST endpoints cold-boot the entire network on every
            // request — observed 30-50s on a ~10-blog network with many plugins.
            // The default 30s timeout is too tight for short calls (SSO mint,
            // /snapshot, /action-log/append) on those sites. Long-running
            // operations (plugin/theme/core update) override per-call so
            // they're unaffected either way.
            $this->timeout = $site->is_multisite
                ? (int) config('clockwork.companion.timeout_multisite', 60)
                : (int) config('clockwork.companion.timeout', 30);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->getJson('/health');
    }

    /**
     * @return array<string, mixed>
     */
    public function detect(): array
    {
        return $this->getJson('/detect');
    }

    /**
     * Pull the local admin user's chosen form-test subscriptions. Companion
     * exposes the wp_options-backed list that the wp-admin Forms tab writes
     * to when the user flips a Monitor toggle. Used by
     * `clockwork:sync-companion-form-subscriptions` for reconciliation.
     *
     * @return array<string, mixed> { ok: bool, max: int, subscriptions: list<...> }
     */
    public function formSubscriptions(): array
    {
        return $this->getJson('/form-subscriptions');
    }

    /**
     * @return array<string, mixed>
     */
    public function testContactForm(string $plugin, string $formId, string $marker, string $mode = 'lab'): array
    {
        return $this->postJson('/test-contact-form', [
            'plugin' => $plugin,
            'form_id' => $formId,
            'marker' => $marker,
            'mode' => $mode,
        ]);
    }

    /**
     * Full Round-1 snapshot — single HMAC call returns plugins + admins +
     * wp_cron + comments_summary. Used by `clockwork:refresh-companion-snapshot`
     * to populate the per-site `companion_snapshot` cache.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->getJson('/snapshot');
    }

    /**
     * Pull hourly CPU/memory rollups for the per-site capacity leaderboard.
     * `$since` is the inclusive lower bound — Clockwork tracks its last-pulled
     * bucket on `sites.resource_metrics_cursor_at` and re-pulls overlapping
     * windows safely (the Clockwork-side ingest is UPSERT).
     *
     * Requires Companion 1.17.0+ and the `resource-sampler` capability.
     *
     * @return array<string, mixed> shape: { ok, version, now, rows: [{bucket_at, cpu_us_total, wall_us_total, mem_peak_max, requests}] }
     */
    public function resourceReport(?string $sinceIso = null, int $limit = 200): array
    {
        $query = ['limit' => $limit];
        if ($sinceIso !== null && $sinceIso !== '') {
            $query['since'] = $sinceIso;
        }

        return $this->getJson('/resource-report', $query);
    }

    /**
     * Flip the per-request resource sampler on or off remotely. Companion
     * stores the flag in wp_options; takes effect on the next request after
     * this POST completes.
     *
     * Requires Companion 1.17.1+ and the `resource-sampler-toggle` capability.
     *
     * @return array<string, mixed> shape: { ok, version, enabled }
     */
    public function setResourceSamplerEnabled(bool $enabled): array
    {
        return $this->postJson('/resource-sampler-config', ['enabled' => $enabled]);
    }

    /**
     * Push a backups report to the site's Companion. Stored in
     * wp_options['clockwork_companion_backups_report']; rendered by the
     * Backups admin page. Last-write-wins.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function pushBackupsReport(array $report): array
    {
        return $this->postJson('/backups-report', $report);
    }

    /**
     * Trigger a backup generation on the remote WordPress site.
     * When upload_url is provided (e.g. S3 presigned PUT URL with GLACIER_IR),
     * the Companion plugin streams the archive directly to S3 and cleans up
     * the temporary local archive.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createBackup(array $payload = []): array
    {
        return $this->postJson('/backup/create', $payload, [
            'timeout' => 300,
            'retries' => 0,
        ]);
    }

    /**
     * Stage an off-site archive restore on the site.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function stageBackupRestore(array $payload): array
    {
        return $this->postJson('/backup/restore/stage', $payload, [
            'timeout' => 55,
            'retries' => 0,
        ]);
    }

    /**
     * Poll live status of an in-flight or completed backup restore.
     * Uses GET so the HMAC signature is not consumed by the replay guard.
     *
     * @return array<string, mixed>
     */
    public function backupRestoreStatus(): array
    {
        return $this->getJson('/backup/restore/status');
    }

    /**
     * Apply a staged backup restore on the site. Companion cross-checks
     * archive_key against its staged state and 409s on mismatch, so a stale
     * staged restore can never be applied under the wrong archive.
     *
     * @return array<string, mixed>
     */
    public function applyBackupRestore(string $stagedId, ?string $archiveKey = null): array
    {
        $payload = ['staged_id' => $stagedId];
        if ($archiveKey !== null && $archiveKey !== '') {
            $payload['archive_key'] = $archiveKey;
        }

        return $this->postJson('/backup/restore/apply', $payload, [
            'timeout' => 55,
            'retries' => 0,
        ]);
    }

    /**
     * Push a 30-day traffic rollup to the site's Companion. Stored in
     * wp_options['clockwork_companion_traffic_report']; rendered by the
     * Traffic admin page (1.16.0+). Last-write-wins.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function pushTrafficReport(array $report): array
    {
        return $this->postJson('/traffic-report', $report);
    }

    /**
     * Push Pressable-only vulnerability alerts + Defensive Mode status.
     * Stored in wp_options['clockwork_companion_pressable_security_summary'];
     * rendered by the Security admin page (1.31.6+). Last-write-wins.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function pushSecuritySummaryReport(array $report): array
    {
        return $this->postJson('/security-summary-report', $report);
    }

    /**
     * Push white-label branding configuration to the site's Companion.
     * Stored in wp_options['clockwork_companion_branding']; used to dynamically
     * filter plugin header, company/author info, admin menu label/icon, support email, and custom logos.
     *
     * @param  array<string, mixed>  $branding
     * @return array<string, mixed>
     */
    public function pushBranding(array $branding): array
    {
        return $this->postJson('/branding', $branding);
    }

    /**
     * Flush object / page caches on the origin (Companion cache-flush capability).
     *
     * @return array<string, mixed>
     */
    public function flushCache(): array
    {
        return $this->postJson('/cache/flush', []);
    }

    /**
     * Installed plugin inventory. See PluginsRoute for shape.
     *
     * @return array<string, mixed>
     */
    public function plugins(): array
    {
        return $this->getJson('/plugins');
    }

    /**
     * Administrator-role users. See AdminsRoute for shape.
     *
     * @return array<string, mixed>
     */
    public function admins(): array
    {
        return $this->getJson('/admins');
    }

    /**
     * WP-Cron health snapshot. See CronRoute for shape.
     *
     * @return array<string, mixed>
     */
    public function cron(): array
    {
        return $this->getJson('/wp-cron');
    }

    /**
     * Comment counts + discussion settings + antispam status. See
     * CommentsSummaryRoute for shape.
     *
     * @return array<string, mixed>
     */
    public function commentsSummary(): array
    {
        return $this->getJson('/comments-summary');
    }

    /**
     * Fetch paginated, filtered comments for the moderation UI.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     comments: array<int, array<string, mixed>>,
     *     total: int,
     *     total_pages: int,
     *     page: int,
     *     per_page: int,
     *     counts: array<string, int>,
     * }
     */
    public function comments(array $filters = []): array
    {
        return $this->getJson('/comments', $filters);
    }

    /**
     * Perform moderation action on one or more comments.
     * Actions: approve, hold, spam, trash, delete.
     *
     * @param  array<int, int>  $commentIds
     * @return array{
     *     action: string,
     *     success_count: int,
     *     fail_count: int,
     *     results: array<int, array{id: int, ok: bool, error?: string}>,
     * }
     */
    public function moderateComments(array $commentIds, string $action): array
    {
        return $this->postJson('/comments/moderate', [
            'comment_ids' => array_values(array_map('intval', $commentIds)),
            'action' => $action,
        ]);
    }

    /**
     * Purge spam and trash comments older than the specified number of days.
     *
     * @return array{
     *     purged_spam: int,
     *     purged_trash: int,
     *     total_purged: int,
     *     older_than_days: int,
     * }
     */
    public function cleanupComments(int $olderThanDays = 30): array
    {
        return $this->postJson('/comments/cleanup', [
            'older_than_days' => max(0, $olderThanDays),
        ]);
    }

    /**
     * Get maintenance mode status and configuration.
     *
     * @return array{
     *     enabled: bool,
     *     title: string,
     *     message: string,
     *     bypass_param: string,
     *     bypass_key_configured: bool,
     *     updated_at: ?string,
     * }
     */
    public function maintenanceMode(): array
    {
        return $this->getJson('/maintenance-mode');
    }

    /**
     * Enable or disable maintenance mode.
     *
     * @return array{
     *     enabled: bool,
     *     title: string,
     *     message: string,
     *     bypass_param: string,
     *     bypass_key_configured: bool,
     *     updated_at: ?string,
     * }
     */
    public function setMaintenanceMode(bool $enabled, ?string $title = null, ?string $message = null, ?string $secretKey = null): array
    {
        $body = ['enabled' => $enabled];
        if ($title !== null) {
            $body['title'] = $title;
        }
        if ($message !== null) {
            $body['message'] = $message;
        }
        if ($secretKey !== null) {
            $body['secret_key'] = $secretKey;
        }

        return $this->postJson('/maintenance-mode', $body);
    }

    /**
     * Execute arbitrary PHP code in a sandboxed output-buffered context on the WordPress site.
     *
     * @return array{
     *     ok: bool,
     *     output: string,
     *     return_value: mixed,
     *     duration_ms: float,
     *     memory_used_bytes: int,
     *     error?: string,
     *     file?: string,
     *     line?: int,
     * }
     */
    public function executeCodeSnippet(string $code, int $timeout = 30): array
    {
        return $this->postJson('/code-snippet', [
            'code' => $code,
            'timeout' => $timeout,
        ], [
            'timeout' => $timeout + 5,
            'retries' => 1,
        ]);
    }

    /**
     * Mint a one-time SSO magic-link URL on the WP side. The URL, when
     * visited in a browser, logs the named user in via wp_set_auth_cookie
     * and redirects to $redirectTo.
     *
     * Default TTL of 60s is enough for the round-trip + a redirect; the
     * Companion's hard ceiling is 300s.
     *
     * @return array{url: string, expires_at: string}
     */
    public function generateSsoLink(string $userLogin, int $ttlSeconds = 60, string $redirectTo = '/wp-admin/'): array
    {
        $payload = $this->postJson('/sso/magic-link', [
            'user_login' => $userLogin,
            'ttl_seconds' => $ttlSeconds,
            'redirect_to' => $redirectTo,
        ]);

        return [
            'url' => (string) ($payload['url'] ?? ''),
            'expires_at' => (string) ($payload['expires_at'] ?? ''),
        ];
    }

    /**
     * Upgrade a single plugin to the latest available version. Synchronous —
     * the call returns when the upgrade has finished (or failed). The Clockwork
     * UI loops over selected slugs client-side rather than batching, so each
     * call is short and the user gets per-plugin live progress.
     *
     * Long timeout (120s) because Plugin_Upgrader has to download, unzip, and
     * copy files. Retries disabled because a "lost response after success"
     * retry would attempt the same upgrade twice — Companion handles "already
     * up to date" gracefully but it's still cleaner not to ask twice.
     *
     * @return array{
     *     ok: bool,
     *     slug: string,
     *     before_version: string,
     *     after_version: ?string,
     *     was_active: bool,
     *     reactivated: bool,
     *     messages: array<int, string>,
     *     elapsed_ms: int,
     *     error?: string,
     * }
     */
    public function updatePlugin(string $slug): array
    {
        return $this->postJson('/plugins/update', ['slug' => $slug], [
            'timeout' => 120,
            'retries' => 1,
        ]);
    }

    /**
     * Update a single theme to its latest version. Companion-side wraps
     * `Theme_Upgrader::upgrade()`. Same response shape as updatePlugin().
     *
     * Requires Companion v1.15.0+ (mu-plugin endpoint added in that
     * release). Older Companion responds 404; the caller surfaces that.
     *
     * @return array{
     *   ok: bool,
     *   slug: string,
     *   before_version: string,
     *   after_version: ?string,
     *   was_active: bool,
     *   reactivated: bool,
     *   messages: array<int, string>,
     *   elapsed_ms: int,
     *   error?: string,
     * }
     */
    public function updateTheme(string $slug): array
    {
        return $this->postJson('/themes/update', ['slug' => $slug], [
            'timeout' => 120,
            'retries' => 1,
        ]);
    }

    /**
     * Update the WordPress core. Companion-side wraps Core_Upgrader::upgrade().
     *
     * Major-version bumps (6.x → 7.x) require explicit confirm_major=true in
     * the body so we never silently major-bump a site. Companion enforces
     * the same check server-side; this is belt-and-suspenders.
     *
     * Bigger timeout (300s) than plugins/themes — the core download is
     * larger and the in-place file copy is slower.
     *
     * @return array{
     *   ok: bool,
     *   before_version: string,
     *   after_version: ?string,
     *   messages: array<int, string>,
     *   elapsed_ms: int,
     *   error?: string,
     * }
     */
    public function updateCore(bool $confirmMajor = false): array
    {
        return $this->postJson('/wp-core/update', ['confirm_major' => $confirmMajor], [
            'timeout' => 300,
            'retries' => 1,
        ]);
    }

    /**
     * Update everything pending in the translations queue. Companion-side
     * wraps Language_Pack_Upgrader::bulk_upgrade(). No per-language partial —
     * the whole pending set goes at once because that's how WP wants to ship
     * them.
     *
     * @return array{
     *   ok: bool,
     *   updated_count: int,
     *   messages: array<int, string>,
     *   elapsed_ms: int,
     *   error?: string,
     * }
     */
    public function updateTranslations(): array
    {
        return $this->postJson('/translations/update', [], [
            'timeout' => 120,
            'retries' => 1,
        ]);
    }

    /**
     * Mirror an action_log entry to Companion. Companion stores its own
     * copy in wp_clockwork_action_log so the Tools → Clockwork "Activity"
     * admin page can show clients what we've done.
     *
     * Push-once-from-Clockwork: we don't retry on transport failure beyond
     * the default policy. Clockwork's local action_logs is the authoritative
     * record; the Companion mirror is a courtesy to the client. Drift is
     * tolerated for first cut.
     *
     * @param  array<string, mixed>  $payload  Already-shaped row matching the endpoint contract.
     * @return array<string, mixed>
     */
    public function appendActionLog(array $payload): array
    {
        return $this->postJson('/action-log/append', $payload);
    }

    /**
     * Rotate the per-site HMAC secret. Signed with the CURRENT secret; the
     * response carries the NEW secret, which the caller must persist into
     * sites.companion_secret atomically with consuming the response.
     *
     * Failure modes:
     *   - 409 secret_pinned : site has CLOCKWORK_COMPANION_SECRET defined in
     *                         wp-config.php — operator owns rotation.
     *   - 404               : older Companion (< 1.14.3) without the route.
     *   - other             : transport / 5xx — leave Clockwork's stored
     *                         secret untouched, caller decides recovery.
     *
     * @return array{ok: true, secret: string, rotated_at: string}|array{ok: false, error_code: string, error: string}
     */
    /**
     * Run the in-WP malware probe. Bypasses Cloudflare so it works on sites
     * where Sucuri SiteCheck gets 403'd at the edge. Endpoint is allowed a
     * generous timeout because the scan walks every PHP file under the doc
     * root; on a large multisite this can be 30s+.
     *
     * Caller is expected to handle:
     *   - 404 : older Companion (pre-1.16) without the route.
     *   - other : transport / 5xx — caller decides whether to fall back to
     *             SSH wp-cli or persist a `failed` row.
     *
     * @return array{ok: bool, findings?: list<array{kind: string, path: string, evidence: string}>, scanned_files_count?: int, scanned_at?: string, wall_seconds?: float, scan_aborted?: bool, abort_reason?: ?string, error_code?: string, error?: string}
     */
    /**
     * Called after every successful update to verify that active plugins and
     * the active theme match the pre-update state. Companion re-activates any
     * plugin that went inactive and restores the theme if it changed.
     *
     * Requires Companion 1.21.3+ (capability: post-update-verify).
     *
     * `network_active_plugins` (Companion 1.22.2+) carries multisite
     * network-active plugins separately so Companion can re-activate them
     * with `$network_wide=true`. Pre-1.22.2 sites ignore the key. Pre-1.22.2
     * Clockwork snapshots don't surface the data — those sites continue to
     * lose network-active plugins on updates (the pre-fix behavior) until
     * Companion is upgraded and the snapshot refreshes.
     *
     * @param  array{active_plugins: list<string>, network_active_plugins?: list<string>, stylesheet: string, template: string}  $stateBefore
     * @return array{ok: bool, repairs: list<array{type: string, slug: string, detail: string}>}
     */
    public function verifyAndRepair(array $stateBefore): array
    {
        return $this->postJson('/post-update-verify', [
            'active_plugins' => $stateBefore['active_plugins'] ?? [],
            'network_active_plugins' => $stateBefore['network_active_plugins'] ?? [],
            'stylesheet' => $stateBefore['stylesheet'] ?? '',
            'template' => $stateBefore['template'] ?? '',
        ], ['timeout' => 60]);
    }

    public function malwareScan(): array
    {
        $response = $this->post('/malware-scan', [], ['timeout' => 90, 'retries' => 0]);

        $body = $response->json();
        if (! is_array($body)) {
            $body = [];
        }

        if ($response->successful() && ($body['ok'] ?? false)) {
            return [
                'ok' => true,
                'findings' => is_array($body['findings'] ?? null) ? $body['findings'] : [],
                'scanned_files_count' => (int) ($body['scanned_files_count'] ?? 0),
                'scanned_at' => (string) ($body['scanned_at'] ?? ''),
                'wall_seconds' => (float) ($body['wall_seconds'] ?? 0),
                'scan_aborted' => (bool) ($body['scan_aborted'] ?? false),
                'abort_reason' => $body['abort_reason'] ?? null,
            ];
        }

        return [
            'ok' => false,
            'error_code' => (string) ($body['code'] ?? 'http_'.$response->status()),
            'error' => (string) ($body['message'] ?? 'malware scan failed'),
        ];
    }

    public function rotateSecret(): array
    {
        $response = $this->post('/secret/rotate', [], ['retries' => 0]);

        $body = $response->json();
        if (! is_array($body)) {
            $body = [];
        }

        if ($response->successful() && ! empty($body['secret'])) {
            return [
                'ok' => true,
                'secret' => (string) $body['secret'],
                'rotated_at' => (string) ($body['rotated_at'] ?? ''),
            ];
        }

        return [
            'ok' => false,
            'error_code' => (string) ($body['code'] ?? 'http_'.$response->status()),
            'error' => (string) ($body['message'] ?? 'rotate failed'),
        ];
    }

    /**
     * Active LLAR lockouts. Shape matches LlarLockoutPuller::activeLockouts() exactly
     * so the puller can use this as a drop-in replacement for the SSH+SQL path.
     *
     * @return array<int, array{ip: string, unlock_at: ?Carbon, source_table: string}>
     */
    public function lockouts(): array
    {
        $payload = $this->getJson('/lockouts');
        $rows = is_array($payload['lockouts'] ?? null) ? $payload['lockouts'] : [];

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['ip'])) {
                continue;
            }
            $out[] = [
                'ip' => (string) $row['ip'],
                'unlock_at' => ! empty($row['unlock_at']) ? Carbon::parse($row['unlock_at']) : null,
                'source_table' => (string) ($row['source_table'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Active Wordfence blocks. Shape matches WordfenceBlocksPuller::activeBlocks() exactly.
     *
     * @return array<int, array{ip: string, expires_at: ?Carbon, source_table: string, reason: ?string, type: ?string}>
     */
    public function wordfenceBlocks(): array
    {
        $payload = $this->getJson('/wordfence-blocks');
        $rows = is_array($payload['blocks'] ?? null) ? $payload['blocks'] : [];

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['ip'])) {
                continue;
            }
            $out[] = [
                'ip' => (string) $row['ip'],
                'expires_at' => ! empty($row['expires_at']) ? Carbon::parse($row['expires_at']) : null,
                'source_table' => (string) ($row['source_table'] ?? ''),
                'reason' => isset($row['reason']) ? (string) $row['reason'] : null,
                'type' => isset($row['type']) ? (string) $row['type'] : null,
            ];
        }

        return $out;
    }

    /**
     * Per-admin/editor 2FA enrollment status plus the site-level WFLS
     * migration picture. See TwoFactorStatusRoute in the Companion repo for
     * the authoritative response shape — notably `users[].state` (
     * 'clockwork' | 'wfls' | 'none') is what a migration caller filters on,
     * and `users` only covers administrator/editor roles even though
     * `wfls_unmigrated_total` counts all roles.
     *
     * Requires Companion 1.28.0+ (route added alongside the 2FA feature).
     *
     * @return array<string, mixed>
     */
    public function twoFactorStatus(): array
    {
        return $this->getJson('/two-factor');
    }

    /**
     * Migrate one user's 2FA from Wordfence Login Security to Companion's
     * own TOTP secret, in place — same underlying key, so their existing
     * authenticator app entry keeps working. Deletes the WFLS DB row for
     * that user and issues 8 fresh Companion backup codes. Companion-side
     * this is WflsMigrator::migrate(); no-ops (ok=true, migrated=false) if
     * the user is already enrolled in Companion 2FA or has no WFLS secret.
     *
     * Requires Companion 1.29.6+ (route built specifically for this —
     * "monitoring-app-driven WFLS migrations"). Unlike the wp-admin
     * migrate button (which only ever acts on the logged-in user), this
     * route takes any user id, which is what makes fleet automation
     * possible at all.
     *
     * @return array{ok: bool, migrated: bool}
     */
    public function migrateTwoFactorUser(int $userId): array
    {
        return $this->postJson('/two-factor/migrate', ['user_id' => $userId]);
    }

    /**
     * Query params are passed through to the URL but NOT included in the
     * HMAC payload — the signature is computed over the registered route
     * pattern only ($request->get_route() on the WP side strips them).
     *
     * @param  array<string, scalar>  $query
     */
    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    protected function getJson(string $route, array $query = []): array
    {
        return $this->decodeJsonBody($this->get($route, $query), 'GET', $route);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array{timeout?: int, retries?: int}  $options
     * @return array<string, mixed>
     */
    protected function postJson(string $route, array $body, array $options = []): array
    {
        return $this->decodeJsonBody($this->post($route, $body, $options), 'POST', $route);
    }

    /**
     * A 2xx response with a body that doesn't decode to an array — either
     * genuinely non-JSON (a broken nginx rewrite serving the homepage for
     * every path, isNonJsonResponse()'s retry already exhausted) or JSON
     * corrupted by something on the site injecting output before it (a
     * timezone-redirect snippet wrapping every response, observed live
     * 2026-09-04 on auctioneersite.example). Salvage by decoding from the
     * first '{' or '[' onward before giving up — cheap, and recovers the
     * corrupted-prefix case without risking a false match (json_decode
     * fails outright if anything trails the value, so stray braces earlier
     * in an HTML/CSS blob can't produce a bogus success).
     *
     * Throwing a clear RuntimeException here (instead of letting `: array`
     * return types coerce null into a TypeError) turns a cryptic crash into
     * an actionable message with the actual body content.
     *
     * @return array<string, mixed>
     */
    private function decodeJsonBody(Response $response, string $method, string $route): array
    {
        $decoded = $response->json();
        if (is_array($decoded)) {
            return $decoded;
        }

        $body = (string) $response->body();
        $start = null;
        foreach (['{', '['] as $char) {
            $pos = strpos($body, $char);
            if ($pos !== false && ($start === null || $pos < $start)) {
                $start = $pos;
            }
        }
        if ($start !== null) {
            $salvaged = json_decode(substr($body, $start), true);
            if (is_array($salvaged)) {
                return $salvaged;
            }
        }

        $excerpt = mb_strimwidth($body, 0, 200, '…');
        throw new RuntimeException(
            "Clockwork Companion {$method} {$route} on {$this->site->domain} returned a non-JSON or malformed body: {$excerpt}"
        );
    }

    protected function get(string $route, array $query = []): Response
    {
        $body = '';
        $request = $this->signedRequest('GET', $route, $body);
        $url = $this->buildUrl($route);
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }
        $response = $request->get($url);

        if ($this->isNonJsonResponse($response)) {
            // Some sites' nginx vhost is missing the standard WordPress REST
            // API rewrite (confirmed live 2026-08-29 on floristclient.example) —
            // /wp-json/... resolves to the site's homepage HTML instead of
            // dispatching to WordPress's REST handler at all, with a 200
            // status that guard() would otherwise treat as success. The
            // query-string form (?rest_route=) bypasses that rewrite
            // entirely. Signature is unaffected — it's computed over the
            // logical route string, which $request->get_route() returns
            // identically regardless of which URL form reached it.
            $retryRequest = $this->signedRequest('GET', $route, $body);
            $retryUrl = $this->buildQueryRouteUrl($route);
            if ($query !== []) {
                $retryUrl .= '&'.http_build_query($query);
            }
            $response = $retryRequest->get($retryUrl);
        }

        return $this->guard($response, 'GET', $route);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array{timeout?: int, retries?: int}  $options  per-call overrides for the few routes (e.g. plugin upgrades) that need a longer timeout or different retry policy than the defaults
     */
    protected function post(string $route, array $body, array $options = []): Response
    {
        $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);
        $request = $this->signedRequest('POST', $route, $jsonBody, $options);

        // withBody sends the exact JSON we signed; using ->post($url, $array)
        // would re-encode and could drift from the signed payload (key order,
        // unicode escaping, etc.).
        $response = $request
            ->withBody($jsonBody, 'application/json')
            ->post($this->buildUrl($route));

        if ($this->isNonJsonResponse($response)) {
            // Same missing-rewrite fallback as get() — see its comment.
            $retryRequest = $this->signedRequest('POST', $route, $jsonBody, $options);
            $response = $retryRequest
                ->withBody($jsonBody, 'application/json')
                ->post($this->buildQueryRouteUrl($route));
        }

        return $this->guard($response, 'POST', $route);
    }

    /**
     * True when a 200-status response is clearly NOT the JSON we expected —
     * the case guard()'s failed()-only check can't catch, since a
     * misconfigured rewrite serving the homepage is still HTTP 200.
     */
    private function isNonJsonResponse(Response $response): bool
    {
        if ($response->failed()) {
            return false; // guard() handles genuine HTTP failures; not our concern here.
        }

        return ! str_contains((string) $response->header('Content-Type'), 'json');
    }

    private function buildQueryRouteUrl(string $route): string
    {
        return 'https://'.$this->site->domain.'/?rest_route='.rawurlencode('/'.self::ROUTE_NAMESPACE.$route);
    }

    /**
     * @param  array{timeout?: int, retries?: int}  $options
     */
    protected function signedRequest(string $method, string $route, string $body, array $options = []): PendingRequest
    {
        $secret = $this->site->companion_secret;
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException(
                "Site #{$this->site->id} ({$this->site->domain}) has no companion_secret. Run clockwork:install-companion first."
            );
        }

        $sign = function (int $timestamp) use ($method, $route, $body, $secret): string {
            $payload = strtoupper($method)
                ."\n".'/wp-json/'.self::ROUTE_NAMESPACE.$route
                ."\n".$timestamp
                ."\n".$body;

            return hash_hmac('sha256', $payload, $secret);
        };

        $timestamp = time();
        $signature = $sign($timestamp);

        // Http::retry($n, ...) treats $n as total attempts (1 = no retries,
        // 2 = one retry after first failure). Default 2 = single retry.
        $retries = max(1, (int) ($options['retries'] ?? 2));

        SsrfGuard::assertPublic($this->buildUrl($route));

        return Http::timeout($options['timeout'] ?? $this->timeout)
            ->withUserAgent(self::USER_AGENT)
            ->withHeaders([
                'X-Clockwork-Signature' => $signature,
                'X-Clockwork-Timestamp' => (string) $timestamp,
            ])
            ->acceptJson()
            // verify => false matches UptimeProber's stance — browsers do
            // AIA fetching for missing intermediates (common with GoDaddy /
            // Sectigo installs that ship the leaf without the chain), but
            // PHP's cURL does not. The TLS handshake still has to succeed
            // (so a genuinely-broken cert / wrong protocol / no listener
            // still fails), and our own HMAC signature in X-Clockwork-
            // Signature is what gates the WP-side request. Skipping chain
            // validation here lets us install Companion on sites whose
            // SSL chain is misconfigured at the origin without first
            // requiring the operator to fix the chain. The dedicated
            // clockwork:check-ssl-certs job is where chain validity gets
            // tracked; it's not this client's job.
            ->withOptions([
                'verify' => false,
                'allow_redirects' => [
                    'max' => 5,
                    'strict' => true,
                    'protocols' => ['https'],
                    'on_redirect' => SsrfGuard::onRedirect(),
                ],
            ])
            // Each retry attempt MUST be re-signed with a fresh timestamp.
            // The HmacVerifier on the WP side consumes a mutating request's
            // signature the moment it verifies — before the route handler
            // runs — so replaying the identical signature is guaranteed a
            // 401 replayed_request, which then MASKS whatever actually
            // failed on the first attempt (found live 2026-09-11: a plain
            // 400 missing_config surfaced as replayed_request). time() can
            // return the same second on a fast retry, so bump past the last
            // used timestamp to force a distinct signature.
            ->retry($retries, 500, function ($exception, $request) use ($sign, &$timestamp) {
                $timestamp = max(time(), $timestamp + 1);
                $request->withHeaders([
                    'X-Clockwork-Signature' => $sign($timestamp),
                    'X-Clockwork-Timestamp' => (string) $timestamp,
                ]);

                return true;
            });
    }

    protected function buildUrl(string $route): string
    {
        return 'https://'.$this->site->domain.'/wp-json/'.self::ROUTE_NAMESPACE.$route;
    }

    protected function guard(Response $response, string $method, string $route): Response
    {
        if ($response->failed()) {
            $excerpt = mb_strimwidth((string) $response->body(), 0, 200, '…');
            throw new RuntimeException(
                "Clockwork Companion {$method} {$route} on {$this->site->domain} failed: HTTP {$response->status()} {$excerpt}"
            );
        }

        return $response;
    }
}
