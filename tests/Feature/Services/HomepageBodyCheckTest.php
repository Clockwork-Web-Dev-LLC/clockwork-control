<?php

use App\Services\Uptime\HomepageBodyCheck;
use App\Services\Uptime\UptimeProber;
use App\Support\SsrfGuard;
use Illuminate\Support\Facades\Http;

describe('HomepageBodyCheck', function () {
    it('fails an empty or tiny body', function () {
        $check = new HomepageBodyCheck;

        expect($check->evaluate(''))->toContain('too short')
            ->and($check->evaluate('<html></html>'))->toContain('too short');
    });

    it('fails WordPress critical-error copy', function () {
        $body = str_repeat('Welcome to this site. ', 20).'There has been a critical error on this website.';

        expect((new HomepageBodyCheck)->evaluate($body))->toContain('There has been a critical error');
    });

    it('fails a missing required keyword', function () {
        $body = str_repeat('Welcome to this site. ', 20);

        expect((new HomepageBodyCheck)->evaluate($body, 'Acme Corp'))->toContain('required keyword')
            ->and((new HomepageBodyCheck)->evaluate($body.' Acme Corp', 'Acme Corp'))->toBeNull();
    });

    it('finds a required keyword located deep in the footer past 8KB', function () {
        // 20KB of head/body content followed by footer copyright keyword
        $body = str_repeat('<div>A long page content block with lots of text.</div>', 400)
            .'<footer>© 2026 Acme Corp. All rights reserved.</footer>';

        expect(strlen($body))->toBeGreaterThan(20000)
            ->and((new HomepageBodyCheck)->evaluate($body, 'Acme Corp'))->toBeNull();
    });

    it('allows a clean minimal landing page', function () {
        $body = '<html><head><title>Coming Soon</title></head><body><h1>Acme Launch</h1><p>We are launching in October 2026. Contact info@acme.com.</p></body></html>';

        expect((new HomepageBodyCheck)->evaluate($body))->toBeNull();
    });

    it('skips the visible-length check but still flags fatals and missing keywords', function () {
        $spaShell = '<html><body><div id="root"></div><script src="/app.js"></script></body></html>';
        $fatalShell = '<html><body><div id="root"></div>Fatal error: Uncaught Error in index.php</body></html>';
        $keywordBody = str_repeat('Welcome to this site. ', 20);

        $check = new HomepageBodyCheck;

        expect($check->evaluate($spaShell, skipVisibleLengthCheck: true))->toBeNull()
            ->and($check->evaluate($fatalShell, skipVisibleLengthCheck: true))->toContain('Fatal error:')
            ->and($check->evaluate($keywordBody, 'Acme Corp', skipVisibleLengthCheck: true))->toContain('required keyword');
    });

    it('anchors fatal error signatures to prevent false positives on plain text', function () {
        // Plain English text discussing fatal errors without PHP colon syntax should not trip
        $article = str_repeat('Padding content for article. ', 10).'How a fatal error was avoided during our launch.';
        expect((new HomepageBodyCheck)->evaluate($article))->toBeNull();

        // PHP fatal error with colon should trip
        $fatal = str_repeat('Padding content for article. ', 10).'Fatal error: Uncaught Exception in index.php';
        expect((new HomepageBodyCheck)->evaluate($fatal))->toContain('Fatal error:');
    });
});

describe('UptimeProber homepage body check', function () {
    beforeEach(function () {
        SsrfGuard::fake(['wsod.example' => ['93.184.216.34']]);
    });

    it('treats a 200 critical-error page as down', function () {
        Http::fake([
            'https://wsod.example/' => Http::response(
                str_repeat('padding ', 20).'There has been a critical error on this website.',
                200,
            ),
        ]);

        $result = (new UptimeProber)->probe('https://wsod.example/');

        expect($result->succeeded)->toBeFalse()
            ->and($result->statusCode)->toBe(200)
            ->and($result->error)->toContain('There has been a critical error');
    });

    it('does not apply the body check to scheduled maintenance', function () {
        Http::fake([
            'https://wsod.example/' => Http::response('Briefly unavailable for scheduled maintenance.', 503, [
                'Retry-After' => '120',
            ]),
        ]);

        $result = (new UptimeProber)->probe('https://wsod.example/');

        expect($result->succeeded)->toBeTrue()
            ->and($result->isMaintenance)->toBeTrue();
    });

    it('does not apply the body check to 3xx redirect stubs', function () {
        Http::fake([
            'https://wsod.example/' => Http::response('<title>Moved</title>', 302),
        ]);

        $result = (new UptimeProber)->probe('https://wsod.example/');

        expect($result->succeeded)->toBeTrue()
            ->and($result->statusCode)->toBe(302);
    });

    it('treats a near-empty 200 as up when skipBodyLengthCheck is set', function () {
        Http::fake([
            'https://wsod.example/' => Http::response('<html><body><div id="root"></div></body></html>', 200),
        ]);

        $skipped = (new UptimeProber)->probe('https://wsod.example/', skipBodyLengthCheck: true);
        $flagged = (new UptimeProber)->probe('https://wsod.example/');

        expect($skipped->succeeded)->toBeTrue()
            ->and($flagged->succeeded)->toBeFalse()
            ->and($flagged->error)->toContain('too short');
    });
});
