<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\Models\TrackedKeyword;
use Modules\Seo\Ranks\RankSettings;

/**
 * The DataForSEO card on /seo/settings: spending caps and a connection
 * test. Admin only. The login and password themselves are entered on
 * Settings → Integrations, where they are stored encrypted.
 */
class DataForSeoSettingsController extends Controller
{
    public function updateBudget(Request $request, BudgetGuard $budget): RedirectResponse
    {
        $rules = [
            'total_cap' => ['required', 'numeric', 'min:0', 'max:5000'],
            'person_allowance' => ['required', 'numeric', 'min:0', 'max:5000'],
            'caps' => ['required', 'array'],
        ];
        foreach (array_keys(Feature::ALL) as $feature) {
            $rules["caps.{$feature}"] = ['required', 'numeric', 'min:0', 'max:5000'];
        }

        $validated = $request->validate($rules);

        $budget->save([
            'caps' => $validated['caps'],
            'total_cap' => $validated['total_cap'],
            'person_allowance' => $validated['person_allowance'],
        ]);

        $sum = array_sum(array_map('floatval', $validated['caps']));
        $message = 'Spending caps saved.';
        if ($sum > (float) $validated['total_cap']) {
            // Allowed on purpose: the total is the real ceiling. Say so.
            $message .= sprintf(' The feature caps add up to $%.2f, more than the $%.2f total, so the total is what stops spending first.', $sum, (float) $validated['total_cap']);
        }

        return redirect()->route('seo.settings')->with('status', $message);
    }

    /** How often and how deep ranks are checked: the two choices that set most of the bill. */
    public function updateRankTracking(Request $request, RankSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'frequency' => ['required', 'string', 'in:'.implode(',', array_keys(RankSettings::FREQUENCIES))],
            'depth' => ['required', 'integer', 'in:'.implode(',', RankSettings::DEPTHS)],
        ]);

        $settings->save($validated['frequency'], (int) $validated['depth']);

        $keywords = TrackedKeyword::query()->where('is_active', true)->count();

        return redirect()->route('seo.settings')->with('status', sprintf(
            'Rank tracking set to %s, top %d. With %d tracked %s that is about $%.2f a month.',
            $validated['frequency'], (int) $validated['depth'], $keywords, $keywords === 1 ? 'keyword' : 'keywords', $settings->monthlyEstimate($keywords),
        ));
    }

    /** One free call that proves the login works and reads the balance. */
    public function test(DataForSeoClient $client): RedirectResponse
    {
        if (! $client->isConfigured()) {
            return redirect()->route('seo.settings')
                ->with('warning', 'Add the DataForSEO API login and password under SEO on Settings → Integrations first.');
        }

        $account = $client->account();

        if (! $account['ok']) {
            return redirect()->route('seo.settings')->with('warning', 'DataForSEO test failed: '.$account['error']);
        }

        return redirect()->route('seo.settings')->with('status', sprintf(
            'DataForSEO is connected as %s%s.%s',
            $account['login'] ?? 'unknown',
            $account['balance'] !== null ? sprintf(', balance $%.2f', $account['balance']) : '',
            $client->usesSandbox() ? ' This is the free sandbox: the data is sample data.' : '',
        ));
    }
}
