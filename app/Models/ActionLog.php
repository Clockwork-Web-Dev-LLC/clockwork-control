<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per meaningful action Clockwork takes. Written by ActionLogger;
 * read by the per-site Recent activity card and (future) cross-site
 * maintenance-history page.
 *
 * @property int $id
 * @property ?int $site_id
 * @property ?int $server_id
 * @property string $action_type
 * @property ?string $target
 * @property string $summary
 * @property ?array $details
 * @property bool $ok
 * @property ?string $error
 * @property ?int $elapsed_ms
 * @property string $actor
 * @property Carbon $ran_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?Site $site
 * @property-read ?Server $server
 */
class ActionLog extends Model
{
    use HasFactory;

    public const TYPE_PLUGIN_UPDATE = 'plugin_update';

    public const TYPE_THEME_UPDATE = 'theme_update';

    public const TYPE_CORE_UPDATE = 'core_update';

    public const TYPE_TRANSLATIONS_UPDATE = 'translations_update';

    public const TYPE_SSO_LOGIN = 'sso_login';

    public const TYPE_COMPANION_INSTALL = 'companion_install';

    public const TYPE_COMPANION_UPDATE = 'companion_update';

    public const TYPE_COMPANION_UNINSTALL = 'companion_uninstall';

    public const TYPE_MANUAL_BAN = 'manual_ban';

    public const TYPE_MANUAL_UNBAN = 'manual_unban';

    public const TYPE_REVIEW_APPROVE = 'review_approve';

    public const TYPE_REVIEW_DISMISS = 'review_dismiss';

    public const TYPE_CARE_PLAN_TOGGLED = 'care_plan_toggled';

    public const TYPE_SITE_DEACTIVATED = 'site_deactivated';

    public const TYPE_SITE_REACTIVATED = 'site_reactivated';

    public const TYPE_AUTO_UPDATES_TOGGLED = 'auto_updates_toggled';

    public const TYPE_SECURITY_SCAN = 'security_scan';

    public const TYPE_BILL_COM_SYNC = 'bill_com_sync';

    public const TYPE_UPTIME_TRANSITION = 'uptime_transition';

    public const TYPE_UPTIME_IGNORED = 'uptime_ignored';

    public const TYPE_UPTIME_UNIGNORED = 'uptime_unignored';

    public const TYPE_PERFORMANCE_SCAN = 'performance_scan';

    public const TYPE_COMPANION_SECRET_ROTATED = 'companion_secret_rotated';

    public const TYPE_SERVER_UPDATE_REAPED = 'server_update_reaped';

    public const TYPE_SERVER_UPDATE_FAILED = 'server_update_failed';

    public const TYPE_WP_CORE_REPAIRED = 'wp_core_repaired';

    // App auth events. The `user_id` of the actor is on the action_logs row
    // for free (Laravel Auth populates it via the actor pipeline if we wire
    // ActionLogger to read Auth::id() — but for now we record actor='auth' and
    // include the email in the summary).
    public const TYPE_LOGIN = 'login';

    public const TYPE_USER_ADDED = 'user_added';

    public const TYPE_USER_REVOKED = 'user_revoked';

    public const TYPE_USER_RESTORED = 'user_restored';

    public const TYPE_USER_PASSWORD_CHANGED = 'user_password_changed';

    public const TYPE_INSTALLER_REOPENED = 'installer_reopened';

    // ManageWP parity features (Comment Moderation, Code Snippets, Site Maintenance)
    public const TYPE_COMMENTS_MODERATED = 'comments_moderated';

    public const TYPE_COMMENTS_CLEANUP = 'comments_cleanup';

    public const TYPE_CODE_SNIPPET_EXECUTED = 'code_snippet_executed';

    public const TYPE_MAINTENANCE_MODE_TOGGLED = 'maintenance_mode_toggled';

    /**
     * The four update-job kinds, grouped for the maintenance-history
     * "All updates" quick filter — kept here so any future caller that
     * needs "is this row an update" doesn't have to re-enumerate.
     *
     * @var array<int, string>
     */
    public const UPDATE_TYPES = [
        self::TYPE_PLUGIN_UPDATE,
        self::TYPE_THEME_UPDATE,
        self::TYPE_CORE_UPDATE,
        self::TYPE_TRANSLATIONS_UPDATE,
    ];

    protected $fillable = [
        'site_id',
        'server_id',
        'action_type',
        'target',
        'summary',
        'details',
        'ok',
        'error',
        'elapsed_ms',
        'actor',
        'ran_at',
        'companion_pushed_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'ok' => 'boolean',
            'elapsed_ms' => 'integer',
            'ran_at' => 'datetime',
            'companion_pushed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
