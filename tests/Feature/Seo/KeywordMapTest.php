<?php

use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Seo\Maps\KeywordMapCsv;
use Modules\Seo\Maps\KeywordMapService;
use Modules\Seo\Maps\StaleMapRowException;
use Modules\Seo\Models\KeywordMapRow;
use Modules\Seo\Models\KeywordMetric;
use Modules\Seo\Models\SiteProfile;
use Tests\Fixtures\SeoStoredData;

function mapCsv(): string
{
    return <<<'CSV'
    Acme Plumbing keyword map,,,,,,,,,,,,,,,
    ,,,,,,,,,,,,,,,
    #,Page,URL,Primary Keyword,Volume,KD %,CPC,Secondary Keywords,Intent,Proposed Title,Len,Proposed Meta,Len,Schema,Ship Status,Notes,Owner
    1,Home,https://www.acme.com/,Plumber  Austin,"1,300",34,$12.50,emergency plumber (390/mo); 24 hour plumber,Commercial,Plumber in Austin TX | Acme Plumbing,99,"Licensed Austin plumbers, on call day and night.",99,"LocalBusiness, FAQPage",Live 9/24 (title + meta),Top priority,Sam
    2,Services,/services/,drain cleaning,880,n/a,,,transactional,Drain Cleaning,,,,,"Drafted (Page 3, September)",,
    3,Water Heaters,/water-heaters/ (NEW),water heater repair,1.9k,41,9,,,,,,,Service,Planned (new page),,
    4,About,/about/,,,,,,,,,,,,Waiting on client,,
    ,,,,,,,,,,,,,,,
    5,Blog,/blog/,plumber austin,,,,,,,,,,,Not started (quick win),=HYPERLINK("http://evil"),
    CSV;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    $this->profile = SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true]);
    $this->service = app(KeywordMapService::class);
});

it('reads a hand-kept sheet: title lines, other header names, numbers, secondaries and statuses', function () {
    $parsed = KeywordMapCsv::parse(mapCsv(), 2026);
    $rows = $parsed['rows'];

    expect($rows)->toHaveCount(5)
        ->and($parsed['unknown_columns'])->toBe(['Owner'])
        ->and($rows[0])->toMatchArray([
            'sort' => 1, 'page_name' => 'Home', 'url' => 'https://www.acme.com/', 'primary_keyword' => 'plumber austin',
            'volume' => 1300, 'difficulty' => 34, 'cpc' => 12.5, 'metrics_provider' => 'imported', 'intent' => 'commercial',
            'schema_types' => ['LocalBusiness', 'FAQPage'], 'ship_status' => 'live', 'shipped_on' => '2026-09-24', 'ship_note' => 'title + meta',
        ])
        ->and($rows[0]['secondary_keywords'])->toBe([['keyword' => 'emergency plumber', 'volume' => 390], ['keyword' => '24 hour plumber', 'volume' => null]])
        // "n/a" is unknown, not zero.
        ->and($rows[1])->toMatchArray(['volume' => 880, 'difficulty' => null, 'cpc' => null, 'ship_status' => 'drafted', 'shipped_on' => null, 'ship_note' => 'Page 3, September'])
        ->and($rows[2])->toMatchArray(['url' => '/water-heaters/', 'is_new_page' => true, 'volume' => 1900, 'ship_status' => 'planned_new'])
        // A status we don't know is kept word for word and flagged.
        ->and($rows[3])->toMatchArray(['ship_status' => 'not_started', 'ship_note' => 'Waiting on client', 'needs_review' => true, 'metrics_provider' => null])
        ->and($rows[4])->toMatchArray(['ship_status' => 'not_started', 'ship_note' => 'quick win', 'needs_review' => false]);

    expect(fn () => KeywordMapCsv::parse("a,b\n1,2"))->toThrow(InvalidArgumentException::class);
});

it('imports as the current map and warns about what a person should check', function () {
    $result = $this->service->importCsv($this->site, mapCsv(), 'November 2026');

    expect($result)->toMatchArray(['rows' => 5, 'needs_review' => 1, 'unknown_columns' => ['Owner']])
        ->and($result['map']->isCurrent())->toBeTrue()
        ->and($result['map']->source)->toBe('csv_import');

    $codes = collect($this->service->warnings($result['map']->fresh()));

    expect($codes->pluck('code')->unique()->sort()->values()->all())->toBe([
        'duplicate_primary_keyword', 'imported_metrics', 'needs_review', 'no_primary_keyword', 'proposed_meta_length', 'proposed_title_length',
    ])
        ->and($codes->firstWhere('code', 'duplicate_primary_keyword')['message'])->toContain('plumber austin')->toContain('Home, Blog')
        ->and($codes->firstWhere('code', 'proposed_title_length')['message'])->toContain('characters; aim for 50 to 60');
});

it('starts a new dated version as a copy and leaves the old one read-only', function () {
    $first = $this->service->importCsv($this->site, mapCsv())['map'];
    $second = $this->service->newVersion($this->site, 'December 2026', null);

    expect($first->fresh()->status)->toBe('archived')
        ->and($second->isCurrent())->toBeTrue()
        ->and($second->copied_from_id)->toBe($first->id)
        ->and($second->rows()->count())->toBe(5)
        ->and($second->rows()->first()->proposed_title)->toBe('Plumber in Austin TX | Acme Plumbing')
        ->and($this->service->current($this->site->id)->id)->toBe($second->id);

    $oldRow = $first->rows()->first();
    expect(fn () => $this->service->updateRow($oldRow, ['notes' => 'x'], null))->toThrow(InvalidArgumentException::class, 'Only the current map');
});

