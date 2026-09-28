<?php

namespace Modules\Feedback\Console\Commands;

use Illuminate\Console\Command;
use Modules\Feedback\Models\FeedbackItem;

class ResolveFeedbackCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clockwork:feedback-resolve
                            {--approved : Mark all approved feedback items as resolved}
                            {--id=* : Specific feedback item ID(s) to mark as resolved}
                            {--all : Mark all active (open, approved, in_progress) feedback items as resolved}
                            {--dry-run : Preview items that would be resolved without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark feedback items as resolved so they disappear from the live screen overlay.';

    public function handle(): int
    {
        $ids = array_filter((array) $this->option('id'));
        $query = FeedbackItem::query();

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        } elseif ($this->option('approved')) {
            $query->where('status', FeedbackItem::STATUS_APPROVED);
        } elseif ($this->option('all')) {
            $query->active();
        } else {
            $this->error('Please specify --approved, --all, or at least one --id=<number>.');

            return Command::FAILURE;
        }

        $items = $query->orderBy('id', 'asc')->get();

        if ($items->isEmpty()) {
            $this->info('No matching feedback items found.');

            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Status', 'Type', 'Title', 'Path'],
            $items->map(fn (FeedbackItem $item) => [
                $item->id,
                $item->status,
                $item->type,
                $item->title,
                $item->path,
            ])
        );

        if ($this->option('dry-run')) {
            $this->warn("Dry run mode: {$items->count()} item(s) would be marked as 'resolved'. No changes made.");

            return Command::SUCCESS;
        }

        FeedbackItem::whereIn('id', $items->pluck('id'))->update(['status' => FeedbackItem::STATUS_RESOLVED]);

        $this->info("Successfully marked {$items->count()} feedback item(s) as resolved.");
        $this->line('These items will no longer render as pins on the live page overlay.');

        return Command::SUCCESS;
    }
}
