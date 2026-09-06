<?php

namespace Tests\Feature\Modules;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Cloudways\CloudwaysClient;
use Modules\Cloudways\CloudwaysReadOnlyException;
use Modules\Core\Exceptions\ReadOnlyModeException;
use Modules\GridPane\GridPaneReadOnlyException;
use Modules\Kinsta\KinstaClient;
use Modules\Kinsta\KinstaReadOnlyException;
use Modules\Pressable\PressableClient;
use Modules\Pressable\PressableReadOnlyException;
use Modules\SpinupWp\SpinupWpClient;
use Modules\SpinupWp\SpinupWpReadOnlyException;
use Modules\WPEngine\WPEngineClient;
use Modules\WPEngine\WPEngineReadOnlyException;

describe('Provider View-Only Client Guards & Test Commands', function () {
    beforeEach(function () {
        Cache::flush();
    });

    it('ensures all provider exceptions inherit from Core ReadOnlyModeException', function () {
        expect(new SpinupWpReadOnlyException('SpinupWP', 'test'))
            ->toBeInstanceOf(ReadOnlyModeException::class)
            ->and(new PressableReadOnlyException('Pressable', 'test'))->toBeInstanceOf(ReadOnlyModeException::class)
            ->and(new WPEngineReadOnlyException('WP Engine', 'test'))->toBeInstanceOf(ReadOnlyModeException::class)
            ->and(new KinstaReadOnlyException('Kinsta', 'test'))->toBeInstanceOf(ReadOnlyModeException::class)
            ->and(new CloudwaysReadOnlyException('Cloudways', 'test'))->toBeInstanceOf(ReadOnlyModeException::class)
            ->and(new GridPaneReadOnlyException('GridPane', 'test'))->toBeInstanceOf(ReadOnlyModeException::class);
    });

    describe('SpinupWpClient view-only guard', function () {
        it('throws SpinupWpReadOnlyException on mutations when view-only is enabled', function () {
            $client = new SpinupWpClient(
                token: 'swp-token',
                baseUrl: 'https://api.spinupwp.app/v1',
                timeout: 5,
                viewOnly: true,
            );

            expect(fn () => $client->post('/sites', ['name' => 'test']))
                ->toThrow(SpinupWpReadOnlyException::class, 'is in View-Only mode')
                ->and(fn () => $client->put('/sites/1', ['name' => 'test']))
                ->toThrow(SpinupWpReadOnlyException::class)
                ->and(fn () => $client->delete('/sites/1'))
                ->toThrow(SpinupWpReadOnlyException::class);
        });
    });

    describe('PressableClient view-only guard', function () {
        it('throws PressableReadOnlyException on mutations when view-only is enabled', function () {
            $client = new PressableClient(
                clientId: 'client-1',
                clientSecret: 'secret-1',
                authUrl: 'https://my.pressable.com/auth/token',
                baseUrl: 'https://my.pressable.com/v1',
                timeout: 5,
                viewOnly: true,
            );

            expect(fn () => $client->purgeEdgeCache('site-1'))
                ->toThrow(PressableReadOnlyException::class, 'is in View-Only mode')
                ->and(fn () => $client->flushObjectCache('site-1'))
                ->toThrow(PressableReadOnlyException::class)
                ->and(fn () => $client->runBashCommands('site-1', ['ls']))
                ->toThrow(PressableReadOnlyException::class)
                ->and(fn () => $client->runWpCliCommands('site-1', ['wp option get siteurl']))
                ->toThrow(PressableReadOnlyException::class);
        });
    });

    describe('WPEngineClient view-only guard', function () {
        it('throws WPEngineReadOnlyException on mutations when view-only is enabled', function () {
            $client = new WPEngineClient(
                apiUserId: 'user-1',
                apiPassword: 'pass-1',
                baseUrl: 'https://api.wpengine.com/v1',
                timeout: 5,
                viewOnly: true,
            );

            expect(fn () => $client->requestSslCertificate('inst-1', 'site1.com'))
                ->toThrow(WPEngineReadOnlyException::class, 'is in View-Only mode')
                ->and(fn () => $client->registerSshKey('ssh-rsa AAAA...', 'test-key'))
                ->toThrow(WPEngineReadOnlyException::class);
        });
    });

    describe('KinstaClient view-only guard', function () {
        it('throws KinstaReadOnlyException on mutations when view-only is enabled', function () {
            $client = new KinstaClient(
                apiKey: 'kinsta-key',
                baseUrl: 'https://api.kinsta.com/v2',
                timeout: 5,
                viewOnly: true,
            );

            expect(fn () => $client->setSshStatus('env-1', true))
                ->toThrow(KinstaReadOnlyException::class, 'is in View-Only mode')
                ->and(fn () => $client->generateSshPassword('env-1'))
                ->toThrow(KinstaReadOnlyException::class)
                ->and(fn () => $client->restoreBackup('env-1', 'backup-1'))
                ->toThrow(KinstaReadOnlyException::class);
        });
    });

    describe('CloudwaysClient view-only guard', function () {
        it('throws CloudwaysReadOnlyException on mutations when view-only is enabled', function () {
            $client = new CloudwaysClient(
                apiKey: 'cw-key',
                email: 'agency@clockworkwd.com',
                baseUrl: 'https://api.cloudways.com/api/v2',
                timeout: 5,
                viewOnly: true,
            );

            expect(fn () => $client->takeBackup('1', 'app-1'))
                ->toThrow(CloudwaysReadOnlyException::class, 'is in View-Only mode')
                ->and(fn () => $client->restoreBackup('1', 'app-1', ['app', 'web']))
                ->toThrow(CloudwaysReadOnlyException::class)
                ->and(fn () => $client->installLetsEncrypt('1', 'app-1', 'email@test.com', ['test.com']))
                ->toThrow(CloudwaysReadOnlyException::class);
        });
    });

    describe('WPEngineTest command', function () {
        it('runs clockwork:wpengine-test and standardized alias clockwork:test-wpengine', function () {
            config([
                'clockwork.wpengine.api_user_id' => 'user-1',
                'clockwork.wpengine.api_password' => 'pass-1',
                'clockwork.wpengine.view_only' => true,
            ]);

            Http::fake([
                'api.wpengine.com/v1/installs*' => Http::response([
                    'count' => 5,
                    'results' => [
                        ['id' => 'inst1', 'name' => 'clientinst1', 'environment' => 'production', 'primary_domain' => 'client1.com'],
                    ],
                ], 200),
            ]);

            $this->artisan('clockwork:wpengine-test')
                ->assertSuccessful()
                ->expectsOutputToContain('View Only (Read-Only)')
                ->expectsOutputToContain('Installs visible: 5')
                ->expectsOutputToContain('clientinst1');

            $this->artisan('clockwork:test-wpengine')
                ->assertSuccessful()
                ->expectsOutputToContain('View Only (Read-Only)');
        });
    });

    describe('KinstaTest command', function () {
        it('runs clockwork:kinsta-test and standardized alias clockwork:test-kinsta', function () {
            config([
                'clockwork.kinsta.api_key' => 'k-token',
                'clockwork.kinsta.view_only' => true,
            ]);

            Http::fake([
                'api.kinsta.com/v2/sites*' => Http::response(['message' => 'company is required'], 422),
            ]);

            $this->artisan('clockwork:kinsta-test')
                ->assertSuccessful()
                ->expectsOutputToContain('View Only (Read-Only)')
                ->expectsOutputToContain('API key accepted (HTTP 422)');

            $this->artisan('clockwork:test-kinsta')
                ->assertSuccessful()
                ->expectsOutputToContain('View Only (Read-Only)');
        });
    });

    describe('CloudwaysTest command', function () {
        it('runs clockwork:cloudways-test and standardized alias clockwork:test-cloudways', function () {
            config([
                'clockwork.cloudways.api_key' => 'cw-key',
                'clockwork.cloudways.email' => 'dev@clockworkwd.com',
                'clockwork.cloudways.view_only' => true,
            ]);

            Http::fake([
                'api.cloudways.com/api/v2/oauth/access_token' => Http::response([
                    'access_token' => 'cw-access-token',
                    'expires_in' => 3600,
                ], 200),
                'api.cloudways.com/api/v2/server' => Http::response([
                    'servers' => [
                        [
                            'id' => 101,
                            'label' => 'cw-node-1',
                            'public_ip' => '192.0.2.1',
                            'apps' => [
                                ['id' => 201, 'label' => 'app-one', 'app_fqdn' => 'one.com'],
                            ],
                        ],
                    ],
                ], 200),
            ]);

            $this->artisan('clockwork:cloudways-test')
                ->assertSuccessful()
                ->expectsOutputToContain('View Only (Read-Only)')
                ->expectsOutputToContain('Servers visible: 1')
                ->expectsOutputToContain('Apps visible:    1');

            $this->artisan('clockwork:test-cloudways')
                ->assertSuccessful()
                ->expectsOutputToContain('View Only (Read-Only)');
        });
    });

    describe('SpinupWP and Pressable standardized aliases', function () {
        it('runs clockwork:test-spinupwp', function () {
            config([
                'clockwork.spinupwp.token' => 'swp-token',
                'clockwork.spinupwp.view_only' => false,
            ]);

            Http::fake([
                'api.spinupwp.app/v1/servers*' => Http::response([
                    'data' => [['id' => 1, 'name' => 'server-1', 'ip_address' => '1.1.1.1']],
                    'pagination' => ['next_page_url' => null],
                ], 200),
                'api.spinupwp.app/v1/sites*' => Http::response([
                    'data' => [['id' => 2, 'domain' => 'site.com']],
                    'pagination' => ['next_page_url' => null],
                ], 200),
            ]);

            $this->artisan('clockwork:test-spinupwp')
                ->assertSuccessful()
                ->expectsOutputToContain('Full Access (Read/Write)')
                ->expectsOutputToContain('Servers visible: 1');
        });

        it('runs clockwork:test-pressable', function () {
            config([
                'clockwork.pressable.client_id' => 'client-1',
                'clockwork.pressable.client_secret' => 'secret-1',
                'clockwork.pressable.view_only' => false,
            ]);

            Http::fake([
                'my.pressable.com/auth/token' => Http::response([
                    'access_token' => 'fake-pressable-token',
                    'expires_in' => 3599,
                ], 200),
                'my.pressable.com/v1/account' => Http::response([
                    'data' => ['organization' => 'Clockwork Agency'],
                ], 200),
                'my.pressable.com/v1/sites*' => Http::response([
                    'data' => [['id' => '100', 'name' => 'site1', 'url' => 'site1.com', 'state' => 'live']],
                    'recordsTotal' => 1,
                ], 200),
            ]);

            $this->artisan('clockwork:test-pressable')
                ->assertSuccessful()
                ->expectsOutputToContain('Full Access (Read/Write)')
                ->expectsOutputToContain('Clockwork Agency');
        });
    });
});
