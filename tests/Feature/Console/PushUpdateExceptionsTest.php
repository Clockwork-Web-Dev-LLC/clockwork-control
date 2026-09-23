<?php

namespace Tests\Feature\Console;

use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

describe('clockwork:push-update-exceptions console command', function () {
    it('pushes active exceptions to sites advertising update-exceptions capability', function () {
        $server = Server::factory()->create(['is_ignored' => false]);
        $site = Site::factory()->for($server)->create([
            'domain' => 'example.com',
            'companion_installed' => true,
            'companion_secret' => Str::random(40),
            'companion_capabilities' => ['update-exceptions'],
            'is_inactive' => false,
        ]);

        PluginUpdateIgnore::create([
            'site_id' => $site->id,
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => 'broken-plugin',
            'source' => PluginUpdateIgnore::SOURCE_AUTO_FAILURE,
            'failure_count' => 5,
            'client_visible' => true,
            'note' => 'Paused after 5 failures',
            'ignored_at' => now(),
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/update-exceptions" => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('clockwork:push-update-exceptions')
            ->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            return $request->url() === "https://{$site->domain}/wp-json/clockwork/v1/update-exceptions"
                && count($request['items']) === 1
                && $request['items'][0]['slug'] === 'broken-plugin';
        });
    });

    it('skips sites without update-exceptions capability', function () {
        $server = Server::factory()->create(['is_ignored' => false]);
        $site = Site::factory()->for($server)->create([
            'domain' => 'legacy.example.com',
            'companion_installed' => true,
            'companion_secret' => Str::random(40),
            'companion_capabilities' => ['cache-flush'],
            'is_inactive' => false,
        ]);

        Http::fake();

        $this->artisan('clockwork:push-update-exceptions')
            ->assertSuccessful();

        Http::assertNothingSent();
    });
});
