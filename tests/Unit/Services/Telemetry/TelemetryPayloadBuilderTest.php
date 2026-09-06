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
            'sites_count_bucket',
            'hosting_provider_mix',
            'modules_enabled',
        ];

        expect(array_keys($payload))->toEqualCanonicalizing($allowedKeys)
            ->and($payload['schema_version'])->toBe(1)
            ->and($payload['install_id'])->toBeString()->not->toBeEmpty()
            ->and($payload['sent_at'])->toBeString()->not->toBeEmpty()
            ->and($payload['sites_count_bucket'])->toBeIn(['0', '1-5', '6-25', '26-100', '101-500', '500+'])
            ->and($payload['hosting_provider_mix'])->toBeArray()
            ->and($payload['modules_enabled'])->toBeArray();
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

    it('buckets hosting provider mix for sites and servers', function () {
        Site::factory()->count(2)->create([
            'hosting_provider' => 'spinupwp',
            'archived_at' => null,
        ]);
        Site::factory()->count(1)->create([
            'hosting_provider' => 'pressable',
            'archived_at' => null,
        ]);
        Server::factory()->count(2)->create([
            'provider' => 'digitalocean',
            'is_ignored' => false,
        ]);

        $settings = app(Settings::class);
        $builder = new TelemetryPayloadBuilder($settings);
        $payload = $builder->build();

        expect($payload['hosting_provider_mix'])->toHaveKey('site:spinupwp', '1-5')
            ->and($payload['hosting_provider_mix'])->toHaveKey('site:pressable', '1-5')
            ->and($payload['hosting_provider_mix'])->toHaveKey('server:digitalocean', '1-5');
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
});
