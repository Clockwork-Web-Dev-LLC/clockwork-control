<?php

namespace Modules\Seo\Google;

use App\Support\CredentialResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The OAuth half of the agency Google connection: the consent URL, the code
 * exchange, refresh, userinfo and revoke. Plain Http rather than Socialite or
 * a Google SDK, so tests can fake every call (plan: key decision 5).
 *
 * Client credentials resolve seo.* first (Settings → Integrations, then
 * CLOCKWORK_SEO_GOOGLE_* env), then the Google login client. Reusing the login
 * client only works if it is in an Internal project and also lists
 * /seo/google/callback as a redirect URI.
 */
class GoogleOAuth
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** Read-only Search Console and Analytics, plus the email to show who is connected. */
    public const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/webmasters.readonly',
        'https://www.googleapis.com/auth/analytics.readonly',
    ];

    public function __construct(private readonly CredentialResolver $resolver) {}

    public function clientId(): string
    {
        return (string) ($this->resolver->get('seo.google_client_id')
            ?: $this->resolver->get('auth_google.client_id')
            ?: config('services.google.client_id'));
    }

    public function clientSecret(): string
    {
        return (string) ($this->resolver->get('seo.google_client_secret')
            ?: $this->resolver->get('auth_google.client_secret')
            ?: config('services.google.client_secret'));
    }

    /** Where the client ID comes from, for the settings page only. */
    public function clientSource(): string
    {
        return match (true) {
            (string) $this->resolver->get('seo.google_client_id') !== '' => 'seo',
            $this->clientId() !== '' => 'login',
            default => 'unset',
        };
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function redirectUri(): string
    {
        return route('seo.google.callback');
    }

    public function authorizationUrl(string $state, ?string $loginHint = null): string
    {
        $params = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            // Always ask, so Google always returns a refresh token.
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];

        if ($loginHint !== null && $loginHint !== '') {
            $params['login_hint'] = $loginHint;
            $domain = str_contains($loginHint, '@') ? substr($loginHint, strrpos($loginHint, '@') + 1) : '';
            if ($domain !== '') {
                $params['hd'] = $domain;
            }
        }

        return self::AUTH_URL.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: string}
     *
     * @throws GoogleOAuthException
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);

        return [
            'access_token' => (string) $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => (int) $response->json('expires_in', 3600),
            'scope' => (string) $response->json('scope', ''),
        ];
    }

    /**
     * @return array{access_token: string, expires_in: int}
     *
     * @throws GoogleOAuthException
     */
    public function refresh(string $refreshToken): array
    {
        $response = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        return [
            'access_token' => (string) $response->json('access_token'),
            'expires_in' => (int) $response->json('expires_in', 3600),
        ];
    }

    /** The connected account's email, or null if Google didn't say. */
    public function userEmail(string $accessToken): ?string
    {
        try {
            $response = Http::timeout(15)->acceptJson()->withToken($accessToken)->get(self::USERINFO_URL);
        } catch (ConnectionException) {
            return null;
        }

        $email = $response->successful() ? $response->json('email') : null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /** Best effort: a token Google already dropped still counts as revoked. */
    public function revoke(string $token): bool
    {
        try {
            $response = Http::asForm()->timeout(15)->post(self::REVOKE_URL, ['token' => $token]);
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() || $response->json('error') === 'invalid_token';
    }

    /**
     * @param  array<string, string>  $params
     *
     * @throws GoogleOAuthException
     */
    private function tokenRequest(array $params): Response
    {
        if (! $this->isConfigured()) {
            throw new GoogleOAuthException('No Google client ID and secret are set for the SEO module.', 'not_configured');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, $params + [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ]);
        } catch (ConnectionException $e) {
            throw new GoogleOAuthException('Could not reach Google: '.$e->getMessage(), 'connection');
        }

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            $error = $response->json('error');
            $description = $response->json('error_description');

            throw new GoogleOAuthException(
                'Google token request failed (HTTP '.$response->status().'): '
                    .(is_string($error) ? $error : 'unknown error')
                    .(is_string($description) ? ' - '.$description : ''),
                is_string($error) ? $error : null,
            );
        }

        return $response;
    }
}
