<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Until today every server was implicitly DigitalOcean. We're adding
            // Hetzner as a second source-of-truth (with SpinupWP as the upstream
            // orchestrator), so we need an explicit provider tag on each row.
            // Default catches any new row created before the importer learns to
            // set it; existing rows are backfilled in the same migration.
            $table->string('provider', 32)->default('digitalocean')->after('spinupwp_id');
        });

        DB::table('servers')->whereNull('provider')->update(['provider' => 'digitalocean']);
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
