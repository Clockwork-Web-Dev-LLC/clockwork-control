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
 *
 * @method static Builder<ClientReportTemplate> default()
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
            'description' => 'Plugin, theme and WordPress core updates applied during the period, and any still waiting at its end.',
            'icon' => 'fa-circle-arrow-up',
        ],
        'uptime' => [
            'label' => 'Uptime & Availability',
            'description' => 'Uptime percentage, outage count, total downtime and the most recent outages, for sites the uptime monitor checks.',
            'icon' => 'fa-heart-pulse',
        ],
        'security' => [
            'label' => 'Security & Firewall',
            'description' => 'Latest malware scan, WordPress core file check, blocklist check and known plugin vulnerabilities, plus attackers blocked when the site reports its blocks.',
            'icon' => 'fa-shield-halved',
        ],
        'performance' => [
            'label' => 'Performance Benchmarks',
            'description' => 'Latest mobile and desktop performance score and page load time (LCP) from tests run during the period.',
            'icon' => 'fa-gauge-high',
        ],
        'forms' => [
            'label' => 'Contact Form Testing',
            'description' => 'Automated test submissions and whether each form accepted them. Scheduled tests suppress the email, so delivery is only reported for live tests.',
            'icon' => 'fa-envelope-circle-check',
        ],
        'traffic' => [
            'label' => 'Traffic Analytics',
            'description' => 'Visits, page and file requests served, and data transferred (when the host reports it).',
            'icon' => 'fa-chart-line',
        ],
        'backups' => [
            'label' => 'Backups & Snapshots',
            'description' => 'Backups recorded from the site\'s own backup source: where they are stored and the last 10 days of the period.',
            'icon' => 'fa-box-archive',
        ],
        'work_log' => [
            'label' => 'Work log',
            'description' => 'Hours logged in the site\'s work log during the period; left out when there are none.',
            'icon' => 'fa-clock',
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
