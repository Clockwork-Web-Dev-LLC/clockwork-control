<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_form_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')
                ->constrained('sites')
                ->cascadeOnDelete();

            // Stable 1..3 display slot. App-layer enforces max 3 active rows
            // per site; the unique index here keeps two rows from claiming
            // the same card position.
            $table->unsignedTinyInteger('slot');

            $table->string('form_id', 64);
            // Denormalised from sites.contact_form_plugin so a single SELECT
            // on this table is enough to drive the scheduler loop.
            $table->string('form_plugin', 32);
            $table->string('form_url', 255)->nullable();

            // 'daily' is operator-only (exposed via the ?admin=1 query string
            // on the edit screen). 'weekly' is the self-serve default.
            $table->enum('frequency', ['daily', 'weekly'])->default('weekly');
            $table->boolean('enabled')->default(true);

            // Mirrors today's per-site contact_form_test_state semantics —
            // just one row per form-tested entity now.
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('failure_streak')->default(0);
            $table->timestamp('last_test_at')->nullable();
            $table->timestamp('state_changed_at')->nullable();
            $table->text('last_test_error')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'slot']);
            $table->unique(['site_id', 'form_id']);
            // "What's due now?" — scheduler loop reads this.
            $table->index(['enabled', 'frequency', 'last_test_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_form_tests');
    }
};
