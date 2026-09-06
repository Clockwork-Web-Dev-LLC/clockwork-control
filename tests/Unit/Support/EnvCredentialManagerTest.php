<?php

use App\Models\IntegrationCredential;
use App\Support\EnvCredentialManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Every EnvCredentialManager instance in this file is bound to a throwaway
// temp file (below), never the real .env — that file holds this app's
// actual live secrets, and a suite that wrote to it directly would be one
// crash/timeout/parallel-run away from clobbering them for real.
$tempEnvPath = null;
$configBackup = null;
$servicesBackup = null;
$envArrayBackup = null;
$serverBackup = null;

beforeEach(function () use (&$tempEnvPath, &$configBackup, &$servicesBackup, &$envArrayBackup, &$serverBackup) {
    $tempEnvPath = tempnam(sys_get_temp_dir(), 'clockwork_test_env_');
    file_put_contents($tempEnvPath, "APP_NAME=Testing\n");

    $this->app->instance(EnvCredentialManager::class, new EnvCredentialManager($tempEnvPath));

    $configBackup = config('clockwork');
    $servicesBackup = config('services');
    $envArrayBackup = $_ENV;
    $serverBackup = $_SERVER;
});

afterEach(function () use (&$tempEnvPath, &$configBackup, &$servicesBackup, &$envArrayBackup, &$serverBackup) {
    if ($tempEnvPath !== null && file_exists($tempEnvPath)) {
        unlink($tempEnvPath);
    }
    config(['clockwork' => $configBackup]);
    config(['services' => $servicesBackup]);
    $_ENV = $envArrayBackup;
    $_SERVER = $serverBackup;
    foreach ([
        'CLOCKWORK_DIGITALOCEAN_TOKEN',
        'CLOCKWORK_HETZNER_TOKEN',
        'CLOCKWORK_LINODE_TOKEN',
        'CLOCKWORK_VULTR_API_KEY',
        'CLOCKWORK_AZURE_TENANT_ID',
        'CLOCKWORK_AZURE_CLIENT_ID',
        'CLOCKWORK_AZURE_CLIENT_SECRET',
        'CLOCKWORK_AZURE_SUBSCRIPTION_ID',
        'CLOCKWORK_SPINUPWP_TOKEN',
        'TWILIO_ACCOUNT_SID',
        'TWILIO_AUTH_TOKEN',
        'TWILIO_FROM_NUMBER',
        'CLOCKWORK_SLACK_WEBHOOK_URL',
        'CLOCKWORK_MATTERMOST_WEBHOOK_URL',
    ] as $key) {
        if (isset($envArrayBackup[$key])) {
            putenv("{$key}={$envArrayBackup[$key]}");
        } else {
            putenv("{$key}");
        }
    }
});

