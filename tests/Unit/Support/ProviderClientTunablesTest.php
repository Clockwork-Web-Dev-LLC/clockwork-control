<?php

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Azure\AzureClient;
use Modules\Cloudways\CloudwaysClient;
use Modules\DigitalOcean\DigitalOceanClient;
use Modules\GridPane\GridPaneClient;
use Modules\Hetzner\HetznerClient;
use Modules\Kinsta\KinstaClient;
use Modules\Linode\LinodeClient;
use Modules\Pressable\PressableClient;
use Modules\SpinupWp\SpinupWpClient;
use Modules\Vultr\VultrClient;
use Modules\WPEngine\WPEngineClient;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Regression coverage for a bug found while wiring the operator-configurable
 * rate-limit tunables (timeout/retry_attempts/delay_ms) through to every
 * provider client, not just DigitalOceanClient: every module's
 * ServiceProvider resolved `timeout` via CredentialResolver and passed it as
 * a concrete value into the client's promoted constructor property. Since
 * promoted params assign before the constructor body runs, the body's
 * `$this->timeout ??= ...` fallback (meant to read the Settings-backed
 * operator override) could never fire — the container-resolved client
 * silently ignored anything saved via /settings/integrations/{service}/limits.
 * Confirmed live for DigitalOceanClient before the fix: setting
 * services.digitalocean.timeout to 999 via Settings still resolved to 15 on
 * the real app(DigitalOceanClient::class) instance.
 *
 * These tests resolve each client through the real container (same path a
 * request takes) rather than constructing it directly, since a direct `new`
 * would never have exposed the bug.
 */
describe('Provider clients consume Settings-backed rate-limit tunables via the container', function () {
    $providers = [
        'digitalocean' => DigitalOceanClient::class,
        'hetzner' => HetznerClient::class,
        'linode' => LinodeClient::class,
        'vultr' => VultrClient::class,
        'azure' => AzureClient::class,
        'spinupwp' => SpinupWpClient::class,
        'pressable' => PressableClient::class,
        'wpengine' => WPEngineClient::class,
        'kinsta' => KinstaClient::class,
        'cloudways' => CloudwaysClient::class,
        'gridpane' => GridPaneClient::class,
    ];

    foreach ($providers as $serviceId => $clientClass) {
        it("resolves {$serviceId}'s operator-configured timeout/retry_attempts/delay_ms through the container", function () use ($serviceId, $clientClass) {
            app(Settings::class)->putMany([
                "services.{$serviceId}.timeout" => 111,
                "services.{$serviceId}.retry_attempts" => 4,
                "services.{$serviceId}.delay_ms" => 222,
            ]);

            $client = app($clientClass);

            expect($client->getTimeout())->toBe(111)
                ->and($client->getRetryAttempts())->toBe(4)
                ->and($client->getDelayMs())->toBe(222);
        });

        it("falls back to a sane default for {$serviceId} when nothing has been configured", function () use ($clientClass) {
            $client = app($clientClass);

            expect($client->getTimeout())->toBeGreaterThan(0)
                ->and($client->getRetryAttempts())->toBeGreaterThanOrEqual(0)
                ->and($client->getDelayMs())->toBeGreaterThanOrEqual(0);
        });
    }
});
