<?php

namespace Modules\Feedback\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modules\Feedback\Models\FeedbackItem;
use Modules\Feedback\Services\FeedbackPromptBuilder;

class GenerateFeedbackPromptCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clockwork:feedback-prompt
                            {--status=approved : Feedback item status to bundle (default: approved)}
                            {--mark-in-progress : Automatically update bundled items to in_progress}
                            {--mark-resolved : Automatically update bundled items to resolved}
                            {--output= : Optional custom file path to write markdown prompt to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a structured Claude/AI prompt bundling approved feedback items for implementation.';

    public function handle(FeedbackPromptBuilder $builder): int
    {
        $status = (string) ($this->option('status') ?: 'approved');
        $items = FeedbackItem::query()
            ->where('status', $status)
            ->with(['user', 'comments.user'])
            ->orderBy('id', 'asc')
            ->get();

        if ($items->isEmpty()) {
            $this->warn("No feedback items found with status '{$status}'.");

            return Command::SUCCESS;
        }

        $prompt = $builder->buildForBatch($items);

        // Ensure default prompt directory exists in storage
        $defaultDir = storage_path('app/prompts');
        if (! File::isDirectory($defaultDir)) {
            File::makeDirectory($defaultDir, 0755, true);
        }

        $defaultFile = $defaultDir.'/latest-feedback-prompt.md';
        File::put($defaultFile, $prompt);

        if ($customPath = $this->option('output')) {
            $customDir = dirname((string) $customPath);
            if (! File::isDirectory($customDir)) {
                File::makeDirectory($customDir, 0755, true);
            }
            File::put((string) $customPath, $prompt);
            $this->info("Prompt saved to: {$customPath}");
        }

        $this->info("Prompt successfully generated for {$items->count()} '{$status}' items.");
        $this->line("Saved to: <comment>{$defaultFile}</comment>");

        if ($this->option('mark-in-progress')) {
            FeedbackItem::whereIn('id', $items->pluck('id'))->update(['status' => FeedbackItem::STATUS_IN_PROGRESS]);
            $this->info("Updated {$items->count()} items to 'in_progress'.");
        }

        if ($this->option('mark-resolved')) {
            FeedbackItem::whereIn('id', $items->pluck('id'))->update(['status' => FeedbackItem::STATUS_RESOLVED]);
            $this->info("Updated {$items->count()} items to 'resolved'.");
        }

        return Command::SUCCESS;
    }
}
