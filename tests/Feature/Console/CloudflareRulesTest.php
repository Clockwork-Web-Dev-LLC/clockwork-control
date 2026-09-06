<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\CloudflareFixtures;

/*
|--------------------------------------------------------------------------
| Call-site coverage for CloudflareRules (clockwork:cf-rules)
|--------------------------------------------------------------------------
|
| Every branch here goes through the real CloudflareClient against
| Http::fake() — no DB fixtures are involved, this command only reads from
| Cloudflare's API and prints. Fake URL patterns are registered from most
| specific to least specific since Http::fake() matches in array order and
| a bare "zones*" pattern would otherwise also swallow the
| /zones/{id}/rulesets/... and /zones/{id}/pagerules requests.
*/

function cfRulesFakeMap(array $overrides = []): array
{
    $zoneId = 'zone-abc123';

    return array_merge([
        "api.cloudflare.com/client/v4/zones/{$zoneId}/rulesets/phases/http_request_firewall_custom/entrypoint" => Http::response(CloudflareFixtures::envelope(['rules' => []])),
        "api.cloudflare.com/client/v4/zones/{$zoneId}/rulesets/phases/http_request_dynamic_redirect/entrypoint" => Http::response(CloudflareFixtures::envelope(['rules' => []])),
        "api.cloudflare.com/client/v4/zones/{$zoneId}/rulesets/phases/http_request_transform/entrypoint" => Http::response(CloudflareFixtures::envelope(['rules' => []])),
        "api.cloudflare.com/client/v4/zones/{$zoneId}/rulesets/phases/http_config_settings/entrypoint" => Http::response(CloudflareFixtures::envelope(['rules' => []])),
        "api.cloudflare.com/client/v4/zones/{$zoneId}/pagerules" => Http::response(CloudflareFixtures::envelope([])),
        'api.cloudflare.com/client/v4/zones*' => Http::response(CloudflareFixtures::envelope([CloudflareFixtures::zone(['id' => $zoneId, 'name' => 'example.com'])])),
    ], $overrides);
}

describe('CloudflareRules', function () {
    it('fails with a clear error when the API token is not configured', function () {
        config(['clockwork.cloudflare.api_token' => '']);

        $this->artisan('clockwork:cf-rules', ['domain' => 'example.com'])
            ->expectsOutputToContain('CLOCKWORK_CLOUDFLARE_API_TOKEN is not set')
            ->assertExitCode(1);
    });

    it('fails when the zone is not found in the account', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token']);
        Http::fake([
            'api.cloudflare.com/client/v4/zones*' => Http::response(CloudflareFixtures::envelope([])),
        ]);

        $this->artisan('clockwork:cf-rules', ['domain' => 'missing.example.com'])
            ->expectsOutputToContain("Zone 'missing.example.com' not found")
            ->assertExitCode(1);
    });

    it('dumps rules per phase and legacy page rules for a found zone', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token']);

        $customWaf = [
            'id' => 'rule-1',
            'enabled' => true,
            'action' => 'block',
            'description' => 'Block bad bots',
            'expression' => 'http.request.uri.path contains "/wp-login.php"',
        ];
        $redirect = [
            'id' => 'rule-2',
            'enabled' => true,
            'action' => 'redirect',
            'description' => 'Force https',
            'expression' => 'http.host eq "example.com"',
            'action_parameters' => [
                'from_value' => [
                    'target_url' => ['expression' => '"https://example.com"'],
                    'status_code' => 301,
                ],
            ],
        ];
        $pageRule = [
            'id' => 'pr-1',
            'status' => 'active',
            'targets' => [['constraint' => ['value' => 'example.com/old/*']]],
            'actions' => [['id' => 'forwarding_url', 'value' => ['url' => 'https://example.com/new', 'status_code' => 301]]],
        ];

        Http::fake(cfRulesFakeMap([
            'api.cloudflare.com/client/v4/zones/zone-abc123/rulesets/phases/http_request_firewall_custom/entrypoint' => Http::response(CloudflareFixtures::envelope(['rules' => [$customWaf]])),
            'api.cloudflare.com/client/v4/zones/zone-abc123/rulesets/phases/http_request_dynamic_redirect/entrypoint' => Http::response(CloudflareFixtures::envelope(['rules' => [$redirect]])),
            'api.cloudflare.com/client/v4/zones/zone-abc123/pagerules' => Http::response(CloudflareFixtures::envelope([$pageRule])),
        ]));

        $this->artisan('clockwork:cf-rules', ['domain' => 'example.com'])
            ->expectsOutputToContain('Zone: example.com (zone-abc123)')
            ->expectsOutputToContain('Custom WAF rules: 1')
            ->expectsOutputToContain('Block bad bots')
            ->expectsOutputToContain('Redirect Rules: 1')
            ->expectsOutputToContain('redirect [301] to: "https://example.com"')
            ->expectsOutputToContain('Page Rules (legacy): 1')
            ->assertExitCode(0);
    });

    it('filters rule output to expressions/actions matching --filter', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token']);

        $matching = [
            'id' => 'rule-1',
            'enabled' => true,
            'action' => 'block',
            'description' => 'Block AU traffic',
            'expression' => 'ip.geoip.country eq "AU"',
        ];
        $nonMatching = [
            'id' => 'rule-2',
            'enabled' => true,
            'action' => 'block',
            'description' => 'Block bad UA',
            'expression' => 'http.user_agent contains "curl"',
        ];

        Http::fake(cfRulesFakeMap([
            'api.cloudflare.com/client/v4/zones/zone-abc123/rulesets/phases/http_request_firewall_custom/entrypoint' => Http::response(CloudflareFixtures::envelope(['rules' => [$matching, $nonMatching]])),
        ]));

        $this->artisan('clockwork:cf-rules', ['domain' => 'example.com', '--filter' => 'AU'])
            ->expectsOutputToContain('Block AU traffic')
            ->assertExitCode(0);
    });

    it('reports missing permissions when a phase returns 403 without aborting the whole dump', function () {
        config(['clockwork.cloudflare.api_token' => 'test-token']);

        Http::fake(cfRulesFakeMap([
            'api.cloudflare.com/client/v4/zones/zone-abc123/rulesets/phases/http_request_firewall_custom/entrypoint' => Http::response(CloudflareFixtures::envelope([], false), 403),
        ]));

        $this->artisan('clockwork:cf-rules', ['domain' => 'example.com'])
            ->expectsOutputToContain("Custom WAF rules: forbidden — token needs 'Zone WAF: Read'")
            ->expectsOutputToContain('Token is missing permissions')
            ->assertExitCode(0);
    });
});
