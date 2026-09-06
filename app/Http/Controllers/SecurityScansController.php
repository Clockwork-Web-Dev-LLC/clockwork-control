<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteCoreChecksumAllowlist;
use App\Models\SiteSecurityScan;
use App\Services\Security\CoreChecksumAllowlist;
use App\Services\Security\SecurityScanRecorder;
use App\Services\Security\SucuriSiteCheckClient;
use App\Services\Security\WpCoreChecksumVerifier;
use App\Services\Ssh\SshClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Fleet-wide security scan inventory + ad-hoc per-site re-scan.
 *
 * Mirrors the WordPressPluginsController shape — header tiles + a single
 * sortable table — but reads from site_security_scans (latest row per
 * (site, scan_type) via the SiteSecurityScan::scopeLatestPerSite scope).
 *
 * Run-now action is synchronous so the user sees the new row appear without
 * waiting for the next scheduled tick. Both scanners are fast for one site.
 */
class SecurityScansController extends Controller
{
    public function index(CoreChecksumAllowlist $checksumAllowlist): View
    {
        $sites = Site::query()
            ->with(['server:id,name,is_ignored', 'latestSiteCheckScan', 'latestChecksumScan'])
            ->hostMonitored()
            ->orderBy('domain')
            ->get();

        $carePlanSites = $sites->where('care_plan_enabled', true);

        // The "issues" tiles are scoped to care-plan sites — that's the
        // population we actually scan on a recurring basis. Stale rows on
        // non-care-plan sites stay visible per-row but don't roll up here.
        $malwareSites = $carePlanSites
            ->filter(fn ($s) => $s->latestSiteCheckScan && ($s->latestSiteCheckScan->has_malware_hit || $s->latestSiteCheckScan->blacklist_hit))
            ->values();

        // Tampering: latest checksum scan flagged issues, AND those issues are
        // not fully covered by the site's allowlist. Once an operator allowlists
        // every flagged path, the site stops counting here — the scan row stays
        // in history, but the active count drops to 0. KEEP IN SYNC with
        // App\Support\IssueCounter::countOpenIssues() (same suppression rule).
        $tamperingCandidates = $carePlanSites
            ->filter(fn ($s) => $s->latestChecksumScan && $s->latestChecksumScan->status === SiteSecurityScan::STATUS_ISSUES_FOUND);
        $suppressedSiteIds = $checksumAllowlist->suppressedSiteIds(
            $tamperingCandidates->pluck('latestChecksumScan')->all(),
        );
        $tamperingSites = $tamperingCandidates
            ->reject(fn ($s) => $suppressedSiteIds->contains($s->id))
            ->values();
        $failedSites = $carePlanSites
            ->filter(fn ($s) => ($s->latestSiteCheckScan && $s->latestSiteCheckScan->status === SiteSecurityScan::STATUS_FAILED)
                || ($s->latestChecksumScan && $s->latestChecksumScan->status === SiteSecurityScan::STATUS_FAILED))
            ->values();

        $totals = [
            'sites' => $sites->count(),
            'care_plan_sites' => $carePlanSites->count(),
            'wordpress_sites' => $sites->where('is_wordpress', true)->count(),
            'malware_or_blacklist' => $malwareSites->count(),
            'checksum_tampering' => $tamperingSites->count(),
            'failed' => $failedSites->count(),
            'never_scanned' => $carePlanSites->filter(fn ($s) => ! $s->latestSiteCheckScan && ! $s->latestChecksumScan)->count(),
        ];

        // Lists for the "which sites?" hint under each red card. Surfaced as
        // names + links so the operator can act on a tampering / malware
        // alert without scrolling the table to find which row.
        $affected = [
            'malware' => $malwareSites,
            'tampering' => $tamperingSites,
            'failed' => $failedSites,
        ];

        return view('security.scans', compact('sites', 'totals', 'affected'));
    }

    /**
     * Manual scan trigger. Optional `type` body param picks ONE scan to run
     * (sitecheck or core_checksums); omit it to run both. The redirect uses
     * the request referer so triggers from the per-site Security tab return
     * the user to that tab instead of bouncing to the fleet inventory.
     */
    public function runForSite(
        Site $site,
        Request $request,
        SucuriSiteCheckClient $sitecheck,
        WpCoreChecksumVerifier $checksums,
        SecurityScanRecorder $recorder,
    ): RedirectResponse {
        set_time_limit(300); // 5 minutes for security scans (Pressable commands need 120s + buffer)
        $type = (string) $request->input('type', 'all');
        $ranLabels = [];

        if ($type === SiteSecurityScan::TYPE_SITECHECK || $type === 'all') {
            $recorder->record($sitecheck->scanSite($site));
            $ranLabels[] = 'Sucuri SiteCheck';
        }

        if (($type === SiteSecurityScan::TYPE_CORE_CHECKSUMS || $type === 'all') && $site->is_wordpress) {
            $recorder->record($checksums->verify($site));
            $ranLabels[] = 'core checksums';
        }

        $message = $ranLabels === []
            ? "Nothing to run for {$site->domain} (not a WordPress site)."
            : 'Ran '.implode(' + ', $ranLabels)." on {$site->domain}.";

        return redirect()
            ->to($request->headers->get('referer') ?: route('security.scans'))
            ->with('flash', $message);
    }

