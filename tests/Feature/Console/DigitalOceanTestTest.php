<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\DigitalOceanFixtures;

/**
 * Coverage for the `clockwork:digitalocean-test` connectivity-check command
 * (App\Console\Commands\DigitalOceanTest). Resolves DigitalOceanClient from
 * the container directly (unlike DigitalOceanCheck, see
 * ModuleDiagnosticChecksTest, which calls Http facade directly) and prints
 * straight to the console.
 *
 * NOTE: DigitalOceanClient::client() chains ->retry(2, 500) without
 * `throw: false`, so Http's own default (throw: true) fires a
 * RequestException as soon as the retries are exhausted — the
 * "DigitalOcean GET {$path} failed: ..." RuntimeException in get()'s
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
describe('clockwork:digitalocean-test', function () {
    it('exits with failure and reports nothing configured when the token is missing', function () {
        config(['clockwork.digitalocean.token' => '']);

        $this->artisan('clockwork:digitalocean-test')
            ->assertFailed()
            ->expectsOutputToContain('CLOCKWORK_DIGITALOCEAN_TOKEN is not set');
    });

    it('authenticates and lists droplets when the token is valid', function () {
        config(['clockwork.digitalocean.token' => 'do-token']);

        Http::fake([
            'api.digitalocean.com/v2/account' => Http::response([
                'account' => ['email' => 'ops@clockworkwd.com', 'uuid' => 'acct-uuid-1'],
            ], 200),
            'api.digitalocean.com/v2/droplets*' => Http::response(
                DigitalOceanFixtures::dropletsListResponse([DigitalOceanFixtures::droplet()]),
                200
            ),
        ]);

        $this->artisan('clockwork:digitalocean-test')
            ->assertSuccessful()
            ->expectsOutputToContain('Authenticated as: ops@clockworkwd.com')
            ->expectsOutputToContain('Droplets visible: 1');
    });

    it('exits with failure and reports the error when the token is rejected', function () {
        config(['clockwork.digitalocean.token' => 'bad-token']);

        Http::fake([
            'api.digitalocean.com/v2/account' => Http::response([
                'id' => 'unauthorized',
                'message' => 'Unable to authenticate you.',
            ], 401),
        ]);

        $expectedBody = json_encode(['id' => 'unauthorized', 'message' => 'Unable to authenticate you.']);

        $this->artisan('clockwork:digitalocean-test')
            ->assertFailed()
            ->expectsOutputToContain("status code 401:\n{$expectedBody}");
    });
});
