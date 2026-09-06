<?php

namespace Modules\AuthGoogle;

use App\Services\Diagnostics\Checks\GoogleOAuthCheck;
use App\Services\Diagnostics\DiagnosticCheck;
use Modules\Core\Contracts\AuthProvider;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class GoogleAuthServiceProvider extends ModuleServiceProvider
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'auth_google',
            name: 'Google',
            description: 'Authenticate users via Google Workspace or personal Google accounts.',
            credentialFields: [
                'client_id' => ['label' => 'OAuth Client ID', 'secret' => false],
                'client_secret' => ['label' => 'OAuth Client Secret', 'secret' => true],
                'hosted_domain' => ['label' => 'Hosted Domain (e.g. youragency.com)', 'secret' => false],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified and in active daily use for Clockwork team authentication.',
        );
    }

    public function authProvider(): ?AuthProvider
    {
        return $this->app->make(GoogleAuthProvider::class);
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(GoogleOAuthCheck::class);
    }
}
