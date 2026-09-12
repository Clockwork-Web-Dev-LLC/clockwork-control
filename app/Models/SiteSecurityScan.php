<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property string $scan_type
 * @property Carbon $scanned_at
 * @property string $status
 * @property bool $has_malware_hit
 * @property bool $blacklist_hit
 * @property int $modified_files_count
 * @property ?string $summary
 * @property ?array $details
 * @property ?string $error
 * @property ?int $elapsed_ms
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Site $site
 */
class SiteSecurityScan extends Model
{
    use HasFactory;

    public const TYPE_SITECHECK = 'sitecheck';

    public const TYPE_CORE_CHECKSUMS = 'core_checksums';

    public const TYPE_BLACKLIST = 'blacklist';

    public const TYPE_COMPANION_MALWARE = 'companion_malware';

    public const STATUS_CLEAN = 'clean';

    public const STATUS_WARNING = 'warning';

    public const STATUS_ISSUES_FOUND = 'issues_found';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'site_id',
        'scan_type',
        'scanned_at',
        'status',
        'has_malware_hit',
        'blacklist_hit',
        'modified_files_count',
        'summary',
        'details',
        'error',
        'elapsed_ms',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'has_malware_hit' => 'boolean',
            'blacklist_hit' => 'boolean',
            'modified_files_count' => 'integer',
            'details' => 'array',
            'elapsed_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Limit a query to the newest scan of $scanType per site. Implemented as a
     * correlated subquery on (site_id, scan_type) — fast under the composite
     * index added in the migration. Use for fleet inventory + issues pages.
     */
    public function scopeLatestPerSite(Builder $query, string $scanType): void
    {
        $query
            ->where('scan_type', $scanType)
            ->whereIn('id', function ($sub) use ($scanType) {
                $sub->selectRaw('MAX(id)')
                    ->from('site_security_scans')
                    ->where('scan_type', $scanType)
                    ->groupBy('site_id');
            });
    }
}
