<?php

namespace Modules\EmailAuth\Services;

use Modules\EmailAuth\Contracts\DnsTxtResolver;

class DmarcValidator
{
    public function __construct(
        protected DnsTxtResolver $resolver
    ) {}

    /**
     * Validate DMARC posture for an apex domain.
     *
     * @return array{
     *     status: string,
     *     policy: ?string,
     *     record: ?string,
     *     findings: array<int, array{check: string, severity: string, code: string, message: string}>
     * }
     */
    public function validate(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $dmarcHost = "_dmarc.{$domain}";

        $txtRecords = $this->resolver->resolveTxt($dmarcHost);

        $dmarcRecords = array_values(array_filter($txtRecords, function ($rec) {
            $trimmed = strtolower(trim($rec));

            return $trimmed === 'v=dmarc1' || str_starts_with($trimmed, 'v=dmarc1;') || str_starts_with($trimmed, 'v=dmarc1 ');
        }));

        $findings = [];

        if (empty($dmarcRecords)) {
            $findings[] = [
                'check' => 'dmarc',
                'severity' => 'fail',
                'code' => 'dmarc_missing',
                'message' => "No DMARC record published at {$dmarcHost}.",
            ];

            return [
                'status' => 'fail',
                'policy' => null,
                'record' => null,
                'findings' => $findings,
            ];
        }

        if (count($dmarcRecords) > 1) {
            $findings[] = [
                'check' => 'dmarc',
                'severity' => 'fail',
                'code' => 'dmarc_duplicate',
                'message' => 'Multiple DMARC records published. RFC 7489 specifies that multiple records will invalidate DMARC.',
            ];

            return [
                'status' => 'fail',
                'policy' => null,
                'record' => implode(' | ', $dmarcRecords),
                'findings' => $findings,
            ];
        }

        $record = $dmarcRecords[0];
        $tags = $this->parseTags($record);

        $policy = strtolower(trim($tags['p'] ?? ''));

        if ($policy === '') {
            $findings[] = [
                'check' => 'dmarc',
                'severity' => 'fail',
                'code' => 'dmarc_missing_policy',
                'message' => "DMARC record is missing the required 'p' (policy) tag.",
            ];
        } elseif ($policy === 'none') {
            $findings[] = [
                'check' => 'dmarc',
                'severity' => 'warn',
                'code' => 'dmarc_policy_none',
                'message' => "DMARC policy is set to 'none' (monitoring only). Unauthorized and spoofed emails will not be blocked.",
            ];
        } elseif (! in_array($policy, ['quarantine', 'reject'], true)) {
            $findings[] = [
                'check' => 'dmarc',
                'severity' => 'warn',
                'code' => 'dmarc_policy_invalid',
                'message' => "DMARC policy '{$policy}' is not recognized (expected 'reject', 'quarantine', or 'none').",
            ];
        }

        if (empty($tags['rua'])) {
            $findings[] = [
                'check' => 'dmarc',
                'severity' => 'warn',
                'code' => 'dmarc_missing_rua',
                'message' => 'No aggregate reporting URI (rua) configured. You will not receive deliverability feedback or spoofing telemetry.',
            ];
        }

        if (isset($tags['pct'])) {
            $pct = (int) $tags['pct'];
            if ($pct < 100) {
                $findings[] = [
                    'check' => 'dmarc',
                    'severity' => 'warn',
                    'code' => 'dmarc_partial_pct',
                    'message' => "DMARC policy applies to only {$pct}% of messages, leaving remaining traffic un-enforced.",
                ];
            }
        }

        $hasFail = collect($findings)->contains(fn ($f) => $f['severity'] === 'fail');
        $hasWarn = collect($findings)->contains(fn ($f) => $f['severity'] === 'warn');

        $status = $hasFail ? 'fail' : ($hasWarn ? 'warn' : 'pass');

        return [
            'status' => $status,
            'policy' => $policy !== '' ? $policy : null,
            'record' => $record,
            'findings' => $findings,
        ];
    }

    protected function parseTags(string $record): array
    {
        $tags = [];
        $parts = explode(';', $record);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $tags[strtolower(trim($pair[0]))] = trim($pair[1]);
            }
        }

        return $tags;
    }
}
