<?php

namespace Modules\Gatekeeper;

use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class GatekeeperServiceProvider extends ModuleServiceProvider
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'gatekeeper',
            name: 'Gatekeeper',
            description: 'Native WordPress brute-force login attack protection, rate limiting, and remote lockout clearing via Companion and fail2ban.',
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Native brute-force login defense replacing LLAR, with instant remote lockout clearing and fail2ban bridge.',
        );
    }
}
