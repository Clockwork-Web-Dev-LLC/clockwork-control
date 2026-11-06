<?php

use App\Models\PluginVulnerability;
use App\Models\Site;
use App\Services\Security\WpVulnerabilityClient;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| clockwork:refresh-plugin-vulnerabilities
|--------------------------------------------------------------------------
|
| WpVulnerabilityClient has no DI seam the command exposes (it's a plain
| new-able service resolved straight from the container with no injected
| collaborator to mock), and it does real provider-sync work — discovering
| plugin slugs from every site's companion_snapshot, hitting
| wpvulnerability.net once per slug, and truncate+reinserting the local
| mirror. So this is a real behavioral test: Http::fake() the wpvulnerability.net
| endpoint and assert the actual plugin_vulnerabilities table effect.
*/

function pluginVulnResponse(array $vulns = []): array
{
    return [
        'error' => 0,
        'data' => [
            'vulnerability' => $vulns,
        ],
    ];
}

function pluginVulnEntry(array $overrides = []): array
{
    return array_merge([
        'uuid' => 'vuln-uuid-1',
        'name' => 'Reflected XSS via unsanitized input',
        'operator' => [
            'min_version' => null,
            'min_operator' => null,
            'max_version' => '2.0.0',
            'max_operator' => 'lt',
            'unfixed' => '0',
        ],
        'source' => [
            ['id' => 'CVE-2026-12345', 'link' => 'https://example.test/cve', 'date' => '2026-01-15'],
        ],
    ], $overrides);
}

describe('clockwork:refresh-plugin-vulnerabilities — no fleet plugin data', function () {
    it('succeeds with a zeroed summary when no site has a companion_snapshot', function () {
        Site::factory()->create(['companion_snapshot' => null]);

        Http::fake();

        $this->artisan('clockwork:refresh-plugin-vulnerabilities')
            ->expectsOutputToContain('unique slugs=0  fetched_ok=0  fetch_failed=0  rows_inserted=0')
            ->assertSuccessful();

        Http::assertNothingSent();
        expect(DB::table('plugin_vulnerabilities')->count())->toBe(0);
    });
});

