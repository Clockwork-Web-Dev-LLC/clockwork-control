<?php

namespace App\Services\Security;

use App\Models\Site;
use App\Models\SiteSecurityScan;

/**
 * Common shape returned by both scanners (Sucuri SiteCheck remote scan
 * and `wp core verify-checksums` over SSH). The recorder writes one of
 * these into a SiteSecurityScan row + a single action_logs entry; the
 * artisan commands only need to know how to bucket each VO into success
 * / issues / failure for their summary line.
 */
final class SecurityScanResult
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        public readonly Site $site,
        public readonly string $scanType,
        public readonly string $status,
        public readonly bool $hasMalwareHit = false,
        public readonly bool $blacklistHit = false,
        public readonly int $modifiedFilesCount = 0,
        public readonly ?string $summary = null,
        public readonly ?array $details = null,
        public readonly ?string $error = null,
        public readonly ?int $elapsedMs = null,
    ) {}

    public function isClean(): bool
    {
        return $this->status === SiteSecurityScan::STATUS_CLEAN;
    }

    public function isWarning(): bool
    {
        return $this->status === SiteSecurityScan::STATUS_WARNING;
    }

    public function hasIssues(): bool
    {
        return $this->status === SiteSecurityScan::STATUS_ISSUES_FOUND;
    }

    public function isFailed(): bool
    {
        return $this->status === SiteSecurityScan::STATUS_FAILED;
    }
}
