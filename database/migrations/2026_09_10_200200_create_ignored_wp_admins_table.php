<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ignored_wp_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 190);
            $table->string('reason')->nullable();
            $table->foreignId('ignored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['site_id', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ignored_wp_admins');
    }
};
