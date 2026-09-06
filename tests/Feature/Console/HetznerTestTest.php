<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\HetznerFixtures;

/**
 * Coverage for the `clockwork:hetzner-test` connectivity-check command
 * (App\Console\Commands\HetznerTest). Resolves HetznerClient from the
 * container directly and prints straight to the console.
 *
 * NOTE: HetznerClient::client() chains ->retry(2, 500) without
 * `throw: false`, so Http's own default (throw: true) fires a
 * RequestException as soon as the retries are exhausted — the
 * "Hetzner GET {$path} failed: ..." RuntimeException in get()'s
 * `if ($response->failed())` branch is unreachable dead code. The failure
 * assertion below matches what the command actually prints (Http's
 * "HTTP request returned status code ..." message), not that string. It's
 * asserted as ONE expectsOutputToContain() call spanning both lines of that
 * message (not two separate calls) because $this->error() writes the whole
 * multi-line string in a single doWrite() invocation — Laravel's test double
 * matches each expectedOutputSubstrings entry against a DIFFERENT doWrite
 * call, so two chained expectsOutputToContain() assertions that both happen
 * to live inside that same single call only ever satisfy the first one.
 */
describe('clockwork:hetzner-test', function () {
    it('exits with failure and reports nothing configured when the token is missing', function () {
        config(['clockwork.hetzner.token' => '']);

        $this->artisan('clockwork:hetzner-test')
            ->assertFailed()
            ->expectsOutputToContain('CLOCKWORK_HETZNER_TOKEN is not set');
    });

    it('authenticates and lists servers when the token is valid', function () {
        config(['clockwork.hetzner.token' => 'hz-token']);

        Http::fake([
            'api.hetzner.cloud/v1/locations*' => Http::response([
                'locations' => [['id' => 1, 'name' => 'nbg1'], ['id' => 2, 'name' => 'fsn1']],
            ], 200),
            'api.hetzner.cloud/v1/servers*' => Http::response(
                HetznerFixtures::serversListResponse([HetznerFixtures::server()]),
                200
            ),
        ]);

        $this->artisan('clockwork:hetzner-test')
            ->assertSuccessful()
            ->expectsOutputToContain('Token authenticated. Locations visible: 2.')
            ->expectsOutputToContain('Servers visible: 1');
    });

    it('exits with failure and reports the error when the token is rejected', function () {
        config(['clockwork.hetzner.token' => 'bad-token']);

        Http::fake([
            'api.hetzner.cloud/v1/locations*' => Http::response([
                'error' => ['message' => 'unable to authenticate'],
            ], 401),
        ]);

        $expectedBody = json_encode(['error' => ['message' => 'unable to authenticate']]);

        $this->artisan('clockwork:hetzner-test')
            ->assertFailed()
            ->expectsOutputToContain("status code 401:\n{$expectedBody}");
    });
});
