<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete(); // null = fleet-wide
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('kind', 32); // google_change, methodology, note
            $table->string('label');
            $table->json('suppresses')->nullable(); // position, impressions, nonbranded
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'starts_on']);
        });

        $now = now();
        $seed = [
            // Google stopped honouring &num=100, so bot impressions vanished and
            // average position "improved" overnight. Comparisons across it lie.
            ['2025-09-10', '2025-09-14', 'Google num=100 change: impressions fell and average position jumped', ['position', 'impressions']],
            ['2026-05-13', '2026-05-13', 'GA4 adds the AI Assistant default channel (not retroactive)', []],
            ['2026-05-18', '2026-05-18', 'Search Console Generative AI report data starts', []],
            // Flags, not suppresses: unclear whether the API web total includes
            // the multimodal split. Phase 1 acceptance checks one property.
            ['2026-09-24', '2026-09-24', 'Search Console splits out multimodal results', ['impressions_flag']],
        ];

        foreach ($seed as [$starts, $ends, $label, $suppresses]) {
            DB::table('seo_annotations')->insert([
                'site_id' => null,
                'starts_on' => $starts,
                'ends_on' => $ends,
                'kind' => 'google_change',
                'label' => $label,
                'suppresses' => json_encode($suppresses),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_annotations');
    }
};
