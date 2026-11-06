<?php

use Illuminate\Support\Facades\DB;

/*
| 2026_11_05_120000_repair_exact_version_plugin_vulnerabilities: exact-version
| advisories ("== X") used to be stored as "<= X" (no lower bound). The
| migration rebuilds them from the advisory title, which carries the feed's
| own operator expression, and leaves everything the title doesn't prove.
*/

function legacyVulnRow(string $id, ?string $from, ?string $to, bool $toInclusive, string $title, ?string $patchedIn = null): int
{
    return (int) DB::table('plugin_vulnerabilities')->insertGetId([
        'wordfence_id' => $id,
        'slug' => 'example-plugin',
        'software_type' => 'plugin',
        'from_version' => $from,
        'from_inclusive' => true,
        'to_version' => $to,
        'to_inclusive' => $toInclusive,
        'patched_in' => $patchedIn,
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function runExactVersionRepair(): void
{
    $migration = require database_path('migrations/2026_11_05_120000_repair_exact_version_plugin_vulnerabilities.php');
    $migration->up();
}

function vulnRange(int $id): array
{
    $row = DB::table('plugin_vulnerabilities')->where('id', $id)->first();

    return [$row->from_version, (bool) $row->from_inclusive, $row->to_version, (bool) $row->to_inclusive];
}

describe('exact-version vulnerability repair', function () {
    it('turns rows stored as "<= X" for a "== X" advisory into exact ranges', function () {
        $plain = legacyVulnRow('a', null, '3.0.123', true, 'Example Plugin [example-plugin] == 3.0.123');
        $unfixed = legacyVulnRow('b', null, '7.1.0', true, 'Example Plugin &#8211; Shop [example-plugin] == 7.1.0 (unfixed)');

        runExactVersionRepair();

        expect(vulnRange($plain))->toBe(['3.0.123', true, '3.0.123', true])
            ->and(vulnRange($unfixed))->toBe(['7.1.0', true, '7.1.0', true]);
    });

    it('leaves real ranges and rows the title does not prove alone', function () {
        $upTo = legacyVulnRow('c', null, '2.0.6', true, 'Example Plugin [example-plugin] <= 2.0.6');
        $below = legacyVulnRow('d', null, '3.0.97', false, 'Example Plugin [example-plugin] < 3.0.97', '3.0.97');
        $window = legacyVulnRow('e', '1.0.0', '1.4.0', true, 'Example Plugin [example-plugin] >= 1.0.0 - <= 1.4.0');
        $otherVersion = legacyVulnRow('f', null, '5.0.0', true, 'Example Plugin [example-plugin] == 4.9.0');

        runExactVersionRepair();

        expect(vulnRange($upTo))->toBe([null, true, '2.0.6', true])
            ->and(vulnRange($below))->toBe([null, true, '3.0.97', false])
            ->and(vulnRange($window))->toBe(['1.0.0', true, '1.4.0', true])
            ->and(vulnRange($otherVersion))->toBe([null, true, '5.0.0', true]);
    });

    it('drops the broken copy when the exact row already exists', function () {
        $exact = legacyVulnRow('g', '3.0.123', '3.0.123', true, 'Example Plugin [example-plugin] == 3.0.123');
        $broken = legacyVulnRow('g', null, '3.0.123', true, 'Example Plugin [example-plugin] == 3.0.123');

        runExactVersionRepair();

        expect(DB::table('plugin_vulnerabilities')->where('id', $broken)->exists())->toBeFalse()
            ->and(vulnRange($exact))->toBe(['3.0.123', true, '3.0.123', true]);
    });
});
