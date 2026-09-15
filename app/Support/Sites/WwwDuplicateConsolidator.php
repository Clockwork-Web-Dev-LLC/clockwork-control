<?php

namespace App\Support\Sites;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapse www/root duplicate site rows that were spawned when SpinupWP
 * listed www.* as a distinct site. Pairs are discovered by domain (www.X
 * vs X on the same server) — no production site IDs.
 *
 * Both-active pairs were double-tailing the same nginx log, so the child's
 * traffic/cursors/threat rows are deleted. If the child was already
 * archived, overlapping dates are dropped and the rest is merged onto
 * the parent.
 */
class WwwDuplicateConsolidator
{
    public function consolidate(): int
    {
        if (! Schema::hasTable('sites')) {
            return 0;
        }

        $pairs = 0;
        $wwwSites = DB::table('sites')
            ->whereNull('consolidated_into_site_id')
            ->where('domain', 'like', 'www.%')
            ->orderBy('id')
            ->get();

        foreach ($wwwSites as $www) {
            $apexDomain = substr((string) $www->domain, 4);
            if ($apexDomain === '') {
                continue;
            }

            $apexQuery = DB::table('sites')
                ->whereNull('consolidated_into_site_id')
                ->where('domain', $apexDomain)
                ->where('id', '!=', $www->id);

            if ($www->server_id === null) {
                $apexQuery->whereNull('server_id');
            } else {
                $apexQuery->where('server_id', $www->server_id);
            }

            $apex = $apexQuery->orderBy('id')->first();
            if ($apex === null) {
                continue;
            }

            [$primary, $duplicate] = $this->pickPrimary($apex, $www);
            $this->consolidatePair($primary, $duplicate);
            $pairs++;
        }

        return $pairs;
    }

    /**
     * Prefer a live row over an archived/inactive one. When both are in the
     * same state, keep the apex (non-www) row as the canonical domain.
     *
     * @return array{0: object, 1: object}
     */
    private function pickPrimary(object $apex, object $www): array
    {
        $apexLive = $this->isLive($apex);
        $wwwLive = $this->isLive($www);

        if ($apexLive !== $wwwLive) {
            return $apexLive ? [$apex, $www] : [$www, $apex];
        }

        return [$apex, $www];
    }

    private function isLive(object $site): bool
    {
        $archived = $site->archived_at ?? null;
        $inactive = (bool) ($site->is_inactive ?? false);

        return $archived === null && $inactive === false;
    }

    private function consolidatePair(object $primary, object $duplicate): void
    {
        $primaryId = (int) $primary->id;
        $dupeId = (int) $duplicate->id;

        if ($primaryId === $dupeId) {
            return;
        }

        $already = DB::table('sites')->where('id', $dupeId)->value('consolidated_into_site_id');
        if ($already !== null) {
            return;
        }

        $spinupId = $primary->spinupwp_id ?: $duplicate->spinupwp_id;

        DB::table('sites')->where('id', $primaryId)->update([
            'spinupwp_id' => $spinupId,
            'updated_at' => now(),
        ]);

        DB::table('sites')->where('id', $dupeId)->update([
            'spinupwp_id' => null,
            'consolidated_into_site_id' => $primaryId,
            'archived_at' => $duplicate->archived_at ?: now(),
            'is_inactive' => true,
            'inactive_reason' => 'Consolidated alias of '.$primary->domain,
            'updated_at' => now(),
        ]);

        $childWasLive = $this->isLive($duplicate);

        if ($childWasLive) {
            $this->deleteIfTable('nginx_log_cursors', $dupeId);
            $this->deleteIfTable('site_traffic_daily', $dupeId);
            $this->deleteIfTable('site_metrics', $dupeId);
            $this->deleteIfTable('threat_logs', $dupeId);
        } else {
            $this->mergeTraffic($primaryId, $dupeId);
            $this->reassignIfTable('threat_logs', $dupeId, $primaryId);
        }

        $this->reassignIfTable('action_logs', $dupeId, $primaryId);
        $this->reassignIfTable('plugin_update_jobs', $dupeId, $primaryId);
        $this->reassignIfTable('site_security_scans', $dupeId, $primaryId);
        $this->reassignIfTable('site_performance_scans', $dupeId, $primaryId);
    }

    private function mergeTraffic(int $primaryId, int $dupeId): void
    {
        if (! Schema::hasTable('site_traffic_daily')) {
            return;
        }

        $primaryDates = DB::table('site_traffic_daily')->where('site_id', $primaryId)->pluck('date')->all();
        if ($primaryDates !== []) {
            DB::table('site_traffic_daily')
                ->where('site_id', $dupeId)
                ->whereIn('date', $primaryDates)
                ->delete();
        }

        DB::table('site_traffic_daily')->where('site_id', $dupeId)->update(['site_id' => $primaryId]);
    }

    private function deleteIfTable(string $table, int $siteId): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->where('site_id', $siteId)->delete();
    }

    private function reassignIfTable(string $table, int $fromSiteId, int $toSiteId): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->where('site_id', $fromSiteId)->update(['site_id' => $toSiteId]);
    }
}
