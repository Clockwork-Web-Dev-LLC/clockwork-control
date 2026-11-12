<?php

use App\Services\Diagnostics\CheckResult;
use Illuminate\Support\Facades\Http;
use Modules\Seo\Diagnostics\GoogleConnectionCheck;
use Modules\Seo\Google\GoogleTokenProvider;
use Modules\Seo\Google\SearchConsoleClient;
use Tests\Fixtures\GoogleOAuthFixtures;

beforeEach(function () {
    Http::preventStrayRequests();
    GoogleOAuthFixtures::configureClient();
});

it('is skipped with no connection', function () {
    expect(app(GoogleConnectionCheck::class)->run()->status)->toBe(CheckResult::STATUS_SKIPPED);
    Http::assertNothingSent();
});

it('uses the cached token and makes one sites.list call', function () {
    $connection = GoogleOAuthFixtures::connection();
    app(GoogleTokenProvider::class)->remember($connection, 'ya29.cached', 3600);
    Http::fake([SearchConsoleClient::BASE_URL.'/sites' => Http::response(['siteEntry' => [
        ['siteUrl' => 'https://www.example.com/', 'permissionLevel' => 'siteFullUser'],
        ['siteUrl' => 'sc-domain:example.org', 'permissionLevel' => 'siteOwner'],
    ]])]);

    $result = app(GoogleConnectionCheck::class)->run();

    expect($result->status)->toBe(CheckResult::STATUS_OK)
        ->and($result->summary)->toContain('2 Search Console properties');
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer ya29.cached'));
});
