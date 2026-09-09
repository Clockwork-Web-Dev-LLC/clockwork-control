<?php

namespace App\Services\Process;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Launch one or more `php artisan …` commands as a detached process so the
 * HTTP request can return immediately.
 *
 * Cron / QUEUE_CONNECTION=sync cannot save these fleet buttons: a queued
 * job still runs in-process before the response. nohup + a fresh PHP
 * binary (PhpExecutableFinder, not PHP_BINARY) is the pattern
 * OperationsUpdatesController already proved.
 *
 * Cache::add is the in-flight lock. Double-clicks get already_running
 * instead of stacking SSH/HTTP storms.
 */
class BackgroundArtisan
{
    public const DEFAULT_TTL_SECONDS = 600;

    public function __construct(private readonly DetachedShell $shell) {}

    /**
     * @param  list<string>  $commands  Artisan argument strings, e.g. ['clockwork:poll-servers']
     */
    public function start(
        string $lockKey,
        array $commands,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        ?string $logBasename = null,
    ): BackgroundArtisanResult {
        $commands = array_values(array_filter($commands, fn ($c) => is_string($c) && $c !== ''));
        if ($commands === []) {
            return BackgroundArtisanResult::error('No artisan command to launch.');
        }

        $gotLock = Cache::add($lockKey, now()->toIso8601String(), $ttlSeconds);
        if (! $gotLock) {
            return BackgroundArtisanResult::busy();
        }

        $php = (new PhpExecutableFinder)->find();
        if ($php === false) {
            Cache::forget($lockKey);

            return BackgroundArtisanResult::error('Could not locate the PHP binary to launch the background job.');
        }

        $chain = implode(' && ', array_map(
            fn (string $cmd) => escapeshellarg($php).' artisan '.$cmd,
            $commands,
        ));

        // Always release the cache lock when the job finishes (on success or failure)
        // so fleet actions are not blocked for the remainder of the fallback TTL.
        $forget = escapeshellarg($php).' artisan cache:forget '.escapeshellarg($lockKey);
        $fullChain = sprintf('(%s) ; %s', $chain, $forget);

        $logPath = storage_path('logs/'.($logBasename ?? 'background-artisan').'.log');
        $shell = sprintf(
            '(cd %s && nohup sh -c %s < /dev/null > %s 2>&1 &) > /dev/null 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg($fullChain),
            escapeshellarg($logPath),
        );

        $this->shell->run($shell);

        return BackgroundArtisanResult::ok();
    }
}
