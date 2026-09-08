<?php

namespace Modules\ClientReports\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property array $sections
 * @property bool $is_default
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ClientReportTemplate extends Model
{
    use HasFactory;

    protected $table = 'client_report_templates';

    protected $fillable = [
        'name',
        'sections',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public const SECTIONS = [
        'updates' => [
            'label' => 'Updates & Upgrades',
            'description' => 'Plugins, themes, core updates and translation upgrades applied.',
            'icon' => 'fa-circle-arrow-up',
        ],
        'uptime' => [
            'label' => 'Uptime & Availability',
            'description' => 'Availability percentage, outage counts, and total downtime.',
            'icon' => 'fa-heart-pulse',
        ],
        'security' => [
            'label' => 'Security & Firewall',
            'description' => 'Checksum malware scans, file integrity, and blocked malicious requests.',
            'icon' => 'fa-shield-halved',
        ],
        'performance' => [
            'label' => 'Performance Benchmarks',
            'description' => 'PageSpeed scores, time to first byte (TTFB), and page load speed.',
            'icon' => 'fa-gauge-high',
        ],
        'forms' => [
            'label' => 'Contact Form Testing',
            'description' => 'Automated synthetic form submission success and deliverability rates.',
            'icon' => 'fa-envelope-circle-check',
        ],
        'traffic' => [
            'label' => 'Traffic Analytics',
            'description' => 'Monthly unique visits, top referrers, and page view statistics.',
            'icon' => 'fa-chart-line',
        ],
        'backups' => [
            'label' => 'Backups & Snapshots',
            'description' => 'Completed backup runs, cloud storage locations, and restore readiness.',
            'icon' => 'fa-box-archive',
        ],
    ];

    public function reports(): HasMany
    {
        return $this->hasMany(ClientReport::class, 'template_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ClientReportSchedule::class, 'template_id');
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    public function hasSection(string $section): bool
    {
        return in_array($section, (array) $this->sections, true);
    }
}
