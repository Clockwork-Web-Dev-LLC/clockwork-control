<?php

namespace App\Services\Logs;

use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * How long raw nginx rows stay in threat_logs. Daily traffic rollups are
 * durable forever; this window only applies to the firehose table.
 */
class ThreatLogRetention
{
    public const SETTING_AMOUNT = 'logs.threat_retention_amount';

    public const SETTING_UNIT = 'logs.threat_retention_unit';

    public const SETTING_LAST_RUN_AT = 'logs.threat_prune_last_run_at';

    public const SETTING_LAST_DELETED = 'logs.threat_prune_last_deleted';

    public const UNIT_DAYS = 'days';

    public const UNIT_WEEKS = 'weeks';

    public const DEFAULT_AMOUNT = 30;

    public const DEFAULT_UNIT = self::UNIT_DAYS;

    public const MIN_DAYS = 7;

    public const MAX_DAYS = 365;

    public function __construct(
        protected Settings $settings,
    ) {}

    public function amount(): int
    {
        $amount = (int) $this->settings->get(self::SETTING_AMOUNT, self::DEFAULT_AMOUNT);

        return max(1, $amount);
    }

    public function unit(): string
    {
        $unit = (string) $this->settings->get(self::SETTING_UNIT, self::DEFAULT_UNIT);

        return in_array($unit, [self::UNIT_DAYS, self::UNIT_WEEKS], true)
            ? $unit
            : self::DEFAULT_UNIT;
    }

    public function days(?int $overrideDays = null): int
    {
        if ($overrideDays !== null) {
            return $this->clampDays($overrideDays);
        }

        return $this->clampDays($this->daysFrom($this->amount(), $this->unit()));
    }

    public function cutoff(?int $overrideDays = null): Carbon
    {
        return now()->subDays($this->days($overrideDays));
    }

    public static function daysFrom(int $amount, string $unit): int
    {
        $amount = max(1, $amount);

        return $unit === self::UNIT_WEEKS ? $amount * 7 : $amount;
    }

    public static function clampDays(int $days): int
    {
        return max(self::MIN_DAYS, min(self::MAX_DAYS, $days));
    }

    /**
     * @return array{amount: int, unit: string, days: int, last_run_at: ?string, last_deleted: int}
     */
    public function viewState(): array
    {
        return [
            'amount' => $this->amount(),
            'unit' => $this->unit(),
            'days' => $this->days(),
            'last_run_at' => $this->settings->get(self::SETTING_LAST_RUN_AT),
            'last_deleted' => (int) $this->settings->get(self::SETTING_LAST_DELETED, 0),
        ];
    }

    public function save(int $amount, string $unit): int
    {
        $unit = $unit === self::UNIT_WEEKS ? self::UNIT_WEEKS : self::UNIT_DAYS;
        $amount = max(1, $amount);
        $days = self::daysFrom($amount, $unit);

        $this->settings->putMany([
            self::SETTING_AMOUNT => $amount,
            self::SETTING_UNIT => $unit,
        ]);

        return $days;
    }

    public function recordRun(int $deleted): void
    {
        $this->settings->putMany([
            self::SETTING_LAST_RUN_AT => now()->toIso8601String(),
            self::SETTING_LAST_DELETED => $deleted,
        ]);
    }
}
