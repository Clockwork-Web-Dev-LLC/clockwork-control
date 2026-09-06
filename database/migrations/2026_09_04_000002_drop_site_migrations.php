<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the site-migrations feature entirely — deemed out of scope.
 * `site_migrations` had zero rows in production; this was "phase 1
 * scaffolding" (per the original routes/web.php comment) that was fully
 * built (a 1600+ line runner) but never adopted. `archived_by_migration_id`
 * is dropped first since it FKs into `site_migrations`; `sites.archived_at`
 * itself stays — it's a general-purpose flag also set by the manual
 * archive action, the Issues page, and OrphanSiteFinder, unrelated to
 * migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by_migration_id');
        });

        Schema::dropIfExists('site_migrations');
    }

    public function down(): void
    {
        Schema::create('site_migrations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('source_server_id')->constrained('servers')->cascadeOnDelete();

            $table->foreignId('destination_server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('destination_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->unsignedBigInteger('destination_spinupwp_site_id')->nullable();

            $table->boolean('migrate_dns')->default(false);
            $table->boolean('auto_cutover')->default(false);

            $table->string('status', 32)->default('pending')->index();
            $table->string('failure_phase', 32)->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('syncing_at')->nullable();
            $table->timestamp('ready_to_test_at')->nullable();
            $table->timestamp('cutting_over_at')->nullable();
            $table->timestamp('cutover_complete_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('cleanup_due_at')->nullable()->index();
            $table->timestamp('cleaned_up_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->json('log')->nullable();

            $table->timestamps();

            $table->json('additional_domains')->nullable()->after('auto_cutover');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('archived_by_migration_id')
                ->nullable()
                ->after('archived_at')
                ->constrained('site_migrations')
                ->nullOnDelete();
        });
    }
};
