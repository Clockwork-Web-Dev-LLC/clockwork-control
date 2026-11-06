<?php

use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Facades\Http;
use Modules\Seo\Google\GoogleOAuth;
use Modules\Seo\Google\GoogleOAuthException;
use Modules\Seo\Google\GoogleTokenProvider;
use Modules\Seo\Models\GoogleConnection;
use Tests\Fixtures\GoogleOAuthFixtures;

beforeEach(function () {
    Http::preventStrayRequests();
    GoogleOAuthFixtures::configureClient();
    $this->chat = Mockery::mock(ChatNotifier::class);
    app()->instance(ChatNotifier::class, $this->chat);
});

it('caches the access token until near expiry', function () {
    Http::fake([GoogleOAuth::TOKEN_URL => Http::response(GoogleOAuthFixtures::refresh('ya29.first'))]);
    $connection = GoogleOAuthFixtures::connection();
    $provider = app(GoogleTokenProvider::class);

    expect($provider->accessToken($connection))->toBe('ya29.first')
        ->and($provider->accessToken($connection))->toBe('ya29.first');

    Http::assertSentCount(1);

    $this->travel(3599 - 300 + 1)->seconds();
    $provider->accessToken($connection);
    Http::assertSentCount(2);
});

it('marks the connection revoked on invalid_grant and alerts once', function () {
    Http::fake([GoogleOAuth::TOKEN_URL => Http::response(GoogleOAuthFixtures::invalidGrant(), 400)]);
    $connection = GoogleOAuthFixtures::connection();
    $provider = app(GoogleTokenProvider::class);

    $this->chat->shouldReceive('send')->once()->withArgs(fn (string $text) => str_contains($text, 'systems@clockworkwd.com') && str_contains($text, 'revoked'))->andReturn(true);

    expect(fn () => $provider->accessToken($connection))->toThrow(GoogleOAuthException::class);
    expect($connection->fresh()->status)->toBe(GoogleConnection::STATUS_REVOKED)
        ->and($connection->fresh()->last_error)->toContain('invalid_grant');

    // A second failure (the next night) sends nothing more and doesn't call Google.
    expect(fn () => $provider->accessToken($connection->fresh()))->toThrow(GoogleOAuthException::class);
    Http::assertSentCount(1);
});

it('records a transient failure without revoking or alerting', function () {
    Http::fake([GoogleOAuth::TOKEN_URL => Http::response(['error' => 'internal_failure'], 500)]);
    $connection = GoogleOAuthFixtures::connection();
    $this->chat->shouldNotReceive('send');

    expect(fn () => app(GoogleTokenProvider::class)->accessToken($connection))->toThrow(GoogleOAuthException::class);
    expect($connection->fresh()->status)->toBe(GoogleConnection::STATUS_ACTIVE)
        ->and($connection->fresh()->last_error)->not->toBeNull();
});
