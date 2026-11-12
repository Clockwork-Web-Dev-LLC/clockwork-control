<?php

use App\Models\ActionLog;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Seo\Maps\KeywordMapService;
use Modules\Seo\Mcp\Tools\SeoKeywordMapGet;
use Modules\Seo\Mcp\Tools\SeoKeywordMapUpdate;
use Modules\Seo\Models\KeywordMap;
use Modules\Seo\Models\KeywordMapRow;
use Modules\Seo\Models\KeywordMetric;
use Modules\Seo\Models\SiteProfile;

const MAP_CSV = "Page,URL,Primary Keyword,Volume,KD %,Proposed Title,Proposed Meta,Status,Notes\n"
    ."Home,https://www.acme.com/,plumber austin,1300,34,Plumber in Austin TX | Acme Plumbing,Short meta.,Live 9/24,Top page\n"
    ."Services,/services/,drain cleaning,,,,,Not started,\n"
    ."Blog,/blog/,plumber austin,,,,,Someday,\n";

function mapTool(User $user, string $tool, array $arguments): array
{
    $response = ClockworkServer::actingAs($user)->tool($tool, $arguments)->assertOk();

    return (fn () => $this->response->toArray()['result']['structuredContent'])->call($response);
}

beforeEach(function () {
    mockIssueCounterZero();
    Http::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true]);
});

afterEach(fn () => Http::assertNothingSent());

describe('keyword map page', function () {
    it('shows an empty state, then an imported map with its checks', function () {
        $this->actingAs($this->user)->get(route('seo.sites.keyword-map', $this->site))->assertOk()->assertSee('No keyword map yet');

        $this->actingAs($this->user)->post(route('seo.sites.keyword-map.import', $this->site), [
            'file' => UploadedFile::fake()->createWithContent('map.csv', MAP_CSV), 'name' => 'November 2026',
        ])->assertRedirect(route('seo.sites.keyword-map', $this->site))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'Imported 3 pages') && str_contains($m, '1 have a status'));

        $this->actingAs($this->user)->get(route('seo.sites.keyword-map', $this->site))
            ->assertOk()
            ->assertSee('November 2026')
            ->assertSee('Plumber in Austin TX | Acme Plumbing')
            ->assertSee('imported')
            ->assertSee('To check')
            ->assertSee('is the primary keyword of 2 pages')
            ->assertSee('Someday');
    });

    it('rejects a file that is not a keyword map and keeps the current map', function () {
        app(KeywordMapService::class)->importCsv($this->site, MAP_CSV);

        $this->actingAs($this->user)->post(route('seo.sites.keyword-map.import', $this->site), ['csv' => "a,b\n1,2"])
            ->assertSessionHasErrors('file');
        $this->actingAs($this->user)->post(route('seo.sites.keyword-map.import', $this->site), [])->assertSessionHasErrors('file');

        expect(KeywordMap::count())->toBe(1);
    });

    it('starts a new version, shows older ones read-only, and downloads CSV', function () {
        $first = app(KeywordMapService::class)->importCsv($this->site, MAP_CSV, 'October')['map'];

        $this->actingAs($this->user)->post(route('seo.sites.keyword-map.version', $this->site), ['name' => 'November'])->assertRedirect();
        expect(KeywordMap::query()->where('status', 'current')->sole()->name)->toBe('November');

        $this->actingAs($this->user)->get(route('seo.sites.keyword-map', [$this->site, 'version' => $first->id]))
            ->assertOk()->assertSee('Older version, read-only');

        $download = $this->actingAs($this->user)->get(route('seo.sites.keyword-map.download', [$this->site, $first]))->assertOk();
        expect($download->headers->get('Content-Type'))->toContain('text/csv')
            ->and($download->headers->get('Content-Disposition'))->toContain('keyword-map-acme.com-October.csv')
            ->and($download->getContent())->toContain('Primary Keyword')->toContain('plumber austin');

        $other = Site::factory()->create(['domain' => 'other.com']);
        $this->actingAs($this->user)->get(route('seo.sites.keyword-map.download', [$other, $first]))->assertNotFound();
    });

    it('fills figures from stored keyword data', function () {
        app(KeywordMapService::class)->importCsv($this->site, MAP_CSV);
        KeywordMetric::create(['keyword' => 'drain cleaning', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'volume' => 720, 'difficulty' => 22, 'fetched_at' => now()]);

        $this->actingAs($this->user)->post(route('seo.sites.keyword-map.fill', $this->site))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'Filled figures for 1 pages') && str_contains($m, '1 keywords have no stored figures'));

        expect(KeywordMapRow::query()->where('primary_keyword', 'drain cleaning')->sole()->volume)->toBe(720);
    });
});

describe('seo_keyword_map_get', function () {
    it('says when there is no map, then returns rows, checks and versions', function () {
        $empty = mapTool($this->user, SeoKeywordMapGet::class, ['site' => 'acme.com']);
        expect($empty['map'])->toBeNull()->and($empty['summary'])->toContain('no keyword map yet');

        app(KeywordMapService::class)->importCsv($this->site, MAP_CSV, 'November');
        $content = mapTool($this->user, SeoKeywordMapGet::class, ['site' => 'acme.com']);

        expect($content['map'])->toMatchArray(['name' => 'November', 'status' => 'current', 'editable' => true])
            ->and($content['rows'])->toHaveCount(3)
            ->and($content['rows'][0])->toMatchArray([
                'page' => 'Home', 'primary_keyword' => 'plumber austin', 'volume' => 1300, 'difficulty' => 34,
                'difficulty_band' => null, 'metrics_source' => 'imported', 'title_length' => 36, 'status' => 'live', 'shipped_on' => '2026-09-24',
            ])
            ->and($content['rows'][0]['updated_at'])->not->toBeNull()
            ->and(array_column($content['warnings'], 'code'))->toContain('duplicate_primary_keyword', 'needs_review', 'proposed_title_length')
            ->and(array_column($content['meta']['caveats'], 'code'))->toContain('imported_metrics')
            ->and($content['versions'])->toHaveCount(1);
    });
});

