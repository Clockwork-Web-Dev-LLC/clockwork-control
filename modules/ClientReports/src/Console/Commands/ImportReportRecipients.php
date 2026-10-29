<?php

namespace Modules\ClientReports\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Models\ClientReportTemplate;
use Modules\ClientReports\Services\ReportRecipientsSyncService;

/**
 * Loads per-site report recipients from a CSV (e.g. a ManageWP client export)
 * into `client_report_schedules`.
 *
 * The CSV holds client names and email addresses, so it is read from a path on
 * the server and must never be committed to this (public) repository.
 */
class ImportReportRecipients extends Command
{
    protected $signature = 'clockwork:import-report-recipients
        {path : Path to the CSV file (header row required)}
        {--apply : Write the changes. Without it the command only shows what it would do}
        {--replace : Replace a schedule\'s existing recipients instead of adding to them}
        {--no-push : Don\'t push the updated recipients to the sites\' WordPress plugin}';

    protected $description = 'Import client report recipients from a CSV (site domain + email columns). Dry run unless --apply.';

    /** Header names (lowercased, trimmed) accepted for the site column, in order of preference. */
    private const DOMAIN_HEADERS = ['domain', 'website', 'site', 'site url', 'site_url', 'url', 'managewp'];

    public function handle(ReportRecipientsSyncService $sync): int
    {
        $path = (string) $this->argument('path');
        $apply = (bool) $this->option('apply');
        $replace = (bool) $this->option('replace');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Can't read {$path}.");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Can't open {$path}.");

            return self::FAILURE;
        }

        $header = fgetcsv($handle, escape: '\\');
        if (! is_array($header)) {
            fclose($handle);
            $this->error('The CSV is empty.');

            return self::FAILURE;
        }

        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h) ?? '')), $header);

        $domainIndex = null;
        foreach (self::DOMAIN_HEADERS as $name) {
            $found = array_search($name, $header, true);
            if ($found !== false) {
                $domainIndex = $found;
                break;
            }
        }

        $emailIndexes = array_keys(array_filter(
            $header,
            fn (string $h) => str_contains($h, 'email') || str_contains($h, 'recipient'),
        ));

        if ($domainIndex === null || $emailIndexes === []) {
            fclose($handle);
            $this->error('Need a site column ('.implode(', ', self::DOMAIN_HEADERS).') and at least one column with "email" or "recipient" in its name.');
            $this->line('Found: '.implode(', ', $header));

            return self::FAILURE;
        }

        // Group recipients by normalized domain so a site listed on several rows gets all of them.
        /** @var array<string, list<string>> $wanted */
        $wanted = [];
        $rowNumber = 1;
        $skippedRows = 0;
        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $rowNumber++;
            $domain = self::normalizeDomain((string) ($row[$domainIndex] ?? ''));
            if ($domain === '') {
                $skippedRows++;

                continue;
            }

            $emails = [];
            foreach ($emailIndexes as $i) {
                $emails = array_merge($emails, $sync->sanitizeEmails((string) ($row[$i] ?? '')));
            }

            $wanted[$domain] = array_values(array_unique(array_merge($wanted[$domain] ?? [], $emails)));
        }
        fclose($handle);

        if ($wanted === []) {
            $this->warn('No rows with a site domain found.');

            return self::SUCCESS;
        }

        $sitesByDomain = [];
        foreach (Site::query()->get(['id', 'domain', 'care_plan_enabled']) as $site) {
            $sitesByDomain[self::normalizeDomain((string) $site->domain)] ??= $site;
        }

        $schedules = ClientReportSchedule::query()->get()->keyBy('site_id');
        $defaultTemplate = ClientReportTemplate::query()->orderByDesc('is_default')->orderBy('name')->first();

        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'no_site' => 0, 'no_emails' => 0];
        $tableRows = [];
        /** @var list<array{0: Site, 1: ClientReportSchedule}> $toPush */
        $toPush = [];

        foreach ($wanted as $domain => $emails) {
            $site = $sitesByDomain[$domain] ?? null;

            if ($site === null) {
                $stats['no_site']++;
                $tableRows[] = [$domain, 'Site not found in Control', ''];

                continue;
            }

            $carePlanNote = $site->isCarePlanActive() ? '' : ' (no care plan, won\'t send)';

            if ($emails === []) {
                $stats['no_emails']++;
                $tableRows[] = [$domain, 'Skipped: no valid email', ''];

                continue;
            }

            /** @var ClientReportSchedule|null $schedule */
            $schedule = $schedules->get($site->id);

            if ($schedule === null) {
                $stats['created']++;
                $tableRows[] = [$domain, 'Create schedule'.$carePlanNote, implode(', ', $emails)];

                if ($apply) {
                    $schedule = ClientReportSchedule::create([
                        'site_id' => $site->id,
                        'template_id' => $defaultTemplate?->id,
                        'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
                        'delivery_mode' => ClientReportSchedule::MODE_AUTO,
                        'recipients' => $emails,
                        'is_enabled' => true,
                        'next_run_at' => (new ClientReportSchedule(['frequency' => ClientReportSchedule::FREQUENCY_MONTHLY]))->computeNextRun(),
                    ]);
                    $toPush[] = [$site, $schedule];
                }

                continue;
            }

            $current = $sync->sanitizeEmails($schedule->recipients ?? []);
            $new = $replace ? $emails : array_values(array_unique(array_merge($current, $emails)));

            if ($new === $current) {
                $stats['unchanged']++;
                $tableRows[] = [$domain, 'Already set'.$carePlanNote, implode(', ', $current)];

                continue;
            }

            $stats['updated']++;
            $tableRows[] = [
                $domain,
                ($current === [] ? 'Add recipients' : ($replace ? 'Replace recipients' : 'Add to recipients')).$carePlanNote,
                implode(', ', $new),
            ];

            if ($apply) {
                $schedule->update(['recipients' => $new]);
                $toPush[] = [$site, $schedule];
            }
        }

        $this->table(['Site', 'Action', 'Recipients'], $tableRows);
        $this->line(sprintf(
            '%s: %d created, %d updated, %d unchanged, %d not found in Control, %d without a valid email%s.',
            $apply ? 'Done' : 'Dry run',
            $stats['created'],
            $stats['updated'],
            $stats['unchanged'],
            $stats['no_site'],
            $stats['no_emails'],
            $skippedRows > 0 ? ", {$skippedRows} rows without a domain" : '',
        ));

        if (! $apply) {
            $this->warn('Nothing was changed. Run again with --apply to write these changes.');

            return self::SUCCESS;
        }

        if (! $this->option('no-push')) {
            foreach ($toPush as [$site, $schedule]) {
                $sync->pushToSite($site->fresh() ?? $site, $schedule->fresh() ?? $schedule);
            }
        }

        return self::SUCCESS;
    }

    /**
     * "https://www.Example.com/path/" → "example.com".
     */
    public static function normalizeDomain(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = explode('/', $value, 2)[0];
        $value = explode('?', $value, 2)[0];
        $value = explode(':', $value, 2)[0];
        $value = rtrim($value, '.');

        if (str_starts_with($value, 'www.')) {
            $value = substr($value, 4);
        }

        return $value;
    }
}
