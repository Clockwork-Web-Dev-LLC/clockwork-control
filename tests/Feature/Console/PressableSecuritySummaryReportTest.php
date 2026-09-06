<?php

namespace Tests\Feature\Console;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Pressable\PressableClient;

/*
|--------------------------------------------------------------------------
| Phase 6 console-command coverage: clockwork:pressable-security-summary-report
|--------------------------------------------------------------------------
|
| Pulls Pressable's own plugin/theme vulnerability feed plus Defensive Mode
| status and POSTs the assembled report to each Companion-equipped
| Pressable site's /security-summary-report endpoint. PressableClient is
| mocked directly as a collaborator (same reasoning as
| PressableBackupsReportTest's docblock) — only the outbound Companion push
| is asserted at the real HTTP boundary via Http::fake().
*/

function pssrSite(array $overrides = []): Site
{
    return Site::factory()->pressable()->withCompanionInstalled()->create(array_merge([
        'companion_capabilities' => ['security-scans'],
    ], $overrides));
}

function pssrMockPressable(?callable $configure = null): void
{
    test()->mock(PressableClient::class, function ($mock) use ($configure) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        if ($configure) {
            $configure($mock);
        }
    });
}

describe('clockwork:pressable-security-summary-report — happy path', function () {
    it('pushes plugin/theme vulnerabilities and an active Defensive Mode window', function () {
        $site = pssrSite();

        pssrMockPressable(function ($mock) use ($site) {
            $mock->shouldReceive('sitePluginSecurityAlerts')->once()->with($site->pressable_site_id)->andReturn([
                ['name' => 'vulnerable-plugin', 'version' => '1.2.3', 'security_alerts' => [['cve' => 'CVE-2026-1111', 'severity' => 'high']]],
            ]);
            $mock->shouldReceive('siteThemeSecurityAlerts')->once()->with($site->pressable_site_id)->andReturn([
                ['name' => 'old-theme', 'version' => '2.0.0', 'security_alerts' => [['cve' => 'CVE-2026-2222', 'severity' => 'medium']]],
            ]);
            $mock->shouldReceive('siteEdgeCacheStatus')->once()->with($site->pressable_site_id)->andReturn([
                'defensive_mode' => ['active' => true, 'active_until' => '2026-09-04T00:00:00+00:00'],
            ]);
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/security-summary-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:pressable-security-summary-report')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/security-summary-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return count($body['vulnerabilities']['plugins']) === 1
                && $body['vulnerabilities']['plugins'][0]['name'] === 'vulnerable-plugin'
                && $body['vulnerabilities']['plugins'][0]['alerts'][0]['cve'] === 'CVE-2026-1111'
                && count($body['vulnerabilities']['themes']) === 1
                && $body['vulnerabilities']['themes'][0]['name'] === 'old-theme'
                && $body['defensive_mode']['active'] === true
                && $body['defensive_mode']['active_until'] === '2026-09-04T00:00:00+00:00';
        });
    });

    it('reports Defensive Mode inactive with a null active_until when edge-cache status carries no defensive_mode key', function () {
        $site = pssrSite();

        pssrMockPressable(function ($mock) {
            $mock->shouldReceive('sitePluginSecurityAlerts')->once()->andReturn([]);
            $mock->shouldReceive('siteThemeSecurityAlerts')->once()->andReturn([]);
            $mock->shouldReceive('siteEdgeCacheStatus')->once()->andReturn([]);
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/security-summary-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:pressable-security-summary-report')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/security-summary-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $body['vulnerabilities']['plugins'] === []
                && $body['vulnerabilities']['themes'] === []
                && $body['defensive_mode'] === ['active' => false, 'active_until' => null];
        });
    });
});

describe('clockwork:pressable-security-summary-report — skip and failure handling', function () {
    it('exits FAILURE immediately when Pressable is not configured, without touching any site', function () {
        pssrSite();

        $this->mock(PressableClient::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturn(false));

        Http::fake();

        $this->artisan('clockwork:pressable-security-summary-report')->assertFailed();

        Http::assertNothingSent();
    });

    it('skips a site whose Companion does not advertise the security-scans capability', function () {
        pssrSite(['companion_capabilities' => ['snapshot']]);

        pssrMockPressable();

        Http::fake();

        $this->artisan('clockwork:pressable-security-summary-report')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('logs and continues (still SUCCESS overall) when the Pressable fetch fails for a site, without pushing to Companion', function () {
        Log::spy();
        pssrSite();

        pssrMockPressable(function ($mock) {
            $mock->shouldReceive('sitePluginSecurityAlerts')->once()->andThrow(new \RuntimeException('Pressable 503'));
        });

        Http::fake();

        $this->artisan('clockwork:pressable-security-summary-report')->assertSuccessful();

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->with('companion.pressable_security_summary.fetch_failed', \Mockery::type('array'));
    });

    it('logs and continues (still SUCCESS overall) when the push to Companion itself fails', function () {
        Log::spy();
        $site = pssrSite();

        pssrMockPressable(function ($mock) {
            $mock->shouldReceive('sitePluginSecurityAlerts')->once()->andReturn([]);
            $mock->shouldReceive('siteThemeSecurityAlerts')->once()->andReturn([]);
            $mock->shouldReceive('siteEdgeCacheStatus')->once()->andReturn([]);
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/security-summary-report" => Http::response(['message' => 'error'], 500, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:pressable-security-summary-report')->assertSuccessful();

        Log::shouldHaveReceived('warning')->with('companion.pressable_security_summary.push_failed', \Mockery::type('array'));
    });
});

describe('clockwork:pressable-security-summary-report — options', function () {
    it('--site limits the run to a single site by domain', function () {
        $target = pssrSite();
        $other = pssrSite();

        pssrMockPressable(function ($mock) {
            $mock->shouldReceive('sitePluginSecurityAlerts')->once()->andReturn([]);
            $mock->shouldReceive('siteThemeSecurityAlerts')->once()->andReturn([]);
            $mock->shouldReceive('siteEdgeCacheStatus')->once()->andReturn([]);
        });

        Http::fake([
            "https://{$target->domain}/wp-json/clockwork/v1/security-summary-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:pressable-security-summary-report', ['--site' => $target->domain])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === "https://{$target->domain}/wp-json/clockwork/v1/security-summary-report");
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $other->domain));
    });
});
