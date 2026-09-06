<?php

use App\Models\Site;
use App\Models\SiteSecurityScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Core\ModuleManifest;
use Modules\Sucuri\SucuriServiceProvider;
use Modules\Sucuri\SucuriSiteCheckClient;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('Sucuri module', function () {
    it('manifest provides verified status and empty credential fields', function () {
        $provider = new SucuriServiceProvider(app());
        $manifest = $provider->manifest();

        expect($manifest->id)->toBe('sucuri');
        expect($manifest->name)->toBe('Sucuri SiteCheck');
        expect($manifest->status)->toBe(ModuleManifest::STATUS_VERIFIED);
        expect($manifest->credentialFields)->toBeEmpty();
    });

    it('resolves SucuriSiteCheckClient from container', function () {
        $client = app(SucuriSiteCheckClient::class);

        expect($client)->toBeInstanceOf(SucuriSiteCheckClient::class);
        expect($client)->toBeInstanceOf(App\Services\Security\SucuriSiteCheckClient::class);
    });

    it('interprets a clean Sucuri scan payload', function () {
        $client = new SucuriSiteCheckClient;
        $site = Site::factory()->make(['domain' => 'example.test']);

        $payload = [
            'ratings' => [
                'security' => [
                    'rating' => 'A',
                    'passed' => 8,
                ],
            ],
        ];

        $result = $client->interpret($site, $payload, 250);

        expect($result->status)->toBe(SiteSecurityScan::STATUS_CLEAN);
        expect($result->summary)->toContain('security A');
        expect($result->hasMalwareHit)->toBeFalse();
        expect($result->blacklistHit)->toBeFalse();
    });

    it('interprets a malware hit correctly', function () {
        $client = new SucuriSiteCheckClient;
        $site = Site::factory()->make(['domain' => 'example.test']);

        $payload = [
            'malware' => [
                'found' => true,
                'warnings' => ['Threat detected in wp-includes/load.php'],
            ],
        ];

        $result = $client->interpret($site, $payload, 300);

        expect($result->status)->toBe(SiteSecurityScan::STATUS_ISSUES_FOUND);
        expect($result->hasMalwareHit)->toBeTrue();
        expect($result->summary)->toContain('malware detected');
    });

    it('handles network failure gracefully in scanSite', function () {
        Http::fake([
            'https://sitecheck.sucuri.net/api/v3/*' => Http::response('Server error', 500),
        ]);

        $client = new SucuriSiteCheckClient;
        $site = Site::factory()->make(['domain' => 'example.test']);

        $result = $client->scanSite($site);

        expect($result->status)->toBe(SiteSecurityScan::STATUS_FAILED);
        expect($result->summary)->toContain('SiteCheck request failed');
    });
});
