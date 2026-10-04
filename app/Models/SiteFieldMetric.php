<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteFieldMetric extends Model
{
    public const FORM_FACTOR_PHONE = 'phone';

    public const FORM_FACTOR_DESKTOP = 'desktop';

    public const STATUS_OK = 'ok';

    public const STATUS_NO_DATA = 'no_data';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'site_id',
        'form_factor',
        'scope',
        'status',
        'lcp_p75_ms',
        'inp_p75_ms',
        'fcp_p75_ms',
        'ttfb_p75_ms',
        'cls_p75_x1000',
        'good_pct',
        'cwv_pass',
        'period_start',
        'period_end',
        'collected_at',
        'error',
    ];

    protected $casts = [
        'good_pct' => 'array',
        'cwv_pass' => 'boolean',
        'period_start' => 'date',
        'period_end' => 'date',
        'collected_at' => 'datetime',
        'lcp_p75_ms' => 'integer',
        'inp_p75_ms' => 'integer',
        'fcp_p75_ms' => 'integer',
        'ttfb_p75_ms' => 'integer',
        'cls_p75_x1000' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function lcpFormatted(): string
    {
        return $this->lcp_p75_ms !== null ? round($this->lcp_p75_ms / 1000, 2).'s' : '—';
    }

    public function inpFormatted(): string
    {
        return $this->inp_p75_ms !== null ? $this->inp_p75_ms.'ms' : '—';
    }

    public function fcpFormatted(): string
    {
        return $this->fcp_p75_ms !== null ? round($this->fcp_p75_ms / 1000, 2).'s' : '—';
    }

    public function ttfbFormatted(): string
    {
        return $this->ttfb_p75_ms !== null ? $this->ttfb_p75_ms.'ms' : '—';
    }

    public function clsFormatted(): string
    {
        return $this->cls_p75_x1000 !== null ? number_format($this->cls_p75_x1000 / 1000, 2) : '—';
    }

    /**
     * Rating based on Google's official Core Web Vitals thresholds.
     * Returns: 'good' | 'needs_improvement' | 'poor' | 'unknown'
     */
    public function ratingForMetric(string $metric): string
    {
        return match (strtolower($metric)) {
            'lcp' => $this->lcp_p75_ms === null ? 'unknown' : ($this->lcp_p75_ms <= 2500 ? 'good' : ($this->lcp_p75_ms <= 4000 ? 'needs_improvement' : 'poor')),
            'inp' => $this->inp_p75_ms === null ? 'unknown' : ($this->inp_p75_ms <= 200 ? 'good' : ($this->inp_p75_ms <= 500 ? 'needs_improvement' : 'poor')),
            'cls' => $this->cls_p75_x1000 === null ? 'unknown' : ($this->cls_p75_x1000 <= 100 ? 'good' : ($this->cls_p75_x1000 <= 250 ? 'needs_improvement' : 'poor')),
            'fcp' => $this->fcp_p75_ms === null ? 'unknown' : ($this->fcp_p75_ms <= 1800 ? 'good' : ($this->fcp_p75_ms <= 3000 ? 'needs_improvement' : 'poor')),
            'ttfb' => $this->ttfb_p75_ms === null ? 'unknown' : ($this->ttfb_p75_ms <= 800 ? 'good' : ($this->ttfb_p75_ms <= 1800 ? 'needs_improvement' : 'poor')),
            default => 'unknown',
        };
    }
}
