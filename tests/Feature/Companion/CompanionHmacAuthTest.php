<?php

namespace Tests\Feature\Companion;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use App\Support\SsrfGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ClockworkCompanionClient::signedRequest() is the trust boundary between
 * this app and every managed WordPress site: it's the one place that turns
 * a plain HTTP call into an HMAC-authenticated one the Companion mu-plugin
 * will accept. These tests recompute the signature independently (same
 * algorithm, different code path) against the site's real companion_secret
 * and assert byte-for-byte equality with what the client actually sent —
 * not just "a signature header is present" — because a client that signs
 * with the wrong payload, the wrong secret, or a stale/empty body would
 * still "look" signed while being trivially forgeable or simply wrong.
 *
 * Real signing scheme (ClockworkCompanionClient::signedRequest(), and its
 * class docblock, which says it MUST match src/Auth/HmacVerifier.php in the
 * Companion plugin repo):
 *
 *   payload   = "{METHOD}\n/wp-json/clockwork/v1{route}\n{unix_timestamp}\n{body}"
 *   signature = hash_hmac('sha256', payload, $site->companion_secret)
 *
 * sent as X-Clockwork-Signature, with the timestamp echoed in
 * X-Clockwork-Timestamp.
 */
function companionSite(array $overrides = []): Site
{
    return Site::factory()->withCompanionInstalled()->create($overrides);
}

/**
 * Independent re-implementation of signedRequest()'s payload + signature
 * construction, used to verify the client's actual output rather than
 * asserting against the client's own logic.
 */
function expectedCompanionSignature(string $method, string $route, int $timestamp, string $body, string $secret): string
{
    $payload = strtoupper($method)
        ."\n".'/wp-json/clockwork/v1'.$route
        ."\n".$timestamp
        ."\n".$body;

    return hash_hmac('sha256', $payload, $secret);
}

