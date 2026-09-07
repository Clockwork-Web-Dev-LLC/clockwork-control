<?php

namespace Modules\ClientReports\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\ClientReports\Models\ClientReport;

class ClientReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ClientReport $report,
        public string $reportUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->report->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: <<<HTML
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; color: #1e293b;">
    <h2 style="font-size: 20px; font-weight: bold; color: #0f172a; margin-bottom: 12px;">{$this->report->title}</h2>
    <p style="font-size: 14px; line-height: 1.6; color: #334155;">
        Your monthly website care and maintenance report for <strong>{$this->report->site->domain}</strong> is now ready for review.
    </p>
    <div style="margin: 28px 0;">
        <a href="{$this->reportUrl}" style="background-color: #0f172a; color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 600; display: inline-block;">
            View Full Report &rarr;
        </a>
    </div>
    <p style="font-size: 12px; color: #64748b; line-height: 1.5;">
        This report covers core updates, security scans, uptime metrics, and performance benchmarks during the reporting period.
    </p>
</div>
HTML
        );
    }
}
