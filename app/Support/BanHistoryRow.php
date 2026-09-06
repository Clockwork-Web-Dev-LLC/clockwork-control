<?php

namespace App\Support;

use App\Models\BlockedIp;
use App\Models\ReviewQueueEntry;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Normalises a row from `review_queue` (decided entry) OR `blocked_ips` (unbanned)
 * into a single shape so the merged History tab on /bans can render both kinds in
 * one chronological list without conditional cell logic in the Blade template.
 *
 * The two source tables are deliberately not FK-linked (see CLAUDE.md decision
 * around review_queue → blocked_ips) so this DTO is the merge point, not a join.
 *
 * Pull last N of each, hand them through fromReviewEntry() / fromBlockedIp(),
 * sort the resulting collection desc by `when`, slice. See BansController::history().
 */
class BanHistoryRow
{
    /** @var array<string, mixed> */
    public array $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromReviewEntry(ReviewQueueEntry $entry): self
    {
        return new self([
            'when' => $entry->decided_at,
            'kind' => match ($entry->status) {
                ReviewQueueEntry::STATUS_APPROVED => 'approved',
                ReviewQueueEntry::STATUS_DISMISSED => 'dismissed',
                ReviewQueueEntry::STATUS_FAILED => 'failed',
                default => 'unknown',
            },
            'ip' => (string) $entry->ip,
            'server' => $entry->server,
            'site' => $entry->site,
            'actor' => (string) ($entry->decided_by ?: '—'),
            'source' => (string) $entry->source,
            'note' => (string) ($entry->reason ?? ''),
        ]);
    }

    public static function fromBlockedIp(BlockedIp $ban): self
    {
        return new self([
            'when' => $ban->unbanned_at,
            'kind' => 'unbanned',
            'ip' => (string) $ban->ip,
            'server' => $ban->server,
            'site' => $ban->site,
            'actor' => 'manual',
            'source' => (string) $ban->source,
            'note' => (string) ($ban->reason ?? ''),
        ]);
    }

    public function when(): ?Carbon
    {
        return $this->data['when'] ?? null;
    }

    public function kind(): string
    {
        return $this->data['kind'];
    }

    public function ip(): string
    {
        return $this->data['ip'];
    }

    public function server(): ?Server
    {
        return $this->data['server'] ?? null;
    }

    public function site(): ?Site
    {
        return $this->data['site'] ?? null;
    }

    public function actor(): string
    {
        return $this->data['actor'];
    }

    public function source(): string
    {
        return $this->data['source'];
    }

    public function note(): string
    {
        return $this->data['note'];
    }
}
