<?php

use App\Models\Site;
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
