<?php

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;

function clientReportMailFixture(array $overrides = []): ClientReport
{
    $site = Site::where('domain', 'www.example-client.org')->first() ?? Site::factory()->create(['domain' => 'www.example-client.org']);

    return ClientReport::create([
        'site_id' => $site->id,
        'title' => 'Maintenance Report: www.example-client.org (Sep 2026)',
        'period_start' => Carbon::parse('2026-09-01'),
        'period_end' => Carbon::parse('2026-09-30'),
        'client_name' => $site->domain,
        'status' => 'generated',
        'sections_data' => array_replace_recursive([
            'meta' => ['custom_notes' => null],
            'branding' => [
                'company_name' => 'Clockwork Web Dev',
                'logo_url' => 'https://brand.example/logo.png',
                'primary_color' => '#2D2062',
                'accent_color' => '#7EFF83',
                'support_email' => 'support@clockwork.test',
                'support_url' => 'http://localhost:8000',
                'footer_text' => '',
            ],
            'site' => ['domain' => 'www.example-client.org'],
            'updates' => ['items' => ['core' => [], 'themes' => [], 'plugins' => [
                ['date' => '2026-09-30', 'target' => 'translatepress-multilingual/index.php', 'summary' => 'Plugin updated: TranslatePress - Multilingual 3.3.6 → 3.3.7 [1 repair: network_plugin_reactivated: translatepress-multilingual/index.php]'],
                ['date' => '2026-09-25', 'target' => 'the-events-calendar/the-events-calendar.php', 'summary' => ''],
            ]]],
            'uptime' => ['monitored' => true, 'outages_count' => 1, 'downtime_minutes' => 75, 'uptime_percentage' => 99.83],
            'security' => ['total_scans' => 91, 'clean_scans' => 59, 'checksum_status' => 'clean', 'blocked_threats_count' => 3, 'active_vulnerabilities' => 0],
            'performance' => ['has_performance' => true, 'mobile' => ['score' => 95, 'lcp_ms' => 1066, 'scanned_at' => '2026-10-04'], 'desktop' => null],
            'forms' => ['pass_rate' => 100, 'successful_tests' => 0, 'total_synthetic_tests' => 0],
            'traffic' => ['has_traffic' => true, 'bandwidth_mb' => 41632.2, 'total_visits' => 230861, 'total_requests' => 1429336],
            'backups' => ['enabled' => true, 'destination' => 'Host Automated Snapshots', 'last_backup_at' => null],
            'work_log' => ['entries' => [], 'total_hours' => 0],
        ], $overrides),
    ]);
}

beforeEach(function () {
    Http::fake(['brand.example/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
});

it('renders the full report in the email body with no links out', function () {
    $html = (new ClientReportMail(clientReportMailFixture()))->render();

    expect($html)
        ->toContain('Website Care Report')
        ->toContain('TranslatePress - Multilingual 3.3.6 → 3.3.7')
        ->toContain('The Events Calendar')
        ->toContain('99.83%')
        ->toContain('1h 15m')
        ->toContain('Verified clean')
        ->toContain('230,861')
        ->toContain('230.9K')
        ->toContain('40.7 GB')
        ->toContain('Tested Oct 4, 2026')
        ->not->toContain('View Full Report')
        ->not->toContain('/reports/view/')
        ->not->toContain('localhost')
        ->not->toContain('network_plugin_reactivated')
        ->not->toContain('translatepress-multilingual/index.php');

    // Only link in the email is the support mailto.
    preg_match_all('/href="([^"]+)"/', $html, $m);
    expect($m[1])->toBe(['mailto:support@clockwork.test']);
});

it('uses the brand purple behind the header so a white logo is visible', function () {
    $html = (new ClientReportMail(clientReportMailFixture()))->render();

    expect($html)->toMatch('/background:#2D2062;[^"]*padding:28px 32px 24px 32px/')
        ->toContain('bgcolor="#2D2062"');
});

it('hides the contact-forms section when no form tests ran', function () {
    $html = (new ClientReportMail(clientReportMailFixture()))->render();
    expect($html)->not->toContain('Contact forms');

    $html = (new ClientReportMail(clientReportMailFixture(['forms' => ['total_synthetic_tests' => 4, 'pass_rate' => 100]])))->render();
    expect($html)->toContain('Contact forms')->toContain('100% delivered');
});

it('has a client-friendly subject and replies go to support', function () {
    $mail = new ClientReportMail(clientReportMailFixture());

    $mail->assertHasSubject('Your September 2026 website report — example-client.org');
    $mail->assertHasReplyTo('support@clockwork.test');
});

it('cleans raw action-log summaries', function (string $summary, ?string $target, string $expected) {
    expect(ClientReportMail::cleanUpdateSummary($summary, $target))->toBe($expected);
})->with([
    ['Plugin updated: Yoast SEO 26.1 → 26.2', null, 'Yoast SEO 26.1 → 26.2'],
    ['Theme updated: Astra 4.1 → 4.2 [1 repair: x]', null, 'Astra 4.1 → 4.2'],
    ['', 'wp-mail-smtp/wp_mail_smtp.php', 'Wp Mail Smtp'],
    ['', null, 'Software update'],
]);
