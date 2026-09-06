<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per performance scan run. See migration for column purposes.
 *
 * @property int $id
 * @property int $site_id
 * @property Carbon $scanned_at
 * @property string $status
 * @property string $strategy
 * @property ?Carbon $source_generated_at
 * @property ?int $performance_score
 * @property ?int $accessibility_score
 * @property ?int $best_practices_score
 * @property ?int $seo_score
 * @property ?int $lcp_ms
 * @property ?int $fcp_ms
 * @property ?int $tbt_ms
 * @property ?int $si_ms
 * @property ?int $cls_x1000
 * @property ?int $page_weight_bytes
 * @property ?int $request_count
 * @property ?string $page_url
 * @property ?string $region
 * @property ?string $error
 * @property ?int $elapsed_ms
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Site $site
 */
class SitePerformanceScan extends Model
{
    use HasFactory;

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STRATEGY_MOBILE = 'mobile';

    public const STRATEGY_DESKTOP = 'desktop';

    public const ENGINE_GTMETRIX = 'gtmetrix';

    public const ENGINE_PSI = 'psi';

    public const ENGINE_PSI_FALLBACK = 'psi-fallback';

    /** Pressable's own built-in Lighthouse report — used exclusively for Pressable-hosted sites. */
    public const ENGINE_PRESSABLE = 'pressable';

    protected $fillable = [
        'site_id',
        'scanned_at',
        'status',
        'strategy',
        'engine',
        'source_generated_at',
        'performance_score',
        'accessibility_score',
        'best_practices_score',
        'seo_score',
        'lcp_ms',
        'fcp_ms',
        'tbt_ms',
        'si_ms',
        'cls_x1000',
        'page_weight_bytes',
        'request_count',
        'page_url',
        'region',
        'error',
        'elapsed_ms',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'source_generated_at' => 'datetime',
            'performance_score' => 'integer',
            'accessibility_score' => 'integer',
            'best_practices_score' => 'integer',
            'seo_score' => 'integer',
            'lcp_ms' => 'integer',
            'fcp_ms' => 'integer',
            'tbt_ms' => 'integer',
            'si_ms' => 'integer',
            'cls_x1000' => 'integer',
            'page_weight_bytes' => 'integer',
            'request_count' => 'integer',
            'elapsed_ms' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Convert the integer-stored CLS (×1000) back to the conventional decimal.
     * Lighthouse reports CLS as 0.00 — 1.00+ in dashboards.
     */
    public function clsValue(): ?float
    {
        return $this->cls_x1000 === null ? null : $this->cls_x1000 / 1000.0;
    }

    /**
     * Lighthouse-style letter grade derived from the 0-100 performance score.
     * Matches Google PSI's color thresholds: green ≥ 90, orange 50–89, red < 50.
     */
    public function letterGrade(): ?string
    {
        if ($this->performance_score === null) {
            return null;
        }

        return match (true) {
            $this->performance_score >= 90 => 'A',
            $this->performance_score >= 75 => 'B',
            $this->performance_score >= 50 => 'C',
            $this->performance_score >= 30 => 'D',
            default => 'E',
        };
    }
}
