<?php

namespace Modules\Llar;

use App\Services\Sites\LlarInstaller as LegacyLlarInstaller;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class LlarServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singleton(LlarInstaller::class);
        $this->app->alias(LlarInstaller::class, LegacyLlarInstaller::class);
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'llar',
            name: 'Limit Login Attempts Reloaded',
            description: 'WordPress brute-force login attack protection, lockout log ingest, and automatic SSH fail2ban integration.',
            credentialFields: [],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Automated wp-cli deployment and security lockout log synchronization.',
        );
    }
}
