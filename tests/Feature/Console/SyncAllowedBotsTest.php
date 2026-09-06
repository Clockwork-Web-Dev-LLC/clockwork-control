<?php

namespace Tests\Feature\Console;

use App\Models\AllowedBot;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| SyncAllowedBots (artisan clockwork:sync-allowed-bots)
|--------------------------------------------------------------------------
|
| Fetches arcjet/well-known-bots JSON over HTTP and upserts UA patterns —
| see resources/docs/integrations/arcjet-bots.md. Http::fake() throughout;
| this suite must never make a real request. Covers a real upsert (rows
| created), the update-existing-row path, the stale-row pruning
| (source=arcjet rows not present in the latest sync get deleted), fetch
| failure, and a malformed/non-array response body.
*/

function arcjetBotsUrl(): string
{
    return (string) config('clockwork.arcjet.bots_url');
}

describe('successful sync', function () {
    it('upserts UA patterns from the payload, one row per accepted pattern', function () {
        Http::fake([
            arcjetBotsUrl() => Http::response([
                [
                    'id' => 'googlebot',
                    'url' => 'https://developers.google.com/search/docs/crawling-indexing/googlebot',
                    'pattern' => ['accepted' => ['Googlebot/2\\.1', 'Googlebot-Image/1\\.0']],
                ],
                [
                    'id' => 'bingbot',
                    'url' => 'https://www.bing.com/bingbot.htm',
                    'pattern' => ['accepted' => ['bingbot/2\\.0']],
                ],
            ], 200),
        ]);

        $this->artisan('clockwork:sync-allowed-bots')
            ->assertSuccessful()
            ->expectsOutputToContain('Synced 3 bot patterns from arcjet/well-known-bots.');

        expect(AllowedBot::query()->where('source', AllowedBot::SOURCE_ARCJET)->count())->toBe(3);

        $googlebot = AllowedBot::query()->where('ua_pattern', 'Googlebot/2\\.1')->first();
        expect($googlebot)->not->toBeNull();
        expect($googlebot->name)->toBe('googlebot');
        expect($googlebot->pattern_type)->toBe(AllowedBot::PATTERN_REGEX);
        expect($googlebot->reference_url)->toBe('https://developers.google.com/search/docs/crawling-indexing/googlebot');
        expect($googlebot->synced_at)->not->toBeNull();
    });

    it('updates an existing row on re-sync (unique on source + ua_pattern) instead of duplicating it', function () {
        $existing = AllowedBot::factory()->create([
            'name' => 'old-name',
            'ua_pattern' => 'Googlebot/2\\.1',
            'pattern_type' => AllowedBot::PATTERN_REGEX,
            'source' => AllowedBot::SOURCE_ARCJET,
            'synced_at' => now()->subDay(),
        ]);

        Http::fake([
            arcjetBotsUrl() => Http::response([
                [
                    'id' => 'googlebot',
                    'url' => 'https://developers.google.com/search/docs/crawling-indexing/googlebot',
                    'pattern' => ['accepted' => ['Googlebot/2\\.1']],
                ],
            ], 200),
        ]);

        $this->artisan('clockwork:sync-allowed-bots')->assertSuccessful();

        expect(AllowedBot::query()->where('source', AllowedBot::SOURCE_ARCJET)->count())->toBe(1);
        expect($existing->refresh()->name)->toBe('googlebot');
    });

    it('deletes stale arcjet rows that no longer appear in the latest payload', function () {
        AllowedBot::factory()->create([
            'ua_pattern' => 'RetiredBot/1.0',
            'source' => AllowedBot::SOURCE_ARCJET,
            'synced_at' => now()->subWeek(),
        ]);
        $manual = AllowedBot::factory()->create([
            'ua_pattern' => 'ManuallyAddedBot/1.0',
            'source' => AllowedBot::SOURCE_MANUAL,
            'synced_at' => now()->subWeek(),
        ]);

        Http::fake([
            arcjetBotsUrl() => Http::response([
                ['id' => 'newbot', 'pattern' => ['accepted' => ['NewBot/1.0']]],
            ], 200),
        ]);

        $this->artisan('clockwork:sync-allowed-bots')->assertSuccessful();

        expect(AllowedBot::query()->where('ua_pattern', 'RetiredBot/1.0')->exists())->toBeFalse();
        // Manual-source rows are never touched by the arcjet-scoped prune.
        expect(AllowedBot::query()->whereKey($manual->id)->exists())->toBeTrue();
        expect(AllowedBot::query()->where('ua_pattern', 'NewBot/1.0')->exists())->toBeTrue();
    });

    it('skips bot entries with no id or no accepted patterns without failing the whole sync', function () {
        Http::fake([
            arcjetBotsUrl() => Http::response([
                ['id' => '', 'pattern' => ['accepted' => ['ShouldSkip/1.0']]],
                ['id' => 'no-patterns', 'pattern' => ['accepted' => []]],
                ['id' => 'goodbot', 'pattern' => ['accepted' => ['GoodBot/1.0']]],
            ], 200),
        ]);

        $this->artisan('clockwork:sync-allowed-bots')
            ->assertSuccessful()
            ->expectsOutputToContain('Synced 1 bot patterns');

        expect(AllowedBot::query()->count())->toBe(1);
        expect(AllowedBot::query()->first()->ua_pattern)->toBe('GoodBot/1.0');
    });
});

