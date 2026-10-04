<?php

namespace Modules\EmailAuth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class ScanEmailAuthFleetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function handle(): void
    {
        $lockKey = 'email_auth.scan_fleet';
        Cache::put($lockKey, now()->toIso8601String(), $this->timeout);

        try {
            Artisan::call('clockwork:check-email-auth');
        } finally {
            Cache::forget($lockKey);
        }
    }
}
