<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Azure\AzureClient;

#[Signature('clockwork:azure-test')]
#[Description('Verify Azure credentials by listing VMs and public IPs in the configured subscription.')]
class AzureTest extends Command
{
    public function handle(AzureClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('Azure credentials are not configured. Set CLOCKWORK_AZURE_TENANT_ID, CLOCKWORK_AZURE_CLIENT_ID, CLOCKWORK_AZURE_CLIENT_SECRET, and CLOCKWORK_AZURE_SUBSCRIPTION_ID in .env.');

            return self::FAILURE;
        }

        try {
            $sub = $client->subscription();
        } catch (\Throwable $e) {
            $this->error('Authentication failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $subName = $sub['displayName'] ?? $sub['subscriptionId'] ?? '(unknown)';
        $this->info("Authenticated. Subscription: {$subName}");

        try {
            $vms = $client->virtualMachines();
        } catch (\Throwable $e) {
            $this->error('Failed to list VMs: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Virtual machines visible: '.count($vms));

        if ($vms !== []) {
            $this->newLine();
            $this->table(
                ['Name', 'Resource Group', 'Location', 'Size', 'Resource ID'],
                collect($vms)->take(10)->map(fn (array $vm) => [
                    $vm['name'] ?? '',
                    $this->resourceGroup($vm['id'] ?? ''),
                    $vm['location'] ?? '',
                    $vm['properties']['hardwareProfile']['vmSize'] ?? '',
                    $vm['id'] ?? '',
                ])->all(),
            );

            if (count($vms) > 10) {
                $this->line('… and '.(count($vms) - 10).' more.');
            }
        }

        try {
            $pips = $client->publicIpAddresses();
        } catch (\Throwable $e) {
            $this->warn('Could not list public IPs: '.$e->getMessage());

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Public IP addresses visible: '.count($pips));

        $assigned = array_filter($pips, fn ($p) => ! empty($p['properties']['ipAddress']));
        if ($assigned) {
            $this->newLine();
            $this->table(
                ['IP Address', 'Name', 'Resource Group', 'Associated NIC'],
                collect($assigned)->take(10)->map(fn (array $pip) => [
                    $pip['properties']['ipAddress'] ?? '',
                    $pip['name'] ?? '',
                    $this->resourceGroup($pip['id'] ?? ''),
                    basename(dirname($pip['properties']['ipConfiguration']['id'] ?? '')),
                ])->all(),
            );
        }

        return self::SUCCESS;
    }

    protected function resourceGroup(string $resourceId): string
    {
        if (preg_match('~/resourceGroups/([^/]+)/~i', $resourceId, $m)) {
            return $m[1];
        }

        return '';
    }
}
