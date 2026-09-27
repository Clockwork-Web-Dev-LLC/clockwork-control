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
            description: 'Legacy WordPress brute-force login attack protection plugin. Retired across the fleet in favor of native Gatekeeper.',
            credentialFields: [],
            status: ModuleManifest::STATUS_DEPRECATED,
            statusNote: 'Deprecated — replaced by native Gatekeeper module with fail2ban integration.',
        );
    }
}
