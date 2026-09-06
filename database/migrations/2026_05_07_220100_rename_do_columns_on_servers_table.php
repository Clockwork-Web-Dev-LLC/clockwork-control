<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The original schema named these fields after DigitalOcean's
        // terminology because DO was the only provider. Now that Hetzner is
        // also wired in, the columns are populated by whichever cloud the
        // server lives on, so the names go provider-agnostic.
        // Laravel 11 + MySQL 8 supports native renameColumn without doctrine/dbal.
        Schema::table('servers', function (Blueprint $table) {
            $table->renameColumn('do_droplet_id', 'provider_id');
            $table->renameColumn('do_size_slug', 'size_slug');
            $table->renameColumn('do_vcpus', 'vcpus');
            $table->renameColumn('do_memory_mb', 'memory_mb');
            $table->renameColumn('do_disk_gb', 'disk_gb');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->renameColumn('provider_id', 'do_droplet_id');
            $table->renameColumn('size_slug', 'do_size_slug');
            $table->renameColumn('vcpus', 'do_vcpus');
            $table->renameColumn('memory_mb', 'do_memory_mb');
            $table->renameColumn('disk_gb', 'do_disk_gb');
        });
    }
};
