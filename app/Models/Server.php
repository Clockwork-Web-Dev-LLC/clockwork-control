<?php

namespace App\Models;

use App\Services\CloudProvider\CloudProviderRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $hostname
 * @property int $ssh_port
 * @property string $ssh_user
 * @property ?string $ssh_private_key encrypted at rest
 * @property ?string $ssh_password encrypted at rest
 * @property ?int $spinupwp_id
 * @property string $provider e.g. "digitalocean", "hetzner" — which cloud owns this server
 * @property ?string $provider_id the droplet/server id within the provider's API
 * @property ?string $size_slug e.g. "s-2vcpu-4gb" (DO Basic) or "cx21" (Hetzner shared)
 * @property ?int $vcpus
 * @property ?int $memory_mb RAM in megabytes per the provider payload
 * @property ?int $disk_gb Local disk in gigabytes per the provider payload
 * @property string $status green|yellow|red|unknown
 * @property bool $is_ignored
 * @property ?string $ignore_reason
 * @property ?Carbon $last_polled_at
 * @property ?Carbon $provider_missing_since set the first time a poll finds this server's provider_id absent from the cloud provider's own inventory; cleared on the next successful poll
 * @property ?Carbon $last_alert_at
 * @property ?Carbon $last_ssh_ok_at
 * @property ?Carbon $clockwork_jail_provisioned_at
 * @property ?string $last_provision_log
 * @property bool $auto_ban_llar
 * @property ?Carbon $last_llar_pull_at
 * @property bool $auto_ban_wordfence
 * @property ?Carbon $last_wordfence_pull_at
 * @property ?string $ubuntu_version
 * @property bool $upgrade_required
 * @property bool $reboot_required
 * @property ?string $update_status queued|running|completed|failed
 * @property ?Carbon $update_queued_at
 * @property ?Carbon $update_started_at
 * @property ?Carbon $update_completed_at
 * @property ?string $last_update_log
 * @property ?Carbon $scheduled_reboot_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Collection<int, Site> $sites
 * @property-read Collection<int, BlockedIp> $blockedIps
 * @property-read Collection<int, ServerMetric> $metrics
 * @property-read Collection<int, Tag> $tags
 * @property-read string $display_name short form of `name` for UI tables — strips configured hostname suffix
 * @property-read string $provider_label human label for `$provider` (e.g. "DigitalOcean droplet")
 */
class Server extends Model
{
    use HasFactory;

    public const STATUS_GREEN = 'green';

    public const STATUS_YELLOW = 'yellow';

    public const STATUS_RED = 'red';

    public const STATUS_UNKNOWN = 'unknown';

    public const UPDATE_STATUS_QUEUED = 'queued';

    public const UPDATE_STATUS_RUNNING = 'running';

    public const UPDATE_STATUS_COMPLETED = 'completed';

    public const UPDATE_STATUS_FAILED = 'failed';

    public const PROVIDER_DIGITALOCEAN = 'digitalocean';

    public const PROVIDER_HETZNER = 'hetzner';

    public const PROVIDER_AZURE = 'azure';

    public const PROVIDER_CLOUDWAYS = 'cloudways';

    public const PROVIDER_VULTR = 'vultr';

    public const PROVIDER_LINODE = 'linode';

    public const PROVIDER_GRIDPANE = 'gridpane';

    /**
     * Default attribute values. `provider` mirrors the DB-level default so
     * an in-memory Server is consistent with what comes back after a
     * round-trip. New rows that don't explicitly set a provider land as
     * DigitalOcean (matches the historical assumption).
     */
    protected $attributes = [
        'provider' => self::PROVIDER_DIGITALOCEAN,
    ];

    protected $fillable = [
        'name',
        'hostname',
        'ssh_port',
        'ssh_user',
        'ssh_private_key',
        'ssh_password',
        'spinupwp_id',
        'provider',
        'provider_id',
        'size_slug',
        'vcpus',
        'memory_mb',
        'disk_gb',
        'status',
        'is_ignored',
        'ignore_reason',
        'last_polled_at',
        'last_alert_at',
        'last_ssh_ok_at',
        'clockwork_jail_provisioned_at',
        'last_provision_log',
        'auto_ban_llar',
        'last_llar_pull_at',
        'auto_ban_wordfence',
        'last_wordfence_pull_at',
        'ubuntu_version',
        'upgrade_required',
        'reboot_required',
        'update_status',
        'update_queued_at',
        'update_started_at',
        'update_completed_at',
        'last_update_log',
        'scheduled_reboot_at',
    ];

    protected $hidden = [
        'ssh_private_key',
        'ssh_password',
    ];

    protected function casts(): array
    {
        return [
            'ssh_port' => 'integer',
            'ssh_private_key' => 'encrypted',
            'ssh_password' => 'encrypted',
            'is_ignored' => 'boolean',
            'auto_ban_llar' => 'boolean',
            'auto_ban_wordfence' => 'boolean',
            'upgrade_required' => 'boolean',
            'reboot_required' => 'boolean',
            'last_wordfence_pull_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'provider_missing_since' => 'datetime',
            'last_alert_at' => 'datetime',
            'last_ssh_ok_at' => 'datetime',
            'clockwork_jail_provisioned_at' => 'datetime',
            'last_llar_pull_at' => 'datetime',
            'update_queued_at' => 'datetime',
            'update_started_at' => 'datetime',
            'update_completed_at' => 'datetime',
            'scheduled_reboot_at' => 'datetime',
        ];
    }