    /**
     * View the contents of a file flagged by the latest core_checksums scan.
     * Path safety: the requested path MUST exist in the latest scan's
     * modified/should_not_exist lists for this site (missing files have no
     * content to read, so they're rejected here). The CoreChecksumAllowlist
     * service does the validation — anything else returns 404.
     */
    public function viewFile(
        Site $site,
        Request $request,
        CoreChecksumAllowlist $allowlist,
        SshClient $ssh,
    ): View|RedirectResponse {
        $path = (string) $request->query('path', '');
        $bucket = $allowlist->bucketForPathInLatestScan($site, $path);

        if ($bucket === null || $bucket === SiteCoreChecksumAllowlist::BUCKET_MISSING) {
            return redirect()
                ->route('sites.show', [$site, 'security'])
                ->with('flash', "Path not in latest scan or not readable: {$path}");
        }

        $scan = $site->latestChecksumScan;
        $wpPath = (string) ($scan?->details['wp_path'] ?? '');
        if ($wpPath === '') {
            return redirect()
                ->route('sites.show', [$site, 'security'])
                ->with('flash', 'Scan is missing wp_path — cannot resolve file location.');
        }

        $absolute = rtrim($wpPath, '/').'/'.$path;

        // Cap the read at 64KB. Hardening files are tiny; if a flagged file is
        // bigger than that it's almost certainly malicious and we'd rather not
        // dump the whole payload to the operator's browser anyway.
        $command = sprintf(
            'ls -la %1$s 2>&1; echo "---STAT---"; stat -c "%%y" %1$s 2>&1; echo "---SIZE---"; wc -c %1$s 2>&1; echo "---HEAD---"; head -c 65536 %1$s 2>&1',
            escapeshellarg($absolute),
        );

        try {
            $output = $ssh->exec($site->server, $command, 30);
        } catch (\Throwable $e) {
            return redirect()
                ->route('sites.show', [$site, 'security'])
                ->with('flash', 'SSH read failed: '.$e->getMessage());
        }

        $sections = $this->splitFileOutput($output);
        $isAllowlisted = $allowlist->isAllowlisted($site, $path, $bucket);

        return view('dashboard.site.security-file', [
            'site' => $site,
            'path' => $path,
            'absolutePath' => $absolute,
            'bucket' => $bucket,
            'sections' => $sections,
            'isAllowlisted' => $isAllowlisted,
            'scannedAt' => $scan?->scanned_at,
        ]);
    }

    /**
     * Add a path to the per-site allowlist. Bucket comes from a hidden form
     * field set by the per-file row that triggered the action.
     */
    public function addToAllowlist(Site $site, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
            'bucket' => ['required', Rule::in(SiteCoreChecksumAllowlist::BUCKETS)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        // Re-validate the path against the latest scan so the operator can't
        // allowlist a path that wasn't actually flagged.
        $allowlist = app(CoreChecksumAllowlist::class);
        if ($allowlist->bucketForPathInLatestScan($site, $data['path']) === null) {
            return back()->with('flash', "Path not in latest scan: {$data['path']}");
        }

        // Idempotent — already-allowlisted paths just bump updated_at.
        SiteCoreChecksumAllowlist::updateOrCreate(
            [
                'site_id' => $site->id,
                'path' => $data['path'],
                'bucket' => $data['bucket'],
            ],
            [
                'reason' => $data['reason'] ?? null,
                'added_by_user_id' => $request->user()?->id,
            ],
        );

        return back()->with('flash', "Allowlisted {$data['path']} for {$site->domain}.");
    }

    public function removeFromAllowlist(Site $site, SiteCoreChecksumAllowlist $entry): RedirectResponse
    {
        if ($entry->site_id !== $site->id) {
            abort(404);
        }

        $entry->delete();

        return back()->with('flash', "Removed {$entry->path} from allowlist.");
    }

    /**
     * Split the SSH viewer's combined output into ls / stat / size / head
     * sections so the view can render each clearly.
     *
     * @return array{ls: string, stat: string, size: string, head: string}
     */
    private function splitFileOutput(string $output): array
    {
        $parts = preg_split('/\R---(STAT|SIZE|HEAD)---\R/', $output);
        $parts = is_array($parts) ? $parts : [$output];

        return [
            'ls' => trim($parts[0] ?? ''),
            'stat' => trim($parts[1] ?? ''),
            'size' => trim($parts[2] ?? ''),
            'head' => $parts[3] ?? '',
        ];
    }
}
