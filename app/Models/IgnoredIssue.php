<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Represents an issue explicitly ignored / suppressed by an operator from /issues.
 *
 * @property int $id
 * @property string $issue_type e.g. 'seo_indexability'
 * @property ?int $site_id
 * @property ?int $server_id
 * @property ?string $reason
 * @property ?int $ignored_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ?Site $site
 * @property-read ?Server $server
 * @property-read ?User $user
 */
class IgnoredIssue extends Model
{
    use HasFactory;

    public const TYPE_SEO_INDEXABILITY = 'seo_indexability';

    public const TYPE_WP_ADMIN_FLAGGED = 'wp_admin_flagged';

    protected $fillable = [
        'issue_type',
        'site_id',
        'server_id',
        'reason',
        'ignored_by_user_id',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by_user_id');
    }
}
