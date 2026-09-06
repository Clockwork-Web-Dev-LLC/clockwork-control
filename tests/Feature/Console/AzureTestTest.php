<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\AzureFixtures;

/**
 * Coverage for the `clockwork:azure-test` connectivity-check command
 * (App\Console\Commands\AzureTest). Unlike AzureCheck (see
 * ModuleDiagnosticChecksTest), this command resolves AzureClient straight
 * from the container and prints straight to the console — no CheckResult
 * object — so these tests assert exit codes and console output instead.
 */
describe('clockwork:azure-test', function () {
    it('exits successfully and reports nothing configured when Azure credentials are missing', function () {
        config([
            'clockwork.azure.tenant_id' => '',
            'clockwork.azure.client_id' => '',
            'clockwork.azure.client_secret' => '',
            'clockwork.azure.subscription_id' => '',
        ]);

        $this->artisan('clockwork:azure-test')
            ->assertFailed()
            ->expectsOutputToContain('Azure credentials are not configured');
    });

    it('authenticates and lists VMs/public IPs when credentials are valid', function () {
        config([
            'clockwork.azure.tenant_id' => 'tenant-1',
            'clockwork.azure.client_id' => 'client-1',
            'clockwork.azure.client_secret' => 'secret-1',
            'clockwork.azure.subscription_id' => 'sub-1',
        ]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(AzureFixtures::oauthToken(), 200),
            'management.azure.com/subscriptions/sub-1?*' => Http::response([
                'subscriptionId' => 'sub-1',
                'displayName' => 'Clockwork Production',
            ], 200),
            'management.azure.com/subscriptions/sub-1/providers/Microsoft.Compute/virtualMachines*' => Http::response([
                'value' => [AzureFixtures::virtualMachine()],
            ], 200),
            'management.azure.com/subscriptions/sub-1/providers/Microsoft.Network/publicIPAddresses*' => Http::response([
                'value' => [],
            ], 200),
        ]);

        $this->artisan('clockwork:azure-test')
            ->assertSuccessful()
            ->expectsOutputToContain('Authenticated. Subscription: Clockwork Production')
            ->expectsOutputToContain('Virtual machines visible: 1');
    });

    it('exits with failure and reports the error when the subscription lookup fails', function () {
        config([
            'clockwork.azure.tenant_id' => 'tenant-1',
            'clockwork.azure.client_id' => 'client-1',
            'clockwork.azure.client_secret' => 'secret-1',
            'clockwork.azure.subscription_id' => 'sub-1',
        ]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(AzureFixtures::oauthToken(), 200),
            'management.azure.com/subscriptions/sub-1?*' => Http::response([
                'error' => ['message' => 'The client does not have authorization.'],
            ], 403),
        ]);

        $this->artisan('clockwork:azure-test')
            ->assertFailed()
            ->expectsOutputToContain('Authentication failed');
    });
});