describe('EnvCredentialManager', function () {
    it('defines canonical credentials for all supported services', function () {
        $manager = app(EnvCredentialManager::class);

        $expectedServices = [
            'digitalocean',
            'hetzner',
            'linode',
            'vultr',
            'azure',
            'spinupwp',
            'pressable',
            'wpengine',
            'kinsta',
            'cloudways',
            'gridpane',
            'cloudflare',
            'gtmetrix',
            'psi',
            'security_scans',
            'twilio',
            'slack',
            'mattermost',
            'bill-com',
            'do_spaces',
            'ssh',
            'auth_google',
            'auth_github',
            'auth_microsoft',
        ];

        foreach ($expectedServices as $serviceId) {
            $defs = $manager->getDefinitions($serviceId);
            expect($defs)->toBeArray()->not->toBeEmpty();

            foreach ($defs as $field => $meta) {
                expect($meta)->toHaveKeys(['env_var', 'label', 'secret', 'guide', 'url'])
                    ->and($meta['env_var'])->toBeString()->not->toBeEmpty()
                    ->and($meta['label'])->toBeString()->not->toBeEmpty()
                    ->and($meta['secret'])->toBeBool();
            }
        }
    });

    it('resolves service aliases to canonical keys', function () {
        $manager = app(EnvCredentialManager::class);

        expect($manager->getDefinitions('billcom'))->toBe($manager->getDefinitions('bill-com'))
            ->and($manager->getDefinitions('bill_com'))->toBe($manager->getDefinitions('bill-com'))
            ->and($manager->getDefinitions('pagespeed'))->toBe($manager->getDefinitions('psi'))
            ->and($manager->getDefinitions('security-scans'))->toBe($manager->getDefinitions('security_scans'))
            ->and($manager->getDefinitions('do-spaces'))->toBe($manager->getDefinitions('do_spaces'))
            ->and($manager->getDefinitions('auth-google'))->toBe($manager->getDefinitions('auth_google'))
            ->and($manager->getDefinitions('auth-github'))->toBe($manager->getDefinitions('auth_github'))
            ->and($manager->getDefinitions('auth-microsoft'))->toBe($manager->getDefinitions('auth_microsoft'));
    });

    it('returns empty array for an unknown service in getDefinitions()', function () {
        $manager = app(EnvCredentialManager::class);

        expect($manager->getDefinitions('unknown-service-foo'))->toBe([]);
    });

    it('formats fields metadata and masks secrets in getFieldsForService()', function () {
        $manager = app(EnvCredentialManager::class);

        // Save a dummy token for digitalocean
        $manager->save('digitalocean', 'token', 'dop_v1_unit_test_token_1234');

        $fields = $manager->getFieldsForService('digitalocean');
        expect($fields)->toBeArray()->toHaveCount(1);

        $field = $fields[0];
        expect($field['field'])->toBe('token')
            ->and($field['env_var'])->toBe('CLOCKWORK_DIGITALOCEAN_TOKEN')
            ->and($field['configured'])->toBeTrue()
            ->and($field['secret'])->toBeTrue()
            ->and($field['preview'])->toBe('••••••••1234');

        // Save a non-secret field for azure (e.g. tenant_id)
        $manager->save('azure', 'tenant_id', 'azure-tenant-uuid-1111');
        $azureFields = $manager->getFieldsForService('azure');

        $tenantField = collect($azureFields)->firstWhere('field', 'tenant_id');
        expect($tenantField)->not->toBeNull()
            ->and($tenantField['secret'])->toBeFalse()
            ->and($tenantField['configured'])->toBeTrue()
            ->and($tenantField['preview'])->toBe('azure-tenant-uuid-1111');
    });

    it('masks short secrets with bullet points without throwing errors', function () {
        $manager = app(EnvCredentialManager::class);

        $manager->save('vultr', 'api_key', 'short');
        $fields = $manager->getFieldsForService('vultr');

        expect($fields[0]['configured'])->toBeTrue()
            ->and($fields[0]['preview'])->toBe('••••••••');
    });

    it('deletes legacy database records when credential is saved to .env', function () {
        $manager = app(EnvCredentialManager::class);

        IntegrationCredential::create([
            'integration' => 'digitalocean',
            'key' => 'token',
            'value' => 'legacy_db_token',
        ]);

        expect(IntegrationCredential::where('integration', 'digitalocean')->count())->toBe(1);

        $saved = $manager->save('digitalocean', 'token', 'new_env_token_val_9999');
        expect($saved)->toBeTrue();

        expect(IntegrationCredential::where('integration', 'digitalocean')->count())->toBe(0);
        expect($manager->getEnvValue('CLOCKWORK_DIGITALOCEAN_TOKEN'))->toBe('new_env_token_val_9999')
            ->and(config('clockwork.digitalocean.token'))->toBe('new_env_token_val_9999');
    });

    it('removes credentials from .env and unsets them in runtime memory', function () {
        $manager = app(EnvCredentialManager::class);

        $manager->save('linode', 'token', 'linode_tok_to_remove');
        expect($manager->getEnvValue('CLOCKWORK_LINODE_TOKEN'))->toBe('linode_tok_to_remove');

        $removed = $manager->remove('linode', 'token');
        expect($removed)->toBeTrue();

        expect($manager->getEnvValue('CLOCKWORK_LINODE_TOKEN'))->toBeNull()
            ->and(config('clockwork.linode.token'))->toBeNull();
    });

    it('returns false when trying to save or remove an unknown field', function () {
        $manager = app(EnvCredentialManager::class);

        expect($manager->save('digitalocean', 'nonexistent_field', 'val'))->toBeFalse()
            ->and($manager->remove('digitalocean', 'nonexistent_field'))->toBeFalse()
            ->and($manager->save('nonexistent_service', 'token', 'val'))->toBeFalse();
    });
});
