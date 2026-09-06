<?php

namespace App\Console\Commands;

use App\Models\BlockedIp;
use App\Models\ReviewQueueEntry;
use App\Services\Chat\ChatNotifier;
use App\Services\Fail2ban\Fail2banClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Process review-queue entries that humans have approved via the bulk-ban UI but where
 * the actual fail2ban call hasn't fired yet. Runs every minute; takes a small batch so
 * a single tick can't run forever.
 *
 * Each batch entry: SSH to the server, run `fail2ban-client set clockwork banip <ip>`,
 * then either move the row to STATUS_APPROVED + create a BlockedIp, or STATUS_FAILED.
 */
class ProcessPendingBans extends Command
{
    protected $signature = 'clockwork:process-pending-bans
        {--limit=25 : Max entries to process this tick}';

    protected $description = 'Issue fail2ban bans for review-queue entries marked queued_for_ban.';

    public function handle(Fail2banClient $fail2ban, ChatNotifier $chat): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $entries = ReviewQueueEntry::query()
            ->with('server')
            ->where('status', ReviewQueueEntry::STATUS_QUEUED_FOR_BAN)
            ->orderBy('decided_at')
            ->limit($limit)
            ->get();

        if ($entries->isEmpty()) {
            return self::SUCCESS;
        }

        $banned = 0;
        $failed = 0;

        foreach ($entries as $entry) {
            if (! $entry->server) {
                $entry->update([
                    'status' => ReviewQueueEntry::STATUS_FAILED,
                    'reason' => ($entry->reason ? $entry->reason.' | ' : '').'server record missing at process time',
                ]);
                $failed++;

                continue;
            }

            $result = $fail2ban->banIp($entry->server, $entry->ip);

            Log::info('process_pending_bans.attempt', [
                'entry_id' => $entry->id,
                'ip' => $entry->ip,
                'server' => $entry->server->name,
                'ok' => $result['ok'],
                'message' => $result['message'],
            ]);

            if (! $result['ok']) {
                $entry->update([
                    'status' => ReviewQueueEntry::STATUS_FAILED,
                    'reason' => ($entry->reason ? $entry->reason.' | ' : '')
                        .'fail2ban: '.$result['message'],
                ]);
                $failed++;

                continue;
            }

            // Avoid duplicate ban records when re-running on an IP that was already banned
            // (e.g. a previous request 500'd halfway through and we're picking up stragglers).
            $alreadyTracked = BlockedIp::query()
                ->where('server_id', $entry->server_id)
                ->where('ip', $entry->ip)
                ->whereNull('unbanned_at')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->exists();

            if (! $alreadyTracked) {
                $blocked = BlockedIp::create([
                    'ip' => $entry->ip,
                    'server_id' => $entry->server_id,
                    'site_id' => $entry->site_id,
                    'source' => $entry->source ?: BlockedIp::SOURCE_MANUAL,
                    'reason' => $entry->reason ?: 'Approved from review queue',
                    'llm_verdict' => $entry->llm_verdict,
                    'llm_reasoning' => $entry->llm_reasoning,
                    'decision' => BlockedIp::DECISION_APPROVED,
                    'decided_by' => $entry->decided_by ?: 'manual',
                    'banned_at' => Carbon::now(),
                    'expires_at' => null,
                    'unbanned_at' => null,
                ]);

                $chat->ipBlocked($blocked);
            }

            $entry->update(['status' => ReviewQueueEntry::STATUS_APPROVED]);
            $banned++;
        }

        $msg = sprintf('process_pending_bans: processed=%d banned=%d failed=%d', $entries->count(), $banned, $failed);
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }
}
