<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_queue', function (Blueprint $table) {
            // Where the entry came from (llar, wordfence, llm, nginx, manual). NOT NULL with
            // default 'llm' so existing rows (none in prod yet) get a sane back-fill.
            $table->string('source', 32)->default('llm')->after('site_id')->index();

            // Free-text reason for non-LLM sources. The LLM verdict/reasoning columns stay
            // around but become nullable so LLAR/Wordfence entries don't have to fake them.
            $table->text('reason')->nullable()->after('source');

            $table->string('llm_verdict')->nullable()->change();
            $table->text('llm_reasoning')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('review_queue', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'reason']);
            $table->string('llm_verdict')->nullable(false)->change();
            $table->text('llm_reasoning')->nullable(false)->change();
        });
    }
};
