<?php

use App\Models\Site;
use Illuminate\Support\Carbon;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Services\ClientReportCompiler;
use Modules\ClientReports\Support\ReportPhrases;
use Modules\ClientReports\Support\ReportSections;

/*
|--------------------------------------------------------------------------
| One rendering truth for a client report
|--------------------------------------------------------------------------
|
| A report is shown three ways: the HTML email, its plain-text part, and the
| preview page, which is what prints as the PDF and is also the public
| /reports/view/{token} page. They must agree on which sections the client
| sees and on how each figure reads. Anything only an operator should see is
| a no-print note on the operator preview and never on the public view.
*/

/** A compiled-looking report: every section present, nothing worth showing in most. */
function viewsReport(array $sections = []): ClientReport
{
    $site = Site::where('domain', 'www.views-example.com')->first() ?? Site::factory()->create(['domain' => 'www.views-example.com']);
    $start = Carbon::parse('2026-09-01');
    $end = Carbon::parse('2026-09-30');

    return ClientReport::create([
        'site_id' => $site->id,
        'title' => 'Views test',
        'period_start' => $start,
        'period_end' => $end,
        'status' => 'generated',
        'sections_data' => array_replace([
            'meta' => ['schema_version' => ClientReportCompiler::SCHEMA_VERSION, 'custom_notes' => null],
            'branding' => [
                'company_name' => 'Example Agency', 'logo_url' => '', 'primary_color' => '#2D2062',
                'accent_color' => '#7EFF83', 'support_email' => 'support@example.com', 'footer_text' => '',
            ],
            'site' => ['domain' => 'www.views-example.com', 'is_wordpress' => true],
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

/** @return array{html: string, text: string, preview: string, public: string} */
function renderReportViews(ClientReport $report): array
{
    $mail = new ClientReportMail($report);
    $content = $mail->content();

    return [
        'html' => $mail->render(),
        'text' => view($content->text, $content->with)->render(),
        'preview' => view('client-reports::reports.show', ['report' => $report, 'isOperatorPreview' => true])->render(),
        'public' => view('client-reports::reports.show', ['report' => $report, 'isOperatorPreview' => false])->render(),
    ];
}

/** The operator note partial: a no-print box holding the note. */
function hiddenNotePattern(string $note): string
{
    return '/<div class="no-print[^"]*">\s*<i[^>]*><\/i>\s*<div>\s*<div>'.preg_quote(e($note), '/').'<\/div>/';
}

describe('which sections the client sees', function () {
    it('hides an empty work log from the email, the text part and the printout, and tells only the operator why', function () {
        $views = renderReportViews(viewsReport());

        expect($views['html'])->not->toContain('Additional work')
            ->and($views['text'])->not->toContain('ADDITIONAL WORK')
            ->and($views['preview'])->not->toContain('0.00 hours')->not->toContain('No manual work')
            ->toMatch(hiddenNotePattern('Work log hidden from the client: no hours were logged this period.'))
            ->and($views['public'])->not->toContain('Work log')->not->toContain('hidden from the client');
    });

    it('shows every section with something true to say in all three views', function () {
        $views = renderReportViews(viewsReport([
            'performance' => ['has_performance' => true, 'mobile' => ['score' => 91, 'lcp_ms' => 1800, 'scanned_at' => '2026-09-20'], 'desktop' => null],
            'forms' => ['live_tests' => 0, 'lab_tests' => 4, 'lab_accepted' => 4, 'pass_rate' => null],
            'traffic' => ['has_traffic' => true, 'total_visits' => 1234, 'total_requests' => 5678, 'bandwidth_mb' => 10.0],
            'work_log' => ['total_hours' => 1.5, 'entries' => [['worked_on' => '2026-09-12', 'hours' => 1.5, 'description' => 'Fixed the contact page layout', 'user' => null]]],
        ]));

        expect($views['html'])->toContain('Speed &amp; performance')->toContain('>Traffic<')->toContain('Contact forms')
            ->toContain('Backups')->toContain('Additional work this month')->toContain('Fixed the contact page layout')
            ->and($views['text'])->toContain('SPEED & PERFORMANCE')->toContain('TRAFFIC')->toContain('CONTACT FORMS')
            ->toContain('BACKUPS')->toContain('ADDITIONAL WORK THIS MONTH')
            ->and($views['preview'])->toContain('Speed & Performance')->toContain('Traffic Analytics')->toContain('Contact Forms')
            ->toContain('Backups & Recovery')->toContain('Fixed the contact page layout')->toContain('1.50 hours')
            ->not->toContain('hidden from the client');
    });

    it('decides visibility and the operator notes once, from the stored data', function () {
        $data = viewsReport([
            'uptime' => ['monitored' => false, 'probe_stopped' => true, 'last_checked_at' => '2026-09-12'],
            'backups' => ['verified' => false, 'status' => 'stale', 'last_backup_at' => '2026-09-05', 'recent' => []],
        ])->sections_data;

        expect(ReportSections::visibility($data))->toBe([
            'updates' => true, 'uptime' => false, 'security' => true, 'performance' => false,
            'traffic' => false, 'forms' => false, 'backups' => false, 'work_log' => false,
        ])->and(ReportSections::hiddenNotes($data))->toBe([
            'uptime' => "Uptime hidden from the client: the uptime probe hasn't checked the site since 2026-09-12.",
            'performance' => 'Speed & performance hidden from the client: no speed test ran in this period.',
            'traffic' => 'Traffic hidden from the client: no traffic was recorded for this period.',
            'forms' => 'Contact forms hidden from the client: no form tests ran in this period.',
            'backups' => 'Backups hidden from the client: no backups in the last 10 days of the period (last one 2026-09-05).',
            'work_log' => 'Work log hidden from the client: no hours were logged this period.',
        ]);

        // A section the template left out was never compiled: no note for it.
        unset($data['traffic']);
        expect(ReportSections::hiddenNotes($data))->not->toHaveKey('traffic');
    });

    it('never shows forms numbers from a report compiled before they were counted correctly', function () {
        $data = viewsReport(['meta' => ['custom_notes' => null], 'forms' => ['pass_rate' => 0, 'total_synthetic_tests' => 12, 'live_tests' => 12]])->sections_data;

        expect(ReportSections::visibility($data)['forms'])->toBeFalse()
            ->and(ReportSections::hiddenNotes($data)['forms'])->toBe('Contact forms hidden from the client: compiled before form results were counted correctly.');
    });
});

describe('the preview updates table', function () {
    it('lists a theme-only month instead of contradicting its own count', function () {
        $views = renderReportViews(viewsReport(['updates' => [
            'total' => 1, 'items' => ['core' => [], 'plugins' => [], 'themes' => [
                ['target' => 'example-theme', 'summary' => 'Theme updated: Example Theme 6.1 → 6.2', 'date' => '2026-09-14'],
            ]],
            // Something else was still waiting, which is what used to print "No updates were applied".
            'pending_plugins' => ['Example Builder'], 'pending_themes' => [], 'pending_core' => false, 'updated_since_period' => 0,
        ]]));

        expect($views['preview'])->toContain('Example Theme 6.1 → 6.2')->toContain('1 update performed')
            ->toMatch('/>1<\/div>\s*<div[^>]*>Updates Completed/')
            ->not->toContain('No updates were applied this period.')
            ->and($views['html'])->toContain('Example Theme 6.1 → 6.2');
    });

    it('lists core, theme and plugin updates, counted from the same rows as the email', function () {
        $views = renderReportViews(viewsReport(['updates' => [
            // The stored total also counts a translations run, which neither view lists.
            'total' => 5, 'translations_count' => 1,
            'items' => [
                'core' => [['target' => 'core', 'summary' => 'WordPress core updated: 6.8.2 → 6.8.3', 'date' => '2026-09-03']],
                'themes' => [['target' => 'example-theme', 'summary' => 'Theme updated: Example Theme 6.1 → 6.2', 'date' => '2026-09-14']],
                'plugins' => [
                    ['target' => 'example-forms/example-forms.php', 'summary' => 'Plugin updated: Example Forms 2.0 → 2.1 [1 repair: reactivated]', 'date' => '2026-09-20'],
                    ['target' => 'example-seo/example-seo.php', 'summary' => '', 'date' => '2026-09-22'],
                ],
            ],
            'pending_plugins' => [], 'pending_themes' => [], 'pending_core' => false, 'updated_since_period' => 0,
        ]]));

        expect($views['preview'])->toContain('4 updates performed')->toMatch('/>4<\/div>\s*<div[^>]*>Updates Completed/')
            ->toContain('6.8.2 → 6.8.3')->toContain('Example Theme 6.1 → 6.2')->toContain('Example Forms 2.0 → 2.1')->toContain('Example Seo')
            ->toContain('>WordPress<')->toContain('>Theme<')->toContain('>Plugin<')
            ->not->toContain('Plugin updated:')->not->toContain('1 repair')->not->toContain('example-forms/example-forms.php')
            ->and($views['text'])->toContain('UPDATES APPLIED (4)');
    });
});

describe('contact forms in the preview', function () {
    it('shows lab checks with the email\'s wording, in a section and a tile', function () {
        $views = renderReportViews(viewsReport(['forms' => ['live_tests' => 0, 'lab_tests' => 19, 'lab_accepted' => 18, 'pass_rate' => null]]));

        expect($views['preview'])->toContain('Your forms accepted <strong>18 of 19</strong> automated check submissions.')
            ->toContain("These checks confirm the form works without sending an email, so they don't test delivery.")
            ->toMatch('/>18\/19<\/div>\s*<div[^>]*>Form Checks Passed/')
            ->not->toContain('% delivered')->not->toContain('Form Deliverability')
            ->and($views['html'])->toContain('accepted <strong>18 of 19</strong> automated check submissions');
    });

    it('shows live delivery tests with the email\'s wording', function () {
        $views = renderReportViews(viewsReport(['forms' => ['live_tests' => 4, 'lab_tests' => 0, 'lab_accepted' => 0, 'pass_rate' => 75]]));

        expect($views['preview'])->toContain('We sent <strong>4</strong> test submissions through your forms to confirm messages reach you')
            ->toContain('75% delivered')->toContain('Form Deliverability')
            ->and($views['html'])->toContain('75% delivered');
    });

    it('leaves the section out when no form tests ran, with a note only the operator sees', function () {
        $views = renderReportViews(viewsReport());

        expect($views['preview'])->not->toContain('Contact Forms')
            ->toMatch(hiddenNotePattern('Contact forms hidden from the client: no form tests ran in this period.'))
            ->and($views['public'])->not->toContain('Contact forms')
            ->and($views['html'])->not->toContain('Contact forms');
    });
});

describe('the public view and the printout', function () {
    it('keeps staff names off the printout and the public view', function () {
        $views = renderReportViews(viewsReport(['work_log' => ['total_hours' => 2, 'entries' => [
            ['worked_on' => '2026-09-12', 'hours' => 2, 'description' => 'Rebuilt the donation form', 'user' => 'Example Staffer'],
        ]]]));

        expect($views['preview'])->toMatch('/<span class="no-print[^"]*">Example Staffer<\/span>/')
            ->and($views['public'])->toContain('Rebuilt the donation form')->not->toContain('Example Staffer')
            ->and($views['html'])->not->toContain('Example Staffer')
            ->and($views['text'])->not->toContain('Example Staffer');
    });

    it('shows internal wording only to the operator, never on the public view', function () {
        $views = renderReportViews(viewsReport([
            'site' => ['domain' => 'www.views-example.com', 'is_wordpress' => false],
            'security' => ['total_scans' => 3, 'vulnerabilities_checked' => false, 'blocked_threats_count' => null, 'checksum_status' => null],
            'backups' => ['verified' => false, 'status' => 'not_checked', 'source' => 'pressable', 'recent' => [], 'check_error' => 'Example API returned 503'],
        ]));

        expect($views['preview'])->toContain('no blocker reports to Control')->toContain('no WordPress detected')
            ->toMatch(hiddenNotePattern('Backups hidden from the client: backups could not be checked.'))
            ->toContain('Last check failed: Example API returned 503');

        foreach (['Control', 'no WordPress detected', 'hidden from the client', 'Last check failed', 'Backups & Recovery', 'Email version', 'IP bans'] as $internal) {
            expect($views['public'])->not->toContain($internal);
        }
        // Like the email: no core-files row for a non-WordPress site, no blocked count without a blocker.
        expect($views['public'])->not->toContain('WordPress Core Files')->not->toContain('Malicious Requests Deflected')
            ->and($views['html'])->not->toContain('WordPress core files')->not->toContain('Malicious visitors blocked');
    });

    it('serves the public page without operator notes', function () {
        $report = viewsReport();

        $this->get(route('client-reports.public', ['token' => $report->public_token]))
            ->assertOk()
            ->assertSee('Website Care Report')
            ->assertDontSee('hidden from the client')
            ->assertDontSee('Email version');
    });
});

describe('outage times', function () {
    function outageReport(string $startedAt, bool $beforePeriod = false): ClientReport
    {
        return viewsReport(['uptime' => [
            'monitored' => true, 'uptime_percentage' => 99.44, 'outages_count' => 1, 'downtime_minutes' => 240,
            'outages' => [['started_at' => $startedAt, 'minutes' => 240, 'started_before_period' => $beforePeriod, 'ongoing' => false]],
        ]]);
    }

    it('shows outage start times in the configured report timezone, not UTC', function () {
        config(['clockwork.reports.timezone' => 'America/Los_Angeles']);
        $views = renderReportViews(outageReport('2026-09-10T10:00:00+00:00'));

        expect($views['html'])->toContain('Down Sep 10, 3:00 AM PDT')->not->toContain('UTC')
            ->and($views['text'])->toContain('- Down Sep 10, 3:00 AM PDT for 240 min');
    });

    it('defaults to the operators\' timezone, with the right abbreviation for the date', function () {
        expect(config('clockwork.reports.timezone'))->toBe('America/New_York')
            ->and(ReportPhrases::outageStart(['started_at' => '2026-09-10T10:00:00+00:00']))->toBe('Down Sep 10, 6:00 AM EDT')
            ->and(ReportPhrases::outageStart(['started_at' => '2026-12-10T15:30:00+00:00']))->toBe('Down Dec 10, 10:30 AM EST');
    });

    it('falls back to UTC for an invalid timezone setting instead of breaking the email', function () {
        config(['clockwork.reports.timezone' => 'Not/A_Zone']);

        expect(ReportPhrases::timezone())->toBe('UTC')
            ->and(renderReportViews(outageReport('2026-09-10T10:00:00+00:00'))['html'])->toContain('Down Sep 10, 10:00 AM UTC');
    });

    it('gives only the date for an outage already open when the period began', function () {
        $views = renderReportViews(outageReport('2026-09-01T00:00:00+00:00', beforePeriod: true));

        expect($views['html'])->toContain('Down since before Sep 1<')->not->toContain('Aug 31')
            ->and($views['text'])->toContain('- Down since before Sep 1 for 240 min');
    });
});

describe('known plugin vulnerabilities', function () {
    it('says when the plugins were checked and names the affected plugins in every view', function () {
        $views = renderReportViews(viewsReport(['security' => [
            'total_scans' => 30, 'vulnerabilities_checked' => true, 'vulnerabilities_as_of' => '2026-10-01',
            'active_vulnerabilities' => 3, 'vulnerable_plugins' => ['Alpha Forms', 'Beta Gallery'], 'inactive_plugin_vulnerabilities' => 1,
            'blocked_threats_count' => null, 'checksum_status' => 'clean',
        ]]));

        expect($views['html'])->toContain('(checked Oct 1)')->toContain('3 found in 2 plugins (1 in inactive plugins)')
            ->toContain('Plugins affected: Alpha Forms, Beta Gallery')->not->toContain('as of')
            ->and($views['text'])->toContain("Known plugin vulnerabilities (checked Oct 1): 3 found in 2 plugins (1 in inactive plugins)\nPlugins affected: Alpha Forms, Beta Gallery\n")
            ->and($views['preview'])->toContain('3 found in 2 plugins (1 in inactive plugins)')
            ->toContain('Plugins affected: Alpha Forms, Beta Gallery')->toContain('Checked Oct 1')->not->toContain('As of');
    });

    it('names no plugins when none were found or the plugins were not checked', function () {
        $none = renderReportViews(viewsReport());
        $unchecked = renderReportViews(viewsReport(['security' => ['total_scans' => 1, 'vulnerabilities_checked' => false, 'vulnerable_plugins' => ['Stale Plugin'], 'active_vulnerabilities' => 1]]));

        expect($none['html'])->toContain('None found')->not->toContain('affected:')
            ->and($none['text'])->toContain('Known plugin vulnerabilities (checked Oct 1): None found')
            ->and($unchecked['html'])->toContain('Not checked')->not->toContain('Stale Plugin')
            ->and($unchecked['preview'])->not->toContain('Stale Plugin');
    });
});

describe('backup destination', function () {
    it('reads as a whole phrase for every backup source', function (string $source, string $phrase) {
        $views = renderReportViews(viewsReport(['backups' => [
            'verified' => true, 'status' => 'verified', 'source' => $source, 'destination' => ReportPhrases::BACKUP_DESTINATIONS[$source],
            'days_with_backups' => 30, 'covers_period' => true, 'recent' => [['date' => '2026-09-30', 'kinds' => ['full'], 'bytes' => null, 'manual' => false]],
        ]]));

        expect($views['html'])->toContain("backed up on <strong>30 days</strong> this month {$phrase}, so it can be restored")
            ->and($views['text'])->toContain("Backed up on 30 days this month {$phrase}. Most recent:")
            ->and($views['preview'])->toContain("Backed up on 30 days this period {$phrase}.");
        foreach (['html', 'text', 'preview'] as $view) {
            expect($views[$view])->not->toContain('by by')->not->toContain('by to ');
        }
    })->with([
        'host' => ['pressable', 'by your host, Pressable'],
        'object storage' => ['spinupwp_spaces', 'by SpinupWP to DigitalOcean Spaces'],
        'our archives' => ['s3_archive', 'to independent off-site storage (Amazon S3)'],
    ]);

    it('keeps reports compiled before the phrase was stored whole reading correctly', function (array $backups, ?string $expected) {
        expect(ReportPhrases::backupDestination($backups))->toBe($expected);
    })->with([
        'old destination, known source' => [['source' => 's3_archive', 'destination' => 'independent off-site storage (Amazon S3)'], 'to independent off-site storage (Amazon S3)'],
        'old destination, no source' => [['destination' => 'Example Snapshots'], 'by Example Snapshots'],
        'whole phrase, no source' => [['destination' => 'to Example Storage'], 'to Example Storage'],
        'nothing known' => [['source' => null, 'destination' => null], null],
    ]);

    it('says where an older report was backed up without a dangling "by"', function () {
        $views = renderReportViews(viewsReport(['backups' => [
            'verified' => true, 'status' => 'verified', 'source' => 'pressable', 'destination' => 'your host, Pressable',
            'days_with_backups' => 9, 'covers_period' => false, 'records_from' => '2026-09-22',
            'recent' => [['date' => '2026-09-30', 'kinds' => ['fs'], 'bytes' => null, 'manual' => false]],
        ]]));

        expect($views['html'])->toContain('Your site is backed up by your host, Pressable, so it can be restored')
            ->and($views['text'])->toContain('Backed up by your host, Pressable. Most recent:')
            ->and($views['preview'])->toContain('Backed up by your host, Pressable.')
            ->toContain('Our records for this site start 2026-09-22')
            ->and($views['public'])->not->toContain('Our records for this site start');
    });
});

describe('partial-month coverage', function () {
    it('says when monitoring began in every view of a site enrolled part-way through the month', function () {
        // Late evening UTC is still the same day in the report timezone.
        $views = renderReportViews(viewsReport([
            'meta' => ['schema_version' => ClientReportCompiler::SCHEMA_VERSION, 'custom_notes' => null, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'coverage_start' => '2026-09-26T18:20:00+00:00'],
            'uptime' => [
                'monitored' => true, 'probe_stopped' => false, 'monitored_since' => '2026-09-26T18:20:00+00:00', 'uptime_percentage' => 98.33,
                'outages_count' => 1, 'downtime_minutes' => 102, 'total_period_minutes' => 6100,
                'outages' => [['started_at' => '2026-09-27T10:00:00+00:00', 'minutes' => 102, 'started_before_period' => false, 'ongoing' => false]],
            ],
        ]));
        $note = 'Monitoring began Sep 26, so this report covers Sep 26 – Sep 30.';

        expect($views['html'])->toContain($note)->toContain('(monitoring began Sep 26)')
            ->and($views['text'])->toContain("for September 2026.\n".$note)->toContain('Availability: 98.33% (monitoring began Sep 26)')
            ->and($views['preview'])->toContain($note)->toContain('Monitoring began')->not->toContain('24 / 7 / 365')
            ->and($views['public'])->toContain($note)->toContain('Monitoring began');
    });

    it('keeps a full month, and reports compiled before coverage was recorded, as they were', function () {
        $views = renderReportViews(viewsReport());

        expect($views['html'])->not->toContain('onitoring began')
            ->and($views['text'])->not->toContain('onitoring began')
            ->and($views['preview'])->toContain('24 / 7 / 365')->not->toContain('onitoring began');
    });

    it('shows the time the monitor was blocked only to the operator', function () {
        $views = renderReportViews(viewsReport(['uptime' => [
            'monitored' => true, 'probe_stopped' => false, 'uptime_percentage' => 100, 'outages_count' => 0, 'downtime_minutes' => 0,
            'outages' => [], 'probe_blocked_minutes' => 720, 'probe_blocked_since' => null,
        ]]));

        expect($views['preview'])->toContain('The uptime monitor was blocked (HTTP 429) for 720 min this period.')
            ->and($views['public'])->not->toContain('HTTP 429')
            ->and($views['html'])->not->toContain('HTTP 429')
            ->and($views['text'])->not->toContain('HTTP 429');
    });
});
