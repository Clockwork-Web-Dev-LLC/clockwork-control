<?php

namespace Tests\Feature\Console;

use App\Models\ActionLog;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Behavioral coverage for clockwork:rotate-companion-secret
|--------------------------------------------------------------------------
|
| Unlike InstallCompanion/InstallLlar, this command instantiates
| ClockworkCompanionClient directly with `new` rather than resolving it
| through the container — so it can't be swapped out with $this->mock().
| The real mockable boundary is the HTTP call itself, faked here exactly
| like ClockworkCompanionClient's own signing tests
| (tests/Feature/Companion/CompanionHmacAuthTest.php) do it. This is real
| data-mutation behavior (sites.companion_secret + action_logs), so these
| assert actual DB state rather than just "didn't throw".
|
| See the "secret_pinned (409)" describe block below for a real behavior
| mismatch found while writing this suite: the command's dedicated
| "skipped (secret_pinned)" branch is unreachable dead code given how
| ClockworkCompanionClient::guard() currently handles non-2xx responses.
*/

describe('clockwork:rotate-companion-secret — argument validation', function () {
    it('fails when neither --site nor --all is given', function () {
        $this->artisan('clockwork:rotate-companion-secret')->assertFailed();
    });

    it('warns and exits successfully when no Companion-installed site matches', function () {
        $this->artisan('clockwork:rotate-companion-secret', ['--site' => 'nope.example'])
            ->assertSuccessful()
            ->expectsOutputToContain('No matching Companion-installed sites.');
    });
});

describe('clockwork:rotate-companion-secret — successful rotation', function () {
    it('persists the new secret and records an ok action log entry', function () {
        $site = Site::factory()->withCompanionInstalled()->create(['companion_secret' => 'old-secret-value']);
        $oldSecret = $site->companion_secret;

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/secret/rotate" => Http::response(
                ['secret' => 'brand-new-secret-value', 'rotated_at' => now()->toIso8601String()],
                200,
                ['Content-Type' => 'application/json'],
            ),
            "https://{$site->domain}/wp-json/clockwork/v1/action-log/append" => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('clockwork:rotate-companion-secret', ['--site' => $site->domain])
            ->assertSuccessful();

        $site->refresh();
        expect($site->companion_secret)->toBe('brand-new-secret-value')
            ->and($site->companion_secret)->not->toBe($oldSecret);

        $log = ActionLog::query()
            ->where('site_id', $site->id)
            ->where('action_type', ActionLog::TYPE_COMPANION_SECRET_ROTATED)
            ->first();
        expect($log)->not->toBeNull()->and($log->ok)->toBeTrue();
    });

    it('--all rotates every Companion-installed site', function () {
        $siteA = Site::factory()->withCompanionInstalled()->create();
        $siteB = Site::factory()->withCompanionInstalled()->create();
        $notInstalled = Site::factory()->create(['companion_installed' => false, 'companion_secret' => null]);

        Http::fake([
            '*/wp-json/clockwork/v1/secret/rotate' => Http::response(
                ['secret' => 'rotated-for-all', 'rotated_at' => now()->toIso8601String()],
                200,
                ['Content-Type' => 'application/json'],
            ),
            '*/wp-json/clockwork/v1/action-log/append' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('clockwork:rotate-companion-secret', ['--all' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Rotated: 2');

        expect($siteA->refresh()->companion_secret)->toBe('rotated-for-all')
            ->and($siteB->refresh()->companion_secret)->toBe('rotated-for-all')
            ->and($notInstalled->refresh()->companion_secret)->toBeNull();
    });
});

describe('clockwork:rotate-companion-secret — secret_pinned (409)', function () {
    /**
     * NOTE — this documents ACTUAL behavior, not the class docblock's
     * documented intent. The docblock (and the command's own
     * `$result['ok'] === false` branch, which prints "skipped (...)" and
     * tallies $skipped) implies a 409 secret_pinned response should be a
     * soft skip that leaves the site's stored secret untouched and exits
     * SUCCESS. In reality, ClockworkCompanionClient::guard() throws a
     * RuntimeException on ANY $response->failed() (i.e. any non-2xx status,
     * 409 included) before rotateSecret() ever gets to inspect the body and
     * return that {ok: false, error_code: 'secret_pinned', ...} shape — so
     * the command's dedicated "skipped" branch is dead code for a real
     * 409. What actually happens is indistinguishable from any other
     * transport failure: it's caught by the generic `catch (Throwable $e)`
     * around the rotateSecret() call, tallied as $failed (not $skipped),
     * and the command exits FAILURE. Confirmed by running this scenario
     * directly through Artisan::call() and inspecting the captured output.
     * Likely a real bug (RotateCompanionSecret's secret_pinned UX is
     * currently unreachable) — flagged for a human, not fixed here per
     * this phase's test-only scope.
     */
    it('is actually caught as a generic transport error (guard() throws before ok:false is ever returned), leaving the secret untouched and exiting FAILURE', function () {
        $site = Site::factory()->withCompanionInstalled()->create(['companion_secret' => 'pinned-secret']);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/secret/rotate" => Http::response(
                ['code' => 'secret_pinned', 'message' => 'Secret is pinned via wp-config.php constant.'],
                409,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        // Both substrings land in the SAME single console line (the "transport
        // error: ..." message), and PendingCommand's substring matcher
        // consumes at most one matching expectation per underlying write —
        // so only one expectsOutputToContain() call can be asserted against
        // it. 'secret_pinned' is the one that actually proves this was the
        // 409 response (vs. some other transport failure).
        $this->artisan('clockwork:rotate-companion-secret', ['--site' => $site->domain])
            ->assertFailed()
            ->expectsOutputToContain('secret_pinned');

        expect($site->refresh()->companion_secret)->toBe('pinned-secret');
        expect(ActionLog::query()->where('site_id', $site->id)->where('action_type', ActionLog::TYPE_COMPANION_SECRET_ROTATED)->count())->toBe(0);
    });
});

describe('clockwork:rotate-companion-secret — transport failure', function () {
    it('leaves the stored secret untouched and exits FAILURE', function () {
        $site = Site::factory()->withCompanionInstalled()->create(['companion_secret' => 'still-old-secret']);

        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->artisan('clockwork:rotate-companion-secret', ['--site' => $site->domain])
            ->assertFailed();

        expect($site->refresh()->companion_secret)->toBe('still-old-secret');
        expect(ActionLog::query()->where('site_id', $site->id)->count())->toBe(0);
    });
});

describe('clockwork:rotate-companion-secret — --dry-run', function () {
    it('never calls Companion and never mutates the stored secret', function () {
        $site = Site::factory()->withCompanionInstalled()->create(['companion_secret' => 'untouched-secret']);

        Http::fake();

        $this->artisan('clockwork:rotate-companion-secret', ['--site' => $site->domain, '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Rotated: 1');

        expect($site->refresh()->companion_secret)->toBe('untouched-secret');
        Http::assertNothingSent();
    });
});
