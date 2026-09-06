<?php

namespace App\Services\Security;

use App\Models\ActionLog;
use App\Models\SiteSecurityScan;
use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single chokepoint for persisting a SecurityScanResult: writes one
 * site_security_scans row + one action_logs row via ActionLogger. Same role
 * `WpPluginDetector::detect()`'s tail plays today — concentrate the write
 * pattern so the artisan commands stay slim.
 *
 * The action_logs row records "we ran a scan on <site>" — one row per scan,
 * not one per finding. The Recent activity card surfaces it; the cross-site
 * maintenance-history page (planned) will too.
 *
 * Also the chokepoint for the clean → malware-hit alert: every scan type
 * that can set has_malware_hit (SiteCheck, Companion in-WP probe) flows
 * through record(), so the transition check lives here once rather than
 * being duplicated in each artisan command.
 */
class SecurityScanRecorder
{
    public function __construct(
        private readonly ActionLogger $logger,
        private readonly CoreChecksumAllowlist $allowlist,
        private readonly ChatNotifier $chat,
    ) {}

    public function record(SecurityScanResult $r): SiteSecurityScan
    {
        $now = Carbon::now();

        // Stamp a core_checksums row as clean when every finding it carries is
        // already covered by the site's allowlist. Without this, the Security
        // tab's Card said "Clean (allowlisted)" (it filters at render time)
        // while the same scan's row in the history table said "Issues found"
        // — same data, two different verdicts. Other scan types are passed
        // through unchanged; only core_checksums uses the allowlist.
        $effectiveStatus = $r->status;
        $effectiveSummary = $r->summary;
        if (
            $r->scanType === SiteSecurityScan::TYPE_CORE_CHECKSUMS
            && $r->status === SiteSecurityScan::STATUS_ISSUES_FOUND
            && $r->modifiedFilesCount > 0
        ) {
            $proxy = new SiteSecurityScan([
                'site_id' => $r->site->id,
                'scan_type' => $r->scanType,
                'status' => $r->status,
                'details' => $r->details,
            ]);
            $filtered = $this->allowlist->filter($r->site, $proxy);
            if (! $filtered['has_unallowlisted']) {
                $effectiveStatus = SiteSecurityScan::STATUS_CLEAN;
                $effectiveSummary = 'All core files match WordPress.org checksums (every finding allowlisted).';
            }
        }

        // Captured before the new row exists so it's genuinely the PREVIOUS
        // scan of this type for this site — used below to alert only on the
        // clean → malware-hit transition, not on every re-scan of a site
        // that's still infected.
        $previouslyHadMalwareHit = (bool) SiteSecurityScan::query()
            ->where('site_id', $r->site->id)
            ->where('scan_type', $r->scanType)
            ->orderByDesc('scanned_at')
            ->value('has_malware_hit');

        $row = SiteSecurityScan::create([
            'site_id' => $r->site->id,
            'scan_type' => $r->scanType,
            'scanned_at' => $now,
            'status' => $effectiveStatus,
            'has_malware_hit' => $r->hasMalwareHit,
            'blacklist_hit' => $r->blacklistHit,
            'modified_files_count' => $r->modifiedFilesCount,
            'summary' => $effectiveSummary,
            'details' => $r->details,
            'error' => $r->error,
            'elapsed_ms' => $r->elapsedMs,
        ]);

        if ($r->hasMalwareHit && ! $previouslyHadMalwareHit) {
            try {
                $this->chat->malwareFindingDetected($r->site, $row);
            } catch (Throwable $e) {
                Log::warning('security_scan.malware_alert_failed', [
                    'site_id' => $r->site->id,
                    'scan_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Forward the operator-actionable subset of the full scan details
        // into the action_log payload so that Companion's Security page can
        // expand each row to show kind/path/evidence per finding (mirrors the
        // disclosure UI on the agency-side Security tab). Bounded by the
        // scanner's MAX_FINDINGS so the JSON payload stays under the
        // Companion endpoint's body limit; safer than blasting the entire
        // SecurityScanResult::$details across the wire.
        $detailsPayload = [
            'scan_id' => $row->id,
            'status' => $r->status,
            'has_malware_hit' => $r->hasMalwareHit,
            'blacklist_hit' => $r->blacklistHit,
            'modified_files_count' => $r->modifiedFilesCount,
        ];

        $scanDetails = is_array($r->details) ? $r->details : [];
        if (isset($scanDetails['transport'])) {
            $detailsPayload['transport'] = $scanDetails['transport'];
        }
        if (isset($scanDetails['scanned_files_count'])) {
            $detailsPayload['scanned_files_count'] = $scanDetails['scanned_files_count'];
        }
        if (! empty($scanDetails['scan_aborted'])) {
            $detailsPayload['scan_aborted'] = (bool) $scanDetails['scan_aborted'];
            $detailsPayload['abort_reason'] = $scanDetails['abort_reason'] ?? null;
        }
        if (! empty($scanDetails['findings']) && is_array($scanDetails['findings'])) {
            $detailsPayload['findings'] = $scanDetails['findings'];
        }

        $this->logger->record(
            actionType: ActionLog::TYPE_SECURITY_SCAN,
            summary: $effectiveSummary ?? ucfirst(str_replace('_', ' ', $r->scanType)).' scan completed.',
            site: $r->site,
            target: $r->scanType,
            details: $detailsPayload,
            ok: ! $r->isFailed(),
            error: $r->error,
            elapsedMs: $r->elapsedMs,
            actor: 'system',
            ranAt: $now,
        );

        return $row;
    }
}
