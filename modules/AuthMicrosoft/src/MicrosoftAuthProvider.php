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

        if ($clientId === '' || $clientSecret === '') {
            return false;
        }

        if (! app()->runningUnitTests() && ! $this->hasPinnedTenant()) {
            return false;
        }

        return true;
    }

    public function handleRedirect(): Response
    {
        if (! app()->runningUnitTests() && ! $this->hasPinnedTenant()) {
            return redirect()->route('login')->with(
                'login_denial',
                'Microsoft sign-in requires a directory (tenant) ID. Ask an administrator to set it under Integrations.'
            );
        }

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
        if (! app()->runningUnitTests() && ! $this->hasPinnedTenant()) {
            return redirect()->route('login')->with(
                'login_denial',
                'Microsoft sign-in requires a directory (tenant) ID. Ask an administrator to set it under Integrations.'
            );
        }

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
            $upn = (string) ($profile['userPrincipalName'] ?? '');
            $userType = (string) ($profile['userType'] ?? '');
            if (str_contains($upn, '#EXT#') || strcasecmp($userType, 'Guest') === 0) {
                return redirect()->route('login')->with(
                    'login_denial',
                    'External guest Microsoft accounts are not permitted to sign in.'
                );
            }

            $idToken = $tokenResponse->json('id_token');
            if (is_string($idToken) && substr_count($idToken, '.') === 2 && $this->hasPinnedTenant()) {
                $parts = explode('.', $idToken);
                $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
                if (is_array($claims) && isset($claims['tid'])) {
                    $tokenTid = strtolower((string) $claims['tid']);
                    if ($this->isBroadTenant($tokenTid)) {
                        return redirect()->route('login')->with(
                            'login_denial',
                            'Personal Microsoft accounts are not permitted to sign in.'
                        );
                    }
                    if (preg_match('/^[0-9a-f-]{36}$/i', $tenant) && strcasecmp($tokenTid, $tenant) !== 0) {
                        return redirect()->route('login')->with(
                            'login_denial',
                            'Microsoft sign-in tenant mismatch.'
                        );
                    }
                }
            }

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
        $tenant = $this->configuredTenantId();

        if ($tenant !== '' && $this->isValidTenantFormat($tenant) && ! $this->isBroadTenant($tenant)) {
            return $tenant;
        }

        return app()->runningUnitTests() ? 'common' : '';
    }

    protected function configuredTenantId(): string
    {
        return trim((string) ($this->resolver->get('auth_microsoft.tenant_id') ?? config('services.microsoft.tenant_id', '')));
    }

    protected function hasPinnedTenant(): bool
    {
        $tenant = $this->configuredTenantId();

        return $tenant !== '' && $this->isValidTenantFormat($tenant) && ! $this->isBroadTenant($tenant);
    }

    protected function isBroadTenant(string $tenant): bool
    {
        return in_array(strtolower($tenant), [
            'common',
            'organizations',
            'consumers',
            '9188040d-6c67-4c5b-b112-36a304b66dad', // Microsoft personal consumer tenant GUID
        ], true);
    }

    protected function isValidTenantFormat(string $tenant): bool
    {
        return (bool) preg_match(
            '/^(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[a-z0-9](?:[a-z0-9.-]{0,248}[a-z0-9])?\.[a-z]{2,})$/i',
            $tenant
        );
    }
}
