<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per allowlisted path on a site. Bucket is one of:
 * - 'modified'         → file present but doesn't match the core manifest
 * - 'missing'          → core file expected but not present
 * - 'unexpected'       → file in core dirs that isn't in the manifest
 * - '*'                → match any bucket (operator's "this path is always fine")
 *
 * Application-level uniqueness on (site_id, path, bucket) — see
 * scopeMatchingExisting() — because MySQL's key length limit prevents a
 * native unique index on the long path column.
 */
class SiteCoreChecksumAllowlist extends Model
{
    use HasFactory;

    public const BUCKET_MODIFIED = 'modified';

    public const BUCKET_MISSING = 'missing';

    public const BUCKET_UNEXPECTED = 'unexpected';

    public const BUCKET_ANY = '*';

    public const BUCKETS = [
        self::BUCKET_MODIFIED,
        self::BUCKET_MISSING,
        self::BUCKET_UNEXPECTED,
        self::BUCKET_ANY,
    ];

    protected $table = 'site_core_checksum_allowlist';

    protected $fillable = [
        'site_id',
        'path',
        'bucket',
        'reason',
        'added_by_user_id',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }
}
