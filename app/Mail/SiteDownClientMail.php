<?php

namespace App\Mail;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SiteDownClientMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Site $site,
        public readonly Carbon $detectedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Site outage alert: {$this->site->domain}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.site-down-client',
            with: [
                'site' => $this->site,
                'domain' => $this->site->domain,
                'detectedAt' => $this->detectedAt,
            ],
        );
    }
}
