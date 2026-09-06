<?php

/**
 * SAFETY NOTE — read before touching this file.
 *
 * EnsureQueueWorker::handle() calls the raw, unqualified exec() twice:
 * `launchctl list {label}` and `launchctl kickstart -k gui/{uid}/{label}`
 * against the REAL com.clockwork.queue launchd service that runs the real
 * queue worker on developer machines. This suite must never let either of
 * those calls reach the real global exec().
 *
 * The technique: PHP resolves an unqualified function call inside a
 * namespaced file by first looking for a function of that name in the
 * CURRENT namespace, and only falls back to the global one if none exists.
 * EnsureQueueWorker.php calls exec() unqualified inside `namespace
 * App\Console\Commands`, so declaring our own
 * App\Console\Commands\exec() below shadows it completely for every call
 * site in that class — the real launchctl binary is never invoked. This
 * was verified stand-alone (a throwaway script outside this repo) before
 * writing the suite below.
 *
 * Because PHP only allows one namespace-declaration style per file once
 * the bracketed form is used, the rest of the file (the actual Pest
 * describe/it block) lives in its own `namespace { ... }` block below.
 */

namespace App\Console\Commands {
    function exec(string $command, &$output = null, &$result_code = null)
    {
        $GLOBALS['__eqw_exec_calls'][] = $command;

        if (str_contains($command, 'kickstart')) {
            $output = $GLOBALS['__eqw_kickstart_output'] ?? [];
            $result_code = $GLOBALS['__eqw_kickstart_exit'] ?? 0;
        } else {
            $output = $GLOBALS['__eqw_list_output'] ?? [];
            $result_code = $GLOBALS['__eqw_list_exit'] ?? 0;
        }

        return implode("\n", $output);
    }
}

namespace {

    use App\Services\Chat\ChatNotifier;
    use Illuminate\Support\Facades\Log;

    function eqwResetExecFixtures(): void
    {
        $GLOBALS['__eqw_exec_calls'] = [];
        $GLOBALS['__eqw_list_output'] = [];
        $GLOBALS['__eqw_list_exit'] = 0;
        $GLOBALS['__eqw_kickstart_output'] = [];
        $GLOBALS['__eqw_kickstart_exit'] = 0;
    }

    describe('EnsureQueueWorker', function () {
        beforeEach(function () {
            eqwResetExecFixtures();
            Log::spy();
        });

        it('returns SUCCESS immediately when launchctl list reports a PID, without ever calling kickstart', function () {
            $GLOBALS['__eqw_list_output'] = [
                '{',
                '    "Label" = "com.clockwork.queue";',
                '    "PID" = 4242;',
                '    "LastExitStatus" = 0;',
                '}',
            ];
            $GLOBALS['__eqw_list_exit'] = 0;

            $this->mock(ChatNotifier::class, function ($mock) {
                $mock->shouldNotReceive('queueWorkerRestartFailed');
            });

            $this->artisan('clockwork:ensure-queue-worker')->assertSuccessful();

            expect($GLOBALS['__eqw_exec_calls'])->toHaveCount(1);
            expect($GLOBALS['__eqw_exec_calls'][0])->toContain('launchctl list');
            Log::shouldNotHaveReceived('info');
            Log::shouldNotHaveReceived('error');
            Log::shouldNotHaveReceived('warning');
        });

        it('kickstarts the service when launchctl list shows it loaded with no PID, and logs success on a clean restart', function () {
            $GLOBALS['__eqw_list_output'] = [
                '{',
                '    "Label" = "com.clockwork.queue";',
                '    "LastExitStatus" = 0;',
                '}',
            ];
            $GLOBALS['__eqw_list_exit'] = 0;
            $GLOBALS['__eqw_kickstart_output'] = [];
            $GLOBALS['__eqw_kickstart_exit'] = 0;

            $this->mock(ChatNotifier::class, function ($mock) {
                $mock->shouldNotReceive('queueWorkerRestartFailed');
            });

            $this->artisan('clockwork:ensure-queue-worker')->assertSuccessful();

            expect($GLOBALS['__eqw_exec_calls'])->toHaveCount(2);
            expect($GLOBALS['__eqw_exec_calls'][0])->toContain('launchctl list');
            expect($GLOBALS['__eqw_exec_calls'][1])->toContain('launchctl kickstart');
            Log::shouldHaveReceived('info')->once();
            Log::shouldNotHaveReceived('error');
            Log::shouldNotHaveReceived('warning');
        });

        it('returns FAILURE and alerts ChatNotifier when kickstart exits non-zero', function () {
            $GLOBALS['__eqw_list_output'] = ['{', '}'];
            $GLOBALS['__eqw_list_exit'] = 0;
            $GLOBALS['__eqw_kickstart_output'] = ['Could not find service "com.clockwork.queue" in domain for port'];
            $GLOBALS['__eqw_kickstart_exit'] = 1;

            $this->mock(ChatNotifier::class, function ($mock) {
                $mock->shouldReceive('queueWorkerRestartFailed')
                    ->once()
                    ->with(Mockery::on(function ($message) {
                        return str_contains($message, 'exited 1')
                            && str_contains($message, 'Could not find service');
                    }))
                    ->andReturn(true);
            });

            $this->artisan('clockwork:ensure-queue-worker')->assertFailed();

            expect($GLOBALS['__eqw_exec_calls'])->toHaveCount(2);
            Log::shouldHaveReceived('error')->once();
            Log::shouldNotHaveReceived('info');
            Log::shouldNotHaveReceived('warning');
        });

        it('still returns FAILURE without crashing when ChatNotifier itself throws, logging a warning instead of propagating', function () {
            $GLOBALS['__eqw_list_output'] = ['{', '}'];
            $GLOBALS['__eqw_list_exit'] = 0;
            $GLOBALS['__eqw_kickstart_output'] = ['boom'];
            $GLOBALS['__eqw_kickstart_exit'] = 1;

            $this->mock(ChatNotifier::class, function ($mock) {
                $mock->shouldReceive('queueWorkerRestartFailed')
                    ->once()
                    ->andThrow(new RuntimeException('slack webhook unreachable'));
            });

            // If the Throwable from queueWorkerRestartFailed() ever escaped the
            // command's try/catch, this artisan() call would itself blow up
            // with that exception rather than yielding a plain exit code.
            $this->artisan('clockwork:ensure-queue-worker')->assertFailed();

            Log::shouldHaveReceived('error')->once();
            Log::shouldHaveReceived('warning')
                ->once()
                ->withArgs(function ($message, $context = []) {
                    return $message === 'clockwork.queue_worker_watchdog.notify_failed'
                        && ($context['error'] ?? null) === 'slack webhook unreachable';
                });
        });
    });

}
