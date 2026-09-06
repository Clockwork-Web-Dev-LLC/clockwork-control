<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Captures the result of the SSH-based diagnostic that runs on an up→down
     * transition for 5xx failures. Lets the operator see "SpinupWP maintenance
     * mode is active" or "PHP-FPM pool socket missing" directly on the event
     * row and in the Mattermost alert, instead of just "HTTP 503".
     *
     * Stored as JSON because the shape varies — successful diagnosis includes
     * the boolean signals plus the maintenance.conf body excerpt; an SSH
     * failure stores just { "error": "..." }. Querying isn't a concern; this
     * is rendered alongside the event, never filtered on.
     */
    public function up(): void
    {
        Schema::table('site_uptime_events', function (Blueprint $table) {
            $table->json('diagnosis')->nullable()->after('response_time_ms');
        });
    }

    public function down(): void
    {
        Schema::table('site_uptime_events', function (Blueprint $table) {
            $table->dropColumn('diagnosis');
        });
    }
};
