<?php

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\BrandRegex;
use Modules\Seo\Services\SiteProfileService;

it('escapes each term and makes the regex case-insensitive', function () {
    expect(BrandRegex::compile('Acme, A+ Plumbing, acme'))->toBe('(?i)(Acme|A\+ Plumbing)');
});

it('returns null for no terms', function () {
    expect(BrandRegex::compile(' , '))->toBeNull()
        ->and(BrandRegex::compile(null))->toBeNull();
});

it('rejects terms that would pass 4,096 characters of regex', function () {
    $terms = implode(', ', array_map(fn ($i) => 'brandterm'.$i, range(1, 400)));

    expect(fn () => BrandRegex::compile($terms))->toThrow(InvalidArgumentException::class);
});

it('the preview excludes the queries the regex would exclude', function () {
    $queries = ['acme roofing', 'roof repair', 'A+ PLUMBING near me', 'a plumbing'];
    $regex = BrandRegex::compile('acme, a+ plumbing');

    $byRegex = array_values(array_filter($queries, fn ($q) => preg_match('/'.substr($regex, 4).'/i', $q) === 1));

    expect(BrandRegex::excluded($queries, 'acme, a+ plumbing'))->toBe(['acme roofing', 'A+ PLUMBING near me'])
        ->and($byRegex)->toBe(BrandRegex::excluded($queries, 'acme, a+ plumbing'));
});

it('the LIKE recompute of is_branded marks the same rows as the regex', function () {
    $site = Site::factory()->create();
    $profile = SiteProfile::query()->create(['site_id' => $site->id, 'brand_terms' => '100%_pure, A+ Plumbing', 'is_enabled' => true]);
    $queries = ['100%_pure honey', '100 pure honey', 'a+ plumbing phoenix', 'aa plumbing', 'roof repair'];

    foreach ($queries as $i => $q) {
        foreach (['query', 'page_query'] as $dimension) {
            DB::table('seo_gsc_month_rows')->insert([
                'site_id' => $site->id, 'month' => '2026-10-01', 'dimension' => $dimension,
                'page' => $dimension === 'page_query' ? 'https://example.com/' : null,
                'query' => $q, 'row_hash' => sha1($dimension.$i), 'clicks' => 1, 'impressions' => 10,
            ]);
        }
    }
    DB::table('seo_gsc_month_rows')->insert([
        'site_id' => $site->id, 'month' => '2026-10-01', 'dimension' => 'page',
        'page' => 'https://example.com/', 'query' => null, 'row_hash' => sha1('page'), 'clicks' => 1, 'impressions' => 10,
    ]);

    app(SiteProfileService::class)->remarkBranded($profile);

    $marked = DB::table('seo_gsc_month_rows')->where('dimension', 'query')->where('is_branded', true)->orderBy('id')->pluck('query')->all();
    $regex = '/'.substr(BrandRegex::compile($profile->brand_terms), 4).'/i';

    expect($marked)->toBe(['100%_pure honey', 'a+ plumbing phoenix'])
        ->and($marked)->toBe(array_values(array_filter($queries, fn ($q) => preg_match($regex, $q) === 1)))
        ->and(DB::table('seo_gsc_month_rows')->where('dimension', 'page_query')->where('is_branded', true)->count())->toBe(2)
        ->and(DB::table('seo_gsc_month_rows')->where('dimension', 'page')->value('is_branded'))->toBeNull();
});
