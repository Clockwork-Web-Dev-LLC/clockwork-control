<?php

namespace Modules\ClientReports\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Throwable;

class ClientReportSender
{
    /** Longer than a send to a long recipient list can take. */
    private const LOCK_SECONDS = 600;

    /** How long a second send waits for the first before giving up. */
    private const LOCK_WAIT_SECONDS = 120;

    /**
     * Emails each recipient separately. Every successful delivery is written to
     * `sent_to` before the next one is attempted, so a failure (or a crash)
     * part-way through leaves an exact record of who already has the report —
     * a retry can then skip them instead of emailing the whole list again.
     *
     * The report is only marked `sent` once nobody in this batch failed.
     *
     * @param  list<string>  $recipients
     * @return list<string> recipients whose send threw
     */
    public function send(ClientReport $report, array $recipients): array
    {
        $sentBefore = $report->sent_to ?? [];

        // One send per report at a time: a double click, two operators on
        // the same held report, or a hand send during the scheduled run.
        // A send that waited re-reads who got it meanwhile and skips them.
        return Cache::lock('client-report-send:'.$report->id, self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, function () use ($report, $recipients, $sentBefore) {
                $report->refresh();
                $sentMeanwhile = ClientReport::addressesWithout($report->sent_to ?? [], $sentBefore);

                return $this->deliver($report, ClientReport::addressesWithout(ClientReport::uniqueAddresses($recipients), $sentMeanwhile));
            });
    }

    /**
     * @param  list<string>  $recipients
     * @return list<string>
     */
    protected function deliver(ClientReport $report, array $recipients): array
    {
        $failed = [];

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new ClientReportMail($report));
            } catch (Throwable $e) {
                $failed[] = $email;
                Log::error("Client report #{$report->id} failed to send to {$email}: {$e->getMessage()}", [
                    'report_id' => $report->id,
                    'site_id' => $report->site_id,
                    'exception' => $e,
                ]);

                continue;
            }

            $report->update([
                'sent_to' => ClientReport::uniqueAddresses([...($report->sent_to ?? []), $email]),
            ]);
        }

        if ($failed === []) {
            $report->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        }

        return $failed;
    }
}
