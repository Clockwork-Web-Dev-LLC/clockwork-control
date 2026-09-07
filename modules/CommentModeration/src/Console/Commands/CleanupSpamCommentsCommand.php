<?php

namespace Modules\CommentModeration\Console\Commands;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class CleanupSpamCommentsCommand extends Command
{
    protected $signature = 'clockwork:cleanup-spam-comments
        {--site= : Limit to a single site (id or domain)}
        {--days=30 : Purge spam and trash comments older than this many days (default: 30)}';

    protected $description = 'Automatically purge spam and trash comments older than N days from all Companion-equipped WordPress sites.';

    public function handle(ActionLogger $logger): int
    {
        $days = max(0, (int) $this->option('days'));
        $sites = $this->resolveSites();

        if ($sites->isEmpty()) {
            $this->warn('No eligible Companion-equipped sites found.');

            return self::SUCCESS;
        }

        $this->info("Purging spam and trash comments older than {$days} days across {$sites->count()} site(s)...");

        $totalPurgedSpam = 0;
        $totalPurgedTrash = 0;
        $successCount = 0;
        $failCount = 0;

        foreach ($sites as $site) {
            $caps = $site->companion_capabilities ?? [];
            if (! in_array('comments-moderation', $caps, true)) {
                $this->line("  - {$site->domain}: skipped (missing comments-moderation capability)");

                continue;
            }

            try {
                $client = new ClockworkCompanionClient($site);
                $result = $client->cleanupComments($days);

                $purgedSpam = (int) ($result['purged_spam'] ?? 0);
                $purgedTrash = (int) ($result['purged_trash'] ?? 0);
                $totalPurged = (int) ($result['total_purged'] ?? ($purgedSpam + $purgedTrash));

                $totalPurgedSpam += $purgedSpam;
                $totalPurgedTrash += $purgedTrash;
                $successCount++;

                $logger->record(
                    actionType: ActionLog::TYPE_COMMENTS_CLEANUP,
                    summary: "Auto-cleaned {$totalPurged} comments ({$purgedSpam} spam, {$purgedTrash} trash) older than {$days} days on {$site->domain}.",
                    site: $site,
                    target: (string) $days,
                    ok: true,
                    details: $result,
                );

                $this->line("  ✓ {$site->domain}: purged {$totalPurged} (spam: {$purgedSpam}, trash: {$purgedTrash})");
            } catch (Throwable $e) {
                $failCount++;
                $this->error("  ✗ {$site->domain}: {$e->getMessage()}");

                $logger->record(
                    actionType: ActionLog::TYPE_COMMENTS_CLEANUP,
                    summary: "Auto-cleanup failed on {$site->domain}: {$e->getMessage()}",
                    site: $site,
                    target: (string) $days,
                    ok: false,
                    error: $e->getMessage(),
                );
            }
        }

        $this->newLine();
        $this->info("Completed. Sites processed: {$successCount}, Failed: {$failCount}. Total spam purged: {$totalPurgedSpam}, Total trash purged: {$totalPurgedTrash}.");

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>
     */
    private function resolveSites(): Collection
    {
        $query = Site::query()
            ->where('is_inactive', false)
            ->where('companion_installed', true);

        if ($target = $this->option('site')) {
            $query->where(function ($q) use ($target) {
                if (is_numeric($target)) {
                    $q->orWhere('id', (int) $target);
                }
                $q->orWhere('domain', $target);
            });
        }

        return $query->orderBy('domain')->get();
    }
}
