<?php

namespace App\Models;

use App\Services\HostingProvider\HostingProviderRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\BillCom\BillComCustomer;
use Modules\ClientManagement\Models\Client;
use Modules\Core\Contracts\HostingProvider;

/**
 * @property int $id
 * @property ?int $spinupwp_id
 * @property ?string $pressable_site_id
 * @property ?string $wpengine_install_name
 * @property ?string $kinsta_environment_id
 * @property ?string $cloudways_app_id
 * @property ?string $gridpane_site_id
 * @property ?int $server_id null for Pressable-hosted sites — Pressable has no server concept
 * @property string $hosting_provider spinupwp|pressable
 * @property string $domain
 * @property ?string $site_user
 * @property ?string $wp_path
 * @property ?string $db_host
 * @property ?int $db_port
 * @property ?string $db_name
 * @property ?string $db_user
 * @property ?string $db_password encrypted at rest
 * @property ?string $table_prefix
 * @property ?string $nginx_access_log_path
 * @property bool $is_wordpress
 * @property bool $is_multisite
 * @property bool $wordfence_enabled
 * @property bool $llar_enabled
 * @property ?bool $wp_core_update
 * @property ?bool $wp_theme_updates
 * @property ?bool $wp_plugin_updates
 * @property ?Carbon $wp_updates_checked_at
 * @property ?Carbon $wp_plugins_detected_at
 * @property ?string $cert_source none|spinupwp_le|external|redirect_only
 * @property ?Carbon $cert_expires_at
 * @property ?Carbon $cert_renews_at
 * @property ?string $cert_notes
 * @property ?string $cert_state none|green|yellow|red
 * @property ?Carbon $cert_state_changed_at
 * @property ?string $cloudflare_state unknown|proxied|dns_only|not_using
 * @property ?Carbon $cloudflare_checked_at
 * @property ?string $resolved_a_record
 * @property ?string $resolved_ns_record
 * @property ?Carbon $archived_at
 * @property ?int $consolidated_into_site_id
 * @property bool $is_inactive
 * @property ?string $inactive_reason
 * @property bool $companion_installed
 * @property ?string $companion_version
 * @property ?array $companion_capabilities
 * @property ?string $companion_secret encrypted at rest
 * @property ?Carbon $companion_last_seen_at
 * @property ?array $companion_snapshot
 * @property ?Carbon $companion_snapshot_at
 * @property ?Carbon $companion_stuck_since
 * @property ?string $companion_stuck_reason
 * @property ?string $contact_form_plugin
 * @property bool $contact_form_test_enabled
 * @property ?Carbon $contact_form_test_subscribed_at
 * @property ?Carbon $contact_form_test_unsubscribed_at
 * @property ?string $client_email
 * @property ?int $client_id
 * @property bool $care_plan_enabled
 * @property bool $backup_relay_enabled
 * @property ?Carbon $backup_relay_last_archived_at
 * @property ?string $bill_com_customer_id
 * @property ?string $bill_com_customer_name
 * @property ?string $bill_com_linked_via_invoice
 * @property ?Carbon $bill_com_linked_at
 * @property ?bool $care_plan_override
 * @property bool $auto_updates_paused
 * @property ?string $auto_updates_paused_reason short note shown as a tooltip in the fleet listing
 * @property ?Carbon $auto_updates_last_run_at last time the nightly loop touched this site
 * @property bool $uptime_monitoring_enabled
 * @property string $uptime_state up|down|unknown
 * @property ?Carbon $uptime_last_checked_at
 * @property ?Carbon $uptime_last_up_at
 * @property ?int $uptime_last_status_code
 * @property int $uptime_consecutive_failures
 * @property ?Carbon $uptime_down_since
 * @property ?Carbon $uptime_ignored_at
 * @property ?string $uptime_ignore_reason
 * @property ?Carbon $sucuri_unavailable_at
 * @property ?string $sucuri_unavailable_reason
 * @property ?Carbon $psi_unavailable_at
 * @property ?string $psi_unavailable_reason
 * @property ?Carbon $resource_metrics_cursor_at
 * @property ?string $contact_form_test_form_id
 * @property ?string $contact_form_test_url
 * @property ?string $contact_form_test_state success|failed|pending
 * @property ?Carbon $contact_form_test_state_changed_at
 * @property ?Carbon $contact_form_last_test_at
 * @property ?string $contact_form_last_test_error
 * @property int $contact_form_test_failure_streak
 * @property ?Carbon $contact_forms_detected_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?Server $server
 * @property-read Collection<int, ThreatLog> $threatLogs
 * @property-read Collection<int, NginxLogCursor> $nginxLogCursors
 * @property-read Collection<int, BlockedIp> $blockedIps
 * @property-read Collection<int, ContactFormTestRun> $contactFormTestRuns
 * @property-read Collection<int, SiteSecurityScan> $securityScans
 * @property-read ?SiteSecurityScan $latestSiteCheckScan
 * @property-read ?SiteSecurityScan $latestChecksumScan
 */
