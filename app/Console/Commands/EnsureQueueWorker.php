<?php

namespace App\Console\Commands;

use App\Services\Chat\ChatNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Watchdog for the com.clockwork.queue launchd service (the Supervisor-style
 * process that drains this app's queued jobs — plugin updates, malware
 * scans, everything dispatched via Laravel's queue). Runs every 5 minutes;
 * see routes/console.php.
 *
 * If the service has crashed or been throttled into a backoff loop (loaded
 * but no PID), this kicks it back alive via `launchctl kickstart`. A
 * successful revival is log-only — a worker restarting itself every so often
 * isn't unusual enough to page anyone. A FAILED revival is the severe case:
 * it means every queued job in the app is stuck and nothing will
 * automatically fix it, so that path fires a Mattermost/Slack alert too —
 * previously log-only, so a genuinely dead queue could sit unnoticed the
 * same way the reaped-server-update and stuck-Companion-install cases did
 * before those got the same treatment.
 */
class EnsureQueueWorker extends Command
{
    protected $signature = 'clockwork:ensure-queue-worker';

    protected $description = 'Restart the com.clockwork.queue launchd service if it is not running.';

    public function handle(ChatNotifier $chatNotifier): int
    {
        $label = 'com.clockwork.queue';
        $uid = posix_getuid();

        exec("launchctl list {$label} 2>/dev/null", $output, $exitCode);

        $info = implode("\n", $output);
        $running = str_contains($info, '"PID"');

        if ($running) {
            return self::SUCCESS;
        }

        // Service is loaded but has no PID — throttled or crashed. Kick it.
        exec("launchctl kickstart -k gui/{$uid}/{$label} 2>&1", $kickOutput, $kickCode);

        $kickMsg = implode(' ', $kickOutput);
        if ($kickCode === 0) {
            Log::info("clockwork.queue_worker_watchdog: restarted {$label}", ['output' => $kickMsg]);
            $this->info("Restarted {$label}.");
        } else {
            Log::error("clockwork.queue_worker_watchdog: kickstart failed for {$label}", [
                'exit_code' => $kickCode,
                'output' => $kickMsg,
            ]);
            $this->error("kickstart failed (exit {$kickCode}): {$kickMsg}");

            try {
                $chatNotifier->queueWorkerRestartFailed(
                    "launchctl kickstart exited {$kickCode}: ".($kickMsg !== '' ? $kickMsg : 'no output captured')
                );
            } catch (Throwable $e) {
                Log::warning('clockwork.queue_worker_watchdog.notify_failed', [
                    'error' => $e->getMessage(),
                ]);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
