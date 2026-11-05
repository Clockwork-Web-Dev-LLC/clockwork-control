<?php

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Modules\ClientManagement\Models\Client;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;

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

    // Domain text is protected from mail-client auto-linking (blue-on-purple).
    expect($html)->toContain('example-client&zwnj;.org')
        ->toContain('format-detection')
        ->toContain('x-apple-data-detectors');

    expect($html)->toMatch('/background:#2D2062;[^"]*padding:28px 32px 24px 32px/')
        ->toContain('bgcolor="#2D2062"');
});

it('hides the contact-forms section when no form tests ran', function () {
    $html = (new ClientReportMail(clientReportMailFixture()))->render();
    expect($html)->not->toContain('Contact forms');

    $html = (new ClientReportMail(clientReportMailFixture([
        'meta' => ['schema_version' => 2],
        'forms' => ['total_synthetic_tests' => 4, 'live_tests' => 4, 'pass_rate' => 100],
    ])))->render();
    expect($html)->toContain('Contact forms')->toContain('your site reported sending the notification email for')
        ->toContain('100%</strong> of them.')->not->toContain('% delivered');
});

it('does not HTML-escape names and notes in the plain-text part', function () {
    $report = clientReportMailFixture(['meta' => ['custom_notes' => "We've fixed the <contact> form & cache."]]);
    $report->forceFill(['client_email' => 'darcy@obrien.test'])->save();
    $report->site->forceFill(['client_id' => Client::create(['name' => "D'Arcy O'Brien", 'email' => 'darcy@obrien.test'])->id])->save();
    $mail = new ClientReportMail($report->fresh());
    $content = $mail->content();

    $text = view($content->text, $content->with)->render();

    expect($text)->toContain("Hi D'Arcy,")
        ->toContain("We've fixed the <contact> form & cache.")
        ->not->toContain('&amp;')->not->toContain('&#039;');
    // The HTML part still escapes them.
    expect($mail->render())->toContain('Hi D&#039;Arcy,')->toContain('&lt;contact&gt; form &amp; cache')->not->toContain('<contact>');
});

describe('the greeting', function () {
    /** @return array{html: string, text: string} */
    function greetingParts(ClientReport $report): array
    {
        $mail = new ClientReportMail($report->fresh());
        $content = $mail->content();

        return ['html' => $mail->render(), 'text' => view($content->text, $content->with)->render()];
    }

    it('greets the one recipient by first name when they are the contact on file, in both parts', function () {
        $report = clientReportMailFixture();
        $report->forceFill(['client_email' => 'Jane@Example.test'])->save();
        $report->site->forceFill(['client_id' => Client::create(['name' => 'Jane Example', 'email' => 'jane@example.test'])->id])->save();

        $parts = greetingParts($report);

        expect($parts['html'])->toContain('Hi Jane,')->not->toContain('Hi Jane Example,')
            ->and($parts['text'])->toContain('Hi Jane,');
    });

    it('greets a recipient by name when another client record holds their address', function () {
        $report = clientReportMailFixture();
        $report->forceFill(['client_email' => 'sam@example.test'])->save();
        $report->site->forceFill(['client_id' => Client::create(['name' => 'Alex Example', 'email' => 'alex@example.test'])->id])->save();
        Client::create(['name' => 'Sam Sample', 'email' => 'sam@example.test']);

        expect(greetingParts($report)['text'])->toContain('Hi Sam,')->not->toContain('Alex');
    });

    it('says "Hi there," when the one recipient is not a known contact, even though the site has one', function () {
        $report = clientReportMailFixture();
        $report->forceFill(['client_email' => 'someone-else@example.test'])->save();
        $report->site->forceFill(['client_id' => Client::create(['name' => 'Alex Example', 'email' => 'alex@example.test'])->id])->save();

        $parts = greetingParts($report);

        expect($parts['html'])->toContain('Hi there,')->not->toContain('Alex')
            ->and($parts['text'])->toContain('Hi there,')->not->toContain('Alex');
    });

    it('says "Hi there," to several recipients, even when the first is the contact on file', function () {
        $report = clientReportMailFixture();
        $report->forceFill(['client_email' => 'jane@example.test, colleague@example.test'])->save();
        $report->site->forceFill(['client_id' => Client::create(['name' => 'Jane Example', 'email' => 'jane@example.test'])->id])->save();

        $parts = greetingParts($report);

        expect($parts['html'])->toContain('Hi there,')->not->toContain('Hi Jane')
            ->and($parts['text'])->toContain('Hi there,');
    });

    it('falls back to the schedule recipients when the report has none stored', function () {
        $report = clientReportMailFixture();
        $schedule = ClientReportSchedule::create([
            'site_id' => $report->site_id, 'frequency' => 'monthly', 'delivery_mode' => 'auto',
            'recipients' => ['jane@example.test', 'colleague@example.test'], 'is_enabled' => true,
        ]);
        $report->forceFill(['schedule_id' => $schedule->id, 'client_email' => null])->save();
        $report->site->forceFill(['client_id' => Client::create(['name' => 'Jane Example', 'email' => 'jane@example.test'])->id])->save();

        expect(greetingParts($report)['text'])->toContain('Hi there,');
    });

    it('says "Hi there," when the contact\'s stored name is not plainly a person\'s', function (string $name) {
        $report = clientReportMailFixture();
        $report->forceFill(['client_email' => 'office@example.test'])->save();
        $report->site->forceFill(['client_id' => Client::create(['name' => $name, 'email' => 'office@example.test'])->id])->save();

        $parts = greetingParts($report);

        expect($parts['html'])->toContain('Hi there,')
            ->and($parts['text'])->toContain('Hi there,');
    })->with([
        'an organisation' => 'Example Institute',
        'an acronym' => 'EXCO',
        'an initial' => 'J. Example',
        'a note in brackets' => 'Jane (Example Strategy)',
        'a company spelled out' => 'Example Girl Interior Design',
    ]);
});