describe('ClockworkCompanionClient HMAC request signing', function () {
    it('signs a real GET request with a signature that exactly matches an independently computed hash_hmac over the real payload', function () {
        $site = companionSite();
        $secret = $site->companion_secret; // decrypted transparently by the model's 'encrypted' cast
        expect($secret)->toBeString()->not->toBe('');

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/detect" => Http::response(
                ['ok' => true, 'is_wordpress' => true],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $before = time();
        $result = (new ClockworkCompanionClient($site))->detect();
        $after = time();

        expect($result)->toBe(['ok' => true, 'is_wordpress' => true]);

        Http::assertSent(function ($request) use ($site, $secret, $before, $after) {
            expect($request->url())->toBe("https://{$site->domain}/wp-json/clockwork/v1/detect");

            $timestampHeader = $request->header('X-Clockwork-Timestamp')[0] ?? null;
            $signatureHeader = $request->header('X-Clockwork-Signature')[0] ?? null;

            expect($timestampHeader)->not->toBeNull();
            expect($signatureHeader)->not->toBeNull();

            $timestamp = (int) $timestampHeader;

            // Plausible current Unix timestamp: within the window the test
            // itself ran in (a couple of seconds of slack either side).
            expect($timestamp)->toBeGreaterThanOrEqual($before - 2);
            expect($timestamp)->toBeLessThanOrEqual($after + 2);

            // GET requests are signed with an empty body string.
            $expected = expectedCompanionSignature('GET', '/detect', $timestamp, '', $secret);

            expect($signatureHeader)->toBe($expected);

            return true;
        });
    });

    it('includes the exact request body in the signed payload, so two different POST bodies produce two different signatures', function () {
        $site = companionSite();
        $secret = $site->companion_secret;

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(
                ['ok' => true],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $client = new ClockworkCompanionClient($site);
        $client->pushBackupsReport(['summary' => 'all good', 'count' => 1]);
        $client->pushBackupsReport(['summary' => 'something changed', 'count' => 2]);

        $sent = Http::recorded(fn ($request) => $request->url() === "https://{$site->domain}/wp-json/clockwork/v1/backups-report");

        expect($sent)->toHaveCount(2);

        [$firstRequest] = $sent[0];
        [$secondRequest] = $sent[1];

        $firstBody = $firstRequest->body();
        $secondBody = $secondRequest->body();
        expect($firstBody)->not->toBe($secondBody);

        $firstTimestamp = (int) $firstRequest->header('X-Clockwork-Timestamp')[0];
        $secondTimestamp = (int) $secondRequest->header('X-Clockwork-Timestamp')[0];

        $firstSignature = $firstRequest->header('X-Clockwork-Signature')[0];
        $secondSignature = $secondRequest->header('X-Clockwork-Signature')[0];

        // The two signatures differ...
        expect($firstSignature)->not->toBe($secondSignature);

        // ...specifically because the body is what changed, proven by
        // recomputing each one independently against its own exact body.
        $expectedFirst = expectedCompanionSignature('POST', '/backups-report', $firstTimestamp, $firstBody, $secret);
        $expectedSecond = expectedCompanionSignature('POST', '/backups-report', $secondTimestamp, $secondBody, $secret);

        expect($firstSignature)->toBe($expectedFirst);
        expect($secondSignature)->toBe($expectedSecond);
    });

    it('sends X-Clockwork-Timestamp as a plausible current Unix timestamp', function () {
        $site = companionSite();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/health" => Http::response(
                ['ok' => true],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $before = time();
        (new ClockworkCompanionClient($site))->health();
        $after = time();

        Http::assertSent(function ($request) use ($before, $after) {
            $timestamp = (int) ($request->header('X-Clockwork-Timestamp')[0] ?? 0);

            expect($timestamp)->toBeGreaterThanOrEqual($before - 2);
            expect($timestamp)->toBeLessThanOrEqual($after + 2);

            return true;
        });
    });

    it('refuses to send a request when the site has no companion_secret (null)', function () {
        $site = Site::factory()->create(['companion_secret' => null]);

        Http::fake();

        expect(fn () => (new ClockworkCompanionClient($site))->detect())
            ->toThrow(RuntimeException::class, "Site #{$site->id} ({$site->domain}) has no companion_secret. Run clockwork:install-companion first.");

        Http::assertNothingSent();
    });

    it('refuses to send a request when the site has an empty-string companion_secret', function () {
        $site = Site::factory()->create(['companion_secret' => '']);

        Http::fake();

        expect(fn () => (new ClockworkCompanionClient($site))->health())
            ->toThrow(RuntimeException::class, "Site #{$site->id} ({$site->domain}) has no companion_secret. Run clockwork:install-companion first.");

        Http::assertNothingSent();
    });

    it('applies verify => false on every signed request (self-signed / broken-chain TLS tolerance, not "skip auth")', function () {
        $site = companionSite();

        $client = new ClockworkCompanionClient($site);

        // signedRequest() is protected and returns the PendingRequest before
        // the verb method (->get()/->post()) is called on it, so we reach it
        // via reflection rather than duplicating its logic. This checks the
        // actual Guzzle-bound option Http::fake()'s assertSent() can't see
        // (verify isn't part of the outgoing PSR request object), not just
        // the docblock's claim about it.
        $method = new \ReflectionMethod(ClockworkCompanionClient::class, 'signedRequest');
        $method->setAccessible(true);

        /** @var PendingRequest $pendingRequest */
        $pendingRequest = $method->invoke($client, 'GET', '/detect', '');

        $optionsProperty = new \ReflectionProperty(PendingRequest::class, 'options');
        $optionsProperty->setAccessible(true);
        $options = $optionsProperty->getValue($pendingRequest);

        expect($options)->toHaveKey('verify');
        expect($options['verify'])->toBeFalse();

        // Belt-and-suspenders: confirm it's still true end-to-end through a
        // real signed call, not just when signedRequest() is invoked in
        // isolation.
        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/detect" => Http::response(
                ['ok' => true],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $client->detect();

        Http::assertSent(function ($request) {
            // The signature/timestamp headers are the auth mechanism;
            // verify => false only relaxes TLS chain validation, so it must
            // coexist with a genuine signature rather than replace one.
            expect($request->header('X-Clockwork-Signature'))->not->toBeEmpty();

            return true;
        });
    });
});

/*
|--------------------------------------------------------------------------
| Malformed/corrupted JSON bodies
|--------------------------------------------------------------------------
|
| Regression coverage for a real bug found live 2026-09-04: health()/
| detect()/etc all declared `: array` and blindly returned ->json(), which
| PHP coerces a null decode into a TypeError with a useless message
| ("Return value must be of type array, null returned") instead of
| anything actionable. Two distinct real sites triggered it:
|   - tourscompany.example: REST API unreachable entirely (a JS SPA's
|     catch-all intercepts every path, including the ?rest_route= fallback)
|     — genuinely not JSON, no way to recover the data.
|   - auctioneersite.example: a rogue plugin/theme snippet wraps every
|     response (even REST ones) in a <script> redirect, prefixing valid
|     JSON with non-JSON text.
| getJson()/postJson() now route through decodeJsonBody(), which salvages
| the prefixed case and throws a clear, diagnostic RuntimeException for the
| genuinely-non-JSON case.
*/
describe('ClockworkCompanionClient non-JSON response handling', function () {
    it('salvages a response body with non-JSON content injected before the real JSON', function () {
        $site = companionSite();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/health" => Http::response(
                '<script type="text/javascript">window.location = "https://'.$site->domain.'/wp-json/clockwork/v1/health?timezone="+Intl.DateTimeFormat().resolvedOptions().timeZone;</script>{"ok":true,"version":"1.31.12"}',
                200,
                ['Content-Type' => 'application/json; charset=UTF-8'],
            ),
        ]);

        $result = (new ClockworkCompanionClient($site))->health();

        expect($result)->toBe(['ok' => true, 'version' => '1.31.12']);
    });

    it('throws a clear, diagnostic error instead of a TypeError when a response is genuinely not JSON', function () {
        $site = companionSite();

        $html = '<!doctype html><html><head><title>Some Site</title></head><body><div id="root"></div></body></html>';

        // Both the direct URL and the ?rest_route= fallback get() retries to
        // on a non-JSON response must fail the same way — a site whose
        // entire routing bypasses WordPress (a JS SPA catch-all) serves the
        // identical body from both paths.
        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/health" => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            "https://{$site->domain}/?rest_route=*" => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        expect(fn () => (new ClockworkCompanionClient($site))->health())
            ->toThrow(RuntimeException::class, "Clockwork Companion GET /health on {$site->domain} returned a non-JSON or malformed body: {$html}");
    });
});

describe('ClockworkCompanionClient SSRF guard', function () {
    it('refuses to call a companion host on a private address', function () {
        Http::fake();
        $site = companionSite(['domain' => '127.0.0.1']);

        expect(fn () => (new ClockworkCompanionClient($site))->detect())
            ->toThrow(RuntimeException::class, 'private/reserved');

        Http::assertNothingSent();
    });

    it('refuses a companion host that resolves to a metadata address', function () {
        Http::fake();
        SsrfGuard::fake(['evil.example' => ['169.254.169.254']]);
        $site = companionSite(['domain' => 'evil.example']);

        expect(fn () => (new ClockworkCompanionClient($site))->detect())
            ->toThrow(RuntimeException::class, 'private/reserved');

        Http::assertNothingSent();
    });
});
