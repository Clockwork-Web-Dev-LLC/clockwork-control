<?php

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Services\ClientReportCompiler;

/*
| clockwork:recompile-held-client-reports: the Recompile action for every
| unsent scheduled report at once. It must never send, never move a
| schedule on, and leave sent or hand-made reports alone. Example data only.
*/

function bulkRecompileReport(array $meta = [], array $attributes = []): ClientReport
{
    $site = Site::factory()->carePlan()->create([
        'domain' => 'bulk-'.Str::lower(Str::random(6)).'.example.com', 'hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM,
        'backup_relay_enabled' => false, 'uptime_last_checked_at' => now(), 'created_at' => now()->subYear(),
    ]);
    $schedule = ClientReportSchedule::create([
        'site_id' => $site->id, 'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
        'delivery_mode' => ClientReportSchedule::MODE_AUTO, 'recipients' => ['client@example.com'],
        'is_enabled' => true, 'next_run_at' => Carbon::parse('2027-01-01 00:00:00'), 'last_sent_at' => now()->subMonths(2),
    ]);
    $start = Carbon::parse('2026-10-01');
    $end = Carbon::parse('2026-10-31');
    $data = app(ClientReportCompiler::class)->compile($site, $start, $end);
    $data['meta'] = array_replace($data['meta'], $meta);

    return ClientReport::create(array_merge([
        'site_id' => $site->id, 'schedule_id' => $schedule->id, 'title' => 'October report',
        'period_start' => $start, 'period_end' => $end, 'sections_data' => $data,
        'status' => ClientReport::STATUS_GENERATED,
    ], $attributes));
}

it('recompiles every unsent scheduled report, never sends, and leaves the schedules where they were', function () {
    Mail::fake();
    $outdated = bulkRecompileReport(['schema_version' => 3, 'review_reasons' => ['Backups could not be checked.']]);
    $current = bulkRecompileReport();
    $sent = bulkRecompileReport(['schema_version' => 3], ['status' => ClientReport::STATUS_SENT, 'sent_to' => ['client@example.com']]);
    $nextRun = $outdated->schedule->next_run_at->toIso8601String();

    $this->artisan('clockwork:recompile-held-client-reports')
        ->expectsOutputToContain('Recompiled 2 report(s)')
        ->assertSuccessful();

    Mail::assertNothingSent();
    expect($outdated->fresh()->status)->toBe(ClientReport::STATUS_SUPERSEDED)
        ->and($current->fresh()->status)->toBe(ClientReport::STATUS_SUPERSEDED)
        ->and($sent->fresh()->status)->toBe(ClientReport::STATUS_SENT)
        ->and($outdated->schedule->fresh()->next_run_at->toIso8601String())->toBe($nextRun);

    $fresh = ClientReport::query()->where('schedule_id', $outdated->schedule_id)->where('status', ClientReport::STATUS_GENERATED)->sole();
    expect($fresh->sections_data['meta']['schema_version'])->toBe(ClientReportCompiler::SCHEMA_VERSION)
        ->and($fresh->isStale())->toBeFalse();
});

it('limits itself to outdated reports with --outdated, and changes nothing on --dry-run', function () {
    Mail::fake();
    $outdated = bulkRecompileReport(['schema_version' => 3]);
    $current = bulkRecompileReport();

    $this->artisan('clockwork:recompile-held-client-reports', ['--outdated' => true, '--dry-run' => true])
        ->expectsOutputToContain("#{$outdated->id}")
        ->expectsOutputToContain('1 report(s) would be recompiled')
        ->assertSuccessful();
    expect($outdated->fresh()->status)->toBe(ClientReport::STATUS_GENERATED);

    $this->artisan('clockwork:recompile-held-client-reports', ['--outdated' => true])->assertSuccessful();

    Mail::assertNothingSent();
    expect($outdated->fresh()->status)->toBe(ClientReport::STATUS_SUPERSEDED)
        ->and($current->fresh()->status)->toBe(ClientReport::STATUS_GENERATED);
});
