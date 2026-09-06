<?php

namespace Modules\AuthMicrosoft;

use App\Services\Auth\OAuthLoginHandler;
use App\Support\CredentialResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Contracts\AuthProvider;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MicrosoftAuthProvider implements AuthProvider
{
    public function __construct(
        protected OAuthLoginHandler $loginHandler,
        protected CredentialResolver $resolver,
    ) {}

    public function id(): string
    {
        return 'microsoft';
    }

    public function name(): string
    {
        return 'Microsoft';
    }

    public function icon(): string
    {
        return 'fa-brands fa-microsoft';
    }

    public function buttonLabel(): string
    {
        return 'Sign in with Microsoft';
    }

    public function redirectUrl(): string
    {
        return route('auth.microsoft.redirect');
    }

    public function callbackUrl(): string
    {
        return route('auth.microsoft.callback');
    }

    public function isConfigured(): bool
    {
        $clientId = $this->clientId();
        $clientSecret = $this->clientSecret();

        return ! empty($clientId) && ! empty($clientSecret);
    }

    public function handleRedirect(): Response
    {
        $state = Str::random(40);
        session(['oauth_microsoft_state' => $state]);

        $tenant = $this->tenantId();
        $query = http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => route('auth.microsoft.callback'),
            'response_mode' => 'query',
            'scope' => 'openid profile email User.Read',
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return redirect("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?{$query}");
    }

    public function handleCallback(Request $request): RedirectResponse
    {
        $savedState = session()->pull('oauth_microsoft_state');
        $givenState = $request->query('state');

        if (! $savedState || ! hash_equals((string) $savedState, (string) $givenState)) {
            return redirect()->route('login')->with('login_denial', 'Microsoft sign-in state mismatch. Please try again.');
        }

        $code = $request->query('code');
        if (! $code) {
            $errorDesc = $request->query('error_description') ?: 'Authentication was canceled or denied.';

            return redirect()->route('login')->with('login_denial', "Microsoft sign-in failed: {$errorDesc}");
        }

        try {
            $tenant = $this->tenantId();
            $tokenResponse = Http::asForm()->timeout(15)->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'code' => $code,
                'redirect_uri' => route('auth.microsoft.callback'),
                'grant_type' => 'authorization_code',
            ]);

            if ($tokenResponse->failed()) {
                Log::warning('auth.microsoft.token_failed', ['body' => $tokenResponse->body()]);

                return redirect()->route('login')->with('login_denial', 'Microsoft token exchange failed. Please verify credentials.');
            }

            $accessToken = $tokenResponse->json('access_token');

            $profileResponse = Http::withToken($accessToken)
                ->timeout(15)
                ->get('https://graph.microsoft.com/v1.0/me');

            if ($profileResponse->failed()) {
                Log::warning('auth.microsoft.profile_failed', ['body' => $profileResponse->body()]);

                return redirect()->route('login')->with('login_denial', 'Could not retrieve Microsoft profile information.');
            }

            $profile = $profileResponse->json();
            $email = $profile['mail'] ?? $profile['userPrincipalName'] ?? null;
            $name = $profile['displayName'] ?? null;
            $providerId = $profile['id'] ?? null;
        } catch (Throwable $e) {
            Log::warning('auth.microsoft.exception', ['error' => $e->getMessage()]);

            return redirect()->route('login')->with('login_denial', 'Microsoft sign-in failed. Try again, and let an administrator know if it keeps happening.');
        }

        return $this->loginHandler->handle(
            request: $request,
            providerName: 'Microsoft',
            email: $email,
            providerId: $providerId ? (string) $providerId : null,
            providerIdColumn: 'microsoft_id',
            name: $name ? (string) $name : null,
        );
    }

    protected function clientId(): string
    {
        return (string) ($this->resolver->get('auth_microsoft.client_id') ?? config('services.microsoft.client_id', ''));
    }

    protected function clientSecret(): string
    {
        return (string) ($this->resolver->get('auth_microsoft.client_secret') ?? config('services.microsoft.client_secret', ''));
    }

    protected function tenantId(): string
    {
        $tenant = (string) ($this->resolver->get('auth_microsoft.tenant_id') ?? config('services.microsoft.tenant_id', ''));

        return $tenant !== '' ? $tenant : 'common';
    }
}
