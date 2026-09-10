<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\CloudProvider\CloudProviderRegistry;
use Modules\Core\Contracts\CloudProvider;
use Tests\Fixtures\FakeCloudProviderForPollServersTest;

/*
|--------------------------------------------------------------------------
| Supplementary coverage for `clockwork:poll-servers`
|--------------------------------------------------------------------------
|
| tests/Feature/ProviderDispatchTest.php already covers the main dispatch
| path (each server routed to its own provider's real API via Http::fake,
| unrecognized providers never dispatched, deleted-at-provider detection).
| This file only adds what that one doesn't touch: the no-providers-
| configured failure path, the metrics() exception branch, the ServerMetric
| row + last_alert_at write on a red transition, and the unlinked/ignored
| count reporting. A hand-written CloudProvider double (not Http::fake) is
| used here because these paths are about PollServers' own control flow
| around whatever a provider adapter returns/throws, not about any real
| provider's HTTP shape.
*/

describe('clockwork:poll-servers', function () {
    it('fails fast when no cloud provider tokens are configured', function () {
        Server::factory()->create(['provider_id' => '12345']);

        $this->artisan('clockwork:poll-servers')
            ->assertFailed()
            ->expectsOutputToContain('No cloud provider tokens are configured');

        expect(ServerMetric::count())->toBe(0);
    });

    it('reports unlinked and ignored counts without polling either', function () {
        $fake = new FakeCloudProviderForPollServersTest('digitalocean', ['cpu_pct' => 5.0, 'memory_pct' => null, 'disk_pct' => null, 'load_1' => null]);

        $this->mock(CloudProviderRegistry::class, function ($mock) use ($fake) {
            $mock->shouldReceive('all')->andReturn([$fake]);
            $mock->shouldReceive('resolve')->andReturn($fake);
        });

        Server::factory()->create(['provider' => 'digitalocean', 'provider_id' => null]); // unlinked
        Server::factory()->create(['provider' => 'digitalocean', 'is_ignored' => true, 'ignore_reason' => 'staging']); // ignored

        // Both parenthetical fragments are written by the SAME $this->info()
        // call (one line) — Laravel's command-output test double matches
        // each chained expectsOutputToContain() against a DIFFERENT doWrite
        // call, so two separate assertions here would only ever satisfy the
        // first one (see SpinupWpTestTest's docblock for the same footgun).
        // Assert the whole substring spanning both fragments in one call.
        $this->artisan('clockwork:poll-servers')
            ->assertSuccessful()
            ->expectsOutputToContain('(1 unlinked → unknown) (1 ignored, skipped)');

        expect(ServerMetric::count())->toBe(0);
    });

    it('counts a provider exception as an error and leaves the server\'s status/last_polled_at untouched', function () {
        $fake = new FakeCloudProviderForPollServersTest('digitalocean', ['throw' => 'metrics API timed out']);

        $this->mock(CloudProviderRegistry::class, function ($mock) use ($fake) {
            $mock->shouldReceive('all')->andReturn([$fake]);
            $mock->shouldReceive('resolve')->andReturn($fake);
        });

        $server = Server::factory()->create([
            'provider' => 'digitalocean',
            'provider_id' => '999',
            'status' => Server::STATUS_GREEN,
            'last_polled_at' => null,
        ]);

        $this->artisan('clockwork:poll-servers')
            ->assertSuccessful()
            ->expectsOutputToContain('metrics API timed out')
            ->expectsOutputToContain('errors=1');

        $server->refresh();
        expect($server->status)->toBe(Server::STATUS_GREEN)
            ->and($server->last_polled_at)->toBeNull();
        expect(ServerMetric::count())->toBe(0);
    });

    it('writes a ServerMetric row and sets last_alert_at on a green-to-red transition', function () {
        $fake = new FakeCloudProviderForPollServersTest('digitalocean', [
            'cpu_pct' => 97.5,
            'memory_pct' => 60.0,
            'disk_pct' => 40.0,
            'load_1' => 3.2,
        ]);

        $this->mock(CloudProviderRegistry::class, function ($mock) use ($fake) {
            $mock->shouldReceive('all')->andReturn([$fake]);
            $mock->shouldReceive('resolve')->andReturn($fake);
        });

        $server = Server::factory()->create([
            'provider' => 'digitalocean',
            'provider_id' => '111',
            'status' => Server::STATUS_GREEN,
            'last_alert_at' => null,
        ]);

        $this->artisan('clockwork:poll-servers')
            ->assertSuccessful()
            ->expectsOutputToContain('red=1');

        $server->refresh();
        expect($server->status)->toBe(Server::STATUS_RED)
            ->and($server->last_alert_at)->not->toBeNull()
            ->and($server->last_polled_at)->not->toBeNull();

        // ServerMetric casts these decimal:2 — Eloquent hands back a numeric
        // string ("97.50"), not a float, so cast before comparing.
        $metric = ServerMetric::where('server_id', $server->id)->firstOrFail();
        expect((float) $metric->cpu_pct)->toBe(97.5)
            ->and((float) $metric->memory_pct)->toBe(60.0)
            ->and((float) $metric->disk_pct)->toBe(40.0)
            ->and((float) $metric->load_1)->toBe(3.2);
    });

    it('does not re-stamp last_alert_at when the server was already red', function () {
        $fake = new FakeCloudProviderForPollServersTest('digitalocean', [
            'cpu_pct' => 95.0,
            'memory_pct' => null,
            'disk_pct' => null,
            'load_1' => null,
        ]);

        $this->mock(CloudProviderRegistry::class, function ($mock) use ($fake) {
            $mock->shouldReceive('all')->andReturn([$fake]);
            $mock->shouldReceive('resolve')->andReturn($fake);
        });

        $originalAlertAt = now()->subHours(3);
        $server = Server::factory()->create([
            'provider' => 'digitalocean',
            'provider_id' => '222',
            'status' => Server::STATUS_RED,
            'last_alert_at' => $originalAlertAt,
        ]);

        $this->artisan('clockwork:poll-servers')->assertSuccessful();

        expect($server->fresh()->last_alert_at->timestamp)->toBe($originalAlertAt->timestamp);
    });

    it('polls telemetry and writes metrics for servers tagged staging as long as they are not ignored', function () {
        $fake = new FakeCloudProviderForPollServersTest('digitalocean', [
            'cpu_pct' => 15.0,
            'memory_pct' => 45.0,
            'disk_pct' => 25.0,
            'load_1' => 0.5,
        ]);

        $this->mock(CloudProviderRegistry::class, function ($mock) use ($fake) {
            $mock->shouldReceive('all')->andReturn([$fake]);
            $mock->shouldReceive('resolve')->andReturn($fake);
        });

        $staging = Server::factory()->create([
            'provider' => 'digitalocean',
            'provider_id' => '333',
            'is_ignored' => false,
        ]);
        $tag = \App\Models\Tag::factory()->create(['slug' => 'staging']);
        $staging->tags()->attach($tag);

        $this->artisan('clockwork:poll-servers')
            ->assertSuccessful()
            ->expectsOutputToContain('green=1');

        expect(ServerMetric::where('server_id', $staging->id)->count())->toBe(1);
        $metric = ServerMetric::where('server_id', $staging->id)->first();
        expect((float) $metric->cpu_pct)->toBe(15.0)
            ->and((float) $metric->memory_pct)->toBe(45.0)
            ->and((float) $metric->disk_pct)->toBe(25.0);
    });
});
