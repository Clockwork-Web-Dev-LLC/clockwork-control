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
use App\Services\Llar\LlarLockoutPuller;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PullLlarLockouts extends Command
{
    protected $signature = 'clockwork:pull-llar-lockouts
        {--server= : Limit to a single server (id, name, or hostname)}
        {--dry-run : Read LLAR data and log decisions, but do not ban or queue}';

    protected $description = 'Pull active LLAR lockouts per site. Auto-ban or queue for review based on per-server toggle.';

    public function handle(LlarLockoutPuller $puller, Fail2banClient $fail2ban, IgnoreIpMatcher $ignoreMatcher, IngestScheduleGate $gate, ChatNotifier $chat): int
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
            'lockouts' => 0,
            'queued' => 0,
            'auto_banned' => 0,
            'skipped_existing' => 0,
            'filtered_protected' => 0,
            'errors' => 0,
        ];

        foreach ($servers as $server) {
            $totals['servers']++;
            $perServer = $this->pullForServer($server, $puller, $fail2ban, $ignoreMatcher, $dryRun, $chat);

            foreach (['sites', 'lockouts', 'queued', 'auto_banned', 'skipped_existing', 'filtered_protected', 'errors'] as $k) {
                $totals[$k] += $perServer[$k];
            }
        }

        $msg = sprintf(
            'llar.pull complete: servers=%d sites=%d lockouts=%d queued=%d auto_banned=%d skipped_existing=%d filtered_protected=%d errors=%d%s',
            $totals['servers'],
            $totals['sites'],
            $totals['lockouts'],
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
            $gate->recordRun(IngestScheduleGate::SOURCE_LLAR);
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Server>
     */
    private function resolveServers(): Collection
    {
        $q = Server::query()
            ->monitored()
            ->whereHas('sites', fn ($qq) => $qq->where('is_wordpress', true)->whereNotNull('db_password'));

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
     * @return array{sites: int, lockouts: int, queued: int, auto_banned: int, skipped_existing: int, filtered_protected: int, errors: int}
     */
    private function pullForServer(Server $server, LlarLockoutPuller $puller, Fail2banClient $fail2ban, IgnoreIpMatcher $ignoreMatcher, bool $dryRun, ChatNotifier $chat): array
    {
        $stats = ['sites' => 0, 'lockouts' => 0, 'queued' => 0, 'auto_banned' => 0, 'skipped_existing' => 0, 'filtered_protected' => 0, 'errors' => 0];

        $autoBan = (bool) $server->auto_ban_llar;
        $sites = $server->sites()
            ->where('is_wordpress', true)
            ->whereNotNull('db_password')
            ->orderBy('domain')
            ->get();

        /** @var Site $site */
        foreach ($sites as $site) {
            $stats['sites']++;

            try {
                $lockouts = $puller->activeLockouts($site);
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('llar.pull.site_failed', [
                    'server' => $server->name,
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($lockouts as $lockout) {
                $stats['lockouts']++;

                if ($protectedReason = $ignoreMatcher->reason($lockout['ip'])) {
                    $stats['filtered_protected']++;
                    // debug, not info — this re-fires for every still-active
                    // protected IP on every pull with no new information each
                    // time (628k lines observed from this alone).
                    Log::debug('llar.lockout.filtered_protected', [
                        'server' => $server->name,
                        'site' => $site->domain,
                        'ip' => $lockout['ip'],
                        'reason' => $protectedReason,
                    ]);

                    continue;
                }

                $action = $this->processLockout($server, $site, $lockout, $autoBan, $dryRun, $fail2ban, $chat, $stats);

                // activeLockouts() returns every lockout still active on the
                // site, not just new ones — logging unconditionally here
                // re-logged the same standing lockouts on every 5-15 min
                // pull. Sites with large lockout tables (e.g. one site alone
                // produced 863k lines) turned laravel.log into pure noise.
                // Only log actions that represent a genuinely new event.
                if (! in_array($action, ['skipped_active_ban', 'incremented_existing', 'skipped_queued_for_ban'], true)) {
                    Log::info('llar.lockout', [
                        'server' => $server->name,
                        'site' => $site->domain,
                        'ip' => $lockout['ip'],
                        'unlock_at' => $lockout['unlock_at']?->toIso8601String(),
                        'source' => $lockout['source_table'],
                        'action' => $action,
                        'auto_ban' => $autoBan,
                        'dry_run' => $dryRun,
                    ]);
                }
            }
        }

        if (! $dryRun) {
            $server->forceFill(['last_llar_pull_at' => Carbon::now()])->save();
        }

        return $stats;
    }

    /**
     * @param  array{ip: string, unlock_at: ?Carbon, source_table: string}  $lockout
     * @param  array{sites: int, lockouts: int, queued: int, auto_banned: int, skipped_existing: int, errors: int}  $stats
     */
    private function processLockout(
        Server $server,
        Site $site,
        array $lockout,
        bool $autoBan,
        bool $dryRun,
        Fail2banClient $fail2ban,
        ChatNotifier $chat,
        array &$stats,
    ): string {
        $ip = $lockout['ip'];

        // De-dupe: skip if we already have an active ban OR a pending review entry
        // for this IP on this server. Anchor on server (not site) because fail2ban bans
        // are server-scoped — once banned for one site on this box, it's banned for all.
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

        // Don't re-create or bump if the entry is already queued for ban — it's about to
        // be processed off the request path. Pending is the only state we mutate.
        $hasOpenEntry = ReviewQueueEntry::query()
            ->where('server_id', $server->id)
            ->where('ip', $ip)
            ->whereIn('status', [
                ReviewQueueEntry::STATUS_PENDING,
                ReviewQueueEntry::STATUS_QUEUED_FOR_BAN,
            ])
            ->first();

        if ($hasOpenEntry) {
            if (! $dryRun && $hasOpenEntry->status === ReviewQueueEntry::STATUS_PENDING) {
                $this->bumpExistingEntry($hasOpenEntry, $site, $lockout);
            }
            $stats['skipped_existing']++;

            return $hasOpenEntry->status === ReviewQueueEntry::STATUS_PENDING
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
                Log::warning('llar.auto_ban.fail2ban_failed', [
                    'server' => $server->name,
                    'site' => $site->domain,
                    'ip' => $ip,
                    'message' => $result['message'],
                    'output' => $result['output'],
                ]);

                // Fail-open: queue for review so the human sees it.
                $this->queueEntry($server, $site, $lockout, "auto-ban attempted but fail2ban failed: {$result['message']}");
                $stats['queued']++;

                return 'auto_ban_failed_queued';
            }

            $blocked = BlockedIp::create([
                'ip' => $ip,
                'server_id' => $server->id,
                'site_id' => $site->id,
                'source' => BlockedIp::SOURCE_LLAR,
                'reason' => $this->reasonText($lockout),
                'llm_verdict' => null,
                'llm_reasoning' => null,
                'decision' => BlockedIp::DECISION_AUTO,
                'decided_by' => 'llar-auto',
                'banned_at' => Carbon::now(),
                'expires_at' => null,
                'unbanned_at' => null,
            ]);

            $chat->ipBlocked($blocked);

            $stats['auto_banned']++;

            return 'auto_banned';
        }

        $this->queueEntry($server, $site, $lockout, $this->reasonText($lockout));
        $stats['queued']++;

        return 'queued';
    }

    /**
     * @param  array{ip: string, unlock_at: ?Carbon, source_table: string}  $lockout
     */
    private function queueEntry(Server $server, Site $site, array $lockout, string $reason): void
    {
        ReviewQueueEntry::create([
            'ip' => $lockout['ip'],
            'server_id' => $server->id,
            'site_id' => $site->id,
            'source' => ReviewQueueEntry::SOURCE_LLAR,
            'reason' => $reason,
            'llm_verdict' => null,
            'llm_reasoning' => null,
            'llm_score' => null,
            'evidence' => [
                'unlock_at' => $lockout['unlock_at']?->toIso8601String(),
                'source_table' => $lockout['source_table'],
                'site_domain' => $site->domain,
                'occurrences' => 1,
                'sites_seen' => [$site->domain],
                'first_seen_at' => Carbon::now()->toIso8601String(),
                'last_seen_at' => Carbon::now()->toIso8601String(),
            ],
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array{ip: string, unlock_at: ?Carbon, source_table: string}  $lockout
     */
    private function bumpExistingEntry(ReviewQueueEntry $entry, Site $site, array $lockout): void
    {
        $evidence = $entry->evidence ?? [];
        $evidence['occurrences'] = (int) ($evidence['occurrences'] ?? 1) + 1;

        $sites = $evidence['sites_seen'] ?? [];
        if (! in_array($site->domain, $sites, true)) {
            $sites[] = $site->domain;
        }
        $evidence['sites_seen'] = $sites;
        $evidence['last_seen_at'] = Carbon::now()->toIso8601String();

        // Refresh unlock_at to the latest sighting's unlock window — the most useful one
        // for the human reviewer is the most recent.
        if ($lockout['unlock_at']) {
            $evidence['unlock_at'] = $lockout['unlock_at']->toIso8601String();
        }

        $entry->update(['evidence' => $evidence]);
    }

    /**
     * @param  array{ip: string, unlock_at: ?Carbon, source_table: string}  $lockout
     */
    private function reasonText(array $lockout): string
    {
        $unlock = $lockout['unlock_at']?->diffForHumans() ?? 'no expiry recorded';

        return "Locked out by Limit Login Attempts Reloaded. Plugin lockout {$unlock}.";
    }
}
