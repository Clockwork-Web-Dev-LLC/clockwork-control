<?php

namespace App\Console\Commands;

use App\Models\BlockedIp;
use App\Models\ReviewQueueEntry;
use App\Models\Server;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Services\Fail2ban\Fail2banClient;
use App\Services\Fail2ban\IgnoreIpMatcher;
use App\Services\Ingest\IngestScheduleGate;
use App\Services\Wordfence\WordfenceBlocksPuller;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PullWordfenceBlocks extends Command
{
    protected $signature = 'clockwork:pull-wordfence-blocks
        {--server= : Limit to a single server (id, name, or hostname)}
        {--dry-run : Read Wordfence data and log decisions, but do not ban or queue}';

    protected $description = 'Pull active Wordfence IP blocks per site. Auto-ban or queue for review based on per-server toggle.';

    public function handle(WordfenceBlocksPuller $puller, Fail2banClient $fail2ban, IgnoreIpMatcher $ignoreMatcher, IngestScheduleGate $gate, ChatNotifier $chat): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $servers = $this->resolveServers();
        if ($servers->isEmpty()) {
            $this->warn('No servers matched.');

            return self::SUCCESS;
        }

        $totals = [
            'servers' => 0,
            'sites' => 0,
            'blocks' => 0,
            'queued' => 0,
            'auto_banned' => 0,
            'skipped_existing' => 0,
            'filtered_protected' => 0,
            'errors' => 0,
        ];

        foreach ($servers as $server) {
            $totals['servers']++;
            $perServer = $this->pullForServer($server, $puller, $fail2ban, $ignoreMatcher, $dryRun, $chat);

            foreach (['sites', 'blocks', 'queued', 'auto_banned', 'skipped_existing', 'filtered_protected', 'errors'] as $k) {
                $totals[$k] += $perServer[$k];
            }
        }

        $msg = sprintf(
            'wordfence.pull complete: servers=%d sites=%d blocks=%d queued=%d auto_banned=%d skipped_existing=%d filtered_protected=%d errors=%d%s',
            $totals['servers'],
            $totals['sites'],
            $totals['blocks'],
            $totals['queued'],
            $totals['auto_banned'],
            $totals['skipped_existing'],
            $totals['filtered_protected'],
            $totals['errors'],
            $dryRun ? ' (dry-run)' : '',
        );
        Log::info($msg);
        $this->info($msg);

        if (! $dryRun) {
            $gate->recordRun(IngestScheduleGate::SOURCE_WORDFENCE);
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Server>
     */
    private function resolveServers(): Collection
    {
        // No wordfence_enabled filter here — the puller probes for the wfBlocks7 table
        // itself, so this command is also the "discover Wordfence installs" pass.
        $q = Server::query()
            ->monitored()
            ->whereHas('sites', fn ($qq) => $qq
                ->where('is_wordpress', true)
                ->whereNotNull('db_password'));

        if ($needle = $this->option('server')) {
            $q->where(function ($q) use ($needle) {
                $q->where('id', $needle)
                    ->orWhere('name', $needle)
                    ->orWhere('hostname', $needle);
            });
        }

        return $q->orderBy('name')->get();
    }

    /**
     * @return array{sites: int, blocks: int, queued: int, auto_banned: int, skipped_existing: int, filtered_protected: int, errors: int}
     */
    private function pullForServer(Server $server, WordfenceBlocksPuller $puller, Fail2banClient $fail2ban, IgnoreIpMatcher $ignoreMatcher, bool $dryRun, ChatNotifier $chat): array
    {
        $stats = ['sites' => 0, 'blocks' => 0, 'queued' => 0, 'auto_banned' => 0, 'skipped_existing' => 0, 'filtered_protected' => 0, 'errors' => 0];

        // Auto-ban toggle for Wordfence is independent of LLAR's. A user might trust LLAR
        // (only-failed-logins) more than Wordfence (broader rules); per-source is the
        // right granularity.
        $autoBan = (bool) $server->auto_ban_wordfence;

        $sites = $server->sites()
            ->where('is_wordpress', true)
            ->whereNotNull('db_password')
            ->orderBy('domain')
            ->get();

        /** @var Site $site */
        foreach ($sites as $site) {
            $stats['sites']++;

            try {
                $blocks = $puller->activeBlocks($site);
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('wordfence.pull.site_failed', [
                    'server' => $server->name,
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($blocks as $block) {
                $stats['blocks']++;

                if ($protectedReason = $ignoreMatcher->reason($block['ip'])) {
                    $stats['filtered_protected']++;
                    // debug, not info — same reasoning as
                    // PullLlarLockouts::pullForServer(): re-fires for every
                    // still-active protected IP on every pull.
                    Log::debug('wordfence.block.filtered_protected', [
                        'server' => $server->name,
                        'site' => $site->domain,
                        'ip' => $block['ip'],
                        'reason' => $protectedReason,
                    ]);

                    continue;
                }

                $action = $this->processBlock($server, $site, $block, $autoBan, $dryRun, $fail2ban, $chat, $stats);

                // Same fix as PullLlarLockouts::pullForServer() — the source
                // query returns every block still active, not just new ones,
                // so logging unconditionally re-logged the same standing
                // blocks on every pull. Only log genuinely new events.
                if (! in_array($action, ['skipped_active_ban', 'incremented_existing', 'skipped_queued_for_ban'], true)) {
                    Log::info('wordfence.block', [
                        'server' => $server->name,
                        'site' => $site->domain,
                        'ip' => $block['ip'],
                        'type' => $block['type'],
                        'expires_at' => $block['expires_at']?->toIso8601String(),
                        'source' => $block['source_table'],
                        'action' => $action,
                        'auto_ban' => $autoBan,
                        'dry_run' => $dryRun,
                    ]);
                }
            }
        }

        if (! $dryRun) {
            $server->forceFill(['last_wordfence_pull_at' => Carbon::now()])->save();
        }

        return $stats;
    }

    /**
     * @param  array{ip: string, expires_at: ?Carbon, source_table: string, reason: ?string, type: ?string}  $block
     * @param  array{sites: int, blocks: int, queued: int, auto_banned: int, skipped_existing: int, errors: int}  $stats
     */
    private function processBlock(
        Server $server,
        Site $site,
        array $block,
        bool $autoBan,
        bool $dryRun,
        Fail2banClient $fail2ban,
        ChatNotifier $chat,
        array &$stats,
    ): string {
        $ip = $block['ip'];

        $hasActiveBan = BlockedIp::query()
            ->where('server_id', $server->id)
            ->where('ip', $ip)
            ->whereNull('unbanned_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($hasActiveBan) {
            $stats['skipped_existing']++;

            return 'skipped_active_ban';
        }

        $existingEntry = ReviewQueueEntry::query()
            ->where('server_id', $server->id)
            ->where('ip', $ip)
            ->whereIn('status', [
                ReviewQueueEntry::STATUS_PENDING,
                ReviewQueueEntry::STATUS_QUEUED_FOR_BAN,
            ])
            ->first();

        if ($existingEntry) {
            if (! $dryRun && $existingEntry->status === ReviewQueueEntry::STATUS_PENDING) {
                $this->bumpExistingEntry($existingEntry, $site, $block);
            }
            $stats['skipped_existing']++;

            return $existingEntry->status === ReviewQueueEntry::STATUS_PENDING
                ? 'incremented_existing'
                : 'skipped_queued_for_ban';
        }

        if ($dryRun) {
            return $autoBan ? 'would_auto_ban' : 'would_queue';
        }

        if ($autoBan) {
            $result = $fail2ban->banIp($server, $ip);
            if (! $result['ok']) {
                $stats['errors']++;
                Log::warning('wordfence.auto_ban.fail2ban_failed', [
                    'server' => $server->name,
                    'site' => $site->domain,
                    'ip' => $ip,
                    'message' => $result['message'],
                    'output' => $result['output'],
                ]);

                $this->queueEntry($server, $site, $block, "auto-ban attempted but fail2ban failed: {$result['message']}");
                $stats['queued']++;

                return 'auto_ban_failed_queued';
            }

            $blocked = BlockedIp::create([
                'ip' => $ip,
                'server_id' => $server->id,
                'site_id' => $site->id,
                'source' => BlockedIp::SOURCE_WORDFENCE,
                'reason' => $this->reasonText($block),
                'llm_verdict' => null,
                'llm_reasoning' => null,
                'decision' => BlockedIp::DECISION_AUTO,
                'decided_by' => 'wordfence-auto',
                'banned_at' => Carbon::now(),
                'expires_at' => null,
                'unbanned_at' => null,
            ]);

            $chat->ipBlocked($blocked);

            $stats['auto_banned']++;

            return 'auto_banned';
        }

        $this->queueEntry($server, $site, $block, $this->reasonText($block));
        $stats['queued']++;

        return 'queued';
    }

    /**
     * @param  array{ip: string, expires_at: ?Carbon, source_table: string, reason: ?string, type: ?string}  $block
     */
    private function queueEntry(Server $server, Site $site, array $block, string $reason): void
    {
        ReviewQueueEntry::create([
            'ip' => $block['ip'],
            'server_id' => $server->id,
            'site_id' => $site->id,
            'source' => ReviewQueueEntry::SOURCE_WORDFENCE,
            'reason' => $reason,
            'llm_verdict' => null,
            'llm_reasoning' => null,
            'llm_score' => null,
            'evidence' => [
                'expires_at' => $block['expires_at']?->toIso8601String(),
                'source_table' => $block['source_table'],
                'site_domain' => $site->domain,
                'wf_type' => $block['type'],
                'wf_reason' => $block['reason'],
                'occurrences' => 1,
                'sites_seen' => [$site->domain],
                'first_seen_at' => Carbon::now()->toIso8601String(),
                'last_seen_at' => Carbon::now()->toIso8601String(),
            ],
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array{ip: string, expires_at: ?Carbon, source_table: string, reason: ?string, type: ?string}  $block
     */
    private function bumpExistingEntry(ReviewQueueEntry $entry, Site $site, array $block): void
    {
        $evidence = $entry->evidence ?? [];
        $evidence['occurrences'] = (int) ($evidence['occurrences'] ?? 1) + 1;

        $sites = $evidence['sites_seen'] ?? [];
        if (! in_array($site->domain, $sites, true)) {
            $sites[] = $site->domain;
        }
        $evidence['sites_seen'] = $sites;
        $evidence['last_seen_at'] = Carbon::now()->toIso8601String();
        if ($block['expires_at']) {
            $evidence['expires_at'] = $block['expires_at']->toIso8601String();
        }

        $entry->update(['evidence' => $evidence]);
    }

    /**
     * @param  array{ip: string, expires_at: ?Carbon, source_table: string, reason: ?string, type: ?string}  $block
     */
    private function reasonText(array $block): string
    {
        $type = $block['type'] ?: 'block';
        $expiry = $block['expires_at']
            ? 'expires '.$block['expires_at']->diffForHumans()
            : 'permanent';
        $wfReason = $block['reason'] ? " — {$block['reason']}" : '';

        return "Wordfence {$type} block ({$expiry}){$wfReason}";
    }
}
