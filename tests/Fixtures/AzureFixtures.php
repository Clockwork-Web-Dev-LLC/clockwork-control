<?php

namespace Tests\Fixtures;

/**
 * Realistic sample Azure Resource Manager + Azure Monitor payloads for
 * Http::fake(). See modules/Azure/src/AzureClient.php and
 * AzureMetricsParser.php. Azure exposes CPU + memory — disk requires the
 * Azure Monitor Agent, not implemented.
 */
class AzureFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function oauthToken(): array
    {
        return ['access_token' => 'fake-azure-bearer-token', 'expires_in' => 3600, 'token_type' => 'Bearer'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function virtualMachine(array $overrides = []): array
    {
        return array_merge([
            'id' => '/subscriptions/sub-1/resourceGroups/rg-1/providers/Microsoft.Compute/virtualMachines/clockwork-az-01',
            'name' => 'clockwork-az-01',
            'location' => 'eastus',
            'properties' => ['hardwareProfile' => ['vmSize' => 'Standard_B2s']],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function virtualMachinesListResponse(array $vms): array
    {
        return ['value' => $vms];
    }

    /**
     * Azure Monitor metrics response — AzureMetricsParser reads
     * value[].name.value + value[].timeseries[].data[].average.
     *
     * @return array<string, mixed>
     */
    public static function vmMetricsResponse(float $cpuPercent = 12.0, float $availableMemoryBytes = 2_000_000_000.0): array
    {
        return [
            'value' => [
                [
                    'name' => ['value' => 'Percentage CPU'],
                    'timeseries' => [['data' => [['timeStamp' => now()->toIso8601String(), 'average' => $cpuPercent]]]],
                ],
                [
                    'name' => ['value' => 'Available Memory Bytes'],
                    'timeseries' => [['data' => [['timeStamp' => now()->toIso8601String(), 'average' => $availableMemoryBytes]]]],
                ],
            ],
        ];
    }
}
