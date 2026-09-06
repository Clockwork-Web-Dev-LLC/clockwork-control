<?php

namespace Tests\Feature\Console;

use App\Models\ActionLog;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Phase 6 console-command coverage: clockwork:clear-site-update-lock
|--------------------------------------------------------------------------
|
| Small, self-contained command: no external HTTP/SSH, so nothing needs
| mocking beyond the cache itself (the array driver in testing is already
| in-process, no real I/O). Coverage is the full option-parsing surface —
| invalid/missing site, and the audit-trail ActionLog row it writes either
| way — plus the two Cache::forget() outcomes.
*/

describe('happy path', function () {
    it('clears an existing lock and records a cache_forget_returned=true audit log', function () {
        $site = Site::factory()->create();
        Cache::put("site_update:{$site->id}", 'held', 300);

        $this->artisan('clockwork:clear-site-update-lock', ['site_id' => $site->id])->assertSuccessful();

        expect(Cache::has("site_update:{$site->id}"))->toBeFalse();

        $log = ActionLog::query()
            ->where('site_id', $site->id)
            ->where('action_type', 'plugin_update.lock_cleared')
            ->first();
        expect($log)->not->toBeNull()
            ->and($log->ok)->toBeTrue()
            ->and($log->target)->toBe("site_update:{$site->id}")
            ->and($log->details['cache_forget_returned'] ?? null)->toBeTrue();
    });

    it('still succeeds and records cache_forget_returned=false when no lock was held', function () {
        $site = Site::factory()->create();

        $this->artisan('clockwork:clear-site-update-lock', ['site_id' => $site->id])->assertSuccessful();

        $log = ActionLog::query()
            ->where('site_id', $site->id)
            ->where('action_type', 'plugin_update.lock_cleared')
            ->first();
        expect($log)->not->toBeNull()
            ->and($log->details['cache_forget_returned'] ?? null)->toBeFalse();
    });
});

describe('validation and error paths', function () {
    it('exits INVALID for a non-positive site_id without querying or logging anything', function () {
        $this->artisan('clockwork:clear-site-update-lock', ['site_id' => '0'])
            ->assertExitCode(Command::INVALID);

        expect(ActionLog::query()->count())->toBe(0);
    });

    it('exits FAILURE for a site_id that does not exist', function () {
        $this->artisan('clockwork:clear-site-update-lock', ['site_id' => 999999])
            ->assertExitCode(Command::FAILURE);

        expect(ActionLog::query()->count())->toBe(0);
    });
});
