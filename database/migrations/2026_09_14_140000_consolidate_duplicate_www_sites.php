<?php

use App\Support\Sites\WwwDuplicateConsolidator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new WwwDuplicateConsolidator)->consolidate();
    }

    public function down(): void
    {
        // Irreversible data deduplication
    }
};
