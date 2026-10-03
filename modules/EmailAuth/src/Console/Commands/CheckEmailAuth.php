<?php

namespace Modules\EmailAuth\Console\Commands;

use App\Models\Site;
use App\Services\Domains\RootDomainResolver;
use Illuminate\Console\Command;
use Modules\EmailAuth\Services\EmailAuthScanner;

class CheckEmailAuth extends Command
{
    protected $signature = 'clockwork:check-email-auth {--domain= : Specific domain to check}';

    protected $description = 'Perform email authentication posture checks (SPF, DMARC, DKIM, MX) across fleet domains';

    public function handle(EmailAuthScanner $scanner): int
    {
        $specificDomain = $this->option('domain');

        if ($specificDomain) {
            $apex = RootDomainResolver::resolve((string) $specificDomain);
            if ($apex === '') {
                $this->error("Invalid domain specified: {$specificDomain}");

                return self::FAILURE;
            }

            $this->info("Checking email authentication posture for {$apex}...");
            $check = $scanner->scan($apex);

            $this->line('  Overall Status: <fg='.($check->overall_status === 'pass' ? 'green' : ($check->overall_status === 'warn' ? 'yellow' : 'red')).'>'.strtoupper($check->overall_status).'</>');
            $this->line("  SPF Status: {$check->spf_status} ({$check->spf_lookup_count} lookups)");
            $this->line("  DMARC Status: {$check->dmarc_status} (policy: ".($check->dmarc_policy ?? 'none').')');
            $this->line("  DKIM Status: {$check->dkim_status} (".count($check->dkim_selectors_found ?? []).' selectors found)');

            return self::SUCCESS;
        }

        $siteDomains = Site::query()
            ->whereNotNull('domain')
            ->where('is_inactive', false)
            ->hostMonitored()
            ->pluck('domain');

        $apexDomains = $siteDomains
            ->map(fn ($d) => RootDomainResolver::resolve((string) $d))
            ->filter(fn ($d) => $d !== '')
            ->unique()
            ->values();

        if ($apexDomains->isEmpty()) {
            $this->info('No active monitored domains found to check.');

            return self::SUCCESS;
        }

        $this->info("Scanning email authentication posture for {$apexDomains->count()} apex domain(s)...");

        $bar = $this->output->createProgressBar($apexDomains->count());
        $bar->start();

        $pass = 0;
        $warn = 0;
        $fail = 0;

        foreach ($apexDomains as $domain) {
            $check = $scanner->scan($domain);

            if ($check->overall_status === 'pass') {
                $pass++;
            } elseif ($check->overall_status === 'warn') {
                $warn++;
            } else {
                $fail++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Email authentication scan complete: {$pass} Pass, {$warn} Warning, {$fail} Fail.");

        return self::SUCCESS;
    }
}
