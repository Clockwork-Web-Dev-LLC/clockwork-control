<?php

namespace Tests\Unit\Services\Security;

use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Services\Security\BlacklistChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlacklistCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_spamhaus_only_when_no_google_or_urlhaus_keys_configured(): void
    {
        $site = Site::factory()->make(['domain' => 'clean-example.com']);
        $checker = new BlacklistChecker(
            webRiskApiKey: '',
            gsbApiKey: '',
            urlhausAuthKey: '',
        );

        $result = $checker->check($site);

        expect($result->status)->toBe(SiteSecurityScan::STATUS_CLEAN)
            ->and($result->summary)->toContain('spamhaus_dbl')
            ->and($result->details['sources_attempted'])->toBe([BlacklistChecker::SOURCE_SPAMHAUS_DBL]);
    }

    public function test_queries_google_web_risk_api_when_key_configured_and_reports_clean(): void
    {
        $site = Site::factory()->make(['domain' => 'clean-example.com']);

        Http::fake([
            'https://webrisk.googleapis.com/v1/uris:search*' => Http::response([], 200),
        ]);

        $checker = new BlacklistChecker(
            webRiskApiKey: 'test-web-risk-key',
            gsbApiKey: '',
            urlhausAuthKey: '',
        );

        $result = $checker->check($site);

        expect($result->status)->toBe(SiteSecurityScan::STATUS_CLEAN)
            ->and($result->summary)->toContain(BlacklistChecker::SOURCE_WEB_RISK)
            ->and($result->details['sources_attempted'])->toContain(BlacklistChecker::SOURCE_WEB_RISK);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://webrisk.googleapis.com/v1/uris:search')
                && str_contains($request->url(), 'key=test-web-risk-key')
                && str_contains($request->url(), 'threatTypes=MALWARE');
        });
    }

    public function test_queries_google_web_risk_api_and_flags_threat(): void
    {
        $site = Site::factory()->make(['domain' => 'malware-example.com']);

        Http::fake([
            'https://webrisk.googleapis.com/v1/uris:search*' => Http::response([
                'threat' => [
                    'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING'],
                    'expireTime' => '2026-09-10T16:00:00Z',
                ],
            ], 200),
        ]);

        $checker = new BlacklistChecker(
            webRiskApiKey: 'test-web-risk-key',
            gsbApiKey: '',
            urlhausAuthKey: '',
        );

        $result = $checker->check($site);

        expect($result->status)->toBe(SiteSecurityScan::STATUS_ISSUES_FOUND)
            ->and($result->blacklistHit)->toBeTrue()
            ->and($result->summary)->toContain('google_web_risk');

        $webRiskHit = collect($result->details['hits'])->firstWhere('source', BlacklistChecker::SOURCE_WEB_RISK);
        expect($webRiskHit)->not->toBeNull()
            ->and($webRiskHit['threat_types'])->toContain('MALWARE', 'SOCIAL_ENGINEERING');
    }

    public function test_falls_back_to_google_safe_browsing_when_web_risk_unconfigured(): void
    {
        $site = Site::factory()->make(['domain' => 'gsb-fallback.com']);

        Http::fake([
            'https://safebrowsing.googleapis.com/v4/threatMatches:find*' => Http::response([
                'matches' => [
                    ['threatType' => 'MALWARE'],
                ],
            ], 200),
        ]);

        $checker = new BlacklistChecker(
            webRiskApiKey: '',
            gsbApiKey: 'test-gsb-key',
            urlhausAuthKey: '',
        );

        $result = $checker->check($site);

        expect($result->status)->toBe(SiteSecurityScan::STATUS_ISSUES_FOUND)
            ->and($result->blacklistHit)->toBeTrue()
            ->and($result->summary)->toContain('google_safe_browsing');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://safebrowsing.googleapis.com/v4/threatMatches:find');
        });
    }
}
