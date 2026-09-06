<?php

namespace App\Http\Controllers;

use App\Models\BlockedIp;
use App\Models\ReviewQueueEntry;
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

    public function queue(Request $request, ReviewQueueController $reviewController, Settings $settings): View
    {
        // Delegate the (already-tested) data assembly to the existing controller.
        // assembleData() returns the array directly — no intermediate view
        // construction, so deleting the legacy review-queue.blade.php template
        // didn't have to break this path.
        $data = $reviewController->assembleData($request, $settings);

        return view('dashboard.bans.layout', array_merge($data, [
            'activeTab' => 'queue',
            'tabPartial' => 'dashboard.bans._tab-queue',
            'activeBansCount' => $this->activeBansCount(),
        ]));
    }

    public function active(Request $request, BlockedIpsController $ipsController): View
    {
        $data = $ipsController->assembleData($request);

        return view('dashboard.bans.layout', array_merge($data, [
            'activeTab' => 'active',
            'tabPartial' => 'dashboard.bans._tab-active',
            'activeBansCount' => $this->activeBansCount(),
        ]));
    }

    public function history(Request $request): View
    {
        $ipFilter = trim((string) $request->query('ip', ''));

        // Pull last 50 of each kind. Eager-load relations so the Blade isn't N+1.
        $decisions = ReviewQueueEntry::query()
            ->with(['server', 'site'])
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
            ->with(['server', 'site'])
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

        return view('dashboard.bans.layout', [
            'activeTab' => 'history',
            'tabPartial' => 'dashboard.bans._tab-history',
            'rows' => $rows,
            'ipFilter' => $ipFilter,
            'activeBansCount' => $this->activeBansCount(),
        ]);
    }

    /**
     * Cheap count for the stats strip + the Active tab pill. Excluded from the
     * Queue and History delegations because their existing controllers don't
     * compute it; computing it once here keeps the strip consistent across tabs.
     */
    private function activeBansCount(): int
    {
        return BlockedIp::query()->whereNull('unbanned_at')->count();
    }
}