class Site extends Model
{
    use HasFactory;

    public const HOSTING_PROVIDER_SPINUPWP = 'spinupwp';

    public const HOSTING_PROVIDER_PRESSABLE = 'pressable';

    public const HOSTING_PROVIDER_WPENGINE = 'wpengine';

    public const HOSTING_PROVIDER_KINSTA = 'kinsta';

    public const HOSTING_PROVIDER_CLOUDWAYS = 'cloudways';

    public const HOSTING_PROVIDER_GRIDPANE = 'gridpane';

    /**
     * Hosting providers with no server concept at all — server_id is
     * always null, so any host-agnostic query gating on "is this site's
     * hosting eligible for X" must treat these as always-eligible rather
     * than requiring a Server row that will never exist. See
     * scopeHostMonitored() below for the real consumer.
     */
    private const HOSTING_PROVIDERS_WITHOUT_SERVER = [
        self::HOSTING_PROVIDER_PRESSABLE,
        self::HOSTING_PROVIDER_WPENGINE,
        self::HOSTING_PROVIDER_KINSTA,
    ];

    public const CERT_SOURCE_NONE = 'none';

    public const CERT_SOURCE_SPINUPWP_LE = 'spinupwp_le';

    public const CERT_SOURCE_EXTERNAL = 'external';

    // Redirect-only domains (or parked domains, or sites behind CF Flexible SSL) where the
    // origin cert is intentionally ignored. Skipped by SSL state monitoring.
    public const CERT_SOURCE_REDIRECT_ONLY = 'redirect_only';

    // Cert data obtained via a direct TLS handshake (LiveCertProbe) rather than a
    // hosting provider's API — used for any site with no spinupwp_id (Pressable
    // today; any future non-SpinupWP provider automatically gets this too).
    public const CERT_SOURCE_LIVE_PROBE = 'live_probe';

    public const SSL_STATE_NONE = 'none';

    public const SSL_STATE_GREEN = 'green';

    public const SSL_STATE_YELLOW = 'yellow';

    public const SSL_STATE_RED = 'red';

    public const DOMAIN_EXPIRATION_STATE_NONE = 'none';

    public const DOMAIN_EXPIRATION_STATE_GREEN = 'green';

    public const DOMAIN_EXPIRATION_STATE_YELLOW = 'yellow';

    public const DOMAIN_EXPIRATION_STATE_RED = 'red';

    public const CF_UNKNOWN = 'unknown';

    public const CF_PROXIED = 'proxied';

    public const CF_DNS_ONLY = 'dns_only';

    public const CF_NOT_USING = 'not_using';

    public const CONTACT_FORM_PLUGIN_CF7 = 'contact-form-7';

    public const CONTACT_FORM_PLUGIN_WPFORMS = 'wpforms';

    public const CONTACT_FORM_PLUGIN_GRAVITY = 'gravityforms';

    public const FORM_TEST_STATE_PENDING = 'pending';

    public const FORM_TEST_STATE_SUCCESS = 'success';

    public const FORM_TEST_STATE_FAILED = 'failed';

