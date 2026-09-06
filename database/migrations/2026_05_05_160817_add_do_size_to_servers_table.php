<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Size info pulled from DigitalOcean's droplet payload during the
            // SpinupWP import cross-reference. Lets the server detail page
            // surface "2 vCPU · 4 GB RAM · 80 GB" without an extra API call.
            // Refreshed daily on import; stable until a manual resize.
            $table->string('do_size_slug', 64)->nullable()->after('do_droplet_id');
            $table->unsignedSmallInteger('do_vcpus')->nullable()->after('do_size_slug');
            $table->unsignedInteger('do_memory_mb')->nullable()->after('do_vcpus');
            $table->unsignedInteger('do_disk_gb')->nullable()->after('do_memory_mb');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['do_size_slug', 'do_vcpus', 'do_memory_mb', 'do_disk_gb']);
        });
    }
};
