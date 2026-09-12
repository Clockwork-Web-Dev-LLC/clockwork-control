<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Mirror of the CISA Known Exploited Vulnerabilities (KEV) catalog.
 *
 * Source: https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json
 *
 * Atomically replaces the `cisa_kev_entries` table on daily refresh using DELETE FROM
 * inside a database transaction so readers always see a complete dataset.
 */
class CisaKevClient
{
    public const FEED_URL = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';

    private const TIMEOUT_SECONDS = 30;

    private const USER_AGENT = 'Clockwork-Monitoring/1.0 (+https://clockworkcontrol.com)';

    /**
     * Fetch the CISA KEV catalog and replace the local cisa_kev_entries table.
     *
     * @return array{ok: bool, count: int, rows_inserted: int, error: ?string}
     */
    public function refresh(?callable $onProgress = null): array
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withUserAgent(self::USER_AGENT)
            ->retry(2, 500, throw: false)
            ->get(self::FEED_URL);

        if ($response->failed()) {
            return [
                'ok' => false,
                'count' => 0,
                'rows_inserted' => 0,
                'error' => "HTTP request to CISA KEV feed failed with status {$response->status()}",
            ];
        }

        $data = $response->json();
        if (! is_array($data) || ! isset($data['vulnerabilities']) || ! is_array($data['vulnerabilities'])) {
            return [
                'ok' => false,
                'count' => 0,
                'rows_inserted' => 0,
                'error' => 'Invalid JSON structure from CISA KEV feed',
            ];
        }

        $byCve = [];
        $now = now();
        foreach ($data['vulnerabilities'] as $v) {
            if (! is_array($v)) {
                continue;
            }

            $cve = trim((string) ($v['cveID'] ?? ''));
            if ($cve === '') {
                continue;
            }

            $dateAdded = ! empty($v['dateAdded']) ? substr((string) $v['dateAdded'], 0, 10) : null;

            $byCve[$cve] = [
                'cve' => $cve,
                'vendor_project' => (string) ($v['vendorProject'] ?? ''),
                'product' => (string) ($v['product'] ?? ''),
                'date_added' => $dateAdded,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $rows = array_values($byCve);

        if (empty($rows) && count($data['vulnerabilities']) > 0) {
            return [
                'ok' => false,
                'count' => 0,
                'rows_inserted' => 0,
                'error' => 'No valid CVE records parsed from CISA KEV feed',
            ];
        }

        if ($onProgress) {
            $onProgress(count($rows));
        }

        // Use DELETE FROM within a transaction instead of TRUNCATE to preserve
        // atomicity across concurrent readers.
        DB::transaction(function () use ($rows) {
            DB::table('cisa_kev_entries')->delete();
            foreach (array_chunk($rows, 500) as $batch) {
                DB::table('cisa_kev_entries')->insert($batch);
            }
        });

        return [
            'ok' => true,
            'count' => count($rows),
            'rows_inserted' => count($rows),
            'error' => null,
        ];
    }
}
