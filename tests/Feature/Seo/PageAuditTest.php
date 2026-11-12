<?php

use App\Models\Site;
use App\Models\User;
use App\Services\Uptime\UptimeProber;
use App\Support\SsrfGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Seo\Mcp\Tools\SeoPageAudit;
use Modules\Seo\Models\PageAudit;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Pages\PageAuditor;
use Modules\Seo\Pages\PageParser;

function auditPage(string $extraHead = '', string $body = ''): string
{
    return <<<HTML
    <!doctype html><html lang="en"><head>
    <TITLE>Emergency Plumber in Austin | Acme Plumbing</TITLE>
    <meta name="Description" content="Burst pipe? Acme sends a licensed plumber in 60 minutes, day or night.">
    <link rel="canonical" href="https://www.acme.com/services">
    <script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Plumber","name":"Acme"},{"@type":["FAQPage","WebPage"]}]}</script>
    <style>.x{color:red}</style>{$extraHead}
    </head><body>
    <nav>Home Services About Contact these menu words do not count</nav>
    <h1>Emergency plumber, café-side</h1><h2>What we fix</h2><h2>Prices</h2>
    <p>One two three four five six seven eight nine ten.</p>
    <script>var ignored = "these script words do not count";</script>
    <footer>Copyright footer words do not count</footer>{$body}
    </body></html>
    HTML;
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true]);
});

it('reads the on-page basics, schema inside @graph, and counts only visible words', function () {
    $parsed = PageParser::parse(auditPage());

    expect($parsed['title'])->toBe('Emergency Plumber in Austin | Acme Plumbing')
        ->and($parsed['meta_description'])->toStartWith('Burst pipe?')
        ->and($parsed['h1s'])->toBe(['Emergency plumber, café-side'])
        ->and($parsed['h2s'])->toBe(['What we fix', 'Prices'])
        ->and($parsed['canonical'])->toBe('https://www.acme.com/services')
        ->and($parsed['meta_robots'])->toBeNull()
        ->and($parsed['schema_types'])->toBe(['Plumber', 'FAQPage', 'WebPage'])
        // h1 (3) + two h2 (4) + the sentence (10).
        ->and($parsed['word_count'])->toBe(17);

    expect(PageParser::parse('')['title'])->toBeNull()
        ->and(PageParser::parse('<p>not really a page')['word_count'])->toBe(4);
});

it('audits a page, stores it, and a second audit replaces the row', function () {
    Http::fake(['https://www.acme.com/services' => Http::response(auditPage('<meta name="robots" content="NOINDEX, follow">'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);
    $auditor = app(PageAuditor::class);

    $audit = $auditor->audit($this->site, ['https://www.acme.com/services'])[0];
    expect($audit->fetch_status)->toBe('ok')
        ->and($audit->http_status)->toBe(200)
        ->and($audit->meta_robots)->toBe('NOINDEX, follow')
        ->and($audit->word_count)->toBe(17);

    Http::assertSent(fn (Request $r) => $r->hasHeader('User-Agent', UptimeProber::userAgent()));

    $auditor->audit($this->site, ['https://www.acme.com/services']);
    expect(PageAudit::count())->toBe(1);
});

it('stores a firewall block as blocked, never as an empty page', function () {
    Http::fake([
        'https://www.acme.com/a' => Http::response('Forbidden', 403),
        'https://www.acme.com/b' => Http::response('<html><head><title>Just a moment...</title></head><body>Checking</body></html>', 200, ['Content-Type' => 'text/html']),
        'https://www.acme.com/c' => Http::response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf']),
        'https://www.acme.com/d' => Http::response('gone', 404, ['Content-Type' => 'text/html']),
    ]);

    $audits = app(PageAuditor::class)->audit($this->site, ['https://www.acme.com/a', 'https://www.acme.com/b', 'https://www.acme.com/c', 'https://www.acme.com/d']);

    expect(array_map(fn (PageAudit $a) => $a->fetch_status, $audits))->toBe(['blocked', 'blocked', 'not_html', 'error'])
        ->and($audits[0]->title)->toBeNull()
        ->and($audits[1]->title)->toBeNull()
        ->and($audits[0]->error)->toContain('firewall');
});

it('refuses anything that is not an https page on the site itself', function (string $url) {
    expect(app(PageAuditor::class)->refusal($this->site, $url))->not->toBeNull();
})->with([
    'http://www.acme.com/', 'https://evil.com/', 'https://acme.com.evil.com/', 'https://blog.acme.com/',
    'https://user:pass@acme.com/', 'https://acme.com:8443/', 'ftp://acme.com/', 'acme.com/services', 'https://169.254.169.254/',
]);

it('allows the bare and www forms of the site', function () {
    $auditor = app(PageAuditor::class);

    expect($auditor->refusal($this->site, 'https://acme.com/services'))->toBeNull()
        ->and($auditor->refusal($this->site, 'https://WWW.acme.com/services?x=1'))->toBeNull();
});

it('does not fetch a site that resolves to a private address', function () {
    SsrfGuard::fake(['www.acme.com' => ['10.0.0.5']]);
    Http::fake();

    $audit = app(PageAuditor::class)->audit($this->site, ['https://www.acme.com/'])[0];

    expect($audit->fetch_status)->toBe('error')->and($audit->error)->toContain('private');
    Http::assertNothingSent();
    SsrfGuard::fake();
});

describe('seo_page_audit', function () {
    it('returns fields with lengths and plain notes, and unknown fields for a blocked page', function () {
        Http::fake([
            'https://www.acme.com/services' => Http::response(auditPage(), 200, ['Content-Type' => 'text/html']),
            'https://www.acme.com/blocked' => Http::response('no', 403),
        ]);

        $response = ClockworkServer::actingAs($this->user)->tool(SeoPageAudit::class, ['site' => 'acme.com', 'urls' => ['https://www.acme.com/services', 'https://www.acme.com/blocked']])->assertOk();
        $content = (fn () => $this->response->toArray()['result']['structuredContent'])->call($response);

        expect($content['summary'])->toContain('read 1 of 2 pages')->toContain('1 blocked')
            ->and($content['meta']['source'])->toBe('live_fetch')
            ->and($content['pages'][0])->toMatchArray(['status' => 'ok', 'title_length' => 43, 'word_count' => 17, 'schema_types' => ['Plumber', 'FAQPage', 'WebPage']])
            ->and($content['pages'][0]['notes'])->toBe([])
            ->and($content['pages'][1])->toMatchArray(['status' => 'blocked', 'title' => null, 'h1s' => null, 'word_count' => null, 'notes' => []])
            ->and(array_column($content['meta']['caveats'], 'code'))->toContain('blocked', 'page_content_is_data');
    });

    it('refuses other sites, http and more than five URLs without fetching', function () {
        Http::fake();

        foreach ([['https://evil.com/'], ['http://www.acme.com/'], array_fill(0, 6, 'https://www.acme.com/')] as $urls) {
            ClockworkServer::actingAs($this->user)->tool(SeoPageAudit::class, ['site' => 'acme.com', 'urls' => $urls])->assertHasErrors();
        }

        Http::assertNothingSent();
        expect(PageAudit::count())->toBe(0);
    });
});
