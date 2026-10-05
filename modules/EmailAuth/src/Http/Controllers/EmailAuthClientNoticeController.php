<?php

namespace Modules\EmailAuth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Domains\RootDomainResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Modules\ClientManagement\Models\Client;
use Modules\EmailAuth\Mail\EmailAuthClientNotice;
use Modules\EmailAuth\Models\EmailAuthCheck;
use Modules\EmailAuth\Models\EmailAuthDomain;
use Modules\EmailAuth\Support\FindingExplainer;
use Throwable;

/**
 * Compose + send a white-labeled email to a client about one domain's email
 * authentication: operator's note, then the selected findings (plain English
 * plus technical detail). Recipients default to the report contacts of every
 * client whose sites resolve to this apex domain.
 */
class EmailAuthClientNoticeController extends Controller
{
    public const DEFAULT_NOTE = "We've noticed some issues with your domain's email settings (DNS). They can cause your messages to land in spam, and make it easier for someone to send email that pretends to be from you.\n\nWould you like us to fix this for you?";

    public function create(EmailAuthDomain $domain): View|RedirectResponse
    {
        $check = $domain->latestCheck;
        if (! $check) {
            return redirect()->route('email-auth.index')->with('error', "{$domain->domain} hasn't been scanned yet — run a scan first.");
        }

        $findings = collect((array) $check->findings)
            ->reject(fn ($f) => FindingExplainer::isPass((array) $f))
            ->map(fn ($f) => FindingExplainer::explain((array) $f) + ['selected' => FindingExplainer::isDefaultSelected((array) $f)])
            ->values();

        [$recipients, $clients] = $this->clientRecipients($domain->domain);

        return view('email-auth::notify', [
            'domain' => $domain,
            'check' => $check,
            'findings' => $findings,
            'recipients' => $recipients,
            'clients' => $clients,
            'defaultSubject' => "A quick note about email on {$domain->domain}",
            'defaultNote' => self::DEFAULT_NOTE,
            'greetingName' => $clients->count() === 1 ? $clients->first()->name : null,
            'deliveryOff' => config('mail.default') === 'log',
        ]);
    }

    public function preview(Request $request, EmailAuthDomain $domain): Response
    {
        $check = $domain->latestCheck;
        abort_if($check === null, 404);

        $mail = $this->buildMail($request, $check, validate: false);
        // CID images only resolve inside a real email; point the preview at the logo URL.
        $html = (string) preg_replace('/src="cid:[^"]+"/', 'src="'.e($mail->branding()['logo_url']).'"', $mail->render());

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function store(Request $request, EmailAuthDomain $domain, ActionLogger $actionLogger): RedirectResponse
    {
        $check = $domain->latestCheck;
        abort_if($check === null, 404);

        $recipients = $this->parseEmails((string) $request->input('to'));
        $request->merge(['to_list' => $recipients]);
        $request->validate([
            'to_list' => ['required', 'array', 'min:1', 'max:20'],
            'to_list.*' => ['email'],
            'subject' => ['required', 'string', 'max:200'],
            'note' => ['required', 'string', 'max:5000'],
            'findings' => ['nullable', 'array'],
            'findings.*' => ['string'],
            'greeting_name' => ['nullable', 'string', 'max:120'],
        ], [
            'to_list.required' => 'Add at least one recipient email address.',
            'to_list.*.email' => 'One of the recipient addresses isn’t a valid email.',
        ]);

        $mail = $this->buildMail($request, $check, validate: true);
        $pending = Mail::to($recipients);
        if ($request->boolean('copy_me') && $request->user()?->email) {
            $pending->bcc($request->user()->email);
        }

        try {
            $pending->send($mail);
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'Sending failed: '.$e->getMessage());
        }

        $actionLogger->record(
            actionType: 'email_auth_client_notice_sent',
            summary: "Emailed client about email authentication for {$domain->domain}",
            target: $domain->domain,
            details: [
                'to' => $recipients,
                'subject' => $mail->subjectLine,
                'findings' => $mail->findingCodes,
                'mailer' => config('mail.default'),
            ],
        );

        $status = 'Email sent to '.implode(', ', $recipients).'.';
        if (config('mail.default') === 'log') {
            $status .= ' Note: email delivery is off (MAIL_MAILER=log), so it was written to the log instead of being delivered.';
        }

        return redirect()->route('email-auth.index', ['search' => $domain->domain])->with('status', $status);
    }

    private function buildMail(Request $request, EmailAuthCheck $check, bool $validate): EmailAuthClientNotice
    {
        $available = collect((array) $check->findings)->pluck('code')->all();
        $codes = array_values(array_intersect((array) $request->input('findings', []), $available));

        return new EmailAuthClientNotice(
            check: $check,
            subjectLine: (string) ($request->input('subject') ?: "A quick note about email on {$check->domain}"),
            note: (string) ($request->input('note') ?: ($validate ? '' : self::DEFAULT_NOTE)),
            findingCodes: $codes,
            greetingName: trim((string) $request->input('greeting_name')) ?: null,
        );
    }

    /**
     * Report contacts of every client owning a site on this apex domain.
     *
     * @return array{0: list<string>, 1: Collection<int, Client>}
     */
    private function clientRecipients(string $apex): array
    {
        if (! class_exists(Client::class)) {
            return [[], collect()];
        }

        $clients = Site::query()
            ->whereNotNull('client_id')
            ->with('client')
            ->get(['id', 'domain', 'client_id'])
            ->filter(fn (Site $s) => RootDomainResolver::resolve((string) $s->domain) === $apex)
            ->pluck('client')
            ->filter()
            ->unique('id')
            ->values();

        $emails = $clients->flatMap(fn ($c) => $c->allRecipients())->unique()->values()->all();

        return [$emails, $clients];
    }

    /** @return list<string> */
    private function parseEmails(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($e) => strtolower(trim($e)),
            preg_split('/[\s,;]+/', $raw) ?: []
        ))));
    }
}
