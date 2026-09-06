<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for every SMS we attempt. Lets the operator answer
     * "did the text actually go out at 3am Saturday?" without guessing.
     * Keeps recipient_id nullable so we can also log fallback events
     * (every-recipient-off, sent to email instead) — they don't have
     * a specific recipient.
     */
    public function up(): void
    {
        Schema::create('notification_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')
                ->nullable()
                ->constrained('notification_recipients')
                ->nullOnDelete();
            $table->foreignId('site_id')
                ->nullable()
                ->constrained('sites')
                ->nullOnDelete();
            // event = "site_down" | "site_up" | "fallback_email" |
            //         "all_off_warning" | "test"
            $table->string('event', 32);
            $table->string('phone', 32)->nullable();
            $table->boolean('ok');
            $table->text('error')->nullable();
            $table->text('body')->nullable();           // the SMS text actually sent
            $table->string('twilio_sid', 64)->nullable(); // Twilio's message ID for cross-ref
            $table->timestamp('sent_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
    }
};
