<?php

namespace Modules\Seo\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One crawl of one site.
 *
 * @property int $id
 * @property int $site_id
 * @property string $provider
 * @property ?string $task_id
 * @property string $status
 * @property int $max_pages
 * @property bool $javascript
 * @property ?int $pages_crawled
 * @property ?float $score
 * @property ?array<string, mixed> $totals
 * @property ?array<string, int> $check_counts
 * @property ?array<string, bool> $site_checks
 * @property float $cost_usd
 * @property ?string $error
 * @property ?int $started_by
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property int $attempts
 */
class SiteAudit extends Model
{
    public const STATUS_CRAWLING = 'crawling';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $table = 'seo_site_audits';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'max_pages' => 'integer', 'javascript' => 'boolean', 'pages_crawled' => 'integer', 'score' => 'float',
            'totals' => 'array', 'check_counts' => 'array', 'site_checks' => 'array', 'cost_usd' => 'float',
            'started_at' => 'datetime', 'finished_at' => 'datetime', 'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<SiteAuditPage, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(SiteAuditPage::class, 'audit_id');
    }
}
