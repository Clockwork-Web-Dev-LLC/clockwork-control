<?php

namespace Modules\ClientReports\Mail;

use App\Support\Mail\BrandLogo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Modules\ClientManagement\Models\Client;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Support\ReportPhrases;
use Throwable;

/**
 * The monthly client report, delivered as a complete self-contained email.
 *
 * Everything the client needs is in the message body — there is deliberately no
 * "view full report" link, because Control runs on a private host the client
 * can't reach. The header uses the report brand's primary colour (the Companion
 * purple by default) so a white logo stays visible, and the logo is embedded
 * inline (CID) when it can be fetched so it shows even where remote images are
 * blocked by default.
 */
class ClientReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ClientReport $report) {}

    public function envelope(): Envelope
    {
        $branding = $this->branding();
        $replyTo = filter_var($branding['support_email'], FILTER_VALIDATE_EMAIL)
            ? [new Address($branding['support_email'], $branding['company_name'])]
            : [];

        return new Envelope(
            from: $this->fromAddress(),
            subject: sprintf('Your %s website report — %s', $this->report->period_start->format('F Y'), $this->domain()),
            replyTo: $replyTo,
        );
    }

    /**
     * The configured sending address under the report brand's company name,
     * so the inbox shows the agency rather than whatever MAIL_FROM_NAME says
     * (by default the internal product name). Null leaves Laravel's global
     * From in place when no sending address is configured.
     */
    public function fromAddress(): ?Address
    {
        $address = (string) config('mail.from.address');

        return filter_var($address, FILTER_VALIDATE_EMAIL)
            ? new Address($address, $this->branding()['company_name'])
            : null;
    }

    public function content(): Content
    {
        return new Content(
            view: 'client-reports::emails.report',
            text: 'client-reports::emails.report-text',
            with: [
                'report' => $this->report,
                'data' => $this->report->sections_data ?? [],
                'branding' => $this->branding(),
                'logoBytes' => $this->logoBytes(),
                // "Hi Jane," or, when we can't be sure who reads it, "Hi there,".
                'greetingName' => $this->greetingName(),
                'domain' => $this->domain(),
                'updateRows' => $this->updateRows(),
            ],
        );
    }

    /**
     * The first name to greet the reader by, or null for "Hi there,". Only
     * when the report goes to one address, and that address is a known
     * client contact whose stored name is plainly a person's
     * (ReportPhrases::firstName): the site's client's name is often an
     * organisation, and a report sent to several people, or to someone
     * other than the site's contact, would greet the wrong person.
     */
    public function greetingName(): ?string
    {
        $recipients = $this->report->recipients();
        if ($recipients === []) {
            $recipients = array_values(array_filter(array_map(
                fn ($r) => trim((string) $r),
                (array) ($this->report->schedule?->recipients ?? []),
            )));
        }
        if (count($recipients) !== 1) {
            return null;
        }

        $address = mb_strtolower(trim($recipients[0]));

        try {
            $own = $this->report->site?->client;
            $contacts = $own instanceof Client && mb_strtolower(trim((string) $own->email)) === $address
                ? collect([$own])
                : Client::query()->whereRaw('LOWER(TRIM(email)) = ?', [$address])->get();
        } catch (Throwable) {
            // No client records to read (the module's table isn't there).
            return null;
        }

        // Two contacts sharing the address under different names: don't guess.
        $names = $contacts->map(fn (Client $c) => ReportPhrases::firstName($c->name))->unique()->values();

        return $names->count() === 1 ? $names->first() : null;
    }

    /**
     * Branding captured at compile time, with the Companion defaults as fallback.
     *
     * @return array{company_name: string, logo_url: string, primary_color: string, accent_color: string, support_email: string, footer_text: string}
     */
    public function branding(): array
    {
        $b = (array) ($this->report->sections_data['branding'] ?? []);

        return [
            'company_name' => (string) ($b['company_name'] ?? 'Clockwork Web Dev'),
            'logo_url' => (string) ($b['logo_url'] ?? ''),
            'primary_color' => ! empty($b['primary_color']) ? (string) $b['primary_color'] : '#2D2062',
            'accent_color' => ! empty($b['accent_color']) ? (string) $b['accent_color'] : '#7EFF83',
            'support_email' => (string) ($b['support_email'] ?? ''),
            'footer_text' => (string) ($b['footer_text'] ?? ''),
        ];
    }

    /**
     * Client-friendly update rows: "TranslatePress - Multilingual 3.3.6 → 3.3.7"
     * instead of the raw action-log summary with slugs and repair notes.
     *
     * @return list<array{type: string, label: string, date: string}>
     */
    public function updateRows(): array
    {
        $items = (array) ($this->report->sections_data['updates']['items'] ?? []);
        $rows = [];
        foreach (['core' => 'WordPress', 'themes' => 'Theme', 'plugins' => 'Plugin'] as $key => $type) {
            foreach ((array) ($items[$key] ?? []) as $item) {
                $rows[] = [
                    'type' => $type,
                    'label' => self::cleanUpdateSummary((string) ($item['summary'] ?? ''), $item['target'] ?? null),
                    'date' => (string) ($item['date'] ?? ''),
                ];
            }
        }

        usort($rows, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return $rows;
    }

    public static function cleanUpdateSummary(string $summary, ?string $target = null): string
    {
        // Names come from WordPress with HTML entities ("Example &amp; Co");
        // the views escape what they print, so decode them here.
        $summary = html_entity_decode($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = preg_replace('/^\s*(plugin|theme|core|wordpress(?: core)?)\s+updated\s*:\s*/i', '', $summary) ?? $summary;
        $clean = trim(preg_replace('/\s*\[[^\]]*\]\s*$/', '', $clean) ?? $clean);

        if ($clean !== '') {
            return $clean;
        }

        // No usable summary — humanise the slug ("the-events-calendar/x.php" → "The Events Calendar").
        $slug = Str::before((string) $target, '/');

        return $slug !== '' ? Str::headline($slug) : 'Software update';
    }

    public function domain(): string
    {
        $domain = $this->report->site->domain ?? ($this->report->sections_data['site']['domain'] ?? '');

        return (string) preg_replace('/^www\./i', '', (string) $domain);
    }

    /** Logo bytes for an inline (CID) image; null falls back to the remote URL / brand name. */
    protected function logoBytes(): ?string
    {
        return BrandLogo::bytes($this->branding()['logo_url']);
    }
}
