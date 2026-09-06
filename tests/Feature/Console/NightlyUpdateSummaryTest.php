<?php

use App\Mail\NightlyUpdateSummaryMail;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * created_at isn't in PluginUpdateJob::$fillable (Eloquent manages it
 * automatically), so passing it through factory ->create() is silently
 * discarded. Back-dating a row for these window-boundary tests requires a
 * direct query-builder update, which bypasses mass-assignment guarding.
 */
function nusBackdateJob(PluginUpdateJob $job, Carbon $createdAt): void
{
    PluginUpdateJob::query()->whereKey($job->id)->update(['created_at' => $createdAt]);
}

describe('NightlyUpdateSummary', function () {
    beforeEach(function () {
        Mail::fake();
    });

    it('sends one summary email with the correct success/failure/skipped/cancelled breakdown', function () {
        config(['clockwork.alerts.email' => 'ops@example.com']);

        $siteA = Site::factory()->create(['domain' => 'a.example.com']);
        $siteB = Site::factory()->create(['domain' => 'b.example.com']);

        nusBackdateJob(PluginUpdateJob::factory()->complete()->create([
            'site_id' => $siteA->id,
            'batch_id' => 'nightly-2026-09-03',
            'target_slug' => 'akismet',
        ]), now()->subHours(2));

        nusBackdateJob(PluginUpdateJob::factory()->failed('SSH connection timed out')->create([
            'site_id' => $siteB->id,
            'batch_id' => 'nightly-2026-09-03',
            'target_slug' => 'jetpack',
        ]), now()->subHours(2));

        nusBackdateJob(PluginUpdateJob::factory()->create([
            'site_id' => $siteA->id,
            'status' => PluginUpdateJob::STATUS_SKIPPED,
            'batch_id' => 'nightly-2026-09-03',
            'target_slug' => 'woocommerce',
        ]), now()->subHours(2));

        nusBackdateJob(PluginUpdateJob::factory()->create([
            'site_id' => $siteB->id,
            'status' => PluginUpdateJob::STATUS_CANCELLED,
            'batch_id' => 'nightly-2026-09-03',
            'target_slug' => 'yoast-seo',
        ]), now()->subHours(2));

        // Outside the --since window — must not be counted.
        nusBackdateJob(PluginUpdateJob::factory()->complete()->create([
            'site_id' => $siteA->id,
            'batch_id' => 'nightly-2026-09-02',
        ]), now()->subHours(20));

        // Not a nightly batch — must not be counted even though it's in the window.
        nusBackdateJob(PluginUpdateJob::factory()->complete()->create([
            'site_id' => $siteA->id,
            'batch_id' => 'manual-run-1',
        ]), now()->subHours(2));

        $this->artisan('clockwork:nightly-update-summary')->assertSuccessful();

        Mail::assertSent(NightlyUpdateSummaryMail::class, function (NightlyUpdateSummaryMail $mail) {
            return count($mail->succeeded) === 1
                && count($mail->failed) === 1
                && count($mail->skipped) === 1
                && count($mail->cancelled) === 1
                && $mail->sitesTouched === 2
                && $mail->succeeded[0]['plugin_slug'] === 'akismet'
                && $mail->failed[0]['plugin_slug'] === 'jetpack'
                && str_contains((string) $mail->failed[0]['error_excerpt'], 'SSH connection timed out');
        });

        Mail::assertSent(NightlyUpdateSummaryMail::class, fn (NightlyUpdateSummaryMail $mail) => $mail->hasTo('ops@example.com'));
    });

    it('sends nothing when there are no nightly jobs in the window', function () {
        config(['clockwork.alerts.email' => 'ops@example.com']);

        $this->artisan('clockwork:nightly-update-summary')
            ->expectsOutputToContain('No nightly jobs in the window')
            ->assertSuccessful();

        Mail::assertNothingSent();
    });

    it('skips sending when no alerts email is configured, even with jobs present', function () {
        config(['clockwork.alerts.email' => '']);

        $site = Site::factory()->create();
        nusBackdateJob(PluginUpdateJob::factory()->complete()->create([
            'site_id' => $site->id,
            'batch_id' => 'nightly-2026-09-03',
        ]), now()->subHours(2));

        $this->artisan('clockwork:nightly-update-summary')
            ->expectsOutputToContain('CLOCKWORK_ALERTS_EMAIL is not set')
            ->assertSuccessful();

        Mail::assertNothingSent();
    });

    it('honors --since to widen the window back far enough to include an older job', function () {
        config(['clockwork.alerts.email' => 'ops@example.com']);

        $site = Site::factory()->create();
        nusBackdateJob(PluginUpdateJob::factory()->complete()->create([
            'site_id' => $site->id,
            'batch_id' => 'nightly-old',
        ]), now()->subHours(20));

        // Default --since (5 hours ago) would miss this row entirely.
        $this->artisan('clockwork:nightly-update-summary')
            ->expectsOutputToContain('No nightly jobs in the window')
            ->assertSuccessful();
        Mail::assertNothingSent();

        $this->artisan('clockwork:nightly-update-summary', ['--since' => now()->subHours(24)->toIso8601String()])
            ->assertSuccessful();

        Mail::assertSent(NightlyUpdateSummaryMail::class, fn (NightlyUpdateSummaryMail $mail) => count($mail->succeeded) === 1);
    });
});
