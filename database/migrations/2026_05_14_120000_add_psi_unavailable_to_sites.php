<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PageSpeed Insights circuit-breaker columns, mirror of the Sucuri pair.
     *
     * - `psi_unavailable_at` is flipped after 3 consecutive PSI failures
     *   (timeouts, malformed responses, anything that surfaces as
     *   status=failed on site_performance_scans).
     * - The scheduled run (`clockwork:run-performance-scans` with no
     *   `--site=`) filters these sites out so the activity log stops
     *   collecting "Performance scan failed" rows for an endpoint we
     *   already know is unreachable.
     * - A subsequent successful scan (operator runs `--site=X` to
     *   smoke-test, OR Google's PSI API starts responding again on the
     *   next scheduled cycle that happens to include this site via
     *   --include-unavailable) clears the flag automatically.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('psi_unavailable_at')->nullable()->after('sucuri_unavailable_reason');
            $table->string('psi_unavailable_reason', 200)->nullable()->after('psi_unavailable_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['psi_unavailable_at', 'psi_unavailable_reason']);
        });
    }
};
