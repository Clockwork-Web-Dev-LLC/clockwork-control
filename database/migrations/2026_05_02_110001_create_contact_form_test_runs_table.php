<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-run history for the contact-form test add-on. The current state
     * lives on the sites row (contact_form_test_state etc.); this table
     * is the audit log + the source for the per-site "Form tests" tab
     * history view + intermittent-failure debugging.
     *
     * mode: 'lab' suppresses email + form-storage side effects (default);
     *       'live' lets the form plugin act normally.
     * accepted: did the form plugin take the submission?
     * mail_invoked: did the form plugin call wp_mail()?
     * mail_outcome: 'sent' | 'failed' | 'suppressed' (lab mode short-circuit) | null
     * status: derived top-level 'success' | 'failed' for the row.
     * post_smtp_log_id: cross-reference into Post SMTP's log table when present.
     */
    public function up(): void
    {
        Schema::create('contact_form_test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->timestamp('ran_at')->index();
            $table->string('mode', 8)->default('lab');
            $table->boolean('accepted')->default(false);
            $table->boolean('mail_invoked')->default(false);
            $table->string('mail_outcome', 16)->nullable();
            $table->string('status', 16);
            $table->unsignedSmallInteger('http_code')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('post_smtp_log_id')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_form_test_runs');
    }
};
