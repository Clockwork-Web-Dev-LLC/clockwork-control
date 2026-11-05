<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * wpvulnerability.net marks an advisory that affects one exact release
     * with the 'eq' operator. WpVulnerabilityClient used to store that as an
     * inclusive upper bound with no lower bound, so an advisory for exactly
     * 3.0.123 matched every release up to 3.0.123, and sites on older,
     * unaffected releases were reported as vulnerable. The client now stores
     * from_version = to_version, both inclusive.
     *
     * The feed's operator block isn't kept, but the advisory title it sends
     * ends with the same expression ("Plugin [slug] == 3.0.123", sometimes
     * followed by "(unfixed)"), so rows written the old way are repaired from
     * their title. A row the title doesn't prove is left alone: the daily
     * clockwork:refresh-plugin-vulnerabilities run rewrites every row from
     * the feed anyway.
     */
    public function up(): void
    {
        $rows = DB::table('plugin_vulnerabilities')
            ->whereNull('from_version')
            ->whereNotNull('to_version')
            ->where('to_inclusive', true)
            ->where('title', 'like', '%==%')
            ->orderBy('id')
            ->get(['id', 'wordfence_id', 'slug', 'to_version', 'title']);

        foreach ($rows as $row) {
            if (! preg_match('/==\s*(\S+)\s*(?:\([^)]*\)\s*)?$/u', (string) $row->title, $m)) {
                continue;
            }
            $version = (string) $row->to_version;
            if ($m[1] !== $version) {
                continue;
            }

            // The unique key covers (wordfence_id, slug, from, to): when the
            // exact row already exists, the broken copy is simply removed.
            $exactExists = DB::table('plugin_vulnerabilities')
                ->where('wordfence_id', $row->wordfence_id)
                ->where('slug', $row->slug)
                ->where('from_version', $version)
                ->where('to_version', $version)
                ->exists();

            if ($exactExists) {
                DB::table('plugin_vulnerabilities')->where('id', $row->id)->delete();

                continue;
            }

            DB::table('plugin_vulnerabilities')->where('id', $row->id)->update([
                'from_version' => $version,
                'from_inclusive' => true,
                'to_inclusive' => true,
                'patched_in' => null,
            ]);
        }
    }

    public function down(): void
    {
        // One-time data repair; the rows were wrong before, so nothing to undo.
    }
};
