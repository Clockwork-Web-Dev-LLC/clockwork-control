<?php

namespace App\Services\Security;

if (! class_exists(SucuriSiteCheckClient::class, false)) {
    class_alias(\Modules\Sucuri\SucuriSiteCheckClient::class, SucuriSiteCheckClient::class);
}