it('edits a row, refuses a stale edit and em dashes, and clears figures when the keyword changes', function () {
    $map = $this->service->importCsv($this->site, mapCsv())['map'];
    $row = $map->rows()->first();
    $seen = $row->updated_at->toIso8601String();

    $this->travel(5)->minutes();
    $updated = $this->service->updateRow($row, ['proposed_title' => 'Austin Plumber Open 24 Hours | Acme Plumbing Company', 'volume' => 999999, 'ship_status' => 'drafted'], $seen);

    expect($updated->proposed_title)->toStartWith('Austin Plumber')
        // Figures can't be typed in through an edit.
        ->and($updated->volume)->toBe(1300)
        ->and($updated->ship_status)->toBe('drafted');

    // Someone still holding the old version is refused.
    expect(fn () => $this->service->updateRow($row->fresh(), ['notes' => 'late'], $seen))->toThrow(StaleMapRowException::class);
    expect(fn () => $this->service->updateRow($row->fresh(), ['proposed_meta' => 'Fast — and friendly'], null))->toThrow(InvalidArgumentException::class, 'em dash');
    expect(fn () => $this->service->updateRow($row->fresh(), ['ship_status' => 'finished'], null))->toThrow(InvalidArgumentException::class);

    $changed = $this->service->updateRow($row->fresh(), ['primary_keyword' => 'Austin Plumbers', 'ship_status' => 'live'], null);
    expect($changed->primary_keyword)->toBe('austin plumbers')
        ->and($changed->volume)->toBeNull()
        ->and($changed->metrics_provider)->toBeNull();

    $added = $this->service->addRow($map, ['page_name' => 'Contact', 'url' => '/contact/', 'primary_keyword' => 'plumber near me']);
    expect($added->sort)->toBe(6);
});

it('fills figures from keyword data already bought, without spending, and lists what is missing', function () {
    Http::fake();
    $map = $this->service->importCsv($this->site, mapCsv())['map'];
    KeywordMetric::create(['keyword' => 'drain cleaning', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'volume' => 720, 'cpc' => 8.4, 'difficulty' => 22, 'intent' => 'commercial', 'fetched_at' => now()]);

    $result = $this->service->fillMetrics($map);
    $row = KeywordMapRow::query()->where('primary_keyword', 'drain cleaning')->sole();

    expect($result['filled'])->toBe(1)
        ->and($result['missing'])->toBe(['plumber austin', 'water heater repair'])
        ->and($row->volume)->toBe(720)
        ->and($row->difficulty)->toBe(22)
        ->and($row->metrics_provider)->toBe('dataforseo')
        // The row's own intent wins over the bought one.
        ->and($row->intent)->toBe('transactional');
    Http::assertNothingSent();
});

it('exports in the fixed column order, with bands only on DataForSEO difficulty and no live formulas', function () {
    $map = $this->service->importCsv($this->site, mapCsv())['map'];
    KeywordMapRow::query()->where('primary_keyword', 'drain cleaning')->update(['difficulty' => 22, 'metrics_provider' => 'dataforseo']);
    KeywordMapRow::query()->where('page_name', 'About')->update(['excluded' => true]);

    $csv = KeywordMapCsv::export($map->fresh());
    $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), explode("\n", trim($csv)));

    expect($lines[0])->toBe(KeywordMapCsv::COLUMNS)
        ->and($lines)->toHaveCount(5) // header + 4, About hidden
        ->and($lines[1][5])->toBe('34')
        ->and($lines[1][7])->toBe('emergency plumber (390/mo); 24 hour plumber')
        ->and($lines[1][10])->toBe('36')
        ->and($lines[1][14])->toBe('Live 2026-09-24 (title + meta)')
        ->and($lines[2][5])->toBe('22 (Easy)')
        ->and($lines[3][2])->toBe('/water-heaters/ (NEW)')
        ->and($lines[4][15])->toStartWith("'=HYPERLINK");

    // What we write, we can read back.
    expect(KeywordMapCsv::parse($csv)['rows'])->toHaveCount(4);
});

it('recommends the page not started with the most non-branded impressions', function () {
    $map = $this->service->importCsv($this->site, mapCsv())['map'];
    $this->service->addRow($map, ['page_name' => 'Leaks', 'url' => '/leaks', 'primary_keyword' => 'leak repair']);

    expect($this->service->recommendedNext($map->fresh()))->toBeNull();

    foreach (['2026-08', '2026-09', '2026-10'] as $month) {
        SeoStoredData::row($this->site->id, $month, 'page_query', 'https://www.acme.com/blog/', 'how to fix a tap', 5, 400);
        SeoStoredData::row($this->site->id, $month, 'page_query', 'https://www.acme.com/leaks/', 'leak repair', 9, 900);
        SeoStoredData::row($this->site->id, $month, 'page_query', 'https://www.acme.com/leaks/', 'acme leaks', 50, 5000, branded: true);
        // Live already, so never recommended however busy it is.
        SeoStoredData::row($this->site->id, $month, 'page_query', 'https://www.acme.com/', 'plumber austin', 90, 9000);
    }

    $next = $this->service->recommendedNext($map->fresh());

    expect($next)->toMatchArray(['page' => 'Leaks', 'impressions' => 2700, 'months' => ['2026-08', '2026-09', '2026-10']])
        ->and($next['reason'])->toContain('2,700');
});

it('treats full and relative URLs as the same page', function () {
    expect(KeywordMapService::pathKey('https://www.acme.com/Services/'))->toBe('/services')
        ->and(KeywordMapService::pathKey('/services'))->toBe('/services')
        ->and(KeywordMapService::pathKey('https://www.acme.com/'))->toBe('/');
});
