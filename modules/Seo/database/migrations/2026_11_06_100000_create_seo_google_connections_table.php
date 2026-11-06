<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row: the agency's Google connection (systems@), separate from
        // the Google login in modules/AuthGoogle, which throws its token away.
        Schema::create('seo_google_connections', function (Blueprint $table) {
            $table->id();
            $table->string('account_email')->nullable();
            $table->json('scopes')->nullable();
            $table->text('refresh_token');
            $table->string('status', 16)->default('active'); // active, revoked, error
            $table->timestamp('last_refreshed_at')->nullable();
            $table->string('last_error', 480)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_google_connections');
    }
};
