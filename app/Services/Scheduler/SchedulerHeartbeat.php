<?php

namespace App\Services\Scheduler;

use App\Services\Chat\ChatNotifier;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Proves `schedule:run` actually executed.
 *
 * Cron writes a timestamp every minute via `clockwork:scheduler-heartbeat`.
 * If that stops (laptop sleep, missing crontab, dead PHP symlink), the rest
 * of the panel still renders — uptime, ingest, and updates just freeze.
 * Detection therefore happens on web requests (and recovery on the next
 * successful tick), because a scheduled command cannot notice its own absence.
 */
class SchedulerHeartbeat
{
    public const SETTING_HEARTBEAT_AT = 'monitoring.scheduler_heartbeat_at';

    public const SETTING_STALE_SINCE = 'monitoring.scheduler_stale_since';

    /** Missed ticks before we call it stale. Cron is every minute. */
    public const THRESHOLD_MINUTES = 5;

    public function __construct(
        private readonly Settings $settings,
        private readonly ChatNotifier $chat,
    ) {}

    public function record(): void
    {
        $hadAlerted = $this->staleSince() !== null;

        $this->settings->put(self::SETTING_HEARTBEAT_AT, now()->toIso8601String());

        if ($hadAlerted) {
            $this->settings->put(self::SETTING_STALE_SINCE, null);
            $this->notifyRecovered();
        }
    }

    public function status(): SchedulerHeartbeatStatus
    {
        $raw = $this->settings->get(self::SETTING_HEARTBEAT_AT);
        if (! is_string($raw) || $raw === '') {
            return SchedulerHeartbeatStatus::never(self::THRESHOLD_MINUTES);
        }

        try {
            $lastAt = Carbon::parse($raw);
        } catch (Throwable) {
            return SchedulerHeartbeatStatus::never(self::THRESHOLD_MINUTES);
        }

        $ageSeconds = (int) abs($lastAt->diffInSeconds(now()));
        $stale = $ageSeconds > self::THRESHOLD_MINUTES * 60;

        return new SchedulerHeartbeatStatus(
            state: $stale ? SchedulerHeartbeatStatus::STALE : SchedulerHeartbeatStatus::OK,
            lastAt: $lastAt,
            ageSeconds: $ageSeconds,
            thresholdMinutes: self::THRESHOLD_MINUTES,
        );
    }

    public function isStale(): bool
    {
        return $this->status()->isStale();
    }

    /**
     * Fire the stale chat alert at most once per outage. Call from a web
     * request — this is the path that still runs when cron is dead.
     */
    public function notifyIfStale(): void
    {
        if (! $this->isStale()) {
            return;
        }

        if ($this->staleSince() !== null) {
            return;
        }

        $this->settings->put(self::SETTING_STALE_SINCE, now()->toIso8601String());
        $this->notifyStale();
    }

    private function staleSince(): ?string
    {
        $raw = $this->settings->get(self::SETTING_STALE_SINCE);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    private function notifyStale(): void
    {
        $status = $this->status();

        try {
            $this->chat->schedulerStale($status->ageSeconds, $status->lastAt);
        } catch (Throwable $e) {
            Log::warning('scheduler.heartbeat.stale_notify_failed', ['error' => $e->getMessage()]);
        }
    }

    private function notifyRecovered(): void
    {
        try {
            $this->chat->schedulerRecovered();
        } catch (Throwable $e) {
            Log::warning('scheduler.heartbeat.recovered_notify_failed', ['error' => $e->getMessage()]);
        }
    }
}
