<?php

namespace Modules\EmailAuth\Services;

use Modules\EmailAuth\Contracts\DnsTxtResolver;

class SpfValidator
{
    public const MAX_DNS_LOOKUPS = 10;

    public const MAX_VOID_LOOKUPS = 2;

    public function __construct(
        protected DnsTxtResolver $resolver
    ) {}

    /**
     * Validate SPF posture for a domain.
     *
     * @return array{
     *     status: string,
     *     record: ?string,
     *     lookup_count: int,
     *     findings: array<int, array{check: string, severity: string, code: string, message: string}>
     * }
     */
    public function validate(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $txtRecords = $this->resolver->resolveTxt($domain);

        $spfRecords = array_values(array_filter($txtRecords, function ($rec) {
            $trimmed = strtolower(trim($rec));

            return $trimmed === 'v=spf1' || str_starts_with($trimmed, 'v=spf1 ') || str_starts_with($trimmed, 'v=spf1;');
        }));

        $findings = [];

        if (empty($spfRecords)) {
            $findings[] = [
                'check' => 'spf',
                'severity' => 'fail',
                'code' => 'spf_missing',
                'message' => 'No SPF (v=spf1) record published for domain.',
            ];

            return [
                'status' => 'fail',
                'record' => null,
                'lookup_count' => 0,
                'findings' => $findings,
            ];
        }

        if (count($spfRecords) > 1) {
            $findings[] = [
                'check' => 'spf',
                'severity' => 'fail',
                'code' => 'spf_duplicate',
                'message' => 'Multiple SPF records found. RFC 7208 forbids multiple records; receivers will treat this as PermError.',
            ];

            return [
                'status' => 'fail',
                'record' => implode(' | ', $spfRecords),
                'lookup_count' => 0,
                'findings' => $findings,
            ];
        }

        $record = $spfRecords[0];

        // Parse mechanisms and expand includes
        $visited = [$domain];
        $lookupCount = 0;
        $voidCount = 0;
        $hasPtr = false;

        $this->evaluateMechanisms(
            record: $record,
            visited: $visited,
            lookupCount: $lookupCount,
            voidCount: $voidCount,
            hasPtr: $hasPtr,
            findings: $findings
        );

        if ($lookupCount > self::MAX_DNS_LOOKUPS) {
            $findings[] = [
                'check' => 'spf',
                'severity' => 'fail',
                'code' => 'spf_too_many_lookups',
                'message' => "SPF record requires {$lookupCount} DNS lookups, exceeding the RFC 7208 limit of 10. Receiving MTAs will evaluate to PermError.",
            ];
        }

        if ($voidCount > self::MAX_VOID_LOOKUPS) {
            $findings[] = [
                'check' => 'spf',
                'severity' => 'warn',
                'code' => 'spf_too_many_void_lookups',
                'message' => "SPF record contains {$voidCount} void DNS lookups (queries returning no records), exceeding RFC 7208 recommendation of 2.",
            ];
        }

        if ($hasPtr) {
            $findings[] = [
                'check' => 'spf',
                'severity' => 'warn',
                'code' => 'spf_ptr_deprecated',
                'message' => "The 'ptr' mechanism is deprecated in RFC 7208 due to performance and reliability issues.",
            ];
        }

        // Terminal qualifier check
        $this->evaluateTerminalQualifier($record, $findings);

        // Compute overall status
        $hasFail = collect($findings)->contains(fn ($f) => $f['severity'] === 'fail');
        $hasWarn = collect($findings)->contains(fn ($f) => $f['severity'] === 'warn');

        $status = $hasFail ? 'fail' : ($hasWarn ? 'warn' : 'pass');

        return [
            'status' => $status,
            'record' => $record,
            'lookup_count' => $lookupCount,
            'findings' => $findings,
        ];
    }

