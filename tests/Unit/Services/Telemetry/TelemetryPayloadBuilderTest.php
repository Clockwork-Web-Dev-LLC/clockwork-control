<?php

use App\Models\Server;
use App\Models\Site;
use App\Services\Telemetry\TelemetryPayloadBuilder;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\InstalledModule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('TelemetryPayloadBuilder', function () {
    it('builds a payload adhering strictly to schema and approved keys', function () {
        $settings = app(Settings::class);
        $builder = new TelemetryPayloadBuilder($settings);

        $payload = $builder->build();

        $allowedKeys = [
            'schema_version',
            'install_id',
            'sent_at',
            'sites_count',
            'servers_count',
            'sites_count_bucket',
            'servers_count_bucket',
            'modules_enabled',
            'module_site_counts',
            'module_server_counts',
        ];

        expect(array_keys($payload))->toEqualCanonicalizing($allowedKeys)
            ->and($payload['schema_version'])->toBe(1)
            ->and($payload['install_id'])->toBeString()->not->toBeEmpty()
            ->and($payload['sent_at'])->toBeString()->not->toBeEmpty()
            ->and($payload['sites_count'])->toBeInt()
            ->and($payload['servers_count'])->toBeInt()
            ->and($payload['sites_count_bucket'])->toBeIn(['0', '1-5', '6-25', '26-100', '101-500', '500+'])
            ->and($payload['servers_count_bucket'])->toBeIn(['0', '1-5', '6-25', '26-100', '101-500', '500+'])
            ->and($payload)->not->toHaveKey('hosting_provider_mix')
            ->and($payload['modules_enabled'])->toBeArray()
            ->and($payload['module_site_counts'])->toBeArray()
            ->and($payload['module_server_counts'])->toBeArray();
    });

    it('persists and reuses the install_id across multiple build calls', function () {
        $settings = app(Settings::class);
        $builder = new TelemetryPayloadBuilder($settings);

        $payload1 = $builder->build();
        $payload2 = $builder->build();

        expect($payload1['install_id'])->toBe($payload2['install_id'])
            ->and($settings->get('telemetry.install_id'))->toBe($payload1['install_id']);
    });

    it('buckets site counts accurately without exposing exact numbers', function () {
        $settings = app(Settings::class);
        $builder = new TelemetryPayloadBuilder($settings);

        // 0 sites
        expect($builder->build()['sites_count_bucket'])->toBe('0');

        // 3 sites -> '1-5'
        Site::factory()->count(3)->create(['archived_at' => null]);
        expect($builder->build()['sites_count_bucket'])->toBe('1-5');

        // Total 8 sites -> '6-25'
        Site::factory()->count(5)->create(['archived_at' => null]);
        expect($builder->build()['sites_count_bucket'])->toBe('6-25');
    });

    it('includes enabled module ids sorted alphabetically and excludes disabled modules', function () {
        InstalledModule::query()->delete();
        InstalledModule::create([
            'module_id' => 'auth_github',
            'name' => 'GitHub Auth',
            'enabled' => true,
            'version' => '1.0.0',
        ]);
        InstalledModule::create([
            'module_id' => 'auth_google',
            'name' => 'Google Auth',
            'enabled' => true,
            'version' => '1.0.0',
        ]);
        InstalledModule::create([
            'module_id' => 'disabled_module',
            'name' => 'Disabled Module',
            'enabled' => false,
            'version' => '1.0.0',
        ]);

        $settings = app(Settings::class);
        $builder = new TelemetryPayloadBuilder($settings);
        $payload = $builder->build();

        expect($payload['modules_enabled'])->toBe(['auth_github', 'auth_google'])
            ->and($payload['modules_enabled'])->not->toContain('disabled_module');
    });

    it('calculates exact server and site counts separately for active modules', function () {
        $server = Server::factory()->create([
            'provider' => 'digitalocean',
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'hosting_provider' => 'spinupwp',
            'backup_relay_enabled' => true,
            'archived_at' => null,
        ]);
        Site::factory()->create([
            'server_id' => null,
            'hosting_provider' => 'pressable',
            'backup_relay_enabled' => false,
            'archived_at' => null,
        ]);

        InstalledModule::create([
            'module_id' => 'digitalocean',
            'name' => 'DigitalOcean',
            'enabled' => true,
            'version' => '1.0.0',
        ]);
        InstalledModule::create([
            'module_id' => 'spinupwp',
            'name' => 'SpinupWP',
            'enabled' => true,
            'version' => '1.0.0',
        ]);
        InstalledModule::create([
            'module_id' => 'backup-relay',
            'name' => 'Backup Relay',
            'enabled' => true,
            'version' => '1.0.0',
        ]);

        $settings = app(Settings::class);
        $builder = new TelemetryPayloadBuilder($settings);
        $payload = $builder->build();

        expect($payload['sites_count'])->toBe(2)
            ->and($payload['servers_count'])->toBe(1)
            ->and($payload['module_server_counts'])->toHaveKey('digitalocean', 1)
            ->and($payload['module_server_counts'])->not->toHaveKey('spinupwp')
            ->and($payload['module_site_counts'])->toHaveKey('digitalocean', 1)
            ->and($payload['module_site_counts'])->toHaveKey('spinupwp', 1)
            ->and($payload['module_site_counts'])->toHaveKey('backup-relay', 1);

        $breakdown = $builder->moduleBreakdown();
        $doRow = collect($breakdown)->firstWhere('id', 'digitalocean');
        expect($doRow)->not->toBeNull()
            ->and($doRow['servers'])->toBe(1)
            ->and($doRow['sites'])->toBe(1);

        $spinupRow = collect($breakdown)->firstWhere('id', 'spinupwp');
        expect($spinupRow)->not->toBeNull()
            ->and($spinupRow['servers'])->toBeNull()
            ->and($spinupRow['sites'])->toBe(1);
    });
});
