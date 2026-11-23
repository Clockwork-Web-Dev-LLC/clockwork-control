<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Modules\Seo\Google\GoogleTokenProvider;
use Modules\Seo\Google\SearchConsoleClient;
use Tests\Fixtures\GoogleOAuthFixtures;
use Tests\Fixtures\SearchConsoleFixtures;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    $this->connection = GoogleOAuthFixtures::connection();
    app(GoogleTokenProvider::class)->remember($this->connection, 'ya29.cached', 3600);
    $this->client = app(SearchConsoleClient::class);
});

it('URL-encodes URL-prefix and sc-domain properties into the path', function (string $siteUrl, string $encoded) {
    Http::fake(['*' => Http::response(['rows' => []])]);

    $this->client->query($this->connection, $siteUrl, ['dimensions' => ['date']]);

    Http::assertSent(fn (Request $r) => $r->url() === SearchConsoleClient::BASE_URL.'/sites/'.$encoded.'/searchAnalytics/query');
})->with([
    ['https://www.example.com/', 'https%3A%2F%2Fwww.example.com%2F'],
    ['sc-domain:example.com', 'sc-domain%3Aexample.com'],
]);

it('keeps paging while a page is full and stops on a short one', function () {
    $full = array_fill(0, SearchConsoleClient::PAGE_SIZE, SearchConsoleFixtures::row(['q'], 1, 1));
    Http::fakeSequence()
        ->push(['rows' => $full])
        ->push(['rows' => [SearchConsoleFixtures::row(['last'], 1, 1)]]);

    $result = $this->client->queryAll($this->connection, 'https://www.example.com/', ['dimensions' => ['query']]);

    expect($result['ok'])->toBeTrue()
        ->and($result['rows'])->toHaveCount(SearchConsoleClient::PAGE_SIZE + 1)
        ->and($result['truncated'])->toBeFalse();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => $r['startRow'] === SearchConsoleClient::PAGE_SIZE && $r['rowLimit'] === SearchConsoleClient::PAGE_SIZE);
    Sleep::assertSleptTimes(1);
});

it('flags a dimension that pages past 50,000 rows as possibly truncated', function () {
    $full = array_fill(0, SearchConsoleClient::PAGE_SIZE, SearchConsoleFixtures::row(['q'], 1, 1));
    Http::fakeSequence()->push(['rows' => $full])->push(['rows' => $full])->push(['rows' => []]);

    expect($this->client->queryAll($this->connection, 'https://www.example.com/', ['dimensions' => ['query']])['truncated'])->toBeTrue();
});

it('puts the brand regex in excludingRegex on query', function () {
    expect(SearchConsoleClient::excludingQueries('(?i)(acme)'))->toBe([
        'dimensionFilterGroups' => [['filters' => [['dimension' => 'query', 'operator' => 'excludingRegex', 'expression' => '(?i)(acme)']]]],
    ]);
});

it('sends a 429 exactly once and returns an error result, not an exception', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']], 429)]);

    $result = $this->client->query($this->connection, 'https://www.example.com/', ['dimensions' => ['date']]);

    expect($result->ok)->toBeFalse()->and($result->isQuota())->toBeTrue();
    Http::assertSentCount(1);
});

it('treats a 403 quotaExceeded the same way', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 403, 'message' => 'Search Analytics load quota exceeded', 'errors' => [['reason' => 'quotaExceeded']]]], 403)]);

    $result = $this->client->query($this->connection, 'https://www.example.com/', ['dimensions' => ['date']]);

    expect($result->isQuota())->toBeTrue();
    Http::assertSentCount(1);
});

it('tries a 5xx twice in all and cuts errors to 480 characters', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => str_repeat('x', 2000)]], 503)]);

    $result = $this->client->query($this->connection, 'https://www.example.com/', ['dimensions' => ['date']]);

    expect($result->ok)->toBeFalse()->and(mb_strlen($result->error))->toBe(480);
    Http::assertSentCount(2);
});

it('fetches a fresh token once after a 401', function () {
    Http::fakeSequence()
        ->push(['error' => ['message' => 'Invalid Credentials']], 401)
        ->push(GoogleOAuthFixtures::refresh('ya29.fresh'))
        ->push(['rows' => []]);
    GoogleOAuthFixtures::configureClient();

    $result = $this->client->query($this->connection, 'https://www.example.com/', ['dimensions' => ['date']]);

    expect($result->ok)->toBeTrue();
    Http::assertSentCount(3);
});
