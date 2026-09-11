<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $templates = DB::table('client_report_templates')->get();
        foreach ($templates as $template) {
            $sections = json_decode((string) $template->sections, true);
            if (! is_array($sections) || in_array('work_log', $sections, true)) {
                continue;
            }
            $sections[] = 'work_log';
            DB::table('client_report_templates')
                ->where('id', $template->id)
                ->update(['sections' => json_encode(array_values($sections))]);
        }
    }

    public function down(): void
    {
        $templates = DB::table('client_report_templates')->get();
        foreach ($templates as $template) {
            $sections = json_decode((string) $template->sections, true);
            if (! is_array($sections)) {
                continue;
            }
            DB::table('client_report_templates')
                ->where('id', $template->id)
                ->update(['sections' => json_encode(array_values(array_filter(
                    $sections,
                    fn ($key) => $key !== 'work_log',
                )))]);
        }
    }
};
