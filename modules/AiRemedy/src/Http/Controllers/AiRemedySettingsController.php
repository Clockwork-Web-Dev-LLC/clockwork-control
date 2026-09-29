<?php

namespace Modules\AiRemedy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\AiRemedy\Services\AiRemedyTriager;
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
        $currentMode = (string) $this->settings->get('clockwork.ai_remedy.mode', AiRemedyTriager::MODE_WATCH);

        $availableModels = [
            'anthropic/claude-3.5-sonnet' => 'Claude 3.5 Sonnet (Recommended - Best Systems & Code Reasoning)',
            'anthropic/claude-3.5-haiku' => 'Claude 3.5 Haiku (Fast & Ultra Cost-Effective)',
            'openai/gpt-4o' => 'OpenAI GPT-4o (High Performance General Intelligence)',
            'openai/gpt-4o-mini' => 'OpenAI GPT-4o Mini (Budget-Friendly Fast Model)',
            'deepseek/deepseek-chat' => 'DeepSeek-V3 (Affordable & High Quality)',
        ];

        $servers = Server::orderBy('name')->get(['id', 'name', 'hostname']);

        return view('ai-remedy::settings', [
            'hasKey' => $hasKey,
            'currentModel' => $currentModel,
            'currentMode' => $currentMode,
            'availableModels' => $availableModels,
            'servers' => $servers,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'openrouter_api_key' => 'nullable|string',
            'model' => 'required|string',
            'mode' => 'required|string|in:watch,interactive,auto_heal',
        ]);

        if ($request->filled('openrouter_api_key')) {
            $this->client->setApiKey($validated['openrouter_api_key']);
        }

        $this->settings->put('clockwork.ai_remedy.model', $validated['model']);
        $this->settings->put('clockwork.ai_remedy.mode', $validated['mode']);
        $this->settings->put('clockwork.ai_remedy.auto_heal', $validated['mode'] === AiRemedyTriager::MODE_AUTO_HEAL);

        return back()->with('status', 'AiRemedy settings updated successfully.');
    }
}
