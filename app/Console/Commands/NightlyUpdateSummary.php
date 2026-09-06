<?php

namespace App\Console\Commands;

use App\Mail\NightlyUpdateSummaryMail;
use App\Models\PluginUpdateJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

#[Signature('clockwork:nightly-update-summary {--since= : ISO datetime; default: 5 hours ago}')]
#[Description('End-of-window summary of nightly plugin auto-updates. Sends one email to clockwork.alerts.email with success/failure breakdown. Fired at 06:15 ET; can also be re-run manually with --since.')]
class NightlyUpdateSummary extends Command
{
    public function handle(): int
    {
        $sinceArg = $this->option('since');
        $since = $sinceArg
            ? Carbon::parse($sinceArg)
            : Carbon::now()->subHours(5);

        // Find every job from a `nightly-*` batch in the window. created_at
        // gates the row to the current night even if the batch_id from a
        // prior night happens to fall within the LIKE pattern.
        $rows = PluginUpdateJob::query()
            ->with('site:id,domain')
            ->where('batch_id', 'like', 'nightly-%')
            ->where('created_at', '>=', $since)
            ->orderBy('site_id')
            ->orderBy('target_slug')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No nightly jobs in the window — no email sent.');

            return self::SUCCESS;
        }

        $recipient = (string) config('clockwork.alerts.email');
        if ($recipient === '') {
            $this->warn('CLOCKWORK_ALERTS_EMAIL is not set; skipping email send. Found '.$rows->count().' rows.');

            return self::SUCCESS;
        }

        $succeeded = [];
        $failed = [];
        $skipped = [];
        $cancelled = [];

        foreach ($rows as $row) {
            $entry = [
                'domain' => $row->site?->domain ?? "site#{$row->site_id}",
                'plugin_slug' => (string) ($row->target_slug ?? ''),
                'before_version' => $row->before_version,
                'target_version' => $row->target_version,
                'after_version' => $row->after_version,
                'error_excerpt' => $row->error
                    ? mb_strimwidth((string) $row->error, 0, 280, '…')
                    : null,
            ];

            match ($row->status) {
                PluginUpdateJob::STATUS_COMPLETE => $succeeded[] = $entry,
                PluginUpdateJob::STATUS_FAILED => $failed[] = $entry,
                PluginUpdateJob::STATUS_SKIPPED => $skipped[] = $entry,
                PluginUpdateJob::STATUS_CANCELLED => $cancelled[] = $entry,
                default => null, // pending / running rows mid-window — ignore
            };
        }

        $sitesTouched = $rows->pluck('site_id')->unique()->count();

        $mailable = new NightlyUpdateSummaryMail(
            runDate: $since->copy()->startOfDay(),
            succeeded: $succeeded,
            failed: $failed,
            skipped: $skipped,
            cancelled: $cancelled,
            sitesTouched: $sitesTouched,
        );

        Mail::to($recipient)->send($mailable);

        $this->info(sprintf(
            'Sent summary to %s: %d succeeded, %d failed, %d skipped, %d cancelled (across %d sites).',
            $recipient,
            count($succeeded),
            count($failed),
            count($skipped),
            count($cancelled),
            $sitesTouched,
        ));

        return self::SUCCESS;
    }
}
