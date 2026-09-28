<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('url');
            $table->string('path', 255)->index();
            $table->string('route_name', 255)->nullable()->index();
            $table->string('controller_action', 255)->nullable();
            $table->string('view_name', 255)->nullable();
            $table->text('selector')->nullable();
            $table->string('element_tag', 50)->nullable();
            $table->text('element_text')->nullable();
            $table->float('x_pos')->default(0);
            $table->float('y_pos')->default(0);
            $table->integer('viewport_width')->nullable();
            $table->integer('viewport_height')->nullable();
            $table->string('type', 50)->default('tweak')->index(); // bug, tweak, feature, copy
            $table->string('status', 50)->default('open')->index(); // open, in_progress, resolved, dismissed
            $table->string('title', 255);
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_items');
    }
};
