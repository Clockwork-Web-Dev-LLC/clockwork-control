<?php

namespace Modules\AuthGoogle;

use App\Services\Auth\OAuthLoginHandler;
use App\Support\CredentialResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider as SocialiteGoogleProvider;
use Modules\Core\Contracts\AuthProvider;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class GoogleAuthProvider implements AuthProvider
{
    public function __construct(
        protected OAuthLoginHandler $loginHandler,
        protected CredentialResolver $resolver,
    ) {}

    public function id(): string
    {
        return 'google';
    }

    public function name(): string
    {
        return 'Google';
    }

    public function icon(): string
    {
        return 'fa-brands fa-google';
    }

    public function buttonLabel(): string
    {
        return 'Sign in with Google';
    }

    public function redirectUrl(): string
    {
        return route('auth.google.redirect');
    }

    public function callbackUrl(): string
    {
        return route('auth.google.callback');
    }

    public function isConfigured(): bool
    {
        $clientId = $this->resolver->get('auth_google.client_id') ?? config('services.google.client_id');
        $clientSecret = $this->resolver->get('auth_google.client_secret') ?? config('services.google.client_secret');

        return ! empty($clientId) && ! empty($clientSecret);
    }

    public function handleRedirect(): Response
    {
        $driver = $this->driver();

        $hd = $this->hostedDomain();
        if ($hd !== '') {
            $driver->with(['hd' => $hd]);
        }

        return $driver->redirect();
    }

    public function handleCallback(Request $request): RedirectResponse
    {
        try {
            $googleUser = $this->driver()->user();
        } catch (Throwable $e) {
            Log::warning('auth.google.callback_failed', ['error' => $e->getMessage()]);

            return redirect()->route('login')->with(
                'login_denial',
                'Google sign-in failed. Try again, and let an administrator know if it keeps happening.'
            );
        }

        $requiredHd = $this->hostedDomain();
        if ($requiredHd !== '') {
            $actualHd = $googleUser->user['hd'] ?? null;
            if (! is_string($actualHd) || strcasecmp($actualHd, $requiredHd) !== 0) {
                return redirect()->route('login')->with(
                    'login_denial',
                    'Google sign-in is restricted to the '.$requiredHd.' workspace. Use a matching account, or ask an administrator to add you.'
                );
            }
        }

        return $this->loginHandler->handle(
            request: $request,
            providerName: 'Google',
            email: $googleUser->getEmail(),
            providerId: $googleUser->getId(),
            providerIdColumn: 'google_id',
            name: $googleUser->getName(),
            avatarUrl: $googleUser->getAvatar(),
        );
    }

    protected function driver(): SocialiteGoogleProvider
    {
        $clientId = (string) ($this->resolver->get('auth_google.client_id') ?? config('services.google.client_id'));
        $clientSecret = (string) ($this->resolver->get('auth_google.client_secret') ?? config('services.google.client_secret'));

        if ($clientId !== '' && $clientSecret !== '') {
            config([
                'services.google.client_id' => $clientId,
                'services.google.client_secret' => $clientSecret,
            ]);
        }

        /** @var SocialiteGoogleProvider $driver */
        $driver = Socialite::driver('google');
        $driver->redirectUrl(route('auth.google.callback'));

        return $driver;
    }

    protected function hostedDomain(): string
    {
        return (string) ($this->resolver->get('auth_google.hosted_domain') ?? config('services.google.hosted_domain', ''));
    }
}
