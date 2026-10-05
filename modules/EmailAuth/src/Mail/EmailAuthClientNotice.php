<?php

namespace Modules\EmailAuth\Mail;

use App\Services\Companion\CompanionBrandingManager;
use App\Support\Mail\BrandLogo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\EmailAuth\Models\EmailAuthCheck;
use Modules\EmailAuth\Support\FindingExplainer;

/**
 * Operator-written notice to a client about a domain's email authentication:
 * a custom note up top, then the selected findings in plain English with the
 * technical detail and current DNS records underneath.
 *
 * White-labeled with the Client Reports branding (White Labeling → Client
 * Reports), so it matches the monthly report email. No links out — the client
 * answers by replying, which goes to the support address.
 */
class EmailAuthClientNotice extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $findingCodes  codes of the findings to include, in display order
     */
    public function __construct(
        public EmailAuthCheck $check,
        public string $subjectLine,
        public string $note,
        public array $findingCodes,
        public ?string $greetingName = null,
    ) {}

    public function envelope(): Envelope
    {
        $branding = $this->branding();

        return new Envelope(
            subject: $this->subjectLine,
            replyTo: filter_var($branding['support_email'], FILTER_VALIDATE_EMAIL)
                ? [new Address($branding['support_email'], $branding['company_name'])]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'email-auth::emails.client-notice',
            text: 'email-auth::emails.client-notice-text',
            with: [
                'check' => $this->check,
                'note' => $this->note,
                'items' => $this->items(),
                'branding' => $this->branding(),
                'logoBytes' => BrandLogo::bytes($this->branding()['logo_url']),
                'greetingName' => $this->greetingName,
                'domain' => $this->check->domain,
            ],
        );
    }

    /**
     * Selected findings, explained, in the order the operator chose.
     *
     * @return list<array{code: string, check: string, severity: string, title: string, explanation: string, technical: string}>
     */
    public function items(): array
    {
        $byCode = collect((array) $this->check->findings)->keyBy('code');

        return collect($this->findingCodes)
            ->map(fn ($code) => $byCode->get($code))
            ->filter(fn ($f) => is_array($f) && ! FindingExplainer::isPass($f))
            ->map(fn ($f) => FindingExplainer::explain($f))
            ->values()
            ->all();
    }

    /**
     * @return array{company_name: string, logo_url: string, primary_color: string, accent_color: string, support_email: string}
     */
    public function branding(): array
    {
        $b = app(CompanionBrandingManager::class)->getReportsBranding();

        return [
            'company_name' => (string) ($b['company_name'] ?: 'Clockwork Web Dev'),
            'logo_url' => (string) $b['logo_url'],
            'primary_color' => (string) ($b['primary_color'] ?: CompanionBrandingManager::DEFAULT_REPORTS_PRIMARY_COLOR),
            'accent_color' => (string) ($b['accent_color'] ?: CompanionBrandingManager::DEFAULT_REPORTS_ACCENT_COLOR),
            'support_email' => (string) $b['support_email'],
        ];
    }
}
