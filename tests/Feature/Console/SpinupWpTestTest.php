<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\SpinupWpFixtures;

/**
 * Coverage for the `clockwork:spinupwp-test` connectivity-check command
 * (App\Console\Commands\SpinupWpTest). Resolves SpinupWpClient from the
 * container directly and prints straight to the console.
 *
 * NOTE: SpinupWpClient::client() chains ->retry(2, 500) without
 * `throw: false`, so Http's own default (throw: true) fires a
 * RequestException as soon as the retries are exhausted — the
 * "SpinupWP GET {$path} failed: ..." RuntimeException in get()'s
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
describe('clockwork:spinupwp-test', function () {
    it('exits with failure and reports nothing configured when the token is missing', function () {
        config(['clockwork.spinupwp.token' => '']);

        $this->artisan('clockwork:spinupwp-test')
            ->assertFailed()
            ->expectsOutputToContain('CLOCKWORK_SPINUPWP_TOKEN is not set');
    });

    it('lists servers and sites when the token is valid', function () {
        config(['clockwork.spinupwp.token' => 'swp-token']);

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response(
                SpinupWpFixtures::listResponse([SpinupWpFixtures::server()]),
                200
            ),
            'api.spinupwp.app/v1/sites*' => Http::response(
                SpinupWpFixtures::listResponse([SpinupWpFixtures::site()]),
                200
            ),
        ]);

        $this->artisan('clockwork:spinupwp-test')
            ->assertSuccessful()
            ->expectsOutputToContain('Servers visible: 1')
            ->expectsOutputToContain('Sites visible: 1');
    });

    it('exits with failure and reports the error when the token is rejected', function () {
        config(['clockwork.spinupwp.token' => 'bad-token']);

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response([
                'message' => 'Unauthenticated.',
            ], 401),
        ]);

        $expectedBody = json_encode(['message' => 'Unauthenticated.']);

        $this->artisan('clockwork:spinupwp-test')
            ->assertFailed()
            ->expectsOutputToContain("status code 401:\n{$expectedBody}");
    });
});
