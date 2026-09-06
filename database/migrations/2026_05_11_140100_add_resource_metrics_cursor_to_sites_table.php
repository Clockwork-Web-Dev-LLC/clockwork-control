<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Tracks the most-recent bucket_at successfully ingested from this
            // site's Companion. Next pull asks for rows >= this cursor. Null
            // until the first successful ingest, in which case the ingestor
            // falls back to "last 24h" to seed.
            $table->timestamp('resource_metrics_cursor_at')->nullable()->after('sucuri_unavailable_reason');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('resource_metrics_cursor_at');
        });
    }
};
