<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\CloudflareFixtures;

/*
|--------------------------------------------------------------------------
| Call-site coverage for AddCloudflareRateLimit (clockwork:cf-rate-limit)
|--------------------------------------------------------------------------
|
| This command performs a real write against Cloudflare's API (PUT the
| http_ratelimit phase ruleset), so beyond the "runs clean" floor this file
| asserts the actual outbound PUT body via Http::assertSent — the
| behavioral equivalent of a DB mutation assertion for a command whose
| state lives entirely in an external provider.
*/

const CF_RL_ZONE_ID = 'zone-abc123';

function cfRateLimitZoneFake(): array
{
    return [
        'api.cloudflare.com/client/v4/zones*' => Http::response(CloudflareFixtures::envelope([CloudflareFixtures::zone(['id' => CF_RL_ZONE_ID, 'name' => 'example.com'])])),
    ];
}

/**
 * Http::fake() matches URL patterns in array order and stops at the first
 * hit — since the zone-lookup pattern above is a bare "zones*" wildcard, it
 * would swallow /zones/{id}/rulesets/... requests too if it came first.
 * $overrides (the specific rulesets/pagerules endpoints under test) must
 * always be listed before the generic zone-lookup fallback.
 */
function cfRateLimitFakeMap(array $overrides = []): array
{
    return array_merge($overrides, cfRateLimitZoneFake());
}

describe('AddCloudflareRateLimit — preconditions', function () {
    it('fails when the API token is not configured', function () {
        config(['clockwork.cloudflare.api_token' => '']);

        $this->artisan('clockwork:cf-rate-limit', ['domain' => 'example.com'])
            ->expectsOutputToContain('CLOCKWORK_CLOUDFLARE_API_TOKEN is not set')
            ->assertExitCode(1);
    });

    it('fails when the zone is not found', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token']);
        Http::fake([
            'api.cloudflare.com/client/v4/zones*' => Http::response(CloudflareFixtures::envelope([])),
        ]);

        $this->artisan('clockwork:cf-rate-limit', ['domain' => 'missing.example.com'])
            ->expectsOutputToContain("Zone 'missing.example.com' not found")
            ->assertExitCode(1);
    });
});

describe('AddCloudflareRateLimit --list', function () {
    it('lists existing rate limit rules', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token']);

        $rule = [
            'id' => 'rl-1',
            'enabled' => true,
            'description' => 'Existing limiter',
            'expression' => 'http.host eq "example.com"',
            'ratelimit' => ['requests_per_period' => 60, 'period' => 60, 'mitigation_timeout' => 3600],
        ];

        Http::fake(cfRateLimitFakeMap([
            'api.cloudflare.com/client/v4/zones/'.CF_RL_ZONE_ID.'/rulesets/phases/http_ratelimit/entrypoint' => Http::response(CloudflareFixtures::envelope(['rules' => [$rule]])),
        ]));

        // Two expectsOutputToContain() calls whose substrings both live on the
        // SAME output line only let the first-registered one actually match
        // (Laravel's testing harness ties each expectation to one doWrite()
        // call) — so "rl-1" and its description are asserted together here.
        $this->artisan('clockwork:cf-rate-limit', ['domain' => 'example.com', '--list' => true])
            ->expectsOutputToContain('rl-1 — Existing limiter')
            ->expectsOutputToContain('limit: 60 req/60s, block 3600s')
            ->assertExitCode(0);
    });

    it('reports no rules found when the zone has none', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token']);

        Http::fake(cfRateLimitFakeMap([
            'api.cloudflare.com/client/v4/zones/'.CF_RL_ZONE_ID.'/rulesets/phases/http_ratelimit/entrypoint' => Http::response(CloudflareFixtures::envelope([], true), 404),
        ]));

        $this->artisan('clockwork:cf-rate-limit', ['domain' => 'example.com', '--list' => true])
            ->expectsOutputToContain('No rate limit rules found.')
            ->assertExitCode(0);
    });
});

describe('AddCloudflareRateLimit --dry-run', function () {
    it('prints the rule that would be created without calling the write endpoint', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token', 'clockwork.cloudflare.write_token' => '']);

        Http::fake(cfRateLimitZoneFake());

        $this->artisan('clockwork:cf-rate-limit', [
            'domain' => 'example.com',
            '--requests' => '30',
            '--period' => '10',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Would create rule:')
            ->expectsOutputToContain('"requests_per_period": 30')
            ->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'rulesets/phases/http_ratelimit'));
    });
});

