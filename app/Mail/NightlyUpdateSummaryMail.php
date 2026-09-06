<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * End-of-window summary for the nightly auto-update loop. One email
 * per night, sent to config('clockwork.alerts.email'), summarising
 * what the queued PluginUpdateJob rows accomplished between 02:00 and
 * 06:00 ET.
 *
 * Companion `SiteVulnerabilityReportMail` is the visual sibling — same
 * envelope shape, same view chrome — so the two ops emails feel
 * coherent in the inbox.
 *
 * Buckets: each is a list of normalised rows for the view to render.
 * NightlyUpdateSummary command builds them from plugin_update_jobs
 * filtered by batch_id LIKE 'nightly-%' for the night in question.
 *
 * Row shape (per bucket):
 *   - domain          string
 *   - plugin_slug     string
 *   - before_version  ?string
 *   - target_version  ?string
 *   - after_version   ?string
 *   - error_excerpt   ?string  (failure bucket only)
 */
class NightlyUpdateSummaryMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array<string, mixed>>  $succeeded
     * @param  list<array<string, mixed>>  $failed
     * @param  list<array<string, mixed>>  $skipped
     * @param  list<array<string, mixed>>  $cancelled
     */
    public function __construct(
        public readonly Carbon $runDate,
        public readonly array $succeeded,
        public readonly array $failed,
        public readonly array $skipped,
        public readonly array $cancelled,
        public readonly int $sitesTouched,
    ) {}

    public function envelope(): Envelope
    {
        $sn = count($this->succeeded);
        $fn = count($this->failed);
        $date = $this->runDate->format('Y-m-d');

        return new Envelope(
            subject: "Nightly auto-updates: {$sn} succeeded, {$fn} failed ({$date})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.nightly-update-summary',
            with: [
                'runDate' => $this->runDate,
                'succeeded' => $this->succeeded,
                'failed' => $this->failed,
                'skipped' => $this->skipped,
                'cancelled' => $this->cancelled,
                'sitesTouched' => $this->sitesTouched,
            ],
        );
    }
}
