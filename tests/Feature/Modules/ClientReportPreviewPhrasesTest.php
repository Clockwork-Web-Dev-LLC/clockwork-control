<?php

use App\Models\Site;
use Illuminate\Support\Carbon;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Services\ClientReportCompiler;

/*
|--------------------------------------------------------------------------
| The preview and public page say what the email says
|--------------------------------------------------------------------------
|
| The report page (operator preview, printout, public view) uses the same
| ReportPhrases as both email parts for the malware label, the "no updates"
| sentence, the uptime colour, backup kinds and sizes, data transferred and
| the care-plan coverage note.
*/

function phrasesReport(array $sections = []): ClientReport
{
    $site = Site::factory()->create(['domain' => 'phrases-'.uniqid().'.example.com']);

    return ClientReport::create([
        'site_id' => $site->id,
        'title' => 'Phrases test',
        'period_start' => Carbon::parse('2026-09-01'),
        'period_end' => Carbon::parse('2026-09-30'),
        'status' => 'generated',
        'sections_data' => array_replace_recursive([
            'meta' => ['schema_version' => ClientReportCompiler::SCHEMA_VERSION, 'custom_notes' => null, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'],
            'branding' => [
                'company_name' => 'Example Agency', 'logo_url' => '', 'primary_color' => '#2D2062',
                'accent_color' => '#7EFF83', 'support_email' => 'support@example.com', 'footer_text' => '',
            ],
            'site' => ['domain' => $site->domain, 'is_wordpress' => true],
            'updates' => [
                'total' => 0, 'items' => ['core' => [], 'themes' => [], 'plugins' => []],
                'pending_plugins' => [], 'pending_themes' => [], 'pending_core' => false, 'updated_since_period' => 0,
            ],
            'uptime' => ['monitored' => true, 'probe_stopped' => false, 'uptime_percentage' => 100, 'outages_count' => 0, 'downtime_minutes' => 0, 'outages' => []],
            'security' => [
                'total_scans' => 30, 'vulnerabilities_checked' => true, 'vulnerabilities_as_of' => '2026-10-01',
                'active_vulnerabilities' => 0, 'vulnerable_plugins' => [], 'inactive_plugin_vulnerabilities' => 0,
                'blocked_threats_count' => 4, 'checksum_status' => 'clean', 'malware_status' => 'clean', 'blacklist_status' => 'clean',
            ],
            'performance' => ['has_performance' => false, 'mobile' => null, 'desktop' => null],
            'forms' => ['live_tests' => 0, 'lab_tests' => 0, 'lab_accepted' => 0, 'pass_rate' => null, 'total_synthetic_tests' => 0],
            'traffic' => ['has_traffic' => false, 'total_visits' => 0, 'total_requests' => 0, 'bandwidth_mb' => null],
            'backups' => [
                'verified' => true, 'status' => 'verified', 'source' => 'pressable', 'destination' => 'by your host, Pressable',
                'days_with_backups' => 30, 'covers_period' => true, 'records_from' => '2026-08-01',
                'recent' => [['date' => '2026-09-30', 'kinds' => ['db', 'fs'], 'bytes' => 60 * 1024 ** 2, 'manual' => false]],
                'last_backup_at' => '2026-09-30', 'check_error' => null,
            ],
            'work_log' => ['total_hours' => 0, 'entries' => []],
        ], $sections),
    ]);
}

/** @return array{html: string, preview: string, public: string} */
function phrasesViews(ClientReport $report): array
{
    return [
        'html' => (new ClientReportMail($report))->render(),
        'preview' => view('client-reports::reports.show', ['report' => $report, 'isOperatorPreview' => true])->render(),
        'public' => view('client-reports::reports.show', ['report' => $report, 'isOperatorPreview' => false])->render(),
    ];
}

it('reads reviewed malware findings as "Reviewed — no threat found", like the email', function () {
    $views = phrasesViews(phrasesReport(['security' => [
        'malware_status' => 'warning', 'malware_scan_id' => 42, 'malware_finding_kinds' => ['php_in_uploads' => 1],
        'malware_reviewed' => ['scan_id' => 42, 'finding_kinds' => ['php_in_uploads' => 1], 'reviewed_by' => 'Example Operator', 'note' => 'Checked.'],
    ]]));

    expect($views['html'])->toContain('Reviewed — no threat found')
        ->and($views['preview'])->toContain('Reviewed — no threat found')->not->toContain('Issues flagged')
        ->and($views['public'])->toContain('Reviewed — no threat found')->not->toContain('Issues flagged');
});

it('colours uptime the way the email does: green from 99.9%, amber from 99%, red below', function (float $pct, string $class, string $label) {
    $views = phrasesViews(phrasesReport(['uptime' => ['uptime_percentage' => $pct]]));

    expect($views['public'])->toMatch('/<div class="text-2xl font-black '.$class.'">'.preg_quote($label, '/').'<\/div>/')
        ->and($views['html'])->toContain($label);
})->with([
    'good' => [99.95, 'text-emerald-600', '99.95%'],
    'fair' => [99.5, 'text-amber-600', '99.5%'],
    'poor' => [97.0, 'text-red-600', '97%'],
]);

it('says the email\'s "no updates" sentence', function () {
    $current = phrasesViews(phrasesReport());
    $waiting = phrasesViews(phrasesReport(['updates' => ['pending_plugins' => ['Example Plugin']]]));

    expect($current['public'])->toContain('Everything was up to date at the end of the month.')
        ->and($waiting['public'])->toContain('No updates were applied this month.')->not->toContain('Everything was up to date');
});

it('names backup kinds and sizes, and data transferred, as the email does', function () {
    $views = phrasesViews(phrasesReport([
        'traffic' => ['has_traffic' => true, 'status' => 'ok', 'total_visits' => 1200, 'total_requests' => 9000, 'bandwidth_mb' => 2048, 'days_covered' => 30, 'period_days' => 30],
    ]));

    expect($views['public'])->toContain('Files &amp; database')->toContain('Wed, Sep 30')->toContain('60 MB')
        ->and($views['html'])->toContain('Files &amp; database');
    expect($views['html'])->toContain('Data transferred')->toContain('2.0 GB')
        ->and($views['public'])->toContain('Data Transferred')->toContain('2.0 GB');

    // No byte counts: the email leaves the row out, and so does the page.
    $unmeasured = phrasesViews(phrasesReport([
        'traffic' => ['has_traffic' => true, 'status' => 'ok', 'total_visits' => 1200, 'total_requests' => 9000, 'bandwidth_mb' => null, 'days_covered' => 30, 'period_days' => 30],
    ]));
    expect($unmeasured['html'])->not->toContain('Data transferred')
        ->and($unmeasured['public'])->not->toContain('Data Transferred')->not->toContain('Not measured');
});

it('says when the care plan began for a site that joined part-way through the month', function () {
    $views = phrasesViews(phrasesReport(['meta' => ['coverage_start' => '2026-09-12T14:00:00+00:00', 'coverage_source' => 'care_plan']]));

    expect($views['public'])->toContain('Your care plan began Sep 12')
        ->and($views['html'])->toContain('Your care plan began Sep 12');
});
