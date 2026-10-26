<?php

namespace Modules\ClientReports\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Throwable;

class ClientReportSender
{
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
                'sent_to' => array_values(array_unique([...($report->sent_to ?? []), $email])),
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
