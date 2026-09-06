<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Domains\DomainExpirationChecker;
use Illuminate\Http\JsonResponse;

class DomainExpirationController extends Controller
{
    public function recheck(Site $site, DomainExpirationChecker $checker): JsonResponse
    {
        $checker->run(silent: false, siteId: $site->id, force: true);
        $site->refresh();

        return response()->json([
            'ok' => true,
            'state' => $site->domain_expiration_state,
            'state_label' => $site->domainExpirationStateLabel(),
            'expires_at' => $site->domain_expires_at?->toIso8601String(),
            'expires_formatted' => $site->domain_expires_at?->format('M j, Y') ?? 'Unknown',
            'days_remaining' => $site->domain_expires_at ? (int) now()->diffInDays($site->domain_expires_at, false) : null,
            'registrar' => $site->domain_registrar ?? 'Unknown',
            'error' => $site->domain_rdap_error,
        ]);
    }
}
