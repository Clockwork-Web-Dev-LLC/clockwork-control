<?php

namespace Modules\Seo\Google;

use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Seo\Models\GoogleConnection;
use Throwable;

/**
 * Short-lived access tokens for the agency connection, cached until five
 * minutes before they expire.
 *
 * When Google answers invalid_grant (the grant was revoked, or the refresh
 * token was dropped), the connection moves from active to revoked and one
 * chat message goes out. It only alerts on that change, so a nightly sync
 * that keeps failing doesn't repeat it.
 */
class GoogleTokenProvider
{
    /** Refresh this long before Google's expiry. */
    private const EXPIRY_MARGIN_SECONDS = 300;

    public function __construct(
        private readonly GoogleOAuth $oauth,
        private readonly ChatNotifier $chat,
    ) {}

    /**
     * @throws GoogleOAuthException when the grant is revoked or Google fails
     */
    public function accessToken(GoogleConnection $connection): string
    {
        $cached = Cache::get($this->cacheKey($connection));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if ($connection->isRevoked()) {
            throw new GoogleOAuthException('The Google connection was revoked. Reconnect on /seo/settings.', 'invalid_grant');
        }

        try {
            $token = $this->oauth->refresh($connection->refresh_token);
        } catch (GoogleOAuthException $e) {
            $this->recordFailure($connection, $e);

            throw $e;
        }

        $this->remember($connection, $token['access_token'], $token['expires_in']);

        $connection->forceFill([
            'last_refreshed_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        return $token['access_token'];
    }

    public function remember(GoogleConnection $connection, string $accessToken, int $expiresIn): void
    {
        $ttl = max(60, $expiresIn - self::EXPIRY_MARGIN_SECONDS);

        Cache::put($this->cacheKey($connection), $accessToken, $ttl);
    }

    /** Drop the cached token, e.g. after Google answered 401 to it. */
    public function forget(GoogleConnection $connection): void
    {
        Cache::forget($this->cacheKey($connection));
    }

    private function recordFailure(GoogleConnection $connection, GoogleOAuthException $e): void
    {
        $wasActive = $connection->isActive();

        $connection->forceFill([
            'status' => $e->isRevoked() ? GoogleConnection::STATUS_REVOKED : $connection->status,
            'last_error' => $e->getMessage(),
            'last_error_at' => now(),
        ])->save();

        if ($e->isRevoked() && $wasActive) {
            $this->alertRevoked($connection);
        }
    }

    private function alertRevoked(GoogleConnection $connection): void
    {
        $account = $connection->account_email ?? 'the agency account';

        try {
            $this->chat->send("SEO: the Google connection for {$account} was revoked. Nightly search sync is stopped. Reconnect on /seo/settings.");
        } catch (Throwable $e) {
            Log::warning('SEO: could not send the Google revoked alert', ['error' => $e->getMessage()]);
        }
    }

    private function cacheKey(GoogleConnection $connection): string
    {
        return 'seo.google.access_token.'.$connection->id;
    }
}
