<?php

return [
    // Application release version (SemVer). Deliberately NOT env()-backed —
    // this is which code is checked out, not install-specific runtime
    // config, so it must come from the code itself and update automatically
    // on every git pull. A CLOCKWORK_VERSION in .env would silently pin this
    // to whatever it was at install time forever, since updates never touch
    // .env by design — exactly the trap a real install hit, where the
    // Updates page kept reporting a stale version release after release
    // despite the code (and every other artifact of the update) being
    // genuinely current. Bump the literal string below at each release
    // per RELEASING.md; do not reintroduce an env() wrapper here.
    'version' => '1.7.1',

    'arcjet' => [
        'bots_url' => env(
            'CLOCKWORK_ARCJET_BOTS_URL',
            'https://raw.githubusercontent.com/arcjet/well-known-bots/main/well-known-bots.json',
        ),
    ],

    'mattermost' => [
        'webhook_url' => env('CLOCKWORK_MATTERMOST_WEBHOOK_URL'),
        'channel' => env('CLOCKWORK_MATTERMOST_CHANNEL'),
        'username' => env('CLOCKWORK_MATTERMOST_USERNAME', 'Clockwork'),
        'icon_emoji' => env('CLOCKWORK_MATTERMOST_ICON_EMOJI', ':lock:'),
        'enabled' => env('CLOCKWORK_MATTERMOST_ENABLED', false),
    ],

    // Slack incoming webhook. Generate at Your App → Incoming Webhooks in
    // the Slack API dashboard. The webhook URL already encodes the target
    // channel, so CLOCKWORK_SLACK_CHANNEL is optional (leave it blank to
    // post to the channel the webhook was configured for). If set, the
    // channel name should include the # prefix (e.g. #monitoring-alerts).
    // Per-event opt-outs are stored separately from Mattermost so both
    // channels can be tuned independently.
    'slack' => [
        'webhook_url' => env('CLOCKWORK_SLACK_WEBHOOK_URL'),
        'channel' => env('CLOCKWORK_SLACK_CHANNEL'),
        'username' => env('CLOCKWORK_SLACK_USERNAME', 'Clockwork'),
        'icon_emoji' => env('CLOCKWORK_SLACK_ICON_EMOJI', ':lock:'),
        'enabled' => env('CLOCKWORK_SLACK_ENABLED', false),
    ],

    'digitalocean' => [
        'token' => env('CLOCKWORK_DIGITALOCEAN_TOKEN'),
        'base_url' => env('CLOCKWORK_DIGITALOCEAN_BASE_URL', 'https://api.digitalocean.com/v2'),
        'timeout' => env('CLOCKWORK_DIGITALOCEAN_TIMEOUT', 15),
    ],

    // Hetzner Cloud API. Used for the same enrichment + metrics pipeline
    // that DigitalOcean drives — the importer cross-references SpinupWP
    // servers tagged provider=hetzner against this API's /servers list,
    // and PollServers fetches CPU/disk/network metrics from /servers/{id}/metrics.
    // Generate a read-only API token from console.hetzner.cloud → project → Security → API Tokens.
    'hetzner' => [
        'token' => env('CLOCKWORK_HETZNER_TOKEN'),
        'base_url' => env('CLOCKWORK_HETZNER_BASE_URL', 'https://api.hetzner.cloud/v1'),
        'timeout' => env('CLOCKWORK_HETZNER_TIMEOUT', 15),
    ],

    // Azure Resource Manager + Azure Monitor. Used for the same provider-metrics
    // pipeline as DigitalOcean and Hetzner: ReconcileProvider cross-references
    // server IPs against Azure public IP addresses to link provider_id, and
    // PollServers fetches Percentage CPU + Available Memory Bytes from Azure Monitor.
    //
    // Requires a Service Principal with Reader access on the subscription:
    //   Azure Portal → Entra ID → App registrations → New registration
    //   → Certificates & secrets → New client secret
    //   → Subscriptions → IAM → Add role assignment → Reader → select the SP
    'azure' => [
        'tenant_id' => env('CLOCKWORK_AZURE_TENANT_ID'),
        'client_id' => env('CLOCKWORK_AZURE_CLIENT_ID'),
        'client_secret' => env('CLOCKWORK_AZURE_CLIENT_SECRET'),
        'subscription_id' => env('CLOCKWORK_AZURE_SUBSCRIPTION_ID'),
        'base_url' => env('CLOCKWORK_AZURE_BASE_URL', 'https://management.azure.com'),
        'login_url' => env('CLOCKWORK_AZURE_LOGIN_URL', 'https://login.microsoftonline.com'),
        'timeout' => env('CLOCKWORK_AZURE_TIMEOUT', 15),
    ],

    // Vultr Cloud API v2. Used for instance discovery, size tiers, and IP matching
    // for SpinupWP servers hosted on Vultr infrastructure.
    'vultr' => [
        'api_key' => env('CLOCKWORK_VULTR_API_KEY'),
        'base_url' => env('CLOCKWORK_VULTR_BASE_URL', 'https://api.vultr.com/v2'),
        'timeout' => env('CLOCKWORK_VULTR_TIMEOUT', 15),
    ],

    // Linode (Akamai) Cloud API v4. Used for instance discovery, CPU metrics,
    // size tiers, and IP matching for SpinupWP servers hosted on Linode.
    'linode' => [
        'token' => env('CLOCKWORK_LINODE_TOKEN'),
        'base_url' => env('CLOCKWORK_LINODE_BASE_URL', 'https://api.linode.com/v4'),
        'timeout' => env('CLOCKWORK_LINODE_TIMEOUT', 15),
    ],

    // DigitalOcean Spaces (S3-compatible object storage). Used to enumerate
    // SpinupWP backup objects so the per-site Backups admin page can show real
    // run history (sizes + dates), since SpinupWP's REST API does not expose it.
    // Auth is HMAC key+secret (NOT the DO Personal Access Token).
    'do_spaces' => [
        'key' => env('CLOCKWORK_DO_SPACES_KEY'),
        'secret' => env('CLOCKWORK_DO_SPACES_SECRET'),
        'region' => env('CLOCKWORK_DO_SPACES_REGION', 'nyc3'),
        'bucket' => env('CLOCKWORK_DO_SPACES_BUCKET', ''),
        // SpinupWP writes to <domain>/<timestamp>-<suffix>.{sql.gz|tar.gz} —
        // probed against a real bucket on 2026-05-03. Overridable for non-SpinupWP
        // setups.
        'prefix_template' => env('CLOCKWORK_DO_SPACES_PREFIX_TEMPLATE', '{domain}/'),
    ],

    'ssh' => [
        'default_user' => env('CLOCKWORK_DEFAULT_SSH_USER', ''),
        'default_port' => env('CLOCKWORK_DEFAULT_SSH_PORT', 22),
        'connect_timeout' => env('CLOCKWORK_SSH_CONNECT_TIMEOUT', 10),
        'exec_timeout' => env('CLOCKWORK_SSH_EXEC_TIMEOUT', 30),

        // Short fsockopen probe before the real SSH handshake, so a
        // deleted/firewalled server fails fast instead of hanging past
        // max_execution_time (see SshClient::connect()).
        'preflight_timeout' => env('CLOCKWORK_SSH_PREFLIGHT_TIMEOUT', 2),

        // Path to the local private key used to log into servers. SpinupWP-managed servers
        // typically have password auth disabled, so a key is the canonical way in.
        // If the key is passphrase-protected, set CLOCKWORK_SSH_KEY_PASSPHRASE.
        // Set to empty to disable key-based default and rely on per-server keys / passwords.
        'default_key_path' => env('CLOCKWORK_SSH_KEY_PATH'),
        'default_key_passphrase' => env('CLOCKWORK_SSH_KEY_PASSPHRASE'),
    ],

    // /settings/scheduled-jobs history retention. Rows are written by
    // RecordScheduledTaskResult for every tick of every scheduled job.
    'scheduled_jobs' => [
        'retention_days' => env('CLOCKWORK_SCHEDULED_JOBS_RETENTION_DAYS', 30),
    ],

    // Where ops-side alert emails (nightly auto-update summary, future
    // fleet-wide health alerts) land. Distinct from per-customer mail —
    // contact-form failures, monthly summaries, etc. still go to the
    // site's client_email. This is the operator address.
    'alerts' => [
        'email' => env('CLOCKWORK_ALERTS_EMAIL'),
    ],

    // Twilio SMS for site-down / site-up alerts on care-plan sites.
    // Recipients are configured at /settings/notifications (database-
    // backed via the notification_recipients table); this block holds
    // only the API credentials. Set enabled=false to keep Mattermost-
    // only behavior even with creds present (useful pre-A2P approval).
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM_NUMBER'),
        'enabled' => env('TWILIO_ENABLED', false),
    ],

    // Identifies whoever is running this instance — used anywhere Clockwork
    // needs to present a "who to contact" identity to an external party: the
    // uptime prober's User-Agent header (so a monitored site's admin can see
    // who's probing them and whitelist/contact if needed), the client-facing
    // Slack support link, and outbound client-facing email footers. Defaults
    // are intentionally generic with no real contact info — set these before
    // running against real sites, especially the uptime prober, which sends
    // whatever's here to every monitored site on every probe.
    'operator' => [
        'name' => env('CLOCKWORK_OPERATOR_NAME', 'Clockwork operator'),
        'contact_email' => env('CLOCKWORK_OPERATOR_CONTACT_EMAIL'),
        'support_url' => env('CLOCKWORK_OPERATOR_SUPPORT_URL'),
        // Plain marketing/company URL for email footers — kept separate from
        // support_url since that may point at a specific support subpage.
        'website_url' => env('CLOCKWORK_OPERATOR_WEBSITE_URL'),
    ],

    'monitoring' => [
        // Server::getDisplayNameAttribute() strips this suffix for UI tables
        // where the full FQDN is constant noise — e.g. "web36" instead of
        // "web36.example.com". Empty by default (no-op strip); set to
        // whatever your own fleet's naming convention's constant suffix is.
        'display_name_strip_suffix' => env('CLOCKWORK_SERVER_DISPLAY_NAME_STRIP_SUFFIX', ''),

        'cpu_red_threshold' => env('CLOCKWORK_CPU_RED_THRESHOLD', 90),
        'cpu_yellow_threshold' => env('CLOCKWORK_CPU_YELLOW_THRESHOLD', 70),
        'memory_yellow_threshold' => env('CLOCKWORK_MEM_YELLOW_THRESHOLD', 80),
        'disk_yellow_threshold' => env('CLOCKWORK_DISK_YELLOW_THRESHOLD', 85),
        'metrics_window_minutes' => env('CLOCKWORK_METRICS_WINDOW_MINUTES', 15),

        // Grace period after Let's Encrypt's scheduled renewal date before we flag a site
        // as "renewal needed" (yellow). SpinupWP's renewal cron doesn't fire at midnight
        // on the renews date — give it a day or two to actually run before alarming.
        'ssl_renewal_grace_hours' => env('CLOCKWORK_SSL_RENEWAL_GRACE_HOURS', 48),

        // Servers whose name matches any of these substrings (case-insensitive) are
        // auto-flagged as ignored on FIRST import — manual changes after that are preserved.
        // Use cases: behind firewalls, can't reach via SSH, customer-managed, etc.
        'auto_ignore_name_patterns' => array_filter(array_map('trim', explode(
            ',',
            (string) env('CLOCKWORK_AUTO_IGNORE_PATTERNS', ''),
        ))),
        'auto_ignore_reason' => env(
            'CLOCKWORK_AUTO_IGNORE_REASON',
            'Behind firewall — auto-flagged from name pattern',
        ),
    ],

    'spinupwp' => [
        'token' => env('CLOCKWORK_SPINUPWP_TOKEN'),
        'base_url' => env('CLOCKWORK_SPINUPWP_BASE_URL', 'https://api.spinupwp.app/v1'),
        'timeout' => env('CLOCKWORK_SPINUPWP_TIMEOUT', 15),
        'view_only' => (bool) env('SPINUPWP_VIEW_ONLY', env('CLOCKWORK_SPINUPWP_VIEW_ONLY', false)),
    ],

    'wpengine' => [
        // Basic Auth (api_user_id/api_password from my.wpengine.com/api_access)
        // for the REST client. Command execution and Companion install go
        // over real per-install SSH instead — a separate credential, since
        // WP Engine's SSH gateway auths by key, not by the API credentials
        // above. No per-site "server" concept, same shape as Pressable.
        'api_user_id' => env('CLOCKWORK_WPENGINE_API_USER_ID'),
        'api_password' => env('CLOCKWORK_WPENGINE_API_PASSWORD'),
        'base_url' => env('CLOCKWORK_WPENGINE_BASE_URL', 'https://api.wpengine.com/v1'),
        'timeout' => env('CLOCKWORK_WPENGINE_TIMEOUT', 15),
        'ssh_private_key' => env('CLOCKWORK_WPENGINE_SSH_PRIVATE_KEY'),
        'ssh_private_key_passphrase' => env('CLOCKWORK_WPENGINE_SSH_PRIVATE_KEY_PASSPHRASE'),
        'view_only' => (bool) env('WPENGINE_VIEW_ONLY', env('CLOCKWORK_WPENGINE_VIEW_ONLY', true)),
    ],

    'kinsta' => [
        // Bearer token API key. Command execution and Companion install go
        // over real per-environment SSH — Kinsta's API can manage SSH
        // access/credentials but cannot execute remote commands itself.
        'api_key' => env('CLOCKWORK_KINSTA_API_KEY'),
        'base_url' => env('CLOCKWORK_KINSTA_BASE_URL', 'https://api.kinsta.com/v2'),
        'timeout' => env('CLOCKWORK_KINSTA_TIMEOUT', 15),
        'ssh_password' => env('CLOCKWORK_KINSTA_SSH_PASSWORD'),
        'view_only' => (bool) env('KINSTA_VIEW_ONLY', env('CLOCKWORK_KINSTA_VIEW_ONLY', true)),
    ],

    'cloudways' => [
        // OAuth2: an API key is exchanged for a bearer access token (see
        // CloudwaysClient::token()). Cloudways provisions real servers on a
        // cloud of its choosing (DO/AWS/GCP/Vultr/Linode) that this app
        // never sees credentials for directly — server metrics come from
        // Cloudways' own monitoring API, not the underlying cloud's native
        // one, via CloudwaysCloudProvider. v1 of this API reached end of
        // life 2026-03-31; only v2 is supported here.
        'api_key' => env('CLOCKWORK_CLOUDWAYS_API_KEY'),
        'email' => env('CLOCKWORK_CLOUDWAYS_EMAIL'),
        'base_url' => env('CLOCKWORK_CLOUDWAYS_BASE_URL', 'https://api.cloudways.com/api/v2'),
        'timeout' => env('CLOCKWORK_CLOUDWAYS_TIMEOUT', 15),
        'view_only' => (bool) env('CLOUDWAYS_VIEW_ONLY', env('CLOCKWORK_CLOUDWAYS_VIEW_ONLY', true)),
    ],

    'gridpane' => [
        // Personal access token generated in my.gridpane.com -> Settings -> GridPane API.
        // Provisions and manages WordPress sites on cloud VPS servers with full root SSH access.
        'api_key' => env('GRIDPANE_API_KEY', env('CLOCKWORK_GRIDPANE_API_KEY')),
        'base_url' => env('GRIDPANE_BASE_URL', env('CLOCKWORK_GRIDPANE_BASE_URL', 'https://my.gridpane.com/oauth/api/v1')),
        'timeout' => (int) env('GRIDPANE_TIMEOUT', env('CLOCKWORK_GRIDPANE_TIMEOUT', 15)),
        'view_only' => (bool) env('GRIDPANE_VIEW_ONLY', env('CLOCKWORK_GRIDPANE_VIEW_ONLY', true)),
    ],

    'pressable' => [
        // OAuth2 client_credentials — no per-site "server" concept, every
        // operation is addressed by site_id alone. See PressableClient.
        'client_id' => env('CLOCKWORK_PRESSABLE_CLIENT_ID'),
        'client_secret' => env('CLOCKWORK_PRESSABLE_CLIENT_SECRET'),
        'auth_url' => env('CLOCKWORK_PRESSABLE_AUTH_URL', 'https://my.pressable.com/auth/token'),
        'base_url' => env('CLOCKWORK_PRESSABLE_BASE_URL', 'https://my.pressable.com/v1'),
        'timeout' => env('CLOCKWORK_PRESSABLE_TIMEOUT', 15),
        'view_only' => (bool) env('PRESSABLE_VIEW_ONLY', env('CLOCKWORK_PRESSABLE_VIEW_ONLY', false)),
    ],

    // Backup relay: a standalone DigitalOcean droplet (separate project, not
    // part of this repo) that archives Pressable site backups to S3 Glacier
    // Instant Retrieval, replacing ManageWP's 90-day Pressable backup
    // retention. No direct connection between this app and the droplet —
    // the Monitoring App only runs locally and was never meant to accept
    // inbound traffic. Instead both sides talk through small JSON objects on
    // the same S3 bucket the backups themselves land in (the 's3' disk in
    // config/filesystems.php): this app pushes the site list the droplet
    // should back up, and reads back the droplet's last run summary. See
    // clockwork:push-backup-relay-targets / clockwork:pull-backup-relay-report.
    'backup_relay' => [
        'mode' => env('CLOCKWORK_BACKUP_RELAY_MODE', 'in_repo'),
        'frequency' => env('CLOCKWORK_BACKUP_RELAY_FREQUENCY', 'weekly'),
        'retention_days' => (int) env('CLOCKWORK_BACKUP_RELAY_RETENTION_DAYS', 90),
        'schema_version' => 2,
        'disk' => env('CLOCKWORK_BACKUP_RELAY_DISK', 's3-backup-relay'),
        's3_prefix' => env('CLOCKWORK_BACKUP_RELAY_S3_PREFIX', '_control/backup-relay'),
        'archive_prefix' => env('CLOCKWORK_BACKUP_RELAY_ARCHIVE_PREFIX', 'archives'),
    ],

    'cloudflare' => [
        // Scoped API token. Needs Zone:Read + Zone WAF:Read at minimum to enumerate
        // redirect/transform rules. Add Zone WAF:Edit if we ever want this CLI to
        // patch rules instead of just diagnosing them.
        'api_token' => env('CLOCKWORK_CLOUDFLARE_API_TOKEN'),
        // Separate write token used by the migration tool's DNS cutover phase.
        // Scope: Zone.DNS:Edit on the zones we manage. Kept distinct from the
        // read token so a leak of the read token doesn't grant write capability,
        // and so the write token can be rotated independently.
        'write_token' => env('CLOCKWORK_CLOUDFLARE_WRITE_TOKEN'),
        'base_url' => env('CLOCKWORK_CLOUDFLARE_BASE_URL', 'https://api.cloudflare.com/client/v4'),
        'timeout' => env('CLOCKWORK_CLOUDFLARE_TIMEOUT', 15),
    ],

    'companion' => [
        'version' => env('CLOCKWORK_COMPANION_VERSION', '1.37.1'),

        // Where the Clockwork Companion mu-plugin .zip is published.
        // Empty during development — installer falls back to local rsync from companion_local_path.
        'dist_url' => env('CLOCKWORK_COMPANION_DIST_URL'),
        'dist_sha256' => env('CLOCKWORK_COMPANION_DIST_SHA256'),

        // Local path to the source repo for development (rsync mode). Defaults to
        // ~/Projects/clockwork-companion. env('HOME') is null under Herd's php-fpm
        // (no shell env), so resolve via passwd first and fall back to env('HOME').
        'local_path' => env('CLOCKWORK_COMPANION_LOCAL_PATH', (function () {
            $home = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_getuid())['dir'] ?? null) : null;
            $home = $home ?: env('HOME');

            return ($home ? rtrim($home, '/') : '').'/Projects/clockwork-companion';
        })()),

        // Per-call timeout (seconds) for HTTP calls to the plugin's REST endpoints.
        'timeout' => env('CLOCKWORK_COMPANION_TIMEOUT', 30),

        // Default timeout for sites flagged sites.is_multisite. WP multisite
        // boots the network on every REST request (30-50s on bigger networks);
        // the standard 30s default times out short calls like SSO and /snapshot.
        // Long calls (plugin/theme/core update) ignore this — they pass their
        // own timeout in $options.
        'timeout_multisite' => env('CLOCKWORK_COMPANION_TIMEOUT_MULTISITE', 60),
    ],

    'sucuri' => [
        // Public, free SiteCheck v3 API. No auth — Sucuri exposes the same
        // scanner ManageWP and others resell. Rate-limited around 30 req/min,
        // so the artisan loop sleeps ~250ms between calls.
        'base_url' => env('CLOCKWORK_SUCURI_BASE_URL', 'https://sitecheck.sucuri.net'),
        'timeout' => env('CLOCKWORK_SUCURI_TIMEOUT', 30),
    ],

    'psi' => [
        // Google PageSpeed Insights v5 API key. As of 2026-06-27 this is the
        // FALLBACK engine — GTmetrix is primary (see gtmetrix block below).
        // We keep PSI wired up so a transient GTmetrix outage (API hiccup,
        // quota exhausted, etc.) doesn't lose a site's nightly data point.
        // Will be removed after a clean 30-day window on GTmetrix-only.
        //
        // Free, register at https://console.cloud.google.com/apis/credentials
        // with "PageSpeed Insights API" enabled. Default quota 25k req/day.
        'api_key' => env('CLOCKWORK_PSI_API_KEY', ''),
        'timeout' => env('CLOCKWORK_PSI_TIMEOUT', 90),
    ],

    'gtmetrix' => [
        // GTmetrix REST API v2 — primary performance-scanning engine as of
        // 2026-06-27 (replaced Google PSI). Picked for pinned test location +
        // pinned browser version, which removes most of PSI's ±10-15 noise
        // floor on the daily score.
        //
        // Auth: HTTP Basic, API key as username, password blank. Generate at
        // https://gtmetrix.com/dashboard/api/ on a paid account (free tier
        // is 5 tests/day — won't cover a 50-site care-plan run).
        'api_key' => env('CLOCKWORK_GTMETRIX_API_KEY', ''),
        'base_url' => env('CLOCKWORK_GTMETRIX_BASE_URL', 'https://gtmetrix.com/api/2.0'),
        // 120s ceiling per scan: submission is instant but the test itself
        // takes 15-60s and we poll for completion. 120s is generous; a stuck
        // test past that is a GTmetrix-side problem worth surfacing as an error.
        'timeout' => (int) env('CLOCKWORK_GTMETRIX_TIMEOUT', 120),
        // Default test location ID (GTmetrix's "location" attribute on the
        // test payload). Per-site override lives on sites.performance_scan_region;
        // when that column is NULL we fall back to this value. GTmetrix v2
        // requires numeric IDs (not slugs) — fetch live list with:
        //   curl -u <key>: https://gtmetrix.com/api/2.0/locations
        // Common IDs as of 2026-06-30: 2=London, 3=Sydney, 4=San Antonio TX,
        // 7=Hong Kong, 9=San Francisco CA, 10=Cheyenne WY, 11=Chicago IL,
        // 12=Danville VA, 24=Seattle WA, etc. San Antonio (4) is the
        // US-central default — closest replacement for the discontinued
        // Dallas location.
        'region' => (int) env('CLOCKWORK_GTMETRIX_REGION', 4),
        // Polling cadence after submitting a test. 5s × 36 = 180s ceiling.
        // Empirically: most tests finish in 15-30s, but busier queues +
        // bigger sites can push to 120s+. 180s covers the long-tail without
        // wedging the daily loop. Tests that genuinely time out beyond
        // this are usually pages Lighthouse can't measure stably anyway.
        'poll_interval_seconds' => (int) env('CLOCKWORK_GTMETRIX_POLL_INTERVAL', 5),
        'poll_max_attempts' => (int) env('CLOCKWORK_GTMETRIX_POLL_MAX_ATTEMPTS', 36),
    ],

    'security_scans' => [
        // Per-site blacklist check (clockwork:check-blacklists). Spamhaus DBL
        // runs always (DNS lookup, no key). The other two sources are opt-in
        // free registrations:
        //
        //   GOOGLE_WEB_RISK_KEY     — Google Cloud Web Risk API (commercial standard).
        //     Free up to 100k req/month. Highest signal for malware, phishing, unwanted software.
        //   GOOGLE_SAFE_BROWSING_KEY — Legacy non-commercial Safe Browsing v4 fallback.
        //   URLHAUS_AUTH_KEY        — auth.abuse.ch, free, generous limits.
        //     Catches malware-host status (4M+ entries).
        //
        // All sources unset = Spamhaus DBL only (still useful baseline).
        // Do NOT default web_risk_key to the v4 secret — they are different APIs.
        // Runtime fallback lives in BlacklistChecker::effectiveGoogleSource().
        'google_web_risk_key' => env('CLOCKWORK_GOOGLE_WEB_RISK_KEY', ''),
        'google_safe_browsing_key' => env('CLOCKWORK_GOOGLE_SAFE_BROWSING_KEY', ''),
        'urlhaus_auth_key' => env('CLOCKWORK_URLHAUS_AUTH_KEY', ''),
        'blacklist_timeout' => env('CLOCKWORK_BLACKLIST_TIMEOUT', 10),
    ],

    'lm_studio' => [
        'base_url' => env('CLOCKWORK_LM_STUDIO_BASE_URL', 'http://localhost:1234/v1'),
        'model' => env('CLOCKWORK_LM_STUDIO_MODEL', 'meta-llama-3-8b-instruct'),
        'api_key' => env('CLOCKWORK_LM_STUDIO_API_KEY', 'lm-studio'),
        'timeout' => env('CLOCKWORK_LM_STUDIO_TIMEOUT', 120),
    ],

    'bill_com' => [
        // Read-only sync. Used to auto-link sites to Bill.com customers (via
        // domain extraction from invoice line item descriptions) and auto-flip
        // care_plan_enabled based on actual care-plan-item invoicing.
        // Generate dev_key from https://developer.bill.com/ developer console.
        // Disabled by default — set CLOCKWORK_BILL_COM_ENABLED=true once
        // credentials are populated.
        'enabled' => env('CLOCKWORK_BILL_COM_ENABLED', false),
        'username' => env('CLOCKWORK_BILL_COM_USERNAME'),
        'password' => env('CLOCKWORK_BILL_COM_PASSWORD'),
        'org_id' => env('CLOCKWORK_BILL_COM_ORG_ID'),
        'dev_key' => env('CLOCKWORK_BILL_COM_DEV_KEY'),
        // Bill.com docs claim v3 lives at gateway.bill.com — that hostname has
        // NO DNS records. v2 at api.bill.com/api/v2 is what actually works for
        // standard accounts. The client is implemented against v2.
        'base_url' => env('CLOCKWORK_BILL_COM_BASE_URL', 'https://api.bill.com/api/v2'),
        'timeout' => env('CLOCKWORK_BILL_COM_TIMEOUT', 30),
        // Regex applied to Bill.com Item.name — any item whose name matches
        // is treated as a care-plan-revealing line item. Default catches this operator's
        // current "WordPress Care Plan - CW" naming. Override if you add tier
        // variants or rename.
        'care_plan_item_regex' => env('CLOCKWORK_BILL_COM_CARE_PLAN_ITEM_REGEX', '/care plan/i'),
        // Walk this many days of invoice history per sync run. The operator may bill some
        // clients monthly (small invoice every month) and others annually (one
        // big invoice covers 12 months at $169×12 = $2028). The 400-day
        // care-plan window catches both — a yearly invoice from up to ~13
        // months ago still flags the customer. Customer linking goes further
        // back so we can still attach sites whose last invoice was years ago.
        'customer_link_window_days' => env('CLOCKWORK_BILL_COM_CUSTOMER_LINK_WINDOW_DAYS', 1095),
        'care_plan_window_days' => env('CLOCKWORK_BILL_COM_CARE_PLAN_WINDOW_DAYS', 400),
    ],

    // Modules Directory Feed. Queries the official Clockwork Control directory
    // endpoint (or local mirror) for official and community modules.
    'modules' => [
        'api_url' => env('CLOCKWORK_MODULES_API_URL', 'https://clockworkcontrol.com/api/modules.json'),
        'cache_ttl' => (int) env('CLOCKWORK_MODULES_CACHE_TTL', 21600), // 6 hours
    ],

    // System self-update settings for Clockwork Control Core.
    // Operator-triggered via Settings → Updates.
    'updates' => [
        'channel' => env('CLOCKWORK_UPDATE_CHANNEL', 'stable'),
        'repo' => env('CLOCKWORK_UPDATE_REPO', 'Clockwork-Web-Dev-LLC/clockwork-control'),
        'api_url' => env(
            'CLOCKWORK_UPDATES_API_URL',
            'https://api.github.com/repos/Clockwork-Web-Dev-LLC/clockwork-control/releases/latest'
        ),
        'cache_ttl' => (int) env('CLOCKWORK_UPDATES_CACHE_TTL', 43200), // 12 hours
    ],

    // RDAP Domain Expiration & Registrar tracking settings.
    'rdap' => [
        'timeout' => (int) env('CLOCKWORK_RDAP_TIMEOUT', 10),
        'rate_limit_per_10s' => (int) env('CLOCKWORK_RDAP_RATE_LIMIT', 10),
        'tld_backoff_hours' => (int) env('CLOCKWORK_RDAP_TLD_BACKOFF_HOURS', 2),
    ],

    // Accidental noindex / SEO Indexability Watchdog settings.
    'seo' => [
        'monitoring_enabled' => (bool) env('CLOCKWORK_SEO_MONITORING_ENABLED', true),
        'robots_txt_timeout' => (int) env('CLOCKWORK_SEO_ROBOTS_TXT_TIMEOUT', 10),
    ],

    // Fleet-wide care plans policy.
    // When enabled (default): sites can be enrolled in care plans individually,
    // gating automated maintenance, update loops, and routine scans.
    // When disabled: all sites are treated as covered for maintenance,
    // and care plan badges/banners/cards are suppressed across the platform.
    'care_plans' => [
        'enabled' => (bool) env('CLOCKWORK_CARE_PLANS_ENABLED', true),
    ],

    // Anonymous usage telemetry — enabled by default, easily disabled anytime.
    // Sends only a bucketed site count and list of enabled module IDs.
    // Never domains, IPs, emails, database contents, or any identifying data.
    // See resources/docs/reference/env-vars.md for the full disclosure.
    'telemetry' => [
        'enabled' => (bool) env('CLOCKWORK_TELEMETRY_ENABLED', true),
        'endpoint' => env('CLOCKWORK_TELEMETRY_ENDPOINT', 'https://telemetry.clockworkcontrol.com/v1/report'),
    ],

    // Security & outbound network restrictions.
    'security' => [
        // Allow outbound HTTP requests (uptime probes, Companion API calls) to
        // resolve to private / loopback IP ranges (e.g. 192.168.x.x, 10.x.x.x).
        // Defaults to false to protect against SSRF. Enable only in private LAN,
        // homelab, or local staging environments.
        'allow_private_hosts' => (bool) env('CLOCKWORK_ALLOW_PRIVATE_HOSTS', false),
    ],
];
