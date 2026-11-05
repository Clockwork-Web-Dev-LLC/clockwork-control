<?php

namespace Modules\ClientReports\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Services\ReportReviewFlags;

/**
 * Operator decisions on a held report's review reasons. Operator-only: the
 * marks live in sections_data, which no client view shows beyond the line
 * they change.
 */
class ReportReviewController extends Controller
{
    /**
     * Record that a person checked the report's review-tier malware findings
     * (a "warning" scan: PHP in uploads, a recently changed wp-config, the
     * dead /e modifier) and found no threat: who, when and what they
     * checked, tied to the scan and findings the report shows. That clears
     * the malware review reason and the client reads "Reviewed — no threat
     * found" instead of "Issues flagged". Recompile keeps the mark while the
     * scan and its findings are the same (ScheduledReportRunner). Suspected
     * malware (issues_found) can never be marked; nor can a report that has
     * reached anyone or isn't held. Nothing is sent.
     */
    public function markMalwareReviewed(Request $request, ClientReport $report): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);
        $user = $request->user();

        try {
            $remaining = DB::transaction(function () use ($report, $validated, $user) {
                // Re-read under a lock, so a Recompile or a send can't slip in between.
                $current = ClientReport::query()->lockForUpdate()->find($report->id);

                if ($current === null || $current->status !== ClientReport::STATUS_GENERATED || ! empty($current->sent_to)) {
                    throw new InvalidArgumentException('Only a report that has not been sent to anyone, and has no newer copy, can have its findings marked reviewed.');
                }

                $data = is_array($current->sections_data) ? $current->sections_data : [];
                if (empty($data['meta']['review_reasons'])) {
                    throw new InvalidArgumentException('This report is not held for review, so there is nothing to mark.');
                }
                if (! ReportReviewFlags::canMarkMalwareReviewed($data)) {
                    throw new InvalidArgumentException('Only review-tier malware findings (a scan graded "warning", with no malware signature) can be marked reviewed, and this report has none waiting.');
                }

                $data = ReportReviewFlags::withMalwareReviewed($data, [
                    'reviewed_by' => (string) ($user?->name ?: $user?->email ?: 'Unknown'),
                    'reviewed_by_id' => $user?->id,
                    'reviewed_at' => now()->toIso8601String(),
                    'note' => trim((string) $validated['note']),
                ]);
                $current->update(['sections_data' => $data]);

                Log::info('client_reports.malware_reviewed', [
                    'report_id' => $current->id,
                    'site_id' => $current->site_id,
                    'scan_id' => $data['security']['malware_reviewed']['scan_id'],
                    'user_id' => $user?->id,
                ]);

                return $data['meta']['review_reasons'];
            });
        } catch (InvalidArgumentException $e) {
            return back()->with('status_error', $e->getMessage());
        }

        $message = 'Marked the malware findings reviewed; the client now reads "Reviewed — no threat found". Nothing was sent.';
        $message .= $remaining === []
            ? ' Nothing else holds this report; send it from the history table when ready.'
            : ' Still held for: '.implode(' ', $remaining);

        return redirect()->route('client-reports.show', $report)->with('status', $message);
    }
}