describe('seo_keyword_map_update', function () {
    it('starts a version, edits and adds rows in one go, and logs before and after', function () {
        app(KeywordMapService::class)->importCsv($this->site, MAP_CSV, 'October');

        $started = mapTool($this->user, SeoKeywordMapUpdate::class, ['site' => 'acme.com', 'action' => 'new_version', 'name' => 'November']);
        expect($started['map'])->toMatchArray(['name' => 'November', 'rows' => 3]);

        $rows = mapTool($this->user, SeoKeywordMapGet::class, ['site' => 'acme.com'])['rows'];
        $services = collect($rows)->firstWhere('page', 'Services');

        $saved = mapTool($this->user, SeoKeywordMapUpdate::class, ['site' => 'acme.com', 'action' => 'edit_rows', 'rows' => [
            ['row_id' => $services['row_id'], 'expected_updated_at' => $services['updated_at'], 'proposed_title' => 'Drain Cleaning in Austin, Same Day | Acme Plumbing', 'status' => 'drafted', 'secondary_keywords' => ['Clogged Drain', 'sewer line cleaning']],
            ['page' => 'Leaks', 'url' => '/leaks/', 'primary_keyword' => 'Leak Repair'],
        ]]);

        expect($saved['rows'])->toHaveCount(2)
            ->and($saved['rows'][0]['title_length'])->toBe(50)
            ->and($saved['summary'])->toContain('Saved 2 rows');

        $row = KeywordMapRow::query()->find($services['row_id']);
        expect($row->ship_status)->toBe('drafted')
            ->and($row->secondary_keywords)->toBe([['keyword' => 'clogged drain', 'volume' => null], ['keyword' => 'sewer line cleaning', 'volume' => null]])
            ->and(KeywordMapRow::query()->where('primary_keyword', 'leak repair')->exists())->toBeTrue();

        $log = ActionLog::query()->where('action_type', 'seo_keyword_map_updated')->latest('id')->first();
        expect($log->site_id)->toBeNull()
            ->and($log->actor)->toBe("user:{$this->user->id}")
            ->and($log->details['changes']['rows']['before'][$services['row_id']]['ship_status'])->toBe('not_started')
            ->and($log->details['changes']['rows']['after'][$services['row_id']]['ship_status'])->toBe('drafted');
    });

    it('saves nothing when one row is stale, has an em dash, or sets a figure', function () {
        app(KeywordMapService::class)->importCsv($this->site, MAP_CSV);
        $rows = mapTool($this->user, SeoKeywordMapGet::class, ['site' => 'acme.com'])['rows'];
        $home = $rows[0];
        $services = $rows[1];

        $attempts = [
            // Second row is based on an old version of the row.
            [['row_id' => $services['row_id'], 'expected_updated_at' => $services['updated_at'], 'notes' => 'first'], ['row_id' => $home['row_id'], 'expected_updated_at' => '2020-01-01T00:00:00+00:00', 'notes' => 'stale']],
            [['row_id' => $services['row_id'], 'expected_updated_at' => $services['updated_at'], 'notes' => 'first'], ['row_id' => $home['row_id'], 'expected_updated_at' => $home['updated_at'], 'proposed_meta' => 'Fast — friendly']],
            [['row_id' => $home['row_id'], 'expected_updated_at' => $home['updated_at'], 'volume' => 99999]],
            [['row_id' => $home['row_id'], 'notes' => 'no version given']],
            [['row_id' => 999999, 'expected_updated_at' => $home['updated_at'], 'notes' => 'x']],
            [['notes' => 'new row with no page']],
        ];

        foreach ($attempts as $attempt) {
            ClockworkServer::actingAs($this->user)->tool(SeoKeywordMapUpdate::class, ['site' => 'acme.com', 'action' => 'edit_rows', 'rows' => $attempt])->assertHasErrors();
        }

        expect(KeywordMapRow::query()->whereNotNull('notes')->pluck('notes')->all())->toBe(['Top page'])
            ->and(KeywordMapRow::query()->find($home['row_id'])->volume)->toBe(1300)
            ->and(KeywordMapRow::count())->toBe(3)
            ->and(ActionLog::query()->where('action_type', 'seo_keyword_map_updated')->count())->toBe(0);
    });

    it('fills from stored data and needs a map first', function () {
        ClockworkServer::actingAs($this->user)->tool(SeoKeywordMapUpdate::class, ['site' => 'acme.com', 'action' => 'fill_metrics'])->assertHasErrors();

        app(KeywordMapService::class)->importCsv($this->site, MAP_CSV);
        KeywordMetric::create(['keyword' => 'drain cleaning', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'volume' => 720, 'difficulty' => 22, 'fetched_at' => now()]);

        $filled = mapTool($this->user, SeoKeywordMapUpdate::class, ['site' => 'acme.com', 'action' => 'fill_metrics']);
        expect($filled['filled'])->toBe(1)->and($filled['missing_keywords'])->toBe(['plumber austin']);

        $row = collect(mapTool($this->user, SeoKeywordMapGet::class, ['site' => 'acme.com'])['rows'])->firstWhere('page', 'Services');
        expect($row)->toMatchArray(['volume' => 720, 'difficulty' => 22, 'difficulty_band' => 'Easy', 'metrics_source' => 'dataforseo']);
    });
});
