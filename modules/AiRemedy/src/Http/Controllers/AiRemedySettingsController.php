<?php

namespace Modules\AiRemedy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\AiRemedy\Services\AiRemedyTriager;
use Modules\AiRemedy\Services\CommandSafetyGuard;
use Modules\AiRemedy\Services\OpenRouterClient;

class AiRemedySettingsController extends Controller
{
    public function __construct(
        protected Settings $settings,
        protected OpenRouterClient $client,
        protected CommandSafetyGuard $guard,
    ) {}

    public function index(): View
    {
        $currentModel = $this->client->getModel();
        $hasKey = ! empty($this->client->getApiKey());
        $currentMode = (string) $this->settings->get('clockwork.ai_remedy.mode', AiRemedyTriager::MODE_WATCH);
        $autoTriageSpikes = (bool) $this->settings->get('clockwork.ai_remedy.auto_triage_spikes', true);
        $cpuSpikeThreshold = (int) $this->settings->get('clockwork.ai_remedy.cpu_spike_threshold', 85);
        $cooldownMinutes = (int) $this->settings->get('clockwork.ai_remedy.cooldown_minutes', 30);
        $autoMuteMaintenance = (bool) $this->settings->get('clockwork.ai_remedy.auto_mute_maintenance_alerts', true);

        $allowedProcesses = $this->settings->get('clockwork.ai_remedy.allowed_maintenance_processes');
        if (is_array($allowedProcesses)) {
            $allowedProcessesString = implode(', ', $allowedProcesses);
        } elseif (is_string($allowedProcesses) && trim($allowedProcesses) !== '') {
            $allowedProcessesString = $allowedProcesses;
        } else {
            $allowedProcessesString = 'rclone, mysqldump, logrotate, borgbackup, borg, gpbup, restic, duplicity';
        }

        $actionsCatalog = $this->guard->getActionsCatalog();
        $lockedGuards = CommandSafetyGuard::LOCKED_PROHIBITED;
        $currentTierRules = $this->guard->getTierRules();

        $availableModels = [
            'anthropic/claude-sonnet-4.5' => 'Claude Sonnet 4.5 (Recommended - Best Systems & Code Reasoning)',
            'anthropic/claude-haiku-4.5' => 'Claude Haiku 4.5 (Fast & Ultra Cost-Effective)',
            'anthropic/claude-sonnet-4' => 'Claude Sonnet 4 (Balanced Performance)',
            'openai/gpt-4o' => 'OpenAI GPT-4o (High Performance General Intelligence)',
            'openai/gpt-4o-mini' => 'OpenAI GPT-4o Mini (Budget-Friendly Fast Model)',
            'deepseek/deepseek-chat' => 'DeepSeek-V3 (Affordable & High Quality)',
        ];

        $servers = Server::orderBy('name')->get(['id', 'name', 'hostname']);

        return view('ai-remedy::settings', [
            'hasKey' => $hasKey,
            'currentModel' => $currentModel,
            'currentMode' => $currentMode,
            'autoTriageSpikes' => $autoTriageSpikes,
            'cpuSpikeThreshold' => $cpuSpikeThreshold,
            'cooldownMinutes' => $cooldownMinutes,
            'autoMuteMaintenance' => $autoMuteMaintenance,
            'allowedProcessesString' => $allowedProcessesString,
            'actionsCatalog' => $actionsCatalog,
            'lockedGuards' => $lockedGuards,
            'currentTierRules' => $currentTierRules,
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
            'auto_triage_spikes' => 'nullable|boolean',
            'cpu_spike_threshold' => 'required|integer|min:50|max:99',
            'cooldown_minutes' => 'required|integer|min:5|max:1440',
            'auto_mute_maintenance_alerts' => 'nullable|boolean',
            'allowed_maintenance_processes' => 'nullable|string',
            'safety_tier_rules' => 'nullable|string',
        ]);

        if ($request->filled('openrouter_api_key')) {
            $this->client->setApiKey($validated['openrouter_api_key']);
        }

        $this->settings->put('clockwork.ai_remedy.model', $validated['model']);
        $this->settings->put('clockwork.ai_remedy.mode', $validated['mode']);
        $this->settings->put('clockwork.ai_remedy.auto_heal', $validated['mode'] === AiRemedyTriager::MODE_AUTO_HEAL);
        $this->settings->put('clockwork.ai_remedy.auto_triage_spikes', $request->boolean('auto_triage_spikes'));
        $this->settings->put('clockwork.ai_remedy.cpu_spike_threshold', (int) $validated['cpu_spike_threshold']);
        $this->settings->put('clockwork.ai_remedy.cooldown_minutes', (int) $validated['cooldown_minutes']);
        $this->settings->put('clockwork.ai_remedy.auto_mute_maintenance_alerts', $request->boolean('auto_mute_maintenance_alerts'));

        if ($request->has('allowed_maintenance_processes')) {
            $rawProcesses = (string) $request->input('allowed_maintenance_processes');
            $processesList = array_values(array_filter(array_map('trim', explode(',', strtolower($rawProcesses)))));
            $this->settings->put('clockwork.ai_remedy.allowed_maintenance_processes', $processesList);
        }

        if ($request->filled('safety_tier_rules')) {
            $decodedRules = json_decode((string) $request->input('safety_tier_rules'), true);
            if (is_array($decodedRules)) {
                $sanitizedRules = [];
                $allowedTiers = [CommandSafetyGuard::TIER_1_SAFE, CommandSafetyGuard::TIER_2_CAUTIOUS, CommandSafetyGuard::TIER_3_PROHIBITED];
                foreach ($decodedRules as $actionKey => $tier) {
                    if (isset(CommandSafetyGuard::ACTIONS[$actionKey]) && in_array($tier, $allowedTiers, true)) {
                        $sanitizedRules[$actionKey] = $tier;
                    }
                }
                $this->settings->put('clockwork.ai_remedy.safety_tier_rules', $sanitizedRules);
            }
        }

        return back()->with('status', 'AiRemedy settings updated successfully.');
    }
}
