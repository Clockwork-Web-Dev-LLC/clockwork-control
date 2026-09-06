<?php

use App\Models\Site;
use App\Models\SiteSecurityScan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sites whose last 3 sitecheck scans were all 'failed' get marked
        // unavailable now — they've already proven the per-scan gate. The
        // scheduled command would catch them on the next run; doing it here
        // means the dashboard reflects reality immediately.
        $latestThreeFailedSiteIds = DB::table('site_security_scans as a')
            ->select('a.site_id')
            ->where('a.scan_type', SiteSecurityScan::TYPE_SITECHECK)
            ->whereIn('a.id', function ($sub) {
                $sub->select(DB::raw('id'))
                    ->from('site_security_scans')
                    ->where('scan_type', SiteSecurityScan::TYPE_SITECHECK)
                    ->orderByDesc('id');
            })
            ->groupBy('a.site_id')
            ->havingRaw('SUM(CASE WHEN a.status = ? THEN 1 ELSE 0 END) >= 3', [SiteSecurityScan::STATUS_FAILED])
            ->pluck('site_id');

        foreach ($latestThreeFailedSiteIds as $siteId) {
            $latest = SiteSecurityScan::query()
                ->where('site_id', $siteId)
                ->where('scan_type', SiteSecurityScan::TYPE_SITECHECK)
                ->orderByDesc('id')
                ->limit(3)
                ->get();

            if ($latest->count() < 3 || $latest->where('status', '!=', SiteSecurityScan::STATUS_FAILED)->isNotEmpty()) {
                continue;
            }

            $site = Site::find($siteId);
            if (! $site || $site->sucuri_unavailable_at !== null) {
                continue;
            }

            $site->forceFill([
                'sucuri_unavailable_at' => $latest->first()->scanned_at ?? now(),
                'sucuri_unavailable_reason' => substr((string) ($latest->first()->error ?? 'Sucuri SiteCheck repeatedly failed'), 0, 200),
            ])->save();
        }
    }

    public function down(): void
    {
        // Reverse path is destructive (we'd lose the per-site reason text).
        // Operator can clear individually via the UI's "Try again" button.
    }
};
