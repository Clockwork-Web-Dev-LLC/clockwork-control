<?php

use App\Models\Site;
use App\Models\User;
use Modules\ClientReports\Models\ClientReport;

/*
|--------------------------------------------------------------------------
| Client report pages — self-hosted styles, fonts and icons
|--------------------------------------------------------------------------
|
| The report page (operator preview, public /reports/view/{token}, and what
| prints as the PDF) and the email preview used to load the Tailwind Play
| CDN, Font Awesome from cdnjs and Plus Jakarta Sans from Google Fonts, so
| without internet access they rendered unstyled. They now load only files
| from public/vendor/client-reports/.
|
| report.css there is generated from the report views by
| `npm run build:report-css` (Tailwind v3). These tests catch a class added
| to a view without rebuilding it. CI also rebuilds the file and fails if it
| differs from the committed one, which covers class names put together in
| PHP that only show up in a branch these fixtures don't render.
*/

/** The report views report.css is built from (see its tailwind.config.cjs). */
function clientReportAssetsViews(): array
{
    return glob(base_path('modules/ClientReports/resources/views/reports/*.blade.php'));
}

function clientReportAssetsSections(array $overrides = []): array
{
    return array_replace_recursive([
        'meta' => ['schema_version' => 2, 'custom_notes' => 'Moved the site to PHP 8.3.'],
        'branding' => ['company_name' => 'Clockwork', 'primary_color' => '#2D2062', 'accent_color' => '#7EFF83', 'support_email' => 'support@clockwork.test', 'footer_text' => 'Thanks!'],
        'site' => ['domain' => 'assets-report.test', 'is_wordpress' => true],
        'updates' => ['total' => 1, 'items' => ['plugins' => [['target' => 'Yoast SEO', 'summary' => 'Updated 23.4 → 23.5', 'date' => '2026-10-09']]]],
        'uptime' => ['uptime_percentage' => 99.98, 'outages_count' => 1, 'downtime_minutes' => 7, 'monitored' => true],
        'security' => [
            'total_scans' => 31, 'checksum_status' => 'clean', 'malware_status' => 'clean', 'blocked_threats_count' => 12,
            'vulnerabilities_checked' => true, 'active_vulnerabilities' => 0,
        ],
        'performance' => ['has_performance' => true, 'desktop' => ['score' => 97, 'lcp_ms' => 812], 'mobile' => ['score' => 84, 'lcp_ms' => 2140]],
        'forms' => ['live_tests' => 4, 'pass_rate' => 100],
        'traffic' => ['has_traffic' => true, 'total_visits' => 1234, 'total_requests' => 5678, 'bandwidth_mb' => 10.0],
        'backups' => [
            'status' => 'verified', 'covers_period' => true, 'days_with_backups' => 31, 'destination' => 'Amazon S3',
            'recent' => [['date' => '2026-10-31', 'kinds' => ['files', 'database'], 'bytes' => 734003200]],
        ],
        'work_log' => ['total_hours' => 1.5, 'entries' => [['worked_on' => 'Oct 6, 2026', 'hours' => 1.5, 'description' => 'Rebuilt the contact form.', 'user' => 'Aaron']]],
    ], $overrides);
}

/** Every class token in the HTML's class attributes, minus Blade echoes in the source views. */
function clientReportAssetsClasses(string $html): array
{
    preg_match_all('/\sclass="([^"]*)"/', $html, $matches);

    return collect($matches[1])
        ->map(fn (string $value) => preg_replace('/\{\{.*?\}\}/s', ' ', $value))
        ->flatMap(fn (string $value) => preg_split('/\s+/', html_entity_decode($value), -1, PREG_SPLIT_NO_EMPTY))
        ->unique()->values()->all();
}

/** True when the CSS has a rule whose selector uses the class (Tailwind escapes ":", ".", "[" etc.). */
function clientReportAssetsCssHasClass(string $css, string $class): bool
{
    $escaped = preg_replace('/[^A-Za-z0-9_-]/', '\\\\$0', $class);

    return preg_match('/\.'.preg_quote($escaped, '/').'(?![A-Za-z0-9_\\\\-])/', $css) === 1;
}

/** The public/ path behind a same-origin URL. */
function clientReportAssetsPublicPath(string $url): string
{
    return public_path(ltrim((string) parse_url($url, PHP_URL_PATH), '/'));
}

beforeEach(function () {
    $site = Site::factory()->create(['domain' => 'assets-report.test']);
    $report = fn (string $title, array $sections) => ClientReport::create([
        'site_id' => $site->id,
        'title' => $title,
        'client_name' => 'Café Łódź',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'status' => 'generated',
        'sections_data' => $sections,
    ]);

    // Flagged, so the operator-only boxes and no-print badges render too.
    $flagged = $report('Flagged report', clientReportAssetsSections([
        'meta' => ['review_reasons' => ['Backups were not checked this period.']],
        'security' => ['checksum_status' => 'issues_found', 'malware_status' => 'warning', 'active_vulnerabilities' => 2, 'vulnerable_plugins' => ['Elementor']],
        'backups' => ['status' => 'stale', 'covers_period' => false, 'records_from' => 'Oct 12, 2026', 'check_error' => 'S3 returned 403'],
    ]));
    $clean = $report('Clean report', clientReportAssetsSections());

    $user = User::factory()->create();
    $this->pages = [
        'operator preview' => $this->actingAs($user)->get(route('client-reports.show', $flagged))->assertOk()->getContent(),
        'email preview' => $this->actingAs($user)->get(route('client-reports.email', $flagged))->assertOk()->getContent(),
        'public page' => $this->get(route('client-reports.public', $clean->public_token))->assertOk()->getContent(),
    ];
});

