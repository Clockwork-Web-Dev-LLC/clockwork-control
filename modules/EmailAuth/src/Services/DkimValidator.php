<?php

namespace Modules\EmailAuth\Services;

use Modules\EmailAuth\Contracts\DnsTxtResolver;

class DkimValidator
{
    public const DEFAULT_SELECTORS = [
        'google',
        'selector1',
        'selector2',
        'k1',
        's1',
        's2',
        'mx',
        'smtp',
        'pm',
        'mandrill',
        'default',
        'dkim',
    ];

    public function __construct(
        protected DnsTxtResolver $resolver
    ) {}

    /**
     * Probe known and custom DKIM selectors for a domain.
     *
     * @param  array<int, string>  $customSelectors
     * @return array{
     *     status: string,
     *     selectors_found: array<int, string>,
     *     findings: array<int, array{check: string, severity: string, code: string, message: string}>
     * }
     */
    public function validate(string $domain, array $customSelectors = []): array
    {
        $domain = strtolower(trim($domain));
        $selectors = array_values(array_unique(array_filter(array_merge(self::DEFAULT_SELECTORS, $customSelectors))));

        $found = [];

        foreach ($selectors as $sel) {
            $sel = strtolower(trim($sel));
            if ($sel === '') {
                continue;
            }

            $host = "{$sel}._domainkey.{$domain}";
            $txtRecords = $this->resolver->resolveTxt($host);

            foreach ($txtRecords as $rec) {
                $lower = strtolower($rec);
                if (str_contains($lower, 'v=dkim1') || str_contains($lower, 'p=')) {
                    $found[] = $sel;
                    break;
                }
            }
        }

        $findings = [];

        if (! empty($found)) {
            $findings[] = [
                'check' => 'dkim',
                'severity' => 'pass',
                'code' => 'dkim_found',
                'message' => 'DKIM key(s) detected on selector(s): '.implode(', ', $found).'.',
            ];

            return [
                'status' => 'pass',
                'selectors_found' => $found,
                'findings' => $findings,
            ];
        }

        // None found: warn/unknown per spec, NEVER fail
        $findings[] = [
            'check' => 'dkim',
            'severity' => 'warn',
            'code' => 'dkim_none_found',
            'message' => 'None of the standard known DKIM selectors were detected in DNS. If this domain sends email via a custom selector, add it under domain settings.',
        ];

        return [
            'status' => 'warn',
            'selectors_found' => [],
            'findings' => $findings,
        ];
    }
}
