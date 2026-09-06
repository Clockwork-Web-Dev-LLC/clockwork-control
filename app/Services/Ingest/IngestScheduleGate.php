<?php

namespace App\Services\Ingest;

use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Multi-source schedule gate. One shared night window + cadence; each source has its
 * own enable toggle and independent last_run_at so the cadence check is per-source.
 *
 * Source keys (string identifiers used in the settings table):
 *   - "llar"      → Limit Login Attempts Reloaded lockouts
 *   - "wordfence" → Wordfence wfBlocks7 entries
 */
class IngestScheduleGate
{
    public const KEY_WINDOW_START = 'ingest.schedule.start_time';

    public const KEY_WINDOW_END = 'ingest.schedule.end_time';

    public const KEY_TIMEZONE = 'ingest.schedule.timezone';

    public const KEY_FREQUENCY_MIN = 'ingest.schedule.frequency_minutes';

    /**
     * Boolean. When true, isInWindow() always returns true — the start/end fields are
     * still stored (so toggling back off restores the prior window), they just don't gate.
     */
    public const KEY_ALWAYS_ON = 'ingest.schedule.always_on';

    public const SOURCE_LLAR = 'llar';

    public const SOURCE_WORDFENCE = 'wordfence';

    public const SOURCES = [self::SOURCE_LLAR, self::SOURCE_WORDFENCE];

    public const DEFAULTS = [
        self::KEY_WINDOW_START => '01:00',
        self::KEY_WINDOW_END => '07:00',
        self::KEY_TIMEZONE => 'America/New_York',
        self::KEY_FREQUENCY_MIN => 60,
        self::KEY_ALWAYS_ON => false,
    ];

    public function __construct(private readonly Settings $settings)
    {
        $this->migrateLegacyKeys();
    }

    public function shouldRunNow(string $source): bool
    {
        if (! in_array($source, self::SOURCES, true)) {
            return false;
        }

        if (! (bool) $this->settings->get($this->enabledKey($source), true)) {
            return false;
        }

        $tz = $this->config(self::KEY_TIMEZONE);
        $now = Carbon::now($tz);

        if (! $this->isInWindow($now)) {
            return false;
        }

        $cadenceMin = max(1, (int) $this->config(self::KEY_FREQUENCY_MIN));
        $lastRunRaw = $this->settings->get($this->lastRunKey($source));
        if ($lastRunRaw) {
            $lastRun = Carbon::parse($lastRunRaw)->setTimezone($tz);
            if ($lastRun->diffInMinutes($now) < $cadenceMin) {
                return false;
            }
        }

        return true;
    }

    public function recordRun(string $source): void
    {
        $this->settings->put($this->lastRunKey($source), Carbon::now('UTC')->toIso8601String());
    }

    /**
     * Render the form state — shared window + per-source enable + per-source last_run.
     *
     * @return array<string, mixed>
     */
    public function currentConfig(): array
    {
        $sources = [];
        foreach (self::SOURCES as $src) {
            $sources[$src] = [
                'enabled' => (bool) $this->settings->get($this->enabledKey($src), true),
                'last_run_at' => $this->settings->get($this->lastRunKey($src)),
            ];
        }

        return [
            'start_time' => (string) $this->config(self::KEY_WINDOW_START),
            'end_time' => (string) $this->config(self::KEY_WINDOW_END),
            'timezone' => (string) $this->config(self::KEY_TIMEZONE),
            'frequency_minutes' => (int) $this->config(self::KEY_FREQUENCY_MIN),
            'always_on' => (bool) $this->config(self::KEY_ALWAYS_ON),
            'sources' => $sources,
        ];
    }

    public function enabledKey(string $source): string
    {
        return "ingest.{$source}.enabled";
    }

    public function lastRunKey(string $source): string
    {
        return "ingest.{$source}.last_run_at";
    }

    private function config(string $key): mixed
    {
        return $this->settings->get($key, self::DEFAULTS[$key] ?? null);
    }

    private function isInWindow(Carbon $now): bool
    {
        if ((bool) $this->config(self::KEY_ALWAYS_ON)) {
            return true;
        }

        [$startH, $startM] = $this->parseTime($this->config(self::KEY_WINDOW_START));
        [$endH, $endM] = $this->parseTime($this->config(self::KEY_WINDOW_END));

        $todayStart = $now->copy()->setTime($startH, $startM, 0);
        $todayEnd = $now->copy()->setTime($endH, $endM, 0);

        // Wrap-past-midnight (e.g. 22:00–05:00).
        if ($todayEnd->lessThanOrEqualTo($todayStart)) {
            return $now->greaterThanOrEqualTo($todayStart) || $now->lessThan($todayEnd);
        }

        return $now->greaterThanOrEqualTo($todayStart) && $now->lessThan($todayEnd);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseTime(string $hhmm): array
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
            return [1, 0];
        }

        return [
            max(0, min(23, (int) $m[1])),
            max(0, min(59, (int) $m[2])),
        ];
    }

    /**
     * One-shot migration: copy any existing llar.schedule.* values into the new
     * ingest.schedule.* + ingest.llar.* keys. Safe to call repeatedly — only writes
     * the new key if the legacy one is set AND the new key is not.
     */
    private function migrateLegacyKeys(): void
    {
        $map = [
            'llar.schedule.enabled' => $this->enabledKey(self::SOURCE_LLAR),
            'llar.schedule.start_time' => self::KEY_WINDOW_START,
            'llar.schedule.end_time' => self::KEY_WINDOW_END,
            'llar.schedule.timezone' => self::KEY_TIMEZONE,
            'llar.schedule.frequency_minutes' => self::KEY_FREQUENCY_MIN,
            'llar.schedule.last_run_at' => $this->lastRunKey(self::SOURCE_LLAR),
        ];

        foreach ($map as $legacy => $new) {
            $legacyValue = $this->settings->get($legacy);
            if ($legacyValue === null) {
                continue;
            }
            if ($this->settings->get($new) === null) {
                $this->settings->put($new, $legacyValue);
            }
        }
    }
}