    /**
     * Short server name for UI display. If your fleet's servers all share a
     * constant hostname suffix (e.g. `.example.com`), set
     * `CLOCKWORK_SERVER_DISPLAY_NAME_STRIP_SUFFIX` and it becomes noise-free
     * in tables — e.g. "web36" instead of "web36.example.com".
     * Full FQDN stays in `name` because SSH, alerting, and audit logs all
     * key off it. Use `$server->display_name` in views, `$server->name`
     * everywhere else. No-op (returns `name` unchanged) when unset.
     */
    public function getDisplayNameAttribute(): string
    {
        $suffix = (string) config('clockwork.monitoring.display_name_strip_suffix', '');

        return $suffix === '' ? (string) $this->name : str_replace($suffix, '', (string) $this->name);
    }

    /**
     * Human-friendly provider label used in tooltips ("DigitalOcean droplet
     * #123", "Hetzner server #456"). Falls back to the raw provider string
     * for any future provider we haven't taught the UI about yet.
     */
    /**
     * Unlike the registry's dispatch resolve() (which defaults unrecognized
     * providers to DigitalOcean so polling/reconciling still works for
     * legacy rows), an unrecognized provider here shows its own raw string
     * rather than falsely claiming to be DigitalOcean — a label is user-
     * facing text, not an API-dispatch decision.
     */
    public function getProviderLabelAttribute(): string
    {
        if ($this->provider === self::PROVIDER_GRIDPANE) {
            return 'GridPane server';
        }

        if (! in_array($this->provider, [self::PROVIDER_DIGITALOCEAN, self::PROVIDER_HETZNER, self::PROVIDER_AZURE, self::PROVIDER_CLOUDWAYS, self::PROVIDER_VULTR, self::PROVIDER_LINODE], true)) {
            return (string) $this->provider;
        }

        return app(CloudProviderRegistry::class)->resolve($this->provider)->label();
    }

    /**
     * True when this server is provisioned/managed via GridPane.
     */
    public function isGridPane(): bool
    {
        if ($this->provider === self::PROVIDER_GRIDPANE) {
            return true;
        }

        if ($this->relationLoaded('sites')) {
            return $this->sites->contains(fn (Site $site) => $site->hosting_provider === Site::HOSTING_PROVIDER_GRIDPANE);
        }

        return false;
    }

    /**
     * True when this server is provisioned/managed via SpinupWP.
     */
    public function isSpinupWp(): bool
    {
        return $this->spinupwp_id !== null;
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function blockedIps(): HasMany
    {
        return $this->hasMany(BlockedIp::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Servers we should actively probe / scan / poll. Combines the manual
     * `is_ignored` flag with the `staging` tag — a staging server stays
     * visible in the dashboard (operators want to see it) but its sites
     * aren't probed and don't fire alerts.
     */
    public function scopeMonitored(Builder $query): Builder
    {
        return $query
            ->where('is_ignored', false)
            ->whereDoesntHave('tags', fn (Builder $q) => $q->where('slug', 'staging'));
    }

    /**
     * True when this server is tagged `staging` — its sites are excluded from
     * monitoring loops via `scopeMonitored`. Cheap accessor used by the
     * server-card UI to render the "Not monitored" annotation.
     */
    public function isStaging(): bool
    {
        return $this->tags->contains(fn (Tag $t) => $t->slug === 'staging');
    }

    /**
     * Servers eligible for the OS-level apt-get update/upgrade pipeline
     * (queue/drain via ServerUpdateController + clockwork:process-server-updates).
     * Deliberately narrower than `scopeMonitored` — staging servers still don't
     * get probed/alerted/WP-updated automatically, but operators do want to be
     * able to patch the box itself, so system updates aren't gated on the
     * `staging` tag the way the rest of the monitoring loops are.
     */
    public function scopeEligibleForSystemUpdates(Builder $query): Builder
    {
        return $query->where('is_ignored', false);
    }

    /**
     * Latest apt-update snapshot. Schema constrains one row per server, so
     * the relation is a hasOne — the daily PollSystemUpdates command upserts
     * via `updateOrCreate` on `server_id`.
     */
    public function updateSnapshot(): HasOne
    {
        return $this->hasOne(ServerUpdateSnapshot::class);
    }

    /**
     * True when SSH credentials (private key, password, or system default key)
     * are configured for this server.
     */
    public function hasSshConfigured(): bool
    {
        if (! empty($this->ssh_private_key) || ! empty($this->ssh_password)) {
            return true;
        }

        $defaultKeyPath = (string) config('clockwork.ssh.default_key_path', '');

        return $defaultKeyPath !== '' && file_exists($defaultKeyPath);
    }

    /**
     * True when this server has had at least one successful SSH connection.
     */
    public function hasSshWorking(): bool
    {
        return $this->last_ssh_ok_at !== null;
    }
}
