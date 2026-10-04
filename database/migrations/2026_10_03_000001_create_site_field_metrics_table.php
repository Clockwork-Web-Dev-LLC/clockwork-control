<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_field_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('form_factor', 16); // phone, desktop
            $table->string('scope', 16)->default('origin'); // origin, url
            $table->string('status', 16)->default('ok'); // ok, no_data, failed
            $table->unsignedInteger('lcp_p75_ms')->nullable();
            $table->unsignedInteger('inp_p75_ms')->nullable();
            $table->unsignedInteger('fcp_p75_ms')->nullable();
            $table->unsignedInteger('ttfb_p75_ms')->nullable();
            $table->unsignedInteger('cls_p75_x1000')->nullable();
            $table->json('good_pct')->nullable();
            $table->boolean('cwv_pass')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('collected_at')->useCurrent();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'form_factor', 'scope', 'period_end'], 'site_field_metrics_unique');
            $table->index(['site_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_field_metrics');
    }
};
