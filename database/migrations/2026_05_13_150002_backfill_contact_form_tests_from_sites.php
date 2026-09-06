<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Copy every site that's actively testing a form today into the new
     * per-form table at slot=1, frequency=daily (preserve existing cadence
     * — the daily→weekly default change only applies to *new* form-tests
     * created after this ships).
     *
     * Then patch every contact_form_test_runs row with a contact_form_test_id
     * pointing at the freshly-inserted row for the same site_id. Rows with
     * no match (orphans) stay NULL.
     */
    public function up(): void
    {
        $now = now();

        DB::table('sites')
            ->whereNotNull('contact_form_test_form_id')
            ->where('contact_form_test_enabled', true)
            ->orderBy('id')
            ->select([
                'id',
                'contact_form_plugin',
                'contact_form_test_form_id',
                'contact_form_test_state',
                'contact_form_test_failure_streak',
                'contact_form_last_test_at',
                'contact_form_test_state_changed_at',
                'contact_form_last_test_error',
            ])
            ->chunkById(200, function ($rows) use ($now) {
                $insertRows = $rows->map(fn ($r) => [
                    'site_id' => $r->id,
                    'slot' => 1,
                    'form_id' => $r->contact_form_test_form_id,
                    'form_plugin' => (string) ($r->contact_form_plugin ?? ''),
                    'form_url' => null,
                    'frequency' => 'daily',
                    'enabled' => true,
                    'state' => $r->contact_form_test_state ?? 'pending',
                    'failure_streak' => (int) ($r->contact_form_test_failure_streak ?? 0),
                    'last_test_at' => $r->contact_form_last_test_at,
                    'state_changed_at' => $r->contact_form_test_state_changed_at,
                    'last_test_error' => $r->contact_form_last_test_error,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if (! empty($insertRows)) {
                    DB::table('contact_form_tests')->insert($insertRows);
                }
            });

        // Patch the run history. For every site that now has exactly one
        // contact_form_tests row, link its runs to that row. If a future site
        // has multiple form-tests, this best-effort match won't apply — we
        // accept the data loss for the historical runs (they keep site_id;
        // the per-form FK just stays NULL).
        //
        // UPDATE...JOIN is MySQL-only syntax; on sqlite (tests, fresh DBs)
        // there's no historical data to backfill anyway.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('
            UPDATE contact_form_test_runs r
            JOIN (
                SELECT site_id, MIN(id) AS cft_id
                FROM contact_form_tests
                GROUP BY site_id
                HAVING COUNT(*) = 1
            ) m ON m.site_id = r.site_id
            SET r.contact_form_test_id = m.cft_id
            WHERE r.contact_form_test_id IS NULL
        ');
    }

    /**
     * Pure data migration — down() just empties the new table and clears
     * the FK on the runs table. The schema migrations above own the actual
     * column/table teardown.
     */
    public function down(): void
    {
        DB::table('contact_form_test_runs')->update(['contact_form_test_id' => null]);
        DB::table('contact_form_tests')->truncate();
    }
};
