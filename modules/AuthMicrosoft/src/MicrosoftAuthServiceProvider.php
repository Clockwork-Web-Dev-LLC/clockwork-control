<?php

namespace Modules\AuthMicrosoft;

use App\Services\Diagnostics\Checks\MicrosoftOAuthCheck;
use App\Services\Diagnostics\DiagnosticCheck;
use Illuminate\Support\Facades\Route;
use Modules\Core\Contracts\AuthProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class MicrosoftAuthServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        if (! $this->enabled()) {
            return;
        }

        Route::middleware(['web', 'throttle:10,1'])->group(function () {
            Route::get('/auth/microsoft/redirect', [MicrosoftAuthController::class, 'redirect'])->name('auth.microsoft.redirect');
            Route::get('/auth/microsoft/callback', [MicrosoftAuthController::class, 'callback'])->name('auth.microsoft.callback');
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'auth_microsoft',
            name: 'Microsoft',
            description: 'Authenticate users via Microsoft 365, Azure AD, or Entra ID accounts.',
            credentialFields: [
                'client_id' => ['label' => 'App (client) ID', 'secret' => false],
                'client_secret' => ['label' => 'Client Secret', 'secret' => true],
                'tenant_id' => ['label' => 'Directory (tenant) ID (required in production; do not use "common")', 'secret' => false],
            ],
            status: ModuleManifest::STATUS_LOOKING_FOR_TESTERS,
            statusNote: 'Microsoft Entra ID integration implemented. Looking for agencies using Microsoft 365 / Entra ID to test authentication.',
        );
    }

    public function authProvider(): ?AuthProvider
    {
        return $this->app->make(MicrosoftAuthProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(MicrosoftOAuthCheck::class);
    }
}
