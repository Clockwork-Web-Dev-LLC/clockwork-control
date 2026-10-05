<?php

namespace Modules\EmailAuth\Support;

/**
 * Client-facing wording for email-authentication findings.
 *
 * The scanner's `message` is written for operators ("No DMARC record published at
 * _dmarc.example.com"). Client notices lead with a plain-English title and
 * explanation and keep the scanner message as the technical detail underneath.
 */
class FindingExplainer
{
    /** @var array<string, array{title: string, explanation: string}> */
    private const COPY = [
        'spf_missing' => [
            'title' => 'No SPF record',
            'explanation' => 'Your domain doesn’t list which servers are allowed to send email for it. Without this, your messages are more likely to land in spam and others can more easily send fake email that looks like it came from you.',
        ],
        'spf_duplicate' => [
            'title' => 'More than one SPF record',
            'explanation' => 'Your domain publishes several SPF records. Email providers treat that as an error and ignore all of them, which hurts delivery.',
        ],
        'spf_missing_all' => [
            'title' => 'SPF record has no closing rule',
            'explanation' => 'Your SPF record doesn’t say what to do with mail from servers that aren’t on the list, so it gives receiving servers no clear instruction.',
        ],
        'spf_permissive_all' => [
            'title' => 'SPF record allows anyone to send',
            'explanation' => 'Your SPF record effectively approves every server on the internet to send as your domain, which defeats its purpose.',
        ],
        'spf_neutral_all' => [
            'title' => 'SPF record is set to “neutral”',
            'explanation' => 'Your SPF record tells receiving servers to take no position on unlisted senders, so it offers little protection against spoofing.',
        ],
        'spf_too_many_lookups' => [
            'title' => 'SPF record is too complex',
            'explanation' => 'Your SPF record requires more than the 10 DNS lookups email providers allow. Past that limit the record fails, and legitimate email can be rejected or marked as spam.',
        ],
        'spf_too_many_void_lookups' => [
            'title' => 'SPF record points at missing entries',
            'explanation' => 'Several parts of your SPF record point to DNS entries that don’t exist, which can cause the whole record to fail.',
        ],
        'spf_include_loop' => [
            'title' => 'SPF record refers back to itself',
            'explanation' => 'Your SPF record includes entries that loop back on each other, which makes it fail when providers check it.',
        ],
        'spf_ptr_deprecated' => [
            'title' => 'SPF record uses an outdated method',
            'explanation' => 'Your SPF record relies on a lookup type that’s deprecated and that many providers ignore.',
        ],
        'dmarc_missing' => [
            'title' => 'No DMARC record',
            'explanation' => 'DMARC tells email providers what to do with messages that fail authentication, and is now required by Google and Yahoo for reliable delivery. Without it, spoofed email using your domain is harder to stop.',
        ],
        'dmarc_duplicate' => [
            'title' => 'More than one DMARC record',
            'explanation' => 'Your domain publishes several DMARC records, so email providers ignore them all.',
        ],
        'dmarc_missing_policy' => [
            'title' => 'DMARC record has no policy',
            'explanation' => 'Your DMARC record doesn’t say what to do with failing messages, so it isn’t being applied.',
        ],
        'dmarc_policy_invalid' => [
            'title' => 'DMARC policy is invalid',
            'explanation' => 'Your DMARC record contains a policy value providers don’t recognise, so it isn’t being applied.',
        ],
        'dmarc_policy_none' => [
            'title' => 'DMARC is in monitor-only mode',
            'explanation' => 'Your DMARC record is set to “none”, so providers report on spoofed email but still deliver it. This is a good first step; the next is moving to “quarantine” or “reject” to actually block spoofing.',
        ],
        'dmarc_partial_pct' => [
            'title' => 'DMARC applies to only part of your mail',
            'explanation' => 'Your DMARC policy is set to cover only a percentage of messages, so some spoofed email can still get through.',
        ],
        'dmarc_missing_rua' => [
            'title' => 'No DMARC reporting address',
            'explanation' => 'Your DMARC record doesn’t ask providers to send reports, so there’s no visibility into who is sending email as your domain.',
        ],
        'dkim_none_found' => [
            'title' => 'No DKIM signature found',
            'explanation' => 'We couldn’t find a DKIM key for your domain. DKIM digitally signs your outgoing email so providers can confirm it wasn’t forged or altered.',
        ],
        'mx_parked_domain' => [
            'title' => 'Domain doesn’t receive email',
            'explanation' => 'This domain has no mail servers. If it never sends email either, it should publish records that tell providers to reject any mail claiming to come from it, so it can’t be used for spoofing.',
        ],
    ];

    /**
     * @param  array{code?: string, check?: string, severity?: string, message?: string}  $finding
     * @return array{code: string, check: string, severity: string, title: string, explanation: string, technical: string}
     */
    public static function explain(array $finding): array
    {
        $code = (string) ($finding['code'] ?? '');
        $copy = self::COPY[$code] ?? [
            'title' => ucfirst(str_replace('_', ' ', $code ?: 'Email authentication issue')),
            'explanation' => (string) ($finding['message'] ?? ''),
        ];

        return [
            'code' => $code,
            'check' => strtoupper((string) ($finding['check'] ?? '')),
            'severity' => (string) ($finding['severity'] ?? 'info'),
            'title' => $copy['title'],
            'explanation' => $copy['explanation'],
            'technical' => (string) ($finding['message'] ?? ''),
        ];
    }

    /** Findings worth raising with a client by default (problems, not passes). */
    public static function isDefaultSelected(array $finding): bool
    {
        return in_array($finding['severity'] ?? '', ['fail', 'warn'], true);
    }

    /** Never offered in a client notice — these are good news. */
    public static function isPass(array $finding): bool
    {
        return ($finding['severity'] ?? '') === 'pass';
    }
}