    protected $fillable = [
        'spinupwp_id',
        'pressable_site_id',
        'wpengine_install_name',
        'kinsta_environment_id',
        'cloudways_app_id',
        'gridpane_site_id',
        'server_id',
        'hosting_provider',
        'domain',
        'site_user',
        'wp_path',
        'db_host',
        'db_port',
        'db_name',
        'db_user',
        'db_password',
        'table_prefix',
        'nginx_access_log_path',
        'is_wordpress',
        'is_multisite',
        'wordfence_enabled',
        'llar_enabled',
        'wp_core_update',
        'wp_theme_updates',
        'wp_plugin_updates',
        'wp_updates_checked_at',
        'wp_plugins_detected_at',
        'cert_source',
        'cert_expires_at',
        'cert_renews_at',
        'cert_notes',
        'cert_state',
        'cert_state_changed_at',
        'cloudflare_state',
        'cloudflare_checked_at',
        'resolved_a_record',
        'resolved_ns_record',
        'archived_at',
        'consolidated_into_site_id',
        'is_inactive',
        'inactive_reason',
        'companion_installed',
        'companion_version',
        'companion_capabilities',
        'companion_secret',
        'companion_last_seen_at',
        'companion_snapshot',
        'companion_snapshot_at',
        'companion_stuck_since',
        'companion_stuck_reason',
        'companion_finding_state',
        'contact_form_plugin',
        'detected_forms',
        'contact_form_test_enabled',
        'contact_form_test_subscribed_at',
        'contact_form_test_unsubscribed_at',
        'client_email',
        'care_plan_enabled',
        'backup_relay_enabled',
        'backup_relay_last_archived_at',
        'bill_com_customer_id',
        'bill_com_customer_name',
        'bill_com_linked_via_invoice',
        'bill_com_linked_at',
        'care_plan_override',
        'auto_updates_paused',
        'auto_updates_paused_reason',
        'auto_updates_last_run_at',
        'uptime_monitoring_enabled',
        'uptime_state',
        'uptime_last_checked_at',
        'uptime_last_up_at',
        'uptime_last_status_code',
        'uptime_consecutive_failures',
        'uptime_down_since',
        'uptime_ignored_at',
        'uptime_ignore_reason',
        'sucuri_unavailable_at',
        'sucuri_unavailable_reason',
        'psi_unavailable_at',
        'psi_unavailable_reason',
        'resource_metrics_cursor_at',
        'contact_form_test_form_id',
        'contact_form_test_url',
        'contact_form_test_state',
        'contact_form_test_state_changed_at',
        'contact_form_last_test_at',
        'contact_form_last_test_error',
        'contact_form_test_failure_streak',
        'contact_forms_detected_at',
        'domain_expires_at',
        'domain_registrar',
        'domain_rdap_status',
        'domain_rdap_checked_at',
        'domain_rdap_error',
        'domain_expiration_state',
        'domain_expiration_state_changed_at',
        'seo_indexable',
        'seo_blocked_reason',
        'seo_checked_at',
        'seo_blocked_snippet',
        'seo_monitoring_enabled',
        'seo_state_changed_at',
        'seo_meta_snippet',
        'seo_header_snippet',
        'seo_robots_snippet',
    ];

    protected $hidden = [
        'db_password',
        'companion_secret',
    ];

