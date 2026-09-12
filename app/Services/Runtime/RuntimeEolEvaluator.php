<?php

namespace App\Services\Runtime;

use App\Models\Site;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class RuntimeEolEvaluator
{
    public const STATUS_EOL = 'eol';

    public const STATUS_SECURITY_ONLY = 'security_only';

    public const STATUS_ACTIVE_SUPPORT = 'active_support';

    public const STATUS_UNKNOWN = 'unknown';

    public static function normalizeCycle(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        if (preg_match('/^(\d+\.\d+)/', trim($version), $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Evaluate lifecycle classification for a given version against cycle definitions.
     *
     * @param  array<string, array{name: string, label: string, release_date: ?string, is_eoas: bool, eoas_from: ?string, is_eol: bool, eol_from: ?string, is_maintained: bool}>  $cycles
     * @return array{
     *   status: 'eol'|'security_only'|'active_support'|'unknown',
     *   cycle: ?string,
     *   version: ?string,
     *   detail: string,
     *   date: ?string,
     *   cycle_data: ?array<string, mixed>
     * }
     */
    public function evaluate(array $cycles, ?string $version, ?CarbonInterface $today = null): array
    {
        $today = $today ? CarbonImmutable::instance($today) : CarbonImmutable::now();
        $cycle = self::normalizeCycle($version);

        if ($cycle === null || ! isset($cycles[$cycle])) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'cycle' => $cycle,
                'version' => $version,
                'detail' => $version ? "Unmatched version ({$version})" : 'Version not reported',
                'date' => null,
                'cycle_data' => null,
            ];
        }

        $cycleData = $cycles[$cycle];
        $todayDateStr = $today->toDateString();

        $isEol = $cycleData['is_eol']
            || ($cycleData['eol_from'] !== null && $todayDateStr >= $cycleData['eol_from']);

        if ($isEol) {
            $date = $cycleData['eol_from'];
            $formatted = $date ? Carbon::parse($date)->format('M Y') : null;

            return [
                'status' => self::STATUS_EOL,
                'cycle' => $cycle,
                'version' => $version,
                'detail' => $formatted ? "Security support ended {$formatted}" : 'Security support ended',
                'date' => $date,
                'cycle_data' => $cycleData,
            ];
        }

        $isEoas = $cycleData['is_eoas']
            || ($cycleData['eoas_from'] !== null && $todayDateStr >= $cycleData['eoas_from']);

        if ($isEoas) {
            $date = $cycleData['eol_from'];
            $formatted = $date ? Carbon::parse($date)->format('M Y') : null;

            return [
                'status' => self::STATUS_SECURITY_ONLY,
                'cycle' => $cycle,
                'version' => $version,
                'detail' => $formatted ? "Security support ends {$formatted}" : 'Security support only',
                'date' => $date,
                'cycle_data' => $cycleData,
            ];
        }

        if ($cycleData['is_maintained']) {
            $date = $cycleData['eoas_from'];
            $formatted = $date ? Carbon::parse($date)->format('M Y') : null;

            return [
                'status' => self::STATUS_ACTIVE_SUPPORT,
                'cycle' => $cycle,
                'version' => $version,
                'detail' => $formatted ? "Active support until {$formatted}" : 'Active support',
                'date' => $date,
                'cycle_data' => $cycleData,
            ];
        }

        return [
            'status' => self::STATUS_UNKNOWN,
            'cycle' => $cycle,
            'version' => $version,
            'detail' => 'Unknown lifecycle state',
            'date' => null,
            'cycle_data' => $cycleData,
        ];
    }

    /**
     * Classify site's PHP version from companion_snapshot.
     *
     * @param  array<string, array{name: string, label: string, release_date: ?string, is_eoas: bool, eoas_from: ?string, is_eol: bool, eol_from: ?string, is_maintained: bool}>  $phpCycles
     * @return array{
     *   status: 'eol'|'security_only'|'active_support'|'unknown',
     *   cycle: ?string,
     *   version: ?string,
     *   detail: string,
     *   date: ?string,
     *   cycle_data: ?array<string, mixed>
     * }|null
     */
    public function forSite(Site $site, array $phpCycles, ?CarbonInterface $today = null): ?array
    {
        $version = $site->companion_snapshot['environment']['php_version'] ?? null;
        if (! is_string($version) || trim($version) === '') {
            return null;
        }

        return $this->evaluate($phpCycles, $version, $today);
    }
}
