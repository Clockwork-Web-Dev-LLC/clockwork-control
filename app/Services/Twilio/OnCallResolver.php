<?php

namespace App\Services\Twilio;

use App\Models\NotificationOffWindow;
use App\Models\NotificationRecipient;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Resolves which recipients are currently on-call given the recipient
 * roster and their off-window schedules.
 *
 * Default state: a recipient with `enabled=true` and zero off-windows
 * is on-call 24/7. An off-window with `enabled=true` whose [start, end)
 * span contains $now opts that recipient out for the duration.
 *
 * Off-windows are span-aware: a single row covers Friday 17:00 →
 * Saturday 20:00 ET in one record, even though that crosses midnight
 * and a day boundary. Same-day wraparound (e.g. Sat 22:00 → Sat 06:00,
 * meaning "until next week") is also handled.
 */
class OnCallResolver
{
    /**
     * Returns the recipients on-call at $now (default: now).
     *
     * @return Collection<int, NotificationRecipient>
     */
    public function activeAt(?Carbon $now = null): Collection
    {
        $now ??= Carbon::now();

        $recipients = NotificationRecipient::query()
            ->where('enabled', true)
            ->with(['offWindows' => fn ($q) => $q->where('enabled', true)])
            ->orderBy('name')
            ->get();

        return $recipients->reject(fn (NotificationRecipient $r) => $r->offWindows->contains(
            fn (NotificationOffWindow $w) => $this->isWindowActive($w, $now)
        ))->values();
    }

    /**
     * True iff $now (UTC, internally) falls inside the off-window in the
     * window's own timezone. Anchors the window's start to the most
     * recent matching day-of-week at-or-before $now and computes the
     * end relative to that anchor.
     */
    public function isWindowActive(NotificationOffWindow $window, Carbon $now): bool
    {
        $tz = new DateTimeZone($window->timezone ?: 'UTC');
        $local = $now->copy()->setTimezone($tz);

        [$startH, $startM] = $this->splitTime((string) $window->start_time);
        [$endH, $endM] = $this->splitTime((string) $window->end_time);

        // Step 1: find the most-recent occurrence of (start_dow, start_time)
        // at or before $local. If start_dow == today's dow and the time is
        // still in the future, back up a full week.
        $daysBackToStart = (($local->dayOfWeek - (int) $window->start_dow) + 7) % 7;
        $start = $local->copy()
            ->subDays($daysBackToStart)
            ->setTime($startH, $startM, 0);
        if ($daysBackToStart === 0 && $start->greaterThan($local)) {
            $start->subDays(7);
        }

        // Step 2: compute end relative to start. dayOffset is days from
        // start_dow to end_dow (forward, 0..6). If end_dow == start_dow
        // and end_time <= start_time, the window wraps around the full
        // week — bump end by 7 days.
        $dayOffset = (((int) $window->end_dow - (int) $window->start_dow) + 7) % 7;
        $end = $start->copy()
            ->addDays($dayOffset)
            ->setTime($endH, $endM, 0);
        if ($dayOffset === 0 && $end->lessThanOrEqualTo($start)) {
            $end->addDays(7);
        }

        return $local->greaterThanOrEqualTo($start) && $local->lessThan($end);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function splitTime(string $hhmmss): array
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})/', trim($hhmmss), $m)) {
            return [0, 0];
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
