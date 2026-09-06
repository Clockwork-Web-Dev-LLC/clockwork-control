<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteSecurityScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteSecurityScan>
 */
class SiteSecurityScanFactory extends Factory
{
    protected $model = SiteSecurityScan::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'scan_type' => SiteSecurityScan::TYPE_SITECHECK,
            'scanned_at' => now(),
            'status' => SiteSecurityScan::STATUS_CLEAN,
            'has_malware_hit' => false,
            'blacklist_hit' => false,
            'modified_files_count' => 0,
        ];
    }

    public function malwareFound(): static
    {
        return $this->state(fn () => [
            'status' => SiteSecurityScan::STATUS_ISSUES_FOUND,
            'has_malware_hit' => true,
            'summary' => 'Malicious code detected',
        ]);
    }

    public function coreChecksums(): static
    {
        return $this->state(fn () => ['scan_type' => SiteSecurityScan::TYPE_CORE_CHECKSUMS]);
    }
}
