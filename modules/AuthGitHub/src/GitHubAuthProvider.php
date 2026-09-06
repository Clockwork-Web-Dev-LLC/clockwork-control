<?php

namespace Modules\AuthGitHub;

use App\Services\Auth\OAuthLoginHandler;
use App\Support\CredentialResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider as SocialiteGithubProvider;
use Modules\Core\Contracts\AuthProvider;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class GitHubAuthProvider implements AuthProvider
{
    public function __construct(
        protected OAuthLoginHandler $loginHandler,
        protected CredentialResolver $resolver,
    ) {}

    public function id(): string
    {
        return 'github';
    }

    public function name(): string
    {
        return 'GitHub';
    }

    public function icon(): string
    {
        return 'fa-brands fa-github';
    }

    public function buttonLabel(): string
    {
        return 'Sign in with GitHub';
    }

    public function redirectUrl(): string
    {
        return route('auth.github.redirect');
    }

    public function callbackUrl(): string
    {
        return route('auth.github.callback');
    }

    public function isConfigured(): bool
    {
        $clientId = $this->resolver->get('auth_github.client_id') ?? config('services.github.client_id');
        $clientSecret = $this->resolver->get('auth_github.client_secret') ?? config('services.github.client_secret');

        return ! empty($clientId) && ! empty($clientSecret);
    }

    public function handleRedirect(): Response
    {
        try {
            return $this->driver()->scopes(['read:user', 'user:email'])->redirect();
        } catch (Throwable $e) {
            Log::warning('auth.github.redirect_failed', ['error' => $e->getMessage()]);

            return redirect()->route('login')->with(
                'login_denial',
                'GitHub sign-in is not available right now. Try again, and let an administrator know if it keeps happening.'
            );
        }
    }

    public function handleCallback(Request $request): RedirectResponse
    {
        try {
            $githubUser = $this->driver()->user();
        } catch (Throwable $e) {
            Log::warning('auth.github.callback_failed', ['error' => $e->getMessage()]);

            return redirect()->route('login')->with(
                'login_denial',
                'GitHub sign-in failed. Try again, and let an administrator know if it keeps happening.'
            );
        }

        return $this->loginHandler->handle(
            request: $request,
            providerName: 'GitHub',
            email: $githubUser->getEmail(),
            providerId: (string) $githubUser->getId(),
            providerIdColumn: 'github_id',
            name: (string) ($githubUser->getName() ?: $githubUser->getNickname()),
            avatarUrl: $githubUser->getAvatar(),
        );
    }

    protected function driver(): SocialiteGithubProvider
    {
        $clientId = (string) ($this->resolver->get('auth_github.client_id') ?? config('services.github.client_id'));
        $clientSecret = (string) ($this->resolver->get('auth_github.client_secret') ?? config('services.github.client_secret'));

        if ($clientId !== '' && $clientSecret !== '') {
            config([
                'services.github.client_id' => $clientId,
                'services.github.client_secret' => $clientSecret,
            ]);
        }

        /** @var SocialiteGithubProvider $driver */
        $driver = Socialite::driver('github');
        $driver->redirectUrl(route('auth.github.callback'));

        return $driver;
    }
}
