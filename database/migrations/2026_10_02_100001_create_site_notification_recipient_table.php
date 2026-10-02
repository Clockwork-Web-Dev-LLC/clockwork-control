<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_notification_recipient', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('notification_recipient_id')->constrained('notification_recipients')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['site_id', 'notification_recipient_id'], 'site_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_notification_recipient');
    }
};
