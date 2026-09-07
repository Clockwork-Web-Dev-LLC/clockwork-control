<?php

namespace Modules\CodeSnippets\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\CodeSnippets\Models\CodeSnippet;

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
