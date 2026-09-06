<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_migrations', function (Blueprint $table) {
            $table->id();

            // Source — the site we're moving and the server it currently lives on.
            // Server denormalized so the migration record stays meaningful even if
            // the source site row gets renamed/relinked later.
            $table->foreignId('source_site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('source_server_id')->constrained('servers')->cascadeOnDelete();

            // Destination — server is required up front; the destination Site row
            // doesn't exist until phase 1 (preparing) creates it via SpinupWP API.
            $table->foreignId('destination_server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('destination_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->unsignedBigInteger('destination_spinupwp_site_id')->nullable();

            // Operator-set options.
            // migrate_dns: when true, the cutover phase flips A/AAAA + www records on CF.
            // auto_cutover: when true, no manual approve gate between syncing and cutting_over.
            $table->boolean('migrate_dns')->default(false);
            $table->boolean('auto_cutover')->default(false);

            // State machine value. See SiteMigration::STATUS_* constants.
            $table->string('status', 32)->default('pending')->index();
            $table->string('failure_phase', 32)->nullable();
            $table->text('failure_reason')->nullable();

            // Per-phase timestamps for audit + UI ("started X ago, ready since Y").
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('syncing_at')->nullable();
            $table->timestamp('ready_to_test_at')->nullable();
            $table->timestamp('cutting_over_at')->nullable();
            $table->timestamp('cutover_complete_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('cleanup_due_at')->nullable()->index();
            $table->timestamp('cleaned_up_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Free-form chronological event log: each entry { ts, phase, level, message }.
            // Lets the operator see step-by-step progress without polling logs.
            $table->json('log')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_migrations');
    }
};
