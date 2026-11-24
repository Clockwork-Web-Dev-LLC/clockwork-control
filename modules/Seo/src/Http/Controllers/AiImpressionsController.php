<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Seo\Mcp\Support\AuditLog;
use Modules\Seo\Models\AiImpressionEntry;

/**
 * The typed AI impressions form on the profile page, for anyone not using
 * Claude. The Generative AI report in Search Console has no API.
 */
class AiImpressionsController extends Controller
{
    public function store(Request $request, Site $site, AuditLog $audit): RedirectResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
            'status' => ['required', 'in:entered,not_available'],
            'ai_impressions' => ['nullable', 'required_if:status,entered', 'integer', 'min:0', 'max:4000000000'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $notAvailable = $validated['status'] === AiImpressionEntry::STATUS_NOT_AVAILABLE;

        $existing = AiImpressionEntry::query()->where('site_id', $site->id)->where('month', $validated['month'].'-01')->first();
        $before = $existing !== null ? ['status' => $existing->status, 'ai_impressions' => $existing->ai_impressions, 'source' => $existing->source] : null;

        $entry = AiImpressionEntry::query()->updateOrCreate(
            ['site_id' => $site->id, 'month' => $validated['month'].'-01'],
            [
                'status' => $validated['status'],
                'ai_impressions' => $notAvailable ? null : (int) $validated['ai_impressions'],
                'top_pages' => null,
                'source' => 'manual',
                'note' => $validated['note'] ?? null,
                'entered_by_user_id' => $request->user()?->id,
            ],
        );

        $audit->page(AuditLog::AI_IMPRESSIONS_RECORDED, $request->user(), $site->domain, "Recorded AI impressions for {$site->domain}, {$validated['month']}: ".($notAvailable ? 'not available' : number_format((int) $entry->ai_impressions)).'.', ['ai_impressions' => [
            'before' => $before,
            'after' => ['status' => $entry->status, 'ai_impressions' => $entry->ai_impressions, 'source' => 'manual', 'month' => $validated['month']],
        ]]);

        return back()->with('status', 'AI impressions for '.$validated['month'].' saved.');
    }
}
