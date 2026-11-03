<?php

use App\Models\Site;
use App\Services\Companion\CompanionBrandingManager;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\ClientReports\Services\ClientReportCompiler;

/*
|--------------------------------------------------------------------------
| Client report compiler: branding and report metadata
|--------------------------------------------------------------------------
|
| What the compiler captures alongside the sections. Branding is stored with
| every report, so a private value written here (Control's own URL) would
| sit in every report and reach clients the moment a view renders it.
*/

beforeEach(function () {
    Http::fake();
    $this->travelTo(Carbon::parse('2026-11-02 06:00'));
});

function compileMonth(Site $site, string $start, string $end, ?array $sections = null): array
{
    return app(ClientReportCompiler::class)->compile($site, Carbon::parse($start), Carbon::parse($end), null, $sections);
}

describe('support URL', function () {
    it('falls back to the agency website, never to the app URL, when both support URLs are blank', function () {
        config(['app.url' => 'http://localhost:8000']);
        app(Settings::class)->putMany([
            'reports.branding.support_url' => '',
            'companion.branding.support_url' => '',
            'companion.branding.company_url' => 'https://agency.example.com',
        ]);

        $branding = compileMonth(Site::factory()->create(), '2026-10-01', '2026-10-31', [])['branding'];

        expect($branding['support_url'])->toBe('https://agency.example.com')
            ->and($branding['support_url'])->not->toContain('localhost');
    });

    it('is null rather than the app URL when there is no agency website either', function () {
        config(['app.url' => 'http://localhost:8000']);
        $this->mock(CompanionBrandingManager::class, fn ($mock) => $mock->shouldReceive('getReportsBranding')->andReturn([
            'enabled' => false,
            'company_name' => 'Example Agency',
            'company_url' => '',
            'support_email' => 'support@example.com',
            'support_url' => '',
            'logo_url' => '',
            'primary_color' => '#2D2062',
            'accent_color' => '#7EFF83',
            'footer_text' => '',
            'is_custom' => false,
        ]));

        $branding = compileMonth(Site::factory()->create(), '2026-10-01', '2026-10-31', [])['branding'];

        expect($branding['support_url'])->toBeNull();
    });

    it('uses the shared Companion support URL when the reports one is blank', function () {
        app(Settings::class)->putMany([
            'reports.branding.support_url' => '',
            'companion.branding.support_url' => 'https://help.example.com',
            'companion.branding.company_url' => 'https://agency.example.com',
        ]);

        expect(compileMonth(Site::factory()->create(), '2026-10-01', '2026-10-31', [])['branding']['support_url'])
            ->toBe('https://help.example.com');
    });

    it('uses the reports support URL when one is set', function () {
        app(Settings::class)->putMany([
            'reports.branding.support_url' => 'https://example.com/support',
            'companion.branding.support_url' => 'https://help.example.com',
        ]);

        expect(compileMonth(Site::factory()->create(), '2026-10-01', '2026-10-31', [])['branding']['support_url'])
            ->toBe('https://example.com/support');
    });
});

describe('period length', function () {
    it('counts a 31-day month as 31 whole days and 44,640 minutes', function () {
        $site = Site::factory()->create(['uptime_monitoring_enabled' => true, 'uptime_last_checked_at' => now()]);

        $data = compileMonth($site, '2026-10-01', '2026-10-31', ['uptime']);

        expect($data['meta']['period_days'])->toBe(31)
            ->and($data['uptime']['total_period_minutes'])->toBe(31 * 24 * 60);
    });

    it('counts a 30-day month as 30 whole days and 43,200 minutes', function () {
        $site = Site::factory()->create(['uptime_monitoring_enabled' => true, 'uptime_last_checked_at' => now()]);

        $data = compileMonth($site, '2026-09-01', '2026-09-30', ['uptime']);

        expect($data['meta']['period_days'])->toBe(30)
            ->and($data['uptime']['total_period_minutes'])->toBe(30 * 24 * 60);
    });

    it('counts a single-day period as one day', function () {
        expect(compileMonth(Site::factory()->create(), '2026-10-15', '2026-10-15', [])['meta']['period_days'])->toBe(1);
    });
});

it('stamps the current schema version', function () {
    expect(ClientReportCompiler::SCHEMA_VERSION)->toBe(4)
        ->and(compileMonth(Site::factory()->create(), '2026-10-01', '2026-10-31', [])['meta']['schema_version'])->toBe(4);
});