    protected function evaluateMechanisms(
        string $record,
        array &$visited,
        int &$lookupCount,
        int &$voidCount,
        bool &$hasPtr,
        array &$findings
    ): void {
        $tokens = preg_split('/\s+/', trim($record));
        array_shift($tokens); // remove 'v=spf1'

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            // Remove modifier prefix (+, -, ~, ?)
            $clean = ltrim($token, '+-~?');
            $lower = strtolower($clean);

            // ptr mechanism
            if ($lower === 'ptr' || str_starts_with($lower, 'ptr:')) {
                $hasPtr = true;
                $lookupCount++;

                continue;
            }

            // a, mx, exists mechanisms
            if ($lower === 'a' || str_starts_with($lower, 'a:') || str_starts_with($lower, 'a/')) {
                $lookupCount++;

                continue;
            }

            if ($lower === 'mx' || str_starts_with($lower, 'mx:') || str_starts_with($lower, 'mx/')) {
                $lookupCount++;

                continue;
            }

            if (str_starts_with($lower, 'exists:')) {
                $lookupCount++;

                continue;
            }

            // redirect= modifier
            if (str_starts_with($lower, 'redirect=')) {
                $lookupCount++;
                $target = substr($lower, 9);
                $this->processIncludeTarget($target, $visited, $lookupCount, $voidCount, $hasPtr, $findings);

                continue;
            }

            // include: mechanism
            if (str_starts_with($lower, 'include:')) {
                $lookupCount++;
                $target = substr($lower, 8);
                $this->processIncludeTarget($target, $visited, $lookupCount, $voidCount, $hasPtr, $findings);

                continue;
            }
        }
    }

    protected function processIncludeTarget(
        string $target,
        array &$visited,
        int &$lookupCount,
        int &$voidCount,
        bool &$hasPtr,
        array &$findings
    ): void {
        $target = strtolower(trim($target));
        if ($target === '') {
            return;
        }

        // Loop detection
        if (in_array($target, $visited, true)) {
            $findings[] = [
                'check' => 'spf',
                'severity' => 'fail',
                'code' => 'spf_include_loop',
                'message' => "Circular include loop detected in SPF records involving '{$target}'.",
            ];

            return;
        }

        $visited[] = $target;

        $targetQuery = $this->resolver->query($target, 'TXT');
        if ($targetQuery['status'] === DnsTxtResolver::STATUS_NXDOMAIN || $targetQuery['status'] === DnsTxtResolver::STATUS_NO_DATA) {
            $voidCount++;

            return;
        }

        if ($targetQuery['status'] !== DnsTxtResolver::STATUS_OK) {
            return;
        }

        $subSpf = array_values(array_filter($targetQuery['records'], function ($rec) {
            $t = strtolower(trim((string) $rec));

            return $t === 'v=spf1' || str_starts_with($t, 'v=spf1 ') || str_starts_with($t, 'v=spf1;');
        }));

        if (empty($subSpf)) {
            $voidCount++;

            return;
        }

        // Recurse into included SPF record
        $this->evaluateMechanisms($subSpf[0], $visited, $lookupCount, $voidCount, $hasPtr, $findings);
    }

    protected function evaluateTerminalQualifier(string $record, array &$findings): void
    {
        $tokens = preg_split('/\s+/', trim($record));
        $allTokens = array_values(array_filter($tokens, fn ($t) => preg_match('/^[+\-~?]?all$/i', $t)));

        if (empty($allTokens)) {
            $findings[] = [
                'check' => 'spf',
                'severity' => 'fail',
                'code' => 'spf_missing_all',
                'message' => 'No terminal qualifier (e.g. -all or ~all) defined in SPF record.',
            ];

            return;
        }

        $terminal = strtolower($allTokens[count($allTokens) - 1]);

        if ($terminal === '+all') {
            $findings[] = [
                'check' => 'spf',
                'severity' => 'fail',
                'code' => 'spf_permissive_all',
                'message' => "Permissive '+all' qualifier allows any server on the internet to send email from this domain.",
            ];
        } elseif ($terminal === '?all') {
            $findings[] = [
                'check' => 'spf',
                'severity' => 'warn',
                'code' => 'spf_neutral_all',
                'message' => "Neutral '?all' qualifier provides no protection against unauthorized mail senders.",
            ];
        }
        // -all and ~all are considered valid/passing
    }
}
