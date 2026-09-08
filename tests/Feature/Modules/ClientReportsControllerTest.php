<?php

use App\Models\Site;
use App\Models\SiteTrafficDaily;
use App\Models\User;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Services\ClientReportCompiler;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| ClientReportsController — HTTP-level coverage
|--------------------------------------------------------------------------
|
| Regression coverage for a real production bug: show() and publicShow()
| rendered view('client-reports::report'), but the view file actually lives
| at resources/views/reports/show.blade.php — which the 'client-reports'
| view namespace resolves as 'client-reports::reports.show'. Every
| "Generate & Preview Report" click 500'd on InvalidArgumentException
| ("View [report] not found") immediately after the report row was already
| successfully created, since generate() redirects straight into show().
*/

it('requires authentication for the operator routes', function () {
    $report = ClientReport::create([
        'site_id' => Site::factory()->create()->id,
        'title' => 'Test Report',
        'period_start' => now()->subMonth(),
        'period_end' => now(),
        'sections_data' => [],
        'status' => 'generated',
    ]);

    $this->get(route('client-reports.index'))->assertRedirect(route('login'));
    $this->get(route('client-reports.show', $report))->assertRedirect(route('login'));
    $this->post(route('client-reports.generate'))->assertRedirect(route('login'));
    $this->post(route('client-reports.send', $report))->assertRedirect(route('login'));
});

describe('index', function () {
    it('renders the reports dashboard', function () {
        $this->mockIssueCounterZero();
        $site = Site::factory()->create(['domain' => 'reports-index.test']);
        ClientReport::create([
            'site_id' => $site->id,
            'title' => 'Existing Report',
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'sections_data' => [],
            'status' => 'generated',
        ]);

        $response = $this->actingAs(User::factory()->create())->get(route('client-reports.index'));

        $response->assertOk()->assertSee('Existing Report');
    });
});

describe('generate + show', function () {
    it('compiles a report and previews it without error — the exact generate-then-preview flow that used to 500', function () {
        $site = Site::factory()->create(['domain' => 'clockworkwd.test', 'care_plan_enabled' => true]);

        $generateResponse = $this->actingAs(User::factory()->create())->post(route('client-reports.generate'), [
            'site_id' => $site->id,
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'client_name' => 'Test Client',
            'client_email' => 'client@example.test',
        ]);

        $report = ClientReport::query()->where('site_id', $site->id)->firstOrFail();

        $generateResponse->assertRedirect(route('client-reports.show', $report))
            ->assertSessionHas('status', 'Report generated successfully.');

        $showResponse = $this->actingAs(User::factory()->create())->get(route('client-reports.show', $report));

        $showResponse->assertOk()
            ->assertSee('clockworkwd.test')
            ->assertSee($report->title);
    });

    it('shows the Traffic Analytics section when the period had recorded traffic', function () {
        $site = Site::factory()->create(['domain' => 'traffic-report.test']);
        $periodStart = now()->subMonth()->startOfMonth();
        SiteTrafficDaily::factory()->create([
            'site_id' => $site->id,
            'date' => $periodStart->copy()->addDays(5)->toDateString(),
            'visits' => 1234,
            'requests' => 5678,
            'bytes_sent' => 10 * 1024 * 1024,
        ]);

        $response = $this->actingAs(User::factory()->create())->post(route('client-reports.generate'), [
            'site_id' => $site->id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodStart->copy()->endOfMonth()->toDateString(),
        ]);

        $report = ClientReport::query()->where('site_id', $site->id)->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->get(route('client-reports.show', $report))
            ->assertOk()
            ->assertSee('Traffic Analytics')
            ->assertSee('1,234')
            ->assertSee('5,678')
            ->assertSee('10.0 MB');
    });

    it('hides the Traffic Analytics section entirely when no traffic was recorded for the period', function () {
        $site = Site::factory()->create(['domain' => 'no-traffic-report.test']);

        $response = $this->actingAs(User::factory()->create())->post(route('client-reports.generate'), [
            'site_id' => $site->id,
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        ]);

        $report = ClientReport::query()->where('site_id', $site->id)->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->get(route('client-reports.show', $report))
            ->assertOk()
            ->assertDontSee('Traffic Analytics');
    });
});

describe('publicShow', function () {
    it('renders the client-facing report via a valid public token without authentication', function () {
        $site = Site::factory()->create(['domain' => 'public-report.test']);
        $sectionsData = app(ClientReportCompiler::class)
            ->compile($site, now()->subMonth(), now());

        $report = ClientReport::create([
            'site_id' => $site->id,
            'title' => 'Public Preview Report',
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'sections_data' => $sectionsData,
            'status' => 'generated',
        ]);

        $response = $this->get(route('client-reports.public', ['token' => $report->public_token]));

        $response->assertOk()->assertSee('Public Preview Report');
    });

    it('404s for an unknown public token', function () {
        $response = $this->get(route('client-reports.public', ['token' => 'not-a-real-token']));

        $response->assertNotFound();
    });
});
