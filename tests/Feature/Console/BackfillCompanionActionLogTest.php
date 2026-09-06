<?php

use App\Models\ActionLog;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| BackfillCompanionActionLog — Phase 6 console coverage
|--------------------------------------------------------------------------
|
| One-time data-backfill command: re-pushes action_log rows whose
| companion_pushed_at is still NULL to each site's Companion mirror.
| ClockworkCompanionClient is `new`'d directly inside the command, so its
| /action-log/append calls are faked via Http::fake() against the real
| signed-request URL (same pattern as SitesControllerTest / CompanionCanaryDeployTest).
*/

function backfillSite(array $overrides = []): Site
{
    $server = Server::factory()->create();

    return Site::factory()->spinupwp()->create(array_merge([
        'server_id' => $server->id,
        'companion_installed' => true,
        'companion_secret' => 'test-secret-'.uniqid(),
        'companion_capabilities' => ['action-log'],
    ], $overrides));
}

describe('clockwork:backfill-companion-action-log', function () {
    it('pushes only the rows with a NULL companion_pushed_at, leaving already-pushed rows untouched (a true no-op for them)', function () {
        $site = backfillSite(['domain' => 'backfill-basic.test']);

        $needsPush = ActionLog::factory()->create([
            'site_id' => $site->id,
            'companion_pushed_at' => null,
            'ran_at' => now()->subDays(3),
        ]);
        $alreadyPushedAt = now()->subHour();
        $alreadyPushed = ActionLog::factory()->create([
            'site_id' => $site->id,
            'companion_pushed_at' => $alreadyPushedAt,
            'ran_at' => now()->subDays(2),
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/action-log/append" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:backfill-companion-action-log')
            ->expectsOutputToContain('rows_ok=1 rows_failed=0')
            ->assertSuccessful();

        Http::assertSentCount(1);
        expect($needsPush->refresh()->companion_pushed_at)->not->toBeNull();
        // Untouched: same timestamp as before the run, not re-pushed.
        expect($alreadyPushed->refresh()->companion_pushed_at->timestamp)->toBe($alreadyPushedAt->timestamp);
    });

    it('skips sites whose Companion does not advertise the action-log capability, without any HTTP call', function () {
        $site = backfillSite(['domain' => 'backfill-nocap.test', 'companion_capabilities' => ['malware-scan']]);
        ActionLog::factory()->create(['site_id' => $site->id, 'companion_pushed_at' => null]);

        $this->artisan('clockwork:backfill-companion-action-log')
            ->expectsOutputToContain('sites_skipped=1')
            ->assertSuccessful();

        Http::assertNothingSent();
    });

    it('counts a site with no pending rows as sites_with_nothing and makes no HTTP call', function () {
        $site = backfillSite(['domain' => 'backfill-clean.test']);
        ActionLog::factory()->create(['site_id' => $site->id, 'companion_pushed_at' => now()]);

        $this->artisan('clockwork:backfill-companion-action-log')
            ->expectsOutputToContain('sites_already_clean=1')
            ->assertSuccessful();

        Http::assertNothingSent();
    });

    it('counts a failed push and leaves companion_pushed_at NULL for that row', function () {
        $site = backfillSite(['domain' => 'backfill-fail.test']);
        $row = ActionLog::factory()->create(['site_id' => $site->id, 'companion_pushed_at' => null]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/action-log/append" => Http::response('Service Unavailable', 503),
        ]);

        $this->artisan('clockwork:backfill-companion-action-log')
            ->expectsOutputToContain('rows_ok=0 rows_failed=1')
            ->assertSuccessful();

        expect($row->refresh()->companion_pushed_at)->toBeNull();
    });

    it('--limit caps the number of rows pushed per site', function () {
        $site = backfillSite(['domain' => 'backfill-limit.test']);
        ActionLog::factory()->count(3)->sequence(fn ($seq) => ['ran_at' => now()->subDays(3 - $seq->index)])->create([
            'site_id' => $site->id,
            'companion_pushed_at' => null,
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/action-log/append" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:backfill-companion-action-log', ['--limit' => 2])
            ->expectsOutputToContain('rows_ok=2')
            ->assertSuccessful();

        expect(ActionLog::query()->where('site_id', $site->id)->whereNull('companion_pushed_at')->count())->toBe(1);
    });

    it('--reset-since re-pushes rows whose companion_pushed_at is older than the cutoff, leaving newer marks alone', function () {
        $site = backfillSite(['domain' => 'backfill-reset.test']);

        $oldMark = ActionLog::factory()->create([
            'site_id' => $site->id,
            'companion_pushed_at' => now()->subDays(10),
            'ran_at' => now()->subDays(10),
        ]);
        $recentMark = ActionLog::factory()->create([
            'site_id' => $site->id,
            'companion_pushed_at' => now()->subHour(),
            'ran_at' => now()->subHour(),
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/action-log/append" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:backfill-companion-action-log', ['--reset-since' => now()->subDays(1)->toDateString()])
            ->expectsOutputToContain('rows_ok=1')
            ->assertSuccessful();

        Http::assertSentCount(1);
        expect($oldMark->refresh()->companion_pushed_at)->not->toBeNull();
        expect($recentMark->refresh()->companion_pushed_at->timestamp)->toBe($recentMark->companion_pushed_at->timestamp);
    });

    it('rejects an invalid --reset-since value', function () {
        backfillSite();

        $this->artisan('clockwork:backfill-companion-action-log', ['--reset-since' => 'not-a-date'])
            ->assertFailed();
    });

    it('--site limits the run to a single site by domain', function () {
        $target = backfillSite(['domain' => 'backfill-target.test']);
        $other = backfillSite(['domain' => 'backfill-other.test']);
        ActionLog::factory()->create(['site_id' => $target->id, 'companion_pushed_at' => null]);
        ActionLog::factory()->create(['site_id' => $other->id, 'companion_pushed_at' => null]);

        Http::fake([
            "https://{$target->domain}/wp-json/clockwork/v1/action-log/append" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:backfill-companion-action-log', ['--site' => 'backfill-target.test'])->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), $other->domain));
    });

    it('exits successfully with a warning when no Companion-equipped site matches', function () {
        $this->artisan('clockwork:backfill-companion-action-log')
            ->expectsOutputToContain('No Companion-equipped sites matched.')
            ->assertSuccessful();
    });
});
