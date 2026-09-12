<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_job_runs', function (Blueprint $table) {
            $table->id();
            // Normalized artisan signature (e.g. "clockwork:refresh-cisa-kev"),
            // shared by the listener that writes rows and the controller that
            // enumerates routes/console.php — see App\Support\ScheduledCommandName.
            $table->string('command');
            $table->string('status'); // success | failed | skipped
            $table->unsignedInteger('duration_ms')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('output')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['command', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_job_runs');
    }
};
