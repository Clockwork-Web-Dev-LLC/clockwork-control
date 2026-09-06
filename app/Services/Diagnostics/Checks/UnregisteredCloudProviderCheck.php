<?php

namespace App\Services\Diagnostics\Checks;

use App\Models\Server;
use App\Services\CloudProvider\CloudProviderRegistry;
use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;

/**
 * Surfaces servers.provider values no registered CloudProvider module
 * claims. Added in Phase 4 when CloudProviderRegistry stopped silently
 * defaulting unrecognized providers to DigitalOcean — that gap used to be
 * invisible; now it's a diagnostics-page finding instead.
 */
class UnregisteredCloudProviderCheck implements DiagnosticCheck
{
    public function __construct(private readonly CloudProviderRegistry $registry) {}

    public function id(): string
    {
        return 'unregistered_cloud_provider';
    }

    public function name(): string
    {
        return 'Server provider values';
    }

    public function description(): string
    {
        return 'Every servers.provider value in use is claimed by a registered cloud-provider module.';
    }

    public function run(): CheckResult
    {
        $registeredIds = array_map(fn ($p) => $p->id(), $this->registry->all());

        $unrecognized = Server::query()
            ->select('provider')
            ->distinct()
            ->pluck('provider')
            ->reject(fn ($provider) => in_array($provider, $registeredIds, true))
            ->values();

        if ($unrecognized->isEmpty()) {
            return CheckResult::ok('Every server.provider value in use is registered.');
        }

        return CheckResult::fail(
            sprintf('%d unrecognized provider value(s) in use', $unrecognized->count()),
            $unrecognized->implode(', '),
        );
    }
}
