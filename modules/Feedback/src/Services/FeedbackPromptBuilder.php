<?php

namespace Modules\Feedback\Services;

use Illuminate\Support\Collection;
use Modules\Feedback\Models\FeedbackItem;

class FeedbackPromptBuilder
{
    /**
     * Build a structured prompt for a single feedback item.
     */
    public function buildForSingle(FeedbackItem $item): string
    {
        $prompt = "## Task: Address feedback reported on Clockwork Control\n\n";

        $authorName = $item->user ? $item->user->name : 'Team Member';
        $authorEmail = $item->user ? $item->user->email : 'unknown';
        $prompt .= "- **Reported by**: {$authorName} ({$authorEmail})\n";
        $prompt .= '- **Category**: '.$item->formattedType()."\n";
        $prompt .= '- **Status**: '.ucfirst(str_replace('_', ' ', $item->status))."\n";
        $prompt .= '- **Date**: '.$item->created_at->format('M j, Y g:i A')."\n";
        $prompt .= '- **Title**: '.$item->title."\n";
        $prompt .= '- **Description**: '.$item->content."\n\n";

        if ($item->comments->isNotEmpty()) {
            $prompt .= "### Discussion Thread\n";
            foreach ($item->comments as $comment) {
                $author = $comment->user ? $comment->user->name : 'Team Member';
                $time = $comment->created_at->format('M j, g:i A');
                $prompt .= "- **{$author}** ({$time}): \"{$comment->content}\"\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "### Page & Code Context\n";
        $prompt .= '- **URL**: '.$item->url."\n";
        if ($item->route_name) {
            $prompt .= '- **Route**: '.$item->route_name."\n";
        }
        if ($item->controller_action) {
            $prompt .= '- **Controller Action**: '.$item->controller_action."\n";
        }
        if ($item->view_name) {
            $prompt .= '- **Blade Template**: '.$item->view_name."\n";
        }
        if ($item->selector) {
            $prompt .= '- **Target Element Selector**: `'.$item->selector."`\n";
        }
        if ($item->element_text) {
            $prompt .= '- **Element Text Snippet**: "'.addcslashes($item->element_text, '"')."\"\n";
        }
        if ($item->viewport_width && $item->viewport_height) {
            $prompt .= "- **Viewport Dimensions**: {$item->viewport_width}x{$item->viewport_height}\n";
        }
        if (is_array($item->metadata)) {
            if (! empty($item->metadata['theme'])) {
                $prompt .= '- **Theme Mode**: '.$item->metadata['theme']."\n";
            }
            if (! empty($item->metadata['site_id'])) {
                $prompt .= '- **Site ID**: '.$item->metadata['site_id']."\n";
            }
            if (! empty($item->metadata['server_id'])) {
                $prompt .= '- **Server ID**: '.$item->metadata['server_id']."\n";
            }
        }

        $prompt .= "\n### Implementation Instructions\n";
        $prompt .= "1. Inspect the relevant Blade view or controller action identified above.\n";
        $prompt .= "2. Address the issue or requested change according to the user report and any discussion thread consensus.\n";
        $prompt .= "3. Adhere to Clockwork design guidelines: maintain CSS tokens/variables, avoid adding Tailwind utility classes, ensure dark/light mode compatibility, and run `composer check` and Pest tests to verify.\n";

        return $prompt;
    }

    /**
     * Build an aggregated master prompt for a batch of approved feedback items.
     *
     * @param  iterable<FeedbackItem>  $items
     */
    public function buildForBatch(iterable $items, ?string $title = null): string
    {
        $collection = $items instanceof Collection ? $items : collect($items);
        $title = $title ?? 'Implement Approved Feedback & Feature Requests';
        $now = now()->format('Y-m-d H:i T');

        $prompt = "# Task: {$title}\n\n";
        $prompt .= "The following **{$collection->count()}** feedback item(s) have been reviewed, discussed, and **Approved** by the team for implementation in Clockwork Control.\n\n";

        // Executive Summary
        $prompt .= "## Executive Summary & Batch Breakdown\n";
        $prompt .= "- **Generated At**: {$now}\n";
        $prompt .= "- **Total Approved Tasks**: {$collection->count()}\n";

        $byType = $collection->groupBy('type');
        $typeBreakdown = [];
        foreach ($byType as $type => $group) {
            $typeBreakdown[] = $group->count().' '.(match ($type) {
                FeedbackItem::TYPE_BUG => 'Bug(s)',
                FeedbackItem::TYPE_FEATURE => 'Feature(s)',
                FeedbackItem::TYPE_COPY => 'Copy/Content Change(s)',
                default => 'UI Tweak(s)',
            });
        }
        $prompt .= '- **Breakdown**: '.implode(', ', $typeBreakdown)."\n\n";

        // Quick Index
        $prompt .= "### Task Index\n";
        $index = 1;
        foreach ($collection as $item) {
            $typeLabel = strtoupper($item->type);
            $prompt .= "{$index}. **[{$typeLabel}]** {$item->title} (`{$item->path}`)\n";
            $index++;
        }
        $prompt .= "\n---\n\n";

        // Detailed Per-Item Specifications
        $itemNum = 1;
        foreach ($collection as $item) {
            $authorName = $item->user ? $item->user->name : 'Team Member';
            $authorEmail = $item->user ? $item->user->email : 'unknown';
            $typeLabel = $item->formattedType();

            $prompt .= "## Task #{$itemNum}: {$item->title}\n\n";
            $prompt .= "- **Category**: {$typeLabel}\n";
            $prompt .= "- **Reported by**: {$authorName} ({$authorEmail})\n";
            $prompt .= "- **Logged At**: {$item->created_at->format('M j, Y g:i A')}\n";
            $prompt .= '- **Status**: '.ucfirst($item->status)."\n";
            $prompt .= "- **Target Screen URL**: {$item->url}\n";

            if ($item->route_name) {
                $prompt .= "- **Route**: `{$item->route_name}`\n";
            }
            if ($item->controller_action) {
                $prompt .= "- **Controller Action**: `{$item->controller_action}`\n";
            }
            if ($item->view_name) {
                $prompt .= "- **Blade Template**: `{$item->view_name}`\n";
            }
            if ($item->selector) {
                $prompt .= "- **DOM Target Selector**: `{$item->selector}`\n";
            }
            if ($item->element_text) {
                $prompt .= '- **Element Text Snippet**: "'.addcslashes($item->element_text, '"')."\"\n";
            }
            if ($item->viewport_width && $item->viewport_height) {
                $prompt .= "- **Viewport**: {$item->viewport_width}x{$item->viewport_height}\n";
            }

            $prompt .= "\n### Request Description\n";
            $prompt .= "{$item->content}\n\n";

            if ($item->comments->isNotEmpty()) {
                $prompt .= "### Discussion Transcript\n";
                foreach ($item->comments as $comment) {
                    $cAuthor = $comment->user ? $comment->user->name : 'Team Member';
                    $cTime = $comment->created_at->format('M j, g:i A');
                    $prompt .= "- **{$cAuthor}** ({$cTime}): \"{$comment->content}\"\n";
                }
                $prompt .= "\n";
            }

            $prompt .= "---\n\n";
            $itemNum++;
        }

        // Global Architectural & Quality Requirements for Claude
        $prompt .= "## Implementation Instructions & Constraints for Claude\n\n";
        $prompt .= "1. **Work Systematically**: Implement each task in sequence. Where multiple items affect the same view or controller, coordinate edits cleanly.\n";
        $prompt .= "2. **Design System & Aesthetics**: Follow Clockwork Control conventions: use CSS custom properties (`--color-surface`, `--color-brand`, `--color-ink`, etc.), maintain Tailwind CSS v4 custom theme tokens, and avoid injecting raw Tailwind utility color classes.\n";
        $prompt .= "3. **Responsive & Accessible**: Ensure buttons have descriptive accessible titles, table cells align cleanly across mobile and desktop, and components look sharp in both dark and light modes.\n";
        $prompt .= "4. **Verification**: Run `composer check` (Pint, PHPStan, Biome, TypeScript) and relevant Pest test suites (`php artisan test`) to ensure zero regressions.\n";

        return $prompt;
    }
}