it('loads nothing from another host', function () {
    $appHost = parse_url(url('/'), PHP_URL_HOST);

    foreach ($this->pages as $page => $html) {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $urls = [];
        foreach ($xpath->query('//script/@src | //link/@href | //img/@src | //iframe/@src | //source/@src | //video/@src | //audio/@src') as $attribute) {
            $urls[] = $attribute->value;
        }
        foreach ($xpath->query('//style | //*/@style') as $node) {
            preg_match_all('/url\(\s*[\'"]?([^\'")\s]+)|@import\s+[\'"]([^\'"]+)/i', $node->textContent, $matches);
            $urls = [...$urls, ...array_filter([...$matches[1], ...$matches[2]])];
        }

        expect($urls)->not->toBeEmpty();
        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            expect($host === null || $host === $appHost)->toBeTrue("The {$page} loads {$url} from another host.");
        }
    }
});

it('serves every stylesheet and font the pages link to', function () {
    foreach ($this->pages as $page => $html) {
        preg_match_all('/<link rel="stylesheet" href="([^"]+)"/', $html, $links);
        expect($links[1])->toHaveCount(3, "The {$page} should link report.css and Font Awesome's two stylesheets.");

        foreach ($links[1] as $href) {
            $stylesheet = clientReportAssetsPublicPath($href);
            expect($stylesheet)->toBeFile("The {$page} links {$href}, which isn't in public/.");

            $css = file_get_contents($stylesheet);
            expect($css)->not->toMatch('#(@import|url\(\s*[\'"]?(https?:)?//)#i', "{$href} pulls in something from another host.");

            // Each @font-face needs a woff2 that exists (Font Awesome's .ttf fallbacks aren't shipped).
            preg_match_all('/@font-face\s*\{[^}]*\}/', $css, $faces);
            foreach ($faces[0] as $face) {
                preg_match('/url\(\s*[\'"]?([^\'")]+\.woff2)/', $face, $woff2);
                expect($woff2)->not->toBeEmpty("{$href} has an @font-face without a woff2 source.");
                expect(dirname($stylesheet).'/'.$woff2[1])->toBeFile("{$href} refers to {$woff2[1]}, which is missing.");
            }
        }
    }
});

it('has a rule in report.css for every class the report views use', function () {
    $css = file_get_contents(public_path('vendor/client-reports/report.css'));

    $classes = collect(clientReportAssetsViews())
        ->map(fn (string $view) => file_get_contents($view))
        ->merge($this->pages)
        ->flatMap(fn (string $html) => clientReportAssetsClasses($html))
        ->unique();

    // Classes the page styles itself (no-print, report-card) and Font Awesome's (checked below).
    preg_match_all('/<style>(.*?)<\/style>/s', file_get_contents(base_path('modules/ClientReports/resources/views/reports/show.blade.php')), $styles);
    preg_match_all('/\.([A-Za-z][\w-]*)/', implode("\n", $styles[1]), $ownClasses);

    $missing = $classes
        ->reject(fn (string $class) => in_array($class, $ownClasses[1], true) || str_starts_with($class, 'fa-'))
        ->reject(fn (string $class) => clientReportAssetsCssHasClass($css, $class))
        ->values()->all();

    expect($missing)->toBe([], 'public/vendor/client-reports/report.css has no rule for: '.implode(', ', $missing)
        .'. Run `npm run build:report-css` and commit the result.');
});

it('ships every Font Awesome icon the report views use', function () {
    $iconCss = file_get_contents(public_path('vendor/client-reports/fontawesome/css/fontawesome.min.css'));

    $faClasses = collect(clientReportAssetsViews())
        ->map(fn (string $view) => file_get_contents($view))
        ->merge($this->pages)
        ->flatMap(fn (string $html) => clientReportAssetsClasses($html))
        ->filter(fn (string $class) => str_starts_with($class, 'fa-'))
        ->unique();

    expect($faClasses)->toContain('fa-solid');

    foreach ($faClasses as $class) {
        // Only the solid style's font is shipped (solid.min.css + fa-solid-900.woff2).
        expect(in_array($class, ['fa-regular', 'fa-brands', 'fa-light', 'fa-thin', 'fa-duotone'], true))
            ->toBeFalse("{$class} icons aren't shipped with the report pages; use fa-solid or add that style's files.");

        if ($class !== 'fa-solid') {
            expect(clientReportAssetsCssHasClass($iconCss, $class))->toBeTrue("Font Awesome 6.4 has no {$class}.");
        }
    }
});