describe('failure paths', function () {
    it('a persistent HTTP failure actually throws — the graceful "Fetch failed: HTTP xxx" branch is unreachable with ->retry()', function () {
        // Surprising real behavior, verified against actual output rather
        // than assumed: the command builds the request as
        // Http::timeout(30)->retry(2, 500)->get($url) and only afterwards
        // checks $response->failed(). But Laravel's retry() defaults to
        // $throw = true, and PendingRequest::sendRequest() calls
        // $response->throw() on every non-2xx attempt (including the last)
        // when $throw is true — so a persistently-failing endpoint throws a
        // RequestException straight out of the Http::get() call. The
        // handle()-level `if ($response->failed())` branch and its "Fetch
        // failed: HTTP {status}" message can only ever be reached if a
        // future change passes `throw: false` to retry(); today it's dead
        // code, and a real Arcjet outage crashes the command instead of
        // exiting cleanly with FAILURE.
        Http::fake([
            arcjetBotsUrl() => Http::response('Service Unavailable', 503),
        ]);

        expect(fn () => $this->artisan('clockwork:sync-allowed-bots')->run())
            ->toThrow(RequestException::class);

        expect(AllowedBot::query()->count())->toBe(0);
    });

    it('fails when the response body decodes to a JSON scalar (not an array at all)', function () {
        Http::fake([
            arcjetBotsUrl() => Http::response('"just-a-string"', 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:sync-allowed-bots')
            ->assertFailed()
            ->expectsOutputToContain('Unexpected response: not a JSON array.');

        expect(AllowedBot::query()->count())->toBe(0);
    });

    it('treats a JSON object body as an associative array — each value fails the per-entry is_array() check and nothing is synced', function () {
        // json_decode of a JSON object also yields a PHP array (associative),
        // so the top-level is_array($bots) check alone does not catch this —
        // verifying the command's real per-entry behavior instead of
        // assuming it errors out.
        Http::fake([
            arcjetBotsUrl() => Http::response(['error' => 'not a list'], 200),
        ]);

        $this->artisan('clockwork:sync-allowed-bots')
            ->assertSuccessful()
            ->expectsOutputToContain('No bot patterns found in payload.');

        expect(AllowedBot::query()->count())->toBe(0);
    });

    it('warns and succeeds when the payload is a JSON array with no usable bot patterns', function () {
        Http::fake([
            arcjetBotsUrl() => Http::response([], 200),
        ]);

        $this->artisan('clockwork:sync-allowed-bots')
            ->assertSuccessful()
            ->expectsOutputToContain('No bot patterns found in payload.');

        expect(AllowedBot::query()->count())->toBe(0);
    });
});
