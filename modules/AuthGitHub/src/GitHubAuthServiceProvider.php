<?php

namespace Modules\AuthGitHub;

use App\Services\Diagnostics\Checks\GitHubOAuthCheck;
use App\Services\Diagnostics\DiagnosticCheck;
use Illuminate\Support\Facades\Route;
use Modules\Core\Contracts\AuthProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class GitHubAuthServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        if (! $this->enabled()) {
            return;
        }

        Route::middleware(['web', 'throttle:10,1'])->group(function () {
            Route::get('/auth/github/redirect', [GitHubAuthController::class, 'redirect'])->name('auth.github.redirect');
            Route::get('/auth/github/callback', [GitHubAuthController::class, 'callback'])->name('auth.github.callback');
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'auth_github',
            name: 'GitHub',
            description: 'Authenticate users via GitHub personal or organization accounts.',
            credentialFields: [
                'client_id' => ['label' => 'OAuth App Client ID', 'secret' => false],
                'client_secret' => ['label' => 'OAuth App Client Secret', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_LOOKING_FOR_TESTERS,
            statusNote: 'OAuth 2.0 integration implemented. Looking for agencies using GitHub accounts to test authentication.',
        );
    }

    public function authProvider(): ?AuthProvider
    {
        return $this->app->make(GitHubAuthProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(GitHubOAuthCheck::class);
    }
}
