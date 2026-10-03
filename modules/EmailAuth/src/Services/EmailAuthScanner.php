<?php

namespace Modules\EmailAuth\Services;

use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Carbon;
use Modules\EmailAuth\Contracts\DnsTxtResolver;
use Modules\EmailAuth\Models\EmailAuthCheck;
use Modules\EmailAuth\Models\EmailAuthDomain;
use Throwable;

class EmailAuthScanner
{
    public function __construct(
        protected DnsTxtResolver $resolver,
        protected SpfValidator $spfValidator,
        protected DmarcValidator $dmarcValidator,
        protected DkimValidator $dkimValidator,
    ) {}

    /**
     * Scan a single apex domain and record results.
     */
    public function scan(string $domain): EmailAuthCheck
    {
        $domain = strtolower(trim($domain));
        $domainModel = EmailAuthDomain::firstOrCreate(['domain' => $domain]);

        $customSelectors = $domainModel->custom_dkim_selectors ?? [];
        $findings = [];

        // 1. Check MX records (mail context)
        $mxRecords = $this->resolver->resolveMx($domain);
        $mxPresent = ! empty($mxRecords) && ! collect($mxRecords)->contains(function ($m) {
            $t = trim((string) ($m['target'] ?? ''));

            return $t === '' || $t === '.';
        });

        if (! $mxPresent) {
            $findings[] = [
                'check' => 'mx',
                'severity' => 'info',
                'code' => 'mx_parked_domain',
                'message' => 'No active mail exchangers (MX) found. Defensive posture (v=spf1 -all and p=reject) recommended to prevent spoofing.',
            ];
        }

        // 2. Validate SPF
        $spf = $this->spfValidator->validate($domain);
        foreach ($spf['findings'] as $f) {
            $findings[] = $f;
        }

        // 3. Validate DMARC
        $dmarc = $this->dmarcValidator->validate($domain);
        foreach ($dmarc['findings'] as $f) {
            $findings[] = $f;
        }

        // 4. Validate DKIM (only if domain handles mail or has custom selectors)
        if ($mxPresent || ! empty($customSelectors)) {
            $dkim = $this->dkimValidator->validate($domain, $customSelectors);
            foreach ($dkim['findings'] as $f) {
                // Ignore "none found" finding if domain is parked
                if (! $mxPresent && $f['code'] === 'dkim_none_found') {
                    continue;
                }
                $findings[] = $f;
            }
        } else {
            $dkim = [
                'status' => 'pass',
                'selectors_found' => [],
                'findings' => [],
            ];
        }

        // 5. Calculate Overall Status
        $hasFail = collect($findings)->contains(fn ($f) => $f['severity'] === 'fail');
        $hasWarn = collect($findings)->contains(fn ($f) => $f['severity'] === 'warn');

        $overallStatus = $hasFail ? EmailAuthCheck::STATUS_FAIL : ($hasWarn ? EmailAuthCheck::STATUS_WARN : EmailAuthCheck::STATUS_PASS);

        // 6. Record Check
        $check = EmailAuthCheck::create([
            'domain' => $domain,
            'overall_status' => $overallStatus,
            'spf_status' => $spf['status'],
            'spf_record' => $spf['record'],
            'spf_lookup_count' => $spf['lookup_count'],
            'dmarc_status' => $dmarc['status'],
            'dmarc_policy' => $dmarc['policy'],
            'dmarc_record' => $dmarc['record'],
            'dkim_status' => $dkim['status'],
            'dkim_selectors_found' => $dkim['selectors_found'],
            'mx_present' => $mxPresent,
            'findings' => $findings,
            'checked_at' => Carbon::now(),
        ]);

        // 7. Transition Alert (Pass/Warn -> Fail)
        $previousStatus = $domainModel->last_overall_status;
        if (! $domainModel->isIgnored() && $overallStatus === EmailAuthCheck::STATUS_FAIL && $previousStatus !== EmailAuthCheck::STATUS_FAIL) {
            try {
                if (app()->has(ChatNotifier::class)) {
                    app(ChatNotifier::class)->emailAuthDegraded($domain, $findings, $previousStatus);
                }
            } catch (Throwable) {
                // Silently swallow notifier exceptions to avoid breaking scan
            }
        }

        // 8. Update domain state
        $domainModel->update([
            'last_overall_status' => $overallStatus,
            'last_checked_at' => Carbon::now(),
        ]);

        // 9. Prune old records (180 days retention)
        EmailAuthCheck::where('domain', $domain)
            ->where('checked_at', '<', Carbon::now()->subDays(180))
            ->delete();

        return $check;
    }
}
