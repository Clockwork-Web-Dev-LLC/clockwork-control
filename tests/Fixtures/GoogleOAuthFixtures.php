<?php

namespace Tests\Fixtures;

use Modules\Seo\Google\GoogleOAuth;
use Modules\Seo\Models\GoogleConnection;

/** Canned Google OAuth responses and a ready connection for SEO tests. */
final class GoogleOAuthFixtures
{
    public const ACCESS_TOKEN = 'ya29.test-access-token';

    public const REFRESH_TOKEN = '1//test-refresh-token-do-not-render';

    /** @return array<string, mixed> */
    public static function codeExchange(bool $withRefresh = true): array
    {
        return array_filter([
            'access_token' => self::ACCESS_TOKEN,
            'expires_in' => 3599,
            'refresh_token' => $withRefresh ? self::REFRESH_TOKEN : null,
            'scope' => implode(' ', GoogleOAuth::SCOPES),
            'token_type' => 'Bearer',
            'id_token' => 'eyJ.test.id',
        ]);
    }

    /** @return array<string, mixed> */
    public static function refresh(string $token = self::ACCESS_TOKEN): array
    {
        return ['access_token' => $token, 'expires_in' => 3599, 'token_type' => 'Bearer'];
    }

    /** @return array<string, mixed> */
    public static function invalidGrant(): array
    {
        return ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'];
    }

    /** @return array<string, mixed> */
    public static function userinfo(string $email = 'systems@clockworkwd.com'): array
    {
        return ['sub' => '1234567890', 'email' => $email, 'email_verified' => true];
    }

    public static function configureClient(): void
    {
        config([
            'clockwork.seo.google_client_id' => 'seo-client-id.apps.googleusercontent.com',
            'clockwork.seo.google_client_secret' => 'seo-client-secret',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function connection(array $overrides = []): GoogleConnection
    {
        return GoogleConnection::query()->create($overrides + [
            'account_email' => 'systems@clockworkwd.com',
            'scopes' => GoogleOAuth::SCOPES,
            'refresh_token' => self::REFRESH_TOKEN,
            'status' => GoogleConnection::STATUS_ACTIVE,
            'last_refreshed_at' => now(),
        ]);
    }
}
