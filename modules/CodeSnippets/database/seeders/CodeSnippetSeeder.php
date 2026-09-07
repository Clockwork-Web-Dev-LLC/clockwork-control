<?php

namespace Modules\CodeSnippets\Database\Seeders;

use App\Models\CodeSnippet;
use Illuminate\Database\Seeder;

class CodeSnippetSeeder extends Seeder
{
    public function run(): void
    {
        foreach (CodeSnippet::defaultPresets() as $preset) {
            CodeSnippet::updateOrCreate(
                ['name' => $preset['name']],
                [
                    'description' => $preset['description'],
                    'code' => $preset['code'],
                    'is_preset' => true,
                ]
            );
        }
    }
}
