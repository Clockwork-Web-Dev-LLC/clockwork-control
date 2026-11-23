<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Seo\Models\AiImpressionEntry;

/**
 * The typed AI impressions form on the profile page, for anyone not using
 * Claude. The Generative AI report in Search Console has no API.
 */
class AiImpressionsController extends Controller
{
    public function store(Request $request, Site $site): RedirectResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
            'status' => ['required', 'in:entered,not_available'],
            'ai_impressions' => ['nullable', 'required_if:status,entered', 'integer', 'min:0', 'max:4000000000'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $notAvailable = $validated['status'] === AiImpressionEntry::STATUS_NOT_AVAILABLE;

        AiImpressionEntry::query()->updateOrCreate(
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

        return back()->with('status', 'AI impressions for '.$validated['month'].' saved.');
    }
}
