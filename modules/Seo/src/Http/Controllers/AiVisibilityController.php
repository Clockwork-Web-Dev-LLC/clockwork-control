<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Seo\Ai\AiEngines;
use Modules\Seo\Ai\AiPrompts;
use Modules\Seo\Ai\AiVisibilityReport;
use Modules\Seo\Ai\AiVisibilitySettings;
use Modules\Seo\Ai\AiVisibilityTracker;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\Models\AiCheck;
use Modules\Seo\Models\AiPrompt;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\BrandRegex;

/**
 * /seo/sites/{site}/ai-visibility: how often a client comes up when AI
 * assistants are asked its tracked prompts, and the forms to manage the
 * prompts. Shows stored answers only; the hourly command does the asking.
 */
class AiVisibilityController extends Controller
{
    public function show(Request $request, Site $site, AiVisibilityReport $report, AiVisibilitySettings $settings, AiVisibilityTracker $tracker, DataForSeoClient $client): View
    {
        $profile = SiteProfile::query()->where('site_id', $site->id)->first();
        $data = $report->forSite($site->id);

        $selected = $request->filled('prompt') ? AiPrompt::query()->where('site_id', $site->id)->find((int) $request->query('prompt')) : null;

        return view('seo::sites.ai-visibility', [
            'site' => $site,
            'report' => $data,
            'engines' => AiEngines::ALL,
            'activeEngines' => $settings->engines(),
            'frequency' => $settings->frequency(),
            'monthlyEstimate' => $settings->monthlyEstimate($data['active']),
            'perPromptMonthly' => $settings->monthlyEstimate(1000) / 1000,
            'brandTerms' => BrandRegex::parseTerms($profile?->brand_terms),
            'competitors' => array_values(array_filter((array) ($profile->competitors ?? []), 'is_string')),
            'configured' => $client->isConfigured(),
            'pending' => $tracker->pending(),
            'max' => AiVisibilitySettings::MAX_PROMPTS_PER_SITE,
            'selected' => $selected,
            'answers' => $selected !== null
                ? AiCheck::query()->where('prompt_id', $selected->id)->orderByDesc('checked_on')->orderBy('engine')->limit(12)->get()
                : collect(),
        ]);
    }

    public function store(Request $request, Site $site, AiPrompts $prompts): RedirectResponse
    {
        if (! SiteProfile::query()->where('site_id', $site->id)->exists()) {
            return redirect()->route('seo.sites.profile', $site)->with('warning', 'Save an SEO profile for this site before tracking prompts.');
        }

        $validated = $request->validate(['prompts' => ['required', 'string', 'max:30000']]);
        $list = array_values(array_filter(array_map('trim', preg_split('/\R/u', $validated['prompts']) ?: [])));
        $result = $prompts->add($site, $list, $request->user()?->id);

        $message = sprintf('%d %s added.', $result['added'], $result['added'] === 1 ? 'prompt' : 'prompts');
        if ($result['existing'] > 0) {
            $message .= " {$result['existing']} already tracked.";
        }
        if ($result['added'] > 0) {
            $message .= ' The first answers arrive within a couple of hours.';
        }

        $redirect = redirect()->route('seo.sites.ai-visibility', $site)->with('status', $message);
        if ($result['rejected'] !== []) {
            $redirect->with('warning', 'Not added: '.implode('; ', array_map(fn (array $r) => "{$r['prompt']} ({$r['reason']})", array_slice($result['rejected'], 0, 8))).'.');
        }

        return $redirect;
    }

    /** Pause or resume one prompt. A paused prompt keeps its history and costs nothing. */
    public function toggle(Site $site, AiPrompt $prompt): RedirectResponse
    {
        abort_unless($prompt->site_id === $site->id, 404);
        $back = redirect()->route('seo.sites.ai-visibility', $site);

        if (! $prompt->is_active
            && AiPrompt::query()->where('site_id', $site->id)->where('is_active', true)->count() >= AiVisibilitySettings::MAX_PROMPTS_PER_SITE) {
            return $back->with('warning', 'This client already has '.AiVisibilitySettings::MAX_PROMPTS_PER_SITE.' active prompts. Pause another first.');
        }

        $prompt->forceFill(['is_active' => ! $prompt->is_active])->save();

        return $back->with('status', $prompt->is_active ? 'Prompt is being asked again.' : 'Prompt paused. Its history is kept.');
    }

    /** Remove a prompt and its stored answers for good. */
    public function destroy(Site $site, AiPrompt $prompt): RedirectResponse
    {
        abort_unless($prompt->site_id === $site->id, 404);
        $prompt->delete();

        return redirect()->route('seo.sites.ai-visibility', $site)->with('status', 'Prompt and its stored answers were deleted.');
    }

    /** How often prompts are asked and of which engines: what AI visibility costs. */
    public function updateSettings(Request $request, AiVisibilitySettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'frequency' => ['required', 'string', 'in:'.implode(',', array_keys(AiVisibilitySettings::FREQUENCIES))],
            'engines' => ['nullable', 'array'],
            'engines.*' => ['string', 'in:'.implode(',', array_keys(AiEngines::ALL))],
        ]);

        $settings->save($validated['frequency'], $validated['engines'] ?? []);
        $prompts = AiPrompt::query()->where('is_active', true)->count();

        return redirect()->route('seo.settings')->with('status', sprintf(
            'AI visibility set to %s on %s. With %d tracked %s that is about $%.2f a month.',
            $validated['frequency'],
            implode(', ', array_map([AiEngines::class, 'label'], $settings->engines())) ?: 'no engine',
            $prompts, $prompts === 1 ? 'prompt' : 'prompts', $settings->monthlyEstimate($prompts),
        ));
    }
}
