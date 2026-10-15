<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backups as reported by each site's real source (host API, Spaces
        // listing, our own S3 archives), recorded daily. Rows are kept after
        // the source prunes them so a report for an older period can still be
        // rebuilt from evidence.
        Schema::create('site_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('source', 32);
            $table->string('kind', 16);
            $table->string('external_ref', 191);
            $table->timestamp('backup_at');
            $table->unsignedBigInteger('bytes')->nullable();
            $table->boolean('is_manual')->default(false);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->unique(['site_id', 'source', 'kind', 'external_ref']);
            $table->index(['site_id', 'backup_at']);
        });

        // Latest attempt to read each site's backups, so "checked, found none"
        // can be told apart from "couldn't check".
        Schema::create('site_backup_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('source', 32);
            $table->timestamp('checked_at');
            $table->boolean('ok');
            $table->unsignedInteger('backups_seen')->default(0);
            $table->text('error')->nullable();

            $table->unique(['site_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_backup_checks');
        Schema::dropIfExists('site_backups');
    }
};