    protected function casts(): array
    {
        return [
            'db_port' => 'integer',
            'db_password' => 'encrypted',
            'is_wordpress' => 'boolean',
            'is_multisite' => 'boolean',
            'wordfence_enabled' => 'boolean',
            'llar_enabled' => 'boolean',
            'wp_core_update' => 'boolean',
            'wp_theme_updates' => 'boolean',
            'wp_plugin_updates' => 'boolean',
            'wp_updates_checked_at' => 'datetime',
            'wp_plugins_detected_at' => 'datetime',
            'cert_expires_at' => 'datetime',
            'cert_renews_at' => 'datetime',
            'cert_state_changed_at' => 'datetime',
            'cloudflare_checked_at' => 'datetime',
            'archived_at' => 'datetime',
            'is_inactive' => 'boolean',
            'companion_installed' => 'boolean',
            'companion_capabilities' => 'array',
            'companion_secret' => 'encrypted',
            'domain_expires_at' => 'datetime',
            'domain_rdap_checked_at' => 'datetime',
            'domain_expiration_state_changed_at' => 'datetime',
            'seo_indexable' => 'boolean',
            'seo_checked_at' => 'datetime',
            'seo_monitoring_enabled' => 'boolean',
            'seo_state_changed_at' => 'datetime',
            'companion_last_seen_at' => 'datetime',
            'companion_snapshot' => 'array',
            'companion_snapshot_at' => 'datetime',
            'companion_stuck_since' => 'datetime',
            'companion_finding_state' => 'array',
            'detected_forms' => 'array',
            'contact_forms_detected_at' => 'datetime',
            'care_plan_enabled' => 'boolean',
            'backup_relay_enabled' => 'boolean',
            'backup_relay_last_archived_at' => 'datetime',
            'bill_com_linked_at' => 'datetime',
            'care_plan_override' => 'boolean',
            'auto_updates_paused' => 'boolean',
            'auto_updates_last_run_at' => 'datetime',
            'uptime_monitoring_enabled' => 'boolean',
            'uptime_last_checked_at' => 'datetime',
            'uptime_last_up_at' => 'datetime',
            'uptime_last_status_code' => 'integer',
            'uptime_consecutive_failures' => 'integer',
            'uptime_down_since' => 'datetime',
            'uptime_ignored_at' => 'datetime',
            'sucuri_unavailable_at' => 'datetime',
            'psi_unavailable_at' => 'datetime',
            'resource_metrics_cursor_at' => 'datetime',
            'contact_form_test_enabled' => 'boolean',
            'contact_form_test_subscribed_at' => 'datetime',
            'contact_form_test_unsubscribed_at' => 'datetime',
            'contact_form_test_state_changed_at' => 'datetime',
            'contact_form_last_test_at' => 'datetime',
            'contact_form_test_failure_streak' => 'integer',
        ];
    }

    /**
     * Number of plugin updates pending per the cached Companion snapshot.
     * Returns 0 when no snapshot, no plugins key, or the field is missing —
     * older snapshots and Companion < 1.14 may lack it.
     */
    public function getPluginUpdatesAvailableAttribute(): int
    {
        return (int) ($this->companion_snapshot['plugins']['counts']['updates_available'] ?? 0);
    }

    /**
     * Number of theme updates pending. Companion v1.15.0+ surfaces
     * snapshot.themes.counts.updates_available; older versions return 0.
     */
    public function getThemeUpdatesAvailableAttribute(): int
    {
        return (int) ($this->companion_snapshot['themes']['counts']['updates_available'] ?? 0);
    }

    /**
     * True iff WP core has an update available. Companion v1.15.0+ surfaces
     * snapshot.wp_core.update_available; older versions return false.
     */
    public function getCoreUpdateAvailableAttribute(): bool
    {
        return (bool) ($this->companion_snapshot['wp_core']['update_available'] ?? false);
    }

    /**
     * True iff the pending core update is a major-version bump (e.g. 6 → 7).
     * Used to gate the Updates page's confirm dialog.
     */
    public function getCoreUpdateIsMajorAttribute(): bool
    {
        $isMinor = $this->companion_snapshot['wp_core']['is_minor_update'] ?? null;

        return $this->core_update_available && $isMinor === false;
    }

    /**
     * Count of pending translation updates. Companion v1.15.0+; older 0.
     */
    public function getTranslationUpdatesCountAttribute(): int
    {
        return (int) ($this->companion_snapshot['translations']['count'] ?? 0);
    }