describe('AddCloudflareRateLimit — add mode', function () {
    it('fails when the write token is not configured', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token', 'clockwork.cloudflare.write_token' => '']);

        Http::fake(cfRateLimitZoneFake());

        $this->artisan('clockwork:cf-rate-limit', ['domain' => 'example.com'])
            ->expectsOutputToContain('CLOCKWORK_CLOUDFLARE_WRITE_TOKEN is not set')
            ->assertExitCode(1);
    });

    it('fetches existing rules, appends the new one, and PUTs the merged ruleset', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token', 'clockwork.cloudflare.write_token' => 'write-token']);

        $existing = [
            'id' => 'rl-old',
            'action' => 'block',
            'ratelimit' => ['characteristics' => ['ip.src'], 'period' => 60, 'requests_per_period' => 100, 'mitigation_timeout' => 600],
            'expression' => 'http.host eq "other.example.com"',
            'description' => 'Old rule',
            'enabled' => true,
        ];

        Http::fake(cfRateLimitFakeMap([
            'api.cloudflare.com/client/v4/zones/'.CF_RL_ZONE_ID.'/rulesets/phases/http_ratelimit/entrypoint' => Http::sequence()
                ->push(CloudflareFixtures::envelope(['rules' => [$existing]]))
                ->push(CloudflareFixtures::envelope(['rules' => [$existing, [
                    'id' => 'rl-new',
                    'action' => 'block',
                    'ratelimit' => ['characteristics' => ['ip.src'], 'period' => 60, 'requests_per_period' => 60, 'mitigation_timeout' => 3600],
                    'expression' => 'http.host eq "example.com"',
                    'description' => 'Clockwork: block IPs exceeding 60 req/60s on example.com',
                    'enabled' => true,
                ]]])),
        ]));

        $this->artisan('clockwork:cf-rate-limit', ['domain' => 'example.com'])
            ->expectsOutputToContain('Existing rate limit rules: 1')
            ->expectsOutputToContain('Rate limit rule created (id: rl-new)')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), 'rulesets/phases/http_ratelimit')) {
                return false;
            }

            $rules = $request->data()['rules'] ?? [];

            return count($rules) === 2
                && $rules[0]['id'] === 'rl-old'
                && $rules[1]['expression'] === 'http.host eq "example.com"'
                && $rules[1]['ratelimit']['requests_per_period'] === 60;
        });
    });
});

describe('AddCloudflareRateLimit --remove', function () {
    it('fails when the write token is not configured', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token', 'clockwork.cloudflare.write_token' => '']);

        Http::fake(cfRateLimitZoneFake());

        $this->artisan('clockwork:cf-rate-limit', ['domain' => 'example.com', '--remove' => 'rl-1'])
            ->expectsOutputToContain('CLOCKWORK_CLOUDFLARE_WRITE_TOKEN is not set')
            ->assertExitCode(1);
    });

    it('removes the matching rule by ID', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token', 'clockwork.cloudflare.write_token' => 'write-token']);

        $rule = [
            'id' => 'rl-1',
            'action' => 'block',
            'ratelimit' => ['characteristics' => ['ip.src'], 'period' => 60, 'requests_per_period' => 60, 'mitigation_timeout' => 3600],
            'expression' => 'http.host eq "example.com"',
            'description' => 'To remove',
            'enabled' => true,
        ];

        Http::fake(cfRateLimitFakeMap([
            'api.cloudflare.com/client/v4/zones/'.CF_RL_ZONE_ID.'/rulesets/phases/http_ratelimit/entrypoint' => Http::response(CloudflareFixtures::envelope(['rules' => [$rule]])),
        ]));

        $this->artisan('clockwork:cf-rate-limit', ['domain' => 'example.com', '--remove' => 'rl-1'])
            ->expectsOutputToContain('Rule rl-1 removed.')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            return ($request->data()['rules'] ?? ['sentinel']) === [];
        });
    });

    it('fails cleanly when the rule ID does not exist in the zone', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token', 'clockwork.cloudflare.write_token' => 'write-token']);

        Http::fake(cfRateLimitFakeMap([
            'api.cloudflare.com/client/v4/zones/'.CF_RL_ZONE_ID.'/rulesets/phases/http_ratelimit/entrypoint' => Http::response(CloudflareFixtures::envelope(['rules' => []])),
        ]));

        $this->artisan('clockwork:cf-rate-limit', ['domain' => 'example.com', '--remove' => 'does-not-exist'])
            ->expectsOutputToContain('not found in zone')
            ->assertExitCode(1);
    });
});
