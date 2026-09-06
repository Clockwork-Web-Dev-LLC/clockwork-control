<?php

namespace App\Http\Controllers;

use App\Console\Commands\AutoApproveRepeats;
use App\Models\ActionLog;
use App\Models\BlockedIp;
use App\Models\ReviewQueueEntry;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use App\Services\Fail2ban\Fail2banClient;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReviewQueueController extends Controller
{
    /**
     * Assemble the data array used by the Bans → Queue tab. Returns an array
     * (NOT a View) — BansController is the only renderer; the legacy
     * /review GET URL is now a 301 redirect closure in routes/web.php.
     *
     * @return array<string, mixed>
     */
    public function assembleData(Request $request, Settings $settings): array
    {
        $sourceFilter = $request->query('source');
        $autoApproveEnabled = (bool) $settings->get('auto_approve_repeats_enabled', false);
        $autoApprovedRecently = ReviewQueueEntry::query()
            ->where('decided_by', AutoApproveRepeats::DECIDED_BY)
            ->where('decided_at', '>=', now()->subDay())
            ->count();

        // Pull all pending entries with relations once. We aggregate in PHP because grouping
        // in SQL across the JSON evidence column is awkward and the volume here is bounded
        // (typically hundreds, not thousands of rows even with a dirty fleet).
        $pending = ReviewQueueEntry::query()
            ->with(['server', 'site'])
            ->where('status', ReviewQueueEntry::STATUS_PENDING)
            ->when($sourceFilter, fn ($q) => $q->where('source', $sourceFilter))
            ->orderBy('ip')
            ->get();

        // Group entries by IP across the whole fleet so a single "row" in the UI represents
        // all pending lockouts for that IP — including across multiple servers.
        $aggregated = $this->aggregateByIp($pending);

        $repeatOffenders = $aggregated
            ->where('total_occurrences', '>=', 2)
            ->sortByDesc(fn ($r) => [$r['multi_server'] ? 1 : 0, $r['total_occurrences'], $r['latest_at']?->timestamp ?? 0])
            ->values();

        $firstSightings = $aggregated
            ->where('total_occurrences', '<', 2)
            ->sortByDesc(fn ($r) => $r['latest_at']?->timestamp ?? 0)
            ->values();

        $recent = ReviewQueueEntry::query()
            ->with(['server', 'site'])
            ->whereIn('status', [
                ReviewQueueEntry::STATUS_APPROVED,
                ReviewQueueEntry::STATUS_DISMISSED,
                ReviewQueueEntry::STATUS_FAILED,
            ])
            ->orderByDesc('decided_at')
            ->limit(25)
            ->get();

        $sourceCounts = ReviewQueueEntry::query()
            ->where('status', ReviewQueueEntry::STATUS_PENDING)
            ->selectRaw('source, COUNT(*) AS n')
            ->groupBy('source')
            ->pluck('n', 'source');

        $queuedCount = ReviewQueueEntry::query()
            ->where('status', ReviewQueueEntry::STATUS_QUEUED_FOR_BAN)
            ->count();

        // Per-domain Cloudflare state lookup so the queue rows can show the proxy
        // status next to each site without N+1 queries. Cheap — one row per site.
        $cfStates = Site::query()
            ->pluck('cloudflare_state', 'domain')
            ->all();

        return compact(
            'repeatOffenders',
            'firstSightings',
            'recent',
            'sourceCounts',
            'sourceFilter',
            'queuedCount',
            'autoApproveEnabled',
            'autoApprovedRecently',
            'cfStates',
        );
    }

    /**
     * Toggle the "auto-approve repeat offenders" setting. Posting with no body flips
     * the current value. When enabling, we run the sweep once synchronously so the
     * user sees the queue drain immediately instead of waiting up to 60s for the
     * scheduled tick.
     */
    public function toggleAutoApprove(Settings $settings, AutoApproveRepeats $sweeper): RedirectResponse
    {
        $current = (bool) $settings->get('auto_approve_repeats_enabled', false);
        $next = ! $current;

        $settings->put('auto_approve_repeats_enabled', $next);

        Log::info('auto_approve_repeats.toggled', [
            'enabled' => $next,
            'by' => 'manual',
        ]);

        if ($next) {
            $promoted = $sweeper->sweep();

            return back()->with(
                'queue_status',
                $promoted > 0
                    ? "Auto-approve enabled. Immediately promoted {$promoted} pending entr".($promoted === 1 ? 'y' : 'ies').' to ban queue.'
                    : 'Auto-approve enabled. No pending entries currently meet the 2+ threshold — future repeat offenders will be banned automatically.'
            );
        }

        return back()->with('queue_status', 'Auto-approve disabled. New repeat offenders will require manual approval.');
    }

    /**
     * Aggregate ReviewQueueEntry rows by IP across servers.
     *
     * @param  Collection<int, ReviewQueueEntry>  $entries
     * @return Collection<int, array{ip: string, total_occurrences: int, sites: array<int,string>, servers: array<int,array{id:int,name:string}>, multi_server: bool, latest_at: ?Carbon, source: string, entry_ids: array<int,int>}>
     */
    private function aggregateByIp(Collection $entries): Collection
    {
        return $entries
            ->groupBy('ip')
            ->map(function (Collection $group) {
                $first = $group->first();
                $sites = [];
                $servers = [];
                $totalOccurrences = 0;
                $latestAt = null;
                $sources = [];

                foreach ($group as $entry) {
                    $evidence = $entry->evidence ?? [];
                    $occ = (int) ($evidence['occurrences'] ?? 1);
                    $totalOccurrences += $occ;

                    foreach ((array) ($evidence['sites_seen'] ?? []) as $domain) {
                        if (! in_array($domain, $sites, true)) {
                            $sites[] = $domain;
                        }
                    }
                    if ($entry->site && ! in_array($entry->site->domain, $sites, true)) {
                        $sites[] = $entry->site->domain;
                    }

                    if ($entry->server) {
                        $key = $entry->server->id;
                        $servers[$key] = ['id' => $key, 'name' => $entry->server->name];
                    }

                    $entryLatest = $entry->updated_at ?? $entry->created_at;
                    if ($entryLatest && (! $latestAt || $entryLatest->greaterThan($latestAt))) {
                        $latestAt = $entryLatest;
                    }

                    $sources[$entry->source] = true;
                }

                return [
                    'ip' => $first->ip,
                    'total_occurrences' => $totalOccurrences,
                    'sites' => $sites,
                    'servers' => array_values($servers),
                    'multi_server' => count($servers) > 1,
                    'latest_at' => $latestAt,
                    'source' => array_key_first($sources) ?? $first->source,
                    'entry_ids' => $group->pluck('id')->all(),
                ];
            })
            ->values();
    }

    public function approve(ReviewQueueEntry $entry, Fail2banClient $fail2ban, ChatNotifier $chat, ActionLogger $logger): RedirectResponse
    {
        if ($entry->status !== ReviewQueueEntry::STATUS_PENDING) {
            return back()->with('queue_error', 'Entry is not pending.');
        }

        if (! $entry->server) {
            return back()->with('queue_error', 'Cannot approve — server record missing.');
        }

        $result = $this->banSingle($entry, $fail2ban, $chat);

        if (! $result['ok']) {
            $logger->record(
                actionType: ActionLog::TYPE_REVIEW_APPROVE,
                summary: "Approve failed for {$entry->ip} on {$entry->server->name}.",
                site: $entry->site,
                server: $entry->server,
                target: $entry->ip,
                details: ['source' => $entry->source, 'entry_id' => $entry->id],
                ok: false,
                error: $result['message'],
            );

            return back()->with('queue_error', $result['message']);
        }

        $logger->record(
            actionType: ActionLog::TYPE_REVIEW_APPROVE,
            summary: "Approved + banned {$entry->ip} on {$entry->server->name}.",
            site: $entry->site,
            server: $entry->server,
            target: $entry->ip,
            details: ['source' => $entry->source, 'entry_id' => $entry->id],
        );

        return back()->with('queue_status', "Banned {$entry->ip} on {$entry->server->name}.");
    }

    public function dismiss(ReviewQueueEntry $entry, ActionLogger $logger): RedirectResponse
    {
        if ($entry->status !== ReviewQueueEntry::STATUS_PENDING) {
            return back()->with('queue_error', 'Entry is not pending.');
        }

        $entry->update([
            'status' => ReviewQueueEntry::STATUS_DISMISSED,
            'decided_at' => Carbon::now(),
            'decided_by' => 'manual',
        ]);

        $logger->record(
            actionType: ActionLog::TYPE_REVIEW_DISMISS,
            summary: "Dismissed review entry for {$entry->ip}".($entry->server ? " on {$entry->server->name}" : '').'.',
            site: $entry->site,
            server: $entry->server,
            target: $entry->ip,
            details: ['source' => $entry->source, 'entry_id' => $entry->id],
        );

        return back()->with('queue_status', "Dismissed {$entry->ip}.");
    }

    /**
     * Bulk-approve: mark each pending entry for the given IPs as queued_for_ban.
     * The actual fail2ban work is done off the request path by
     * clockwork:process-pending-bans on the next scheduler tick.
     */
    public function bulkApprove(Request $request): RedirectResponse
    {
        $ips = $this->validatedIps($request);
        if ($ips === []) {
            return back()->with('queue_error', 'Select at least one IP.');
        }

        $count = ReviewQueueEntry::query()
            ->whereIn('ip', $ips)
            ->where('status', ReviewQueueEntry::STATUS_PENDING)
            ->update([
                'status' => ReviewQueueEntry::STATUS_QUEUED_FOR_BAN,
                'decided_at' => Carbon::now(),
                'decided_by' => 'manual',
            ]);

        Log::info('review_queue.bulk_approve_queued', [
            'ip_count' => count($ips),
            'rows_queued' => $count,
        ]);

        return back()->with(
            'queue_status',
            sprintf('Queued %d ban %s across %d %s. The processor runs every minute — refresh shortly to see results.',
                $count,
                Str::plural('operation', $count),
                count($ips),
                Str::plural('IP', count($ips)),
            )
        );
    }

    /**
     * Bulk-dismiss: mark all pending entries for the given IPs as dismissed.
     */
    public function bulkDismiss(Request $request): RedirectResponse
    {
        $ips = $this->validatedIps($request);
        if ($ips === []) {
            return back()->with('queue_error', 'Select at least one IP.');
        }

        $count = ReviewQueueEntry::query()
            ->whereIn('ip', $ips)
            ->where('status', ReviewQueueEntry::STATUS_PENDING)
            ->update([
                'status' => ReviewQueueEntry::STATUS_DISMISSED,
                'decided_at' => Carbon::now(),
                'decided_by' => 'manual',
            ]);

        Log::info('review_queue.bulk_dismiss', [
            'ip_count' => count($ips),
            'rows_affected' => $count,
        ]);

        return back()->with('queue_status', sprintf('Dismissed %d entries across %d IPs.', $count, count($ips)));
    }

    /**
     * @return array<int, string>
     */
    private function validatedIps(Request $request): array
    {
        $raw = $request->input('ips', []);
        if (! is_array($raw)) {
            return [];
        }

        $valid = [];
        foreach ($raw as $ip) {
            $ip = trim((string) $ip);
            if (filter_var($ip, FILTER_VALIDATE_IP) && ! in_array($ip, $valid, true)) {
                $valid[] = $ip;
            }
        }

        return $valid;
    }

    /**
     * Ban one entry's (server, IP) and convert the entry to an approved BlockedIp row.
     *
     * @return array{ok: bool, message: string}
     */
    private function banSingle(ReviewQueueEntry $entry, Fail2banClient $fail2ban, ChatNotifier $chat): array
    {
        $result = $fail2ban->banIp($entry->server, $entry->ip);

        Log::info('review_queue.approve', [
            'entry_id' => $entry->id,
            'ip' => $entry->ip,
            'server' => $entry->server->name,
            'source' => $entry->source,
            'fail2ban_ok' => $result['ok'],
            'fail2ban_message' => $result['message'],
        ]);

        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message'].' — '.$result['output']];
        }

        $blocked = BlockedIp::create([
            'ip' => $entry->ip,
            'server_id' => $entry->server_id,
            'site_id' => $entry->site_id,
            'source' => $entry->source ?: BlockedIp::SOURCE_MANUAL,
            'reason' => $entry->reason ?: 'Approved from review queue',
            'llm_verdict' => $entry->llm_verdict,
            'llm_reasoning' => $entry->llm_reasoning,
            'decision' => BlockedIp::DECISION_APPROVED,
            'decided_by' => 'manual',
            'banned_at' => Carbon::now(),
            'expires_at' => null,
            'unbanned_at' => null,
        ]);

        $chat->ipBlocked($blocked);

        $entry->update([
            'status' => ReviewQueueEntry::STATUS_APPROVED,
            'decided_at' => Carbon::now(),
            'decided_by' => 'manual',
        ]);

        return ['ok' => true, 'message' => 'banned'];
    }
}