describe('clockwork:refresh-plugin-vulnerabilities — real sync against a faked feed', function () {
    it('discovers slugs from companion_snapshot, fetches each, and replaces the local mirror', function () {
        Site::factory()->create([
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        ['slug' => 'contact-form-7/wp-contact-form-7.php'],
                        ['slug' => 'wordfence/wordfence.php'],
                    ],
                ],
            ],
        ]);

        // Pre-existing row must be gone after refresh — the table is a
        // truncate-and-replace mirror, not an incremental upsert.
        DB::table('plugin_vulnerabilities')->insert([
            'wordfence_id' => 'stale-uuid',
            'slug' => 'some-other-plugin',
            'software_type' => 'plugin',
            'title' => 'Stale entry',
            'from_inclusive' => true,
            'to_inclusive' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'wpvulnerability.net/plugin/contact-form-7*' => Http::response(
                pluginVulnResponse([pluginVulnEntry(['uuid' => 'cf7-vuln'])])
            ),
            'wpvulnerability.net/plugin/wordfence*' => Http::response(
                pluginVulnResponse([]) // no known vulns for this slug
            ),
        ]);

        $this->artisan('clockwork:refresh-plugin-vulnerabilities')
            ->expectsOutputToContain('unique slugs=2  fetched_ok=2  fetch_failed=0  rows_inserted=1')
            ->assertSuccessful();

        expect(DB::table('plugin_vulnerabilities')->count())->toBe(1);

        $row = DB::table('plugin_vulnerabilities')->first();
        expect($row->wordfence_id)->toBe('cf7-vuln')
            ->and($row->slug)->toBe('contact-form-7')
            ->and($row->cve)->toBe('CVE-2026-12345')
            ->and($row->to_version)->toBe('2.0.0')
            ->and($row->patched_in)->toBe('2.0.0');

        Http::assertSentCount(2);
    });

    it('treats a 404 as "no vulns" (fetched_ok) and a transport failure as fetch_failed, without aborting the run', function () {
        Site::factory()->create([
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        ['slug' => 'unknown-plugin/unknown.php'],
                        ['slug' => 'broken-feed-plugin/broken.php'],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'wpvulnerability.net/plugin/unknown-plugin*' => Http::response('', 404),
            'wpvulnerability.net/plugin/broken-feed-plugin*' => Http::response('', 500),
        ]);

        $this->artisan('clockwork:refresh-plugin-vulnerabilities')
            ->expectsOutputToContain('unique slugs=2  fetched_ok=1  fetch_failed=1  rows_inserted=0')
            ->assertSuccessful();

        expect(DB::table('plugin_vulnerabilities')->count())->toBe(0);
    });

    it('keeps the previous entries of a plugin whose lookup failed, instead of silently dropping them', function () {
        Site::factory()->create([
            'companion_snapshot' => ['plugins' => ['plugins' => [
                ['slug' => 'contact-form-7/wp-contact-form-7.php'],
                ['slug' => 'flaky-plugin/flaky.php'],
            ]]],
        ]);
        foreach (['contact-form-7' => 'old-cf7-vuln', 'flaky-plugin' => 'flaky-vuln', 'removed-plugin' => 'removed-vuln'] as $slug => $id) {
            DB::table('plugin_vulnerabilities')->insert([
                'wordfence_id' => $id, 'slug' => $slug, 'software_type' => 'plugin', 'title' => 'Earlier entry',
                'from_inclusive' => true, 'to_inclusive' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Http::fake([
            'wpvulnerability.net/plugin/contact-form-7*' => Http::response(pluginVulnResponse([pluginVulnEntry(['uuid' => 'cf7-vuln'])])),
            'wpvulnerability.net/plugin/flaky-plugin*' => Http::response('', 500),
        ]);

        $this->artisan('clockwork:refresh-plugin-vulnerabilities')
            ->expectsOutputToContain('fetch_failed=1')
            ->expectsOutputToContain('Kept the previous entries for 1 plugin(s) that could not be fetched: flaky-plugin')
            ->assertSuccessful();

        // Fetched slugs are replaced, the failed one is kept, plugins no longer installed go.
        expect(DB::table('plugin_vulnerabilities')->orderBy('wordfence_id')->pluck('wordfence_id')->all())
            ->toBe(['cf7-vuln', 'flaky-vuln']);
    });

    it('records when each plugin was last looked up, keeping the earlier time of one that failed this run', function () {
        Site::factory()->create([
            'companion_snapshot' => ['plugins' => ['plugins' => [
                ['slug' => 'contact-form-7/wp-contact-form-7.php'],
                ['slug' => 'flaky-plugin/flaky.php'],
                ['slug' => 'never-plugin/never.php'],
            ]]],
        ]);
        app(Settings::class)->put(WpVulnerabilityClient::LOOKUPS_SETTING, ['flaky-plugin' => '2026-11-20T03:15:00+00:00']);
        Http::fake([
            'wpvulnerability.net/plugin/contact-form-7*' => Http::response(pluginVulnResponse()),
            'wpvulnerability.net/plugin/flaky-plugin*' => Http::response('', 500),
            'wpvulnerability.net/plugin/never-plugin*' => Http::response('', 500),
        ]);

        $this->artisan('clockwork:refresh-plugin-vulnerabilities')->assertSuccessful();

        $lookups = WpVulnerabilityClient::lastLookups();
        expect(array_keys($lookups))->toBe(['contact-form-7', 'flaky-plugin'])
            ->and($lookups['flaky-plugin'])->toBe('2026-11-20T03:15:00+00:00');
    });

    it('changes nothing and fails when every lookup fails', function () {
        Site::factory()->create([
            'companion_snapshot' => ['plugins' => ['plugins' => [['slug' => 'contact-form-7/wp-contact-form-7.php']]]],
        ]);
        DB::table('plugin_vulnerabilities')->insert([
            'wordfence_id' => 'cf7-vuln', 'slug' => 'contact-form-7', 'software_type' => 'plugin', 'title' => 'Earlier entry',
            'from_inclusive' => true, 'to_inclusive' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake(['wpvulnerability.net/*' => Http::response('', 503)]);

        $this->artisan('clockwork:refresh-plugin-vulnerabilities')
            ->expectsOutputToContain('Refresh failed: every plugin lookup failed')
            ->assertFailed();

        expect(DB::table('plugin_vulnerabilities')->pluck('wordfence_id')->all())->toBe(['cf7-vuln']);
    });

    it('stores each feed operator as the right range, and an exact-version advisory as that version only', function () {
        Site::factory()->create([
            'companion_snapshot' => ['plugins' => ['plugins' => [['slug' => 'example-seo/example-seo.php']]]],
        ]);

        $op = fn (?string $min, ?string $minOp, ?string $max, ?string $maxOp, string $unfixed = '0') => [
            'min_version' => $min, 'min_operator' => $minOp, 'max_version' => $max, 'max_operator' => $maxOp, 'unfixed' => $unfixed,
        ];

        Http::fake([
            'wpvulnerability.net/plugin/example-seo*' => Http::response(pluginVulnResponse([
                pluginVulnEntry(['uuid' => 'exact', 'name' => 'Example SEO [example-seo] == 3.0.123', 'operator' => $op(null, null, '3.0.123', 'eq')]),
                pluginVulnEntry(['uuid' => 'exact-in-min', 'operator' => $op('2.5.0', 'eq', null, null)]),
                pluginVulnEntry(['uuid' => 'below', 'operator' => $op(null, null, '3.0.97', 'lt')]),
                pluginVulnEntry(['uuid' => 'up-to', 'operator' => $op(null, null, '2.0.6', 'lte', '1')]),
                pluginVulnEntry(['uuid' => 'window', 'operator' => $op('1.0.0', 'gte', '1.4.0', 'lt')]),
                pluginVulnEntry(['uuid' => 'after', 'operator' => $op('4.0.0', 'gt', null, null, '1')]),
                pluginVulnEntry(['uuid' => 'numeric', 'operator' => ['max_version' => 2, 'max_operator' => 'lt', 'unfixed' => 0]]),
            ])),
        ]);

        $this->artisan('clockwork:refresh-plugin-vulnerabilities')->assertSuccessful();

        $rows = DB::table('plugin_vulnerabilities')->get()->keyBy('wordfence_id');
        $range = fn (string $id) => [
            $rows[$id]->from_version, (bool) $rows[$id]->from_inclusive,
            $rows[$id]->to_version, (bool) $rows[$id]->to_inclusive,
            $rows[$id]->patched_in,
        ];

        expect($range('exact'))->toBe(['3.0.123', true, '3.0.123', true, null])
            ->and($range('exact-in-min'))->toBe(['2.5.0', true, '2.5.0', true, null])
            ->and($range('below'))->toBe([null, true, '3.0.97', false, '3.0.97'])
            ->and($range('up-to'))->toBe([null, true, '2.0.6', true, null])
            ->and($range('window'))->toBe(['1.0.0', true, '1.4.0', false, '1.4.0'])
            ->and($range('after'))->toBe(['4.0.0', false, null, true, null])
            ->and($range('numeric'))->toBe([null, true, '2', false, '2']);

        // The matcher sees the exact advisory only on that exact release.
        $exact = PluginVulnerability::query()->where('wordfence_id', 'exact')->sole();
        expect($exact->rangeIncludes('3.0.121'))->toBeFalse()
            ->and($exact->rangeIncludes('3.0.123'))->toBeTrue()
            ->and($exact->rangeIncludes('3.0.124'))->toBeFalse();
    });

    it('de-duplicates unique plugin slugs across multiple sites into a single fetch each', function () {
        $snapshot = [
            'plugins' => [
                'plugins' => [
                    ['slug' => 'akismet/akismet.php'],
                ],
            ],
        ];
        Site::factory()->create(['companion_snapshot' => $snapshot]);
        Site::factory()->create(['companion_snapshot' => $snapshot]);

        Http::fake([
            'wpvulnerability.net/plugin/akismet*' => Http::response(pluginVulnResponse([])),
        ]);

        $this->artisan('clockwork:refresh-plugin-vulnerabilities')
            ->expectsOutputToContain('unique slugs=1  fetched_ok=1  fetch_failed=0  rows_inserted=0')
            ->assertSuccessful();

        Http::assertSentCount(1);
    });
});
