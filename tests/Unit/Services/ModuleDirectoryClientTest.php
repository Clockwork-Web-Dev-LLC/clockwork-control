<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Core\ModuleDirectoryClient;
use Tests\TestCase;

uses(TestCase::class);

describe('ModuleDirectoryClient', function () {
    beforeEach(function () {
        Cache::flush();
        $this->apiUrl = 'https://clockworkcontrol.com/api/modules.json';
        $this->client = new ModuleDirectoryClient($this->apiUrl, 3600);
    });

    it('fetches modules from the remote directory feed and marks source as network', function () {
        Http::fake([
            $this->apiUrl => Http::response([
                'schema_version' => '1.0',
                'generated_at' => '2026-09-04T12:00:00Z',
                'total_modules' => 2,
                'modules' => [
                    [
                        'id' => 'digitalocean',
                        'name' => 'DigitalOcean',
                        'category' => 'cloud_vps',
                        'status' => 'official',
                    ],
                    [
                        'id' => 'slack',
                        'name' => 'Slack',
                        'category' => 'notifications',
                        'status' => 'official',
                    ],
                ],
            ]),
        ]);

        $feed = $this->client->fetch();

        expect($feed['source'])->toBe('network');
        expect($feed['total_modules'])->toBe(2);
        expect($feed['modules'])->toHaveCount(2);
        expect($this->client->all())->toHaveCount(2);
    });

    it('caches successful responses and serves subsequent requests from cache', function () {
        Http::fake([
            $this->apiUrl => Http::response([
                'schema_version' => '1.0',
                'generated_at' => '2026-09-04T12:00:00Z',
                'total_modules' => 1,
                'modules' => [
                    [
                        'id' => 'hetzner',
                        'name' => 'Hetzner Cloud',
                        'category' => 'cloud_vps',
                        'status' => 'verified',
                    ],
                ],
            ]),
        ]);

        // First call hits network
        $first = $this->client->fetch();
        expect($first['source'])->toBe('network');

        // Second call served from cache without extra HTTP request
        $second = $this->client->fetch();
        expect($second['source'])->toBe('cache');
        expect($second['modules'][0]['id'])->toBe('hetzner');

        Http::assertSentCount(1);
    });

    it('forceRefresh flushes the cache and fetches fresh data from network', function () {
        Http::fake([
            $this->apiUrl => Http::sequence()
                ->push(['schema_version' => '1.0', 'generated_at' => 'v1', 'total_modules' => 1, 'modules' => [['id' => 'one']]])
                ->push(['schema_version' => '1.0', 'generated_at' => 'v2', 'total_modules' => 2, 'modules' => [['id' => 'one'], ['id' => 'two']]]),
        ]);

        $first = $this->client->fetch();
        expect($first['generated_at'])->toBe('v1');

        $refreshed = $this->client->fetch(forceRefresh: true);
        expect($refreshed['generated_at'])->toBe('v2');
        expect($refreshed['source'])->toBe('network');
        expect($refreshed['modules'])->toHaveCount(2);

        Http::assertSentCount(2);
    });

    it('falls back to local bundled catalog when network request fails and no cache exists', function () {
        Http::fake([
            $this->apiUrl => Http::response('Server Error', 500),
        ]);

        $feed = $this->client->fetch();

        expect($feed['source'])->toBe('fallback');
        expect($feed['modules'])->not->toBeEmpty();
        $ids = array_column($feed['modules'], 'id');
        expect($ids)->toContain('digitalocean');
        expect($ids)->toContain('gtmetrix');
        expect($ids)->toContain('auth_google');
    });

    it('filters modules by category and looks up specific module by id', function () {
        Http::fake([
            $this->apiUrl => Http::response([
                'schema_version' => '1.0',
                'generated_at' => '2026-09-04T12:00:00Z',
                'total_modules' => 3,
                'modules' => [
                    ['id' => 'digitalocean', 'name' => 'DigitalOcean', 'category' => 'cloud_vps'],
                    ['id' => 'vultr', 'name' => 'Vultr', 'category' => 'cloud_vps'],
                    ['id' => 'slack', 'name' => 'Slack', 'category' => 'notifications'],
                ],
            ]),
        ]);

        $cloudModules = $this->client->byCategory('cloud_vps');
        expect($cloudModules)->toHaveCount(2);

        $slack = $this->client->find('slack');
        expect($slack)->not->toBeNull();
        expect($slack['name'])->toBe('Slack');

        $nonexistent = $this->client->find('does-not-exist');
        expect($nonexistent)->toBeNull();
    });
});
