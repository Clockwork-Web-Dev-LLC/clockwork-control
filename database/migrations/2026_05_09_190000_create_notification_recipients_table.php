<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // E.164 phone (e.g. +15555550100). Unique so the same human can't
            // be entered twice with subtly different formatting and end up
            // double-paged at 3am.
            $table->string('phone', 32)->unique();
            // Email fallback used by the notifier when SMS fails or when
            // every recipient is currently inside an off-window.
            $table->string('email_fallback')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};
