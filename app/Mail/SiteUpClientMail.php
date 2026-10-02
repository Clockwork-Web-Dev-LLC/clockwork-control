<?php

namespace App\Mail;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SiteUpClientMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Site $site,
        public readonly ?int $downtimeSec,
        public readonly Carbon $recoveredAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Resolved: {$this->site->domain} is back online",
        );
    }

    public function content(): Content
    {
        $downtimeMin = $this->downtimeSec !== null ? (int) round($this->downtimeSec / 60) : 0;

        return new Content(
            view: 'emails.site-up-client',
            with: [
                'site' => $this->site,
                'domain' => $this->site->domain,
                'downtimeSec' => $this->downtimeSec,
                'downtimeMin' => $downtimeMin,
                'recoveredAt' => $this->recoveredAt,
            ],
        );
    }
}
