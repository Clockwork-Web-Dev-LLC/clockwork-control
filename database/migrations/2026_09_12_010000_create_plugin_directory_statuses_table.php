<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_directory_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('status')->index(); // open, closed, not_found, error
            $table->text('reason')->nullable();
            $table->string('closed_date')->nullable();
            $table->timestamp('checked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_directory_statuses');
    }
};