it('has a client-friendly subject and replies go to support', function () {
    $mail = new ClientReportMail(clientReportMailFixture());

    $mail->assertHasSubject('Your September 2026 website report — example-client.org');
    $mail->assertHasReplyTo('support@clockwork.test');
});

it('sends from the configured address under the report brand name, not MAIL_FROM_NAME', function () {
    config(['mail.from.address' => 'reports@example.com', 'mail.from.name' => 'Internal Control App']);
    $mail = new ClientReportMail(clientReportMailFixture(['branding' => ['company_name' => 'Example Agency']]));

    $mail->assertFrom('reports@example.com', 'Example Agency');
    expect($mail->envelope()->from?->name)->toBe('Example Agency');

    // The header that actually goes out, through the array transport.
    Mail::to('client@example.com')->send($mail);
    $from = app('mail.manager')->mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getFrom();

    expect($from)->toHaveCount(1)
        ->and($from[0]->getAddress())->toBe('reports@example.com')
        ->and($from[0]->getName())->toBe('Example Agency');
});

it('leaves the global From in place when no sending address is configured', function () {
    config(['mail.from.address' => null]);

    expect((new ClientReportMail(clientReportMailFixture()))->envelope()->from)->toBeNull();
});

it('prints the footer text in the plain-text part when one is set', function () {
    $mail = new ClientReportMail(clientReportMailFixture(['branding' => ['footer_text' => 'Example Agency, 1 Example Street']]));
    $content = $mail->content();
    $text = view($content->text, $content->with)->render();

    expect($text)->toContain("Example Agency, 1 Example Street\n\nQuestions about anything in this report?")
        ->and($mail->render())->toContain('Example Agency, 1 Example Street');

    // No footer set: no stray blank block before the sign-off.
    $plain = new ClientReportMail(clientReportMailFixture());
    $content = $plain->content();

    expect(view($content->text, $content->with)->render())->not->toContain("\n\n\nQuestions about anything");
});

it('cleans raw action-log summaries', function (string $summary, ?string $target, string $expected) {
    expect(ClientReportMail::cleanUpdateSummary($summary, $target))->toBe($expected);
})->with([
    ['Plugin updated: Yoast SEO 26.1 → 26.2', null, 'Yoast SEO 26.1 → 26.2'],
    ['Theme updated: Astra 4.1 → 4.2 [1 repair: x]', null, 'Astra 4.1 → 4.2'],
    ['', 'wp-mail-smtp/wp_mail_smtp.php', 'Wp Mail Smtp'],
    ['', null, 'Software update'],
]);
