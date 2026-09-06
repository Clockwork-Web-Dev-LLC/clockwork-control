<?php

namespace Tests\Unit\Services\Domains;

use App\Services\Domains\RdapClient;
use App\Support\SsrfGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RdapClientTest extends TestCase
{
    private RdapClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->client = new RdapClient(timeout: 10, tldBackoffHours: 2);
    }

    public function test_lookup_successful_response(): void
    {
        Http::fake([
            'https://rdap.org/domain/example.com' => Http::response([
                'events' => [
                    ['eventAction' => 'registration', 'eventDate' => '1995-08-14T04:00:00Z'],
                    ['eventAction' => 'expiration', 'eventDate' => '2028-08-13T04:00:00Z'],
                    ['eventAction' => 'last changed', 'eventDate' => '2024-08-14T07:01:44Z'],
                ],
                'entities' => [
                    [
                        'roles' => ['registrar'],
                        'vcardArray' => [
                            'vcard',
                            [
                                ['version', new \stdClass, 'text', '4.0'],
                                ['fn', new \stdClass, 'text', 'Example Registrar, LLC'],
                            ],
                        ],
                    ],
                ],
                'status' => ['clientTransferProhibited'],
            ], 200),
        ]);

        $result = $this->client->lookup('example.com');

        $this->assertNotNull($result);
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('2028-08-13 04:00:00', $result->expiresAt?->format('Y-m-d H:i:s'));
        $this->assertSame('Example Registrar, LLC', $result->registrar);
        $this->assertSame('clientTransferProhibited', $result->status);
    }

    public function test_lookup_detects_redemption_or_pending_delete_status(): void
    {
        Http::fake([
            'https://rdap.org/domain/expiring-domain.com' => Http::response([
                'events' => [
                    ['eventAction' => 'expiration', 'eventDate' => '2026-10-01T00:00:00Z'],
                ],
                'entities' => [],
                'status' => ['redemptionPeriod', 'clientTransferProhibited'],
            ], 200),
        ]);

        $result = $this->client->lookup('expiring-domain.com');

        $this->assertNotNull($result);
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('redemptionPeriod', $result->status);
    }

    public function test_lookup_handles_404_not_found(): void
    {
        Http::fake([
            'https://rdap.org/domain/nonexistent-domain.xyz' => Http::response([
                'errorCode' => 404,
                'title' => 'Not Found',
            ], 404),
        ]);

        $result = $this->client->lookup('nonexistent-domain.xyz');

        $this->assertNotNull($result);
        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->expiresAt);
        $this->assertStringContainsString('HTTP 404', $result->rawError);
    }

    public function test_lookup_handles_429_rate_limit_and_enters_tld_cooldown(): void
    {
        Http::fake([
            'https://rdap.org/domain/ratelimited.org' => Http::response('Too Many Requests', 429),
        ]);

        $result = $this->client->lookup('ratelimited.org');

        $this->assertNotNull($result);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('429', $result->rawError);

        // Next lookup on same TLD should skip HTTP and return null due to cooldown
        $result2 = $this->client->lookup('another-domain.org');
        $this->assertNull($result2);
        $this->assertTrue($this->client->isTldCoolingDown('org'));
    }

    public function test_lookup_handles_malformed_json_gracefully(): void
    {
        Http::fake([
            'https://rdap.org/domain/bad-json.com' => Http::response('<html>Server Error</html>', 200),
        ]);

        $result = $this->client->lookup('bad-json.com');

        $this->assertNotNull($result);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Malformed JSON', $result->rawError);
    }

    public function test_lookup_handles_network_transport_failure(): void
    {
        Http::fake([
            'https://rdap.org/domain/timeout.com' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $result = $this->client->lookup('timeout.com');

        $this->assertNotNull($result);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Connection timed out', $result->rawError);
    }

    public function test_lookup_refuses_a_referral_that_resolves_to_a_private_address(): void
    {
        // Even though rdap.org itself is fine, the redirect hardening must
        // also cover a genuinely malicious/compromised RDAP referral chain
        // that resolves somewhere internal — the SSRF guard rejects it
        // before the request is ever dispatched, gracefully surfacing as a
        // failed lookup rather than actually connecting to it.
        SsrfGuard::fake(['rdap.org' => ['10.0.0.5']]);

        $result = $this->client->lookup('private-target.com');

        $this->assertNotNull($result);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('private/reserved address', $result->rawError);

        Http::assertNothingSent();
    }
}