    /**
     * Archived sites are excluded from polling, dashboards, and ingest
     * pipelines via this scope. Use Site::query() for the default 'active'
     * set; chain ->withoutGlobalScope('notArchived') (or
     * ->withoutGlobalScopes()) when you genuinely need archived rows
     * (importer dedupe, orphan finder).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('notArchived', function (Builder $query) {
            $query->whereNull("{$query->getModel()->getTable()}.archived_at");
        });
    }

    public function scopeNotArchived(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function scopeBackupRelayEnabled(Builder $query): Builder
    {
        return $query->where('backup_relay_enabled', true);
    }

    /**
     * "Is this site's hosting eligible for monitoring/scan loops at all."
     * SpinupWP sites: gated on their server not being ignored/staging
     * (Server::scopeMonitored). Pressable sites: always eligible — Pressable
     * has no server-level ignore/staging concept to check. Use this instead
     * of `whereHas('server', fn ($q) => $q->monitored())` directly so every
     * host-agnostic and Companion-gated command covers both providers
     * uniformly. Commands that are inherently SSH/server-specific (nginx log
     * tailing, apt updates, fail2ban, wp-config extraction) should keep the
     * plain server-only check — those genuinely have no Pressable
     * equivalent yet.
     */
    public function scopeHostMonitored(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereHas('server', fn (Builder $sq) => $sq->monitored())
                ->orWhereIn('hosting_provider', self::HOSTING_PROVIDERS_WITHOUT_SERVER);
        });
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function billComCustomer(): BelongsTo
    {
        return $this->belongsTo(BillComCustomer::class, 'bill_com_customer_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ignoredIssues(): HasMany
    {
        return $this->hasMany(IgnoredIssue::class);
    }

    public function isIssueIgnored(string $issueType): bool
    {
        return $this->relationLoaded('ignoredIssues')
            ? $this->ignoredIssues->contains('issue_type', $issueType)
            : $this->ignoredIssues()->where('issue_type', $issueType)->exists();
    }

    /**
     * Identity check — "is this specifically Pressable" (native UI tools,
     * transport quirks like edge-cache purging, import guards). For a
     * cross-provider behavioral question that should generalize to a future
     * third provider, use $site->host()->supports(HostingProvider::CAP_*)
     * instead. Both are valid; they answer different questions.
     */
    public function isPressable(): bool
    {
        return $this->hosting_provider === self::HOSTING_PROVIDER_PRESSABLE;
    }

    /** Identity check — see isPressable() docblock. */
    public function isSpinupWp(): bool
    {
        return $this->hosting_provider === self::HOSTING_PROVIDER_SPINUPWP;
    }

    /** Identity check — see isPressable() docblock. */
    public function isWpEngine(): bool
    {
        return $this->hosting_provider === self::HOSTING_PROVIDER_WPENGINE;
    }

    /** Identity check — see isPressable() docblock. */
    public function isKinsta(): bool
    {
        return $this->hosting_provider === self::HOSTING_PROVIDER_KINSTA;
    }

    /** Identity check — see isPressable() docblock. */
    public function isCloudways(): bool
    {
        return $this->hosting_provider === self::HOSTING_PROVIDER_CLOUDWAYS;
    }

    public function host(): HostingProvider
    {
        return app(HostingProviderRegistry::class)->resolve($this->hosting_provider);
    }

    /**
     * Whether traffic reporting is supported for this site.
     *
     * Traffic stats require either:
     * 1. Pressable API metrics (when on Pressable hosting and configured)
     * 2. Or access-log tailing over SSH on a linked server that has working
     *    or configured SSH credentials (e.g. SpinupWP, GridPane, custom VPS).
     *
     * If a host doesn't support SSH (and isn't Pressable) or if the server
     * lacks SSH setup, this returns false so Companion can hide the Traffic section.
     */
    public function supportsTrafficReport(): bool
    {
        if ($this->isPressable()) {
            return $this->host()->isConfigured();
        }

        if (! $this->host()->supports(HostingProvider::CAP_SSH)) {
            return false;
        }

        if (! $this->server) {
            return false;
        }

        return $this->server->hasSshWorking() || $this->server->hasSshConfigured();
    }

    public function threatLogs(): HasMany
    {
        return $this->hasMany(ThreatLog::class);
    }

    public function nginxLogCursors(): HasMany
    {
        return $this->hasMany(NginxLogCursor::class);
    }

    public function blockedIps(): HasMany
    {
        return $this->hasMany(BlockedIp::class);
    }

    /**
     * @return HasMany<ContactFormTestRun, $this>
     */
    public function contactFormTestRuns(): HasMany
    {
        return $this->hasMany(ContactFormTestRun::class);
    }

    public function contactFormTests(): HasMany
    {
        return $this->hasMany(ContactFormTest::class)->orderBy('slot');
    }

    public function securityScans(): HasMany
    {
        return $this->hasMany(SiteSecurityScan::class);
    }

    public function uptimeEvents(): HasMany
    {
        return $this->hasMany(SiteUptimeEvent::class);
    }

    public function performanceScans(): HasMany
    {
        return $this->hasMany(SitePerformanceScan::class);
    }

    /**
     * Latest mobile-strategy performance scan. Mobile is Google's authoritative
     * SEO ranking surface — it's the "headline" score on the per-site tab.
     */
    public function latestPerformanceScanMobile(): HasOne
    {
        return $this->hasOne(SitePerformanceScan::class)
            ->ofMany(
                ['scanned_at' => 'max', 'id' => 'max'],
                fn ($query) => $query
                    ->where('strategy', SitePerformanceScan::STRATEGY_MOBILE)
                    ->where('status', SitePerformanceScan::STATUS_OK),
            );
    }

    /**
     * Latest desktop-strategy performance scan. The score most clients identify
     * with, since they evaluate their own site from a laptop most of the time.
     */
    public function latestPerformanceScanDesktop(): HasOne
    {
        return $this->hasOne(SitePerformanceScan::class)
            ->ofMany(
                ['scanned_at' => 'max', 'id' => 'max'],
                fn ($query) => $query
                    ->where('strategy', SitePerformanceScan::STRATEGY_DESKTOP)
                    ->where('status', SitePerformanceScan::STATUS_OK),
            );
    }

    /**
     * Latest Sucuri SiteCheck row for this site (any status). Drives the
     * per-site Security tab "SiteCheck" card and the fleet inventory pill.
     *
     * IMPORTANT: must use ofMany() with a constraint closure rather than
     * `->where(...)->latestOfMany('scanned_at')`. The latter applies the
     * scan_type filter only AFTER the subquery picks the globally-latest
     * scanned_at — so if the most recent scan on the site is a checksum
     * run, the SiteCheck relation silently resolves to NULL even though a
     * SiteCheck row exists. The closure form pushes the filter INTO the
     * subquery, so "latest of this type" works correctly per scan type.
     */
    public function latestSiteCheckScan(): HasOne
    {
        return $this->hasOne(SiteSecurityScan::class)
            ->ofMany(
                ['scanned_at' => 'max', 'id' => 'max'],
                fn ($query) => $query->where('scan_type', SiteSecurityScan::TYPE_SITECHECK),
            );
    }

    /**
     * Latest wp core verify-checksums row for this site (any status). See
     * latestSiteCheckScan() for why ofMany() is used instead of latestOfMany().
     */
    public function latestChecksumScan(): HasOne
    {
        return $this->hasOne(SiteSecurityScan::class)
            ->ofMany(
                ['scanned_at' => 'max', 'id' => 'max'],
                fn ($query) => $query->where('scan_type', SiteSecurityScan::TYPE_CORE_CHECKSUMS),
            );
    }

    /**
     * Compute the current SSL state for this site.
     *
     * - none: no cert tracked (cert_source=none or no expiry stored)
     * - green: cert valid, renewal date hasn't passed yet (or no renewal date for external certs)
     * - yellow: renewal date passed without the cert rolling forward — for SpinupWP/LE,
     *   that means LE auto-renew failed and we have ~6 weeks before expiry. For external,
     *   it means we're inside 30 days of expiry.
     * - red: cert has expired.
     */
    public function sslState(): string
    {
        if (in_array($this->cert_source, [self::CERT_SOURCE_NONE, self::CERT_SOURCE_REDIRECT_ONLY], true)
            || ! $this->cert_expires_at) {
            return self::SSL_STATE_NONE;
        }

        $now = Carbon::now();

        if ($this->cert_expires_at->lessThanOrEqualTo($now)) {
            return self::SSL_STATE_RED;
        }

        if ($this->cert_source === self::CERT_SOURCE_SPINUPWP_LE) {
            // LE missed-renewal warning: renewal window passed AND grace period elapsed
            // without the cert being replaced. The grace gives SpinupWP/LE time to actually
            // run the renewal cron — they don't fire at midnight on the renews date.
            $graceHours = (int) config('clockwork.monitoring.ssl_renewal_grace_hours', 48);
            if ($this->cert_renews_at && $this->cert_renews_at->copy()->addHours($graceHours)->lessThanOrEqualTo($now)) {
                return self::SSL_STATE_YELLOW;
            }

            return self::SSL_STATE_GREEN;
        }

        // External certs: warn at 30 days, otherwise green.
        // Carbon 3 returns SIGNED diffs — receiver later than argument is
        // negative. Here cert_expires_at is in the future (we already
        // returned RED above if it's expired), so $now->diffInDays($expires)
        // gives a positive number of days until expiry.
        if ($now->diffInDays($this->cert_expires_at) <= 30) {
            return self::SSL_STATE_YELLOW;
        }

        return self::SSL_STATE_GREEN;
    }

    public function sslStateLabel(): string
    {
        return match ($this->sslState()) {
            self::SSL_STATE_GREEN => 'SSL ok',
            self::SSL_STATE_YELLOW => 'SSL renewal',
            self::SSL_STATE_RED => 'SSL expired',
            default => 'No SSL',
        };
    }

    /**
     * Compute the current domain-expiration state for this site.
     *
     * - none: no expiration data yet (never RDAP-checked, or lookup failed every time).
     * - green: > 30 days until expiration.
     * - yellow: <= 30 days until expiration.
     * - red: <= 7 days until expiration, OR the RDAP status includes redemptionPeriod/pendingDelete
     *   (the domain may already be functionally lost even if the raw date hasn't passed).
     */
    public function domainExpirationState(): string
    {
        if (! $this->domain_expires_at) {
            return self::DOMAIN_EXPIRATION_STATE_NONE;
        }

        // Registries return this status in inconsistent shapes — some the
        // legacy EPP camelCase ('redemptionPeriod'), most the IANA RDAP
        // JSON status registry's own lowercase, space-separated form
        // ('redemption period') — so match case-insensitively against both.
        $badStatuses = ['redemptionperiod', 'redemption period', 'pendingdelete', 'pending delete'];
        if ($this->domain_rdap_status && in_array(strtolower($this->domain_rdap_status), $badStatuses, true)) {
            return self::DOMAIN_EXPIRATION_STATE_RED;
        }

        $now = Carbon::now();
        if ($this->domain_expires_at->lessThanOrEqualTo($now)) {
            return self::DOMAIN_EXPIRATION_STATE_RED;
        }

        $daysLeft = (int) $now->diffInDays($this->domain_expires_at, false);
        if ($daysLeft <= 7) {
            return self::DOMAIN_EXPIRATION_STATE_RED;
        }

        if ($daysLeft <= 30) {
            return self::DOMAIN_EXPIRATION_STATE_YELLOW;
        }

        return self::DOMAIN_EXPIRATION_STATE_GREEN;
    }

    public function domainExpirationStateLabel(): string
    {
        return match ($this->domainExpirationState()) {
            self::DOMAIN_EXPIRATION_STATE_GREEN => 'Domain OK',
            self::DOMAIN_EXPIRATION_STATE_YELLOW => 'Domain renewal',
            self::DOMAIN_EXPIRATION_STATE_RED => 'Domain expiring',
            default => 'No domain data',
        };
    }

    public function seoStatusLabel(): string
    {
        if (! $this->seo_monitoring_enabled) {
            return 'Disabled';
        }

        if ($this->server?->isStaging()) {
            return $this->seo_indexable ? 'Indexable' : 'Protected from Search';
        }

        return $this->seo_indexable ? 'Indexable' : 'Blocking Search Engines';
    }

    /**
     * "Ignored" = probe still runs + state still recorded, but the site is
     * suppressed from Issues, the nav badge, and Mattermost alerts. Distinct
     * from `uptime_monitoring_enabled = false`, which stops probing entirely.
     * Use case: site is known down indefinitely (client offline, paused,
     * etc.) and we want to keep monitoring quietly so we know when it
     * recovers without polluting the daily work queue meanwhile.
     */
    public function isUptimeIgnored(): bool
    {
        return $this->uptime_ignored_at !== null;
    }
}
