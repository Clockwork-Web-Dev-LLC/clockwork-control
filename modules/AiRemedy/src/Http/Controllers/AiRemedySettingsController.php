<?php

namespace Modules\AiRemedy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\AiRemedy\Services\OpenRouterClient;

class AiRemedySettingsController extends Controller
{
    public function __construct(
        protected Settings $settings,
        protected OpenRouterClient $client,
    ) {}

    public function index(): View
    {
        $currentModel = $this->client->getModel();
        $hasKey = ! empty($this->client->getApiKey());
        $autoHeal = (bool) $this->settings->get('clockwork.ai_remedy.auto_heal', false);

        $availableModels = [
            'anthropic/claude-3.5-sonnet' => 'Claude 3.5 Sonnet (Recommended - Best Systems & Code Reasoning)',
            'anthropic/claude-3.5-haiku' => 'Claude 3.5 Haiku (Fast & Ultra Cost-Effective)',
            'openai/gpt-4o' => 'OpenAI GPT-4o (High Performance General Intelligence)',
            'openai/gpt-4o-mini' => 'OpenAI GPT-4o Mini (Budget-Friendly Fast Model)',
            'deepseek/deepseek-chat' => 'DeepSeek-V3 (Affordable & High Quality)',
        ];

        return view('ai-remedy::settings', [
            'hasKey' => $hasKey,
            'currentModel' => $currentModel,
            'autoHeal' => $autoHeal,
            'availableModels' => $availableModels,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'openrouter_api_key' => 'nullable|string',
            'model' => 'required|string',
            'auto_heal' => 'nullable|boolean',
        ]);

        if ($request->filled('openrouter_api_key')) {
            $this->client->setApiKey($validated['openrouter_api_key']);
        }

        $this->settings->put('clockwork.ai_remedy.model', $validated['model']);
        $this->settings->put('clockwork.ai_remedy.auto_heal', $request->boolean('auto_heal'));

        return back()->with('status', 'AiRemedy settings updated successfully.');
    }
}
