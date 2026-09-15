<?php

namespace App\Http\Controllers;

use App\Console\Commands\AutoApproveRepeats;
use App\Models\BlockedIp;
use App\Models\ReviewQueueEntry;
use App\Services\Fail2ban\BanRetention;
use App\Support\BanHistoryRow;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thin shell that unifies the Queue (review_queue) and IPs (blocked_ips)
 * pages into one /bans destination with three tabs.
 *
 * Per the plan in Plans/image-19-i-feel-dynamic-stallman.md:
 *   - queue()   delegates to ReviewQueueController::index() for data
 *   - active()  delegates to BlockedIpsController::index() for data
 *   - history() merges decided ReviewQueueEntry rows + unbanned BlockedIp rows
 *               via App\Support\BanHistoryRow into a single chronological list
 *
 * All POST endpoints (review-queue.approve / .bulkApprove / .toggleAutoApprove,
 * blocked-ips.unban, etc.) are unchanged — they map to resources, not pages.
 * The forms in the new Blade partials still post to those existing routes.
 */
class BansController extends Controller
{
    /**
     * The "default" landing — queue is the actionable tab, so /bans → /bans/queue.
     * Implemented as a controller redirect (not a routes/web.php redirect) so
     * the route name `bans.index` exists and can be linked from the nav.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('bans.queue');
    }

    public function queue(Request $request, ReviewQueueController $reviewController, Settings $settings, BanRetention $retention): View
    {
        $data = $reviewController->assembleData($request, $settings);

        return view('dashboard.bans.layout', array_merge($data, [
            'activeTab' => 'queue',
            'tabPartial' => 'dashboard.bans._tab-queue',
        ], $this->sharedStats($settings, $retention)));
    }

    public function active(Request $request, BlockedIpsController $ipsController, Settings $settings, BanRetention $retention): View
    {
        $data = $ipsController->assembleData($request);

        return view('dashboard.bans.layout', array_merge($data, [
            'activeTab' => 'active',
            'tabPartial' => 'dashboard.bans._tab-active',
        ], $this->sharedStats($settings, $retention)));
    }

    public function history(Request $request, Settings $settings, BanRetention $retention): View
    {
        $ipFilter = trim((string) $request->query('ip', ''));

        // Pull last 50 of each kind. Eager-load relations so the Blade isn't N+1.
        $decisions = ReviewQueueEntry::query()
            ->with(['server', 'site.server'])
            ->whereIn('status', [
                ReviewQueueEntry::STATUS_APPROVED,
                ReviewQueueEntry::STATUS_DISMISSED,
                ReviewQueueEntry::STATUS_FAILED,
            ])
            ->whereNotNull('decided_at')
            ->when($ipFilter !== '', fn ($q) => $q->where('ip', $ipFilter))
            ->orderByDesc('decided_at')
            ->limit(50)
            ->get();

        $unbans = BlockedIp::query()
            ->with(['server', 'site.server'])
            ->whereNotNull('unbanned_at')
            ->when($ipFilter !== '', fn ($q) => $q->where('ip', $ipFilter))
            ->orderByDesc('unbanned_at')
            ->limit(50)
            ->get();

        $rows = collect()
            ->concat($decisions->map(fn ($e) => BanHistoryRow::fromReviewEntry($e)))
            ->concat($unbans->map(fn ($b) => BanHistoryRow::fromBlockedIp($b)))
            ->filter(fn (BanHistoryRow $r) => $r->when() !== null)
            ->sortByDesc(fn (BanHistoryRow $r) => $r->when()->getTimestamp())
            ->take(50)
            ->values();

        return view('dashboard.bans.layout', array_merge([
            'activeTab' => 'history',
            'tabPartial' => 'dashboard.bans._tab-history',
            'rows' => $rows,
            'ipFilter' => $ipFilter,
        ], $this->sharedStats($settings, $retention)));
    }

    /**
     * Update default ban retention months setting.
     */
    public function updateRetention(Request $request, BanRetention $retention): RedirectResponse
    {
        $inputVal = $request->input('retention_months', $request->input('months'));

        $validated = validator(['retention_months' => $inputVal], [
            'retention_months' => ['required', 'integer', 'min:0', 'max:120'],
        ])->validate();

        $retention->setRetentionMonths((int) $validated['retention_months']);

        $label = (int) $validated['retention_months'] > 0
            ? "{$validated['retention_months']} months"
            : 'indefinite (never expire)';

        return back()->with('ban_status', "Ban retention policy updated to {$label}.");
    }

    /**
     * Bulk clear / prune active bans on-demand by age cutoff.
     */
    public function bulkClear(Request $request, BanRetention $retention): RedirectResponse
    {
        $validated = $request->validate([
            'months' => ['required', 'integer', 'in:'.implode(',', BanRetention::PRUNE_MONTH_OPTIONS)],
        ]);

        $months = (int) $validated['months'];
        $pruned = $retention->prune($months, actor: 'manual');

        $label = $months > 0 ? "older than {$months} month(s)" : 'across all dates';

        return back()->with('ban_status', "Successfully cleared {$pruned} active ban(s) {$label}.");
    }

    /**
     * Shared stats strip metrics across all three tabs.
     */
    private function sharedStats(Settings $settings, BanRetention $retention): array
    {
        return [
            'activeBansCount' => BlockedIp::query()->whereNull('unbanned_at')->count(),
            'reviewQueueCount' => ReviewQueueEntry::query()->where('status', ReviewQueueEntry::STATUS_PENDING)->count(),
            'autoApproveEnabled' => (bool) $settings->get('auto_approve_repeats_enabled', false),
            'autoApprovedRecently' => ReviewQueueEntry::query()
                ->where('decided_by', AutoApproveRepeats::DECIDED_BY)
                ->where('decided_at', '>=', now()->subDay())
                ->count(),
            'retentionMonths' => $retention->retentionMonths(),
            'banBreakdown' => $retention->breakdown(),
        ];
    }
}
