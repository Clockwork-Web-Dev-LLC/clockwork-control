<?php

namespace Modules\AiRemedy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\AiRemedy\Models\AiRemedyRun;
use Modules\AiRemedy\Services\AiRemedyTriager;
use Modules\AiRemedy\Services\OpenRouterClient;
use Modules\AiRemedy\Services\RemedyExecutor;
use Modules\AiRemedy\Services\ServerTelemetryCollector;

class AiRemedyController extends Controller
{
    public function __construct(
        protected OpenRouterClient $client,
        protected ServerTelemetryCollector $collector,
        protected RemedyExecutor $executor,
    ) {}

    /**
     * Display the AiRemedy audit log dashboard.
     */
    public function index(Request $request): View
    {
        $query = AiRemedyRun::query()
            ->with(['server', 'site', 'user'])
            ->latest('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($serverId = $request->input('server_id')) {
            $query->where('server_id', (int) $serverId);
        }

        $runs = $query->paginate(20)->withQueryString();

        $stats = [
            'total' => AiRemedyRun::count(),
            'resolved' => AiRemedyRun::where('status', AiRemedyRun::STATUS_RESOLVED)->count(),
            'unfixable' => AiRemedyRun::where('status', AiRemedyRun::STATUS_UNFIXABLE)->count(),
            'total_cost' => (float) AiRemedyRun::sum('total_cost_usd'),
        ];

        $servers = Server::orderBy('name')->get(['id', 'name', 'hostname']);

        return view('ai-remedy::index', [
            'runs' => $runs,
            'stats' => $stats,
            'servers' => $servers,
            'activeStatus' => $status,
            'selectedServerId' => $serverId,
        ]);
    }

    /**
     * View detailed forensics for a specific run.
     */
    public function show(AiRemedyRun $run): View|JsonResponse
    {
        $run->load(['server', 'site', 'user']);

        if (request()->wantsJson()) {
            return response()->json(['ok' => true, 'run' => $run]);
        }

        return view('ai-remedy::show', ['run' => $run]);
    }

    /**
     * Trigger an on-demand AI diagnostic for a server performance spike.
     */
    public function diagnoseServer(Request $request, Server $server): JsonResponse
    {
        $reason = (string) ($request->input('reason') ?: 'Server performance spike detected');

        // Step 1: Gather SSH telemetry
        $telemetry = $this->collector->collect($server);
        if (! $telemetry['ok']) {
            return response()->json([
                'ok' => false,
                'message' => $telemetry['error'] ?? 'Failed to collect server telemetry.',
            ], 422);
        }

        // Step 2: OpenRouter AI Analysis
        $analysis = $this->client->diagnoseServerSpike($telemetry, $reason);

        // Step 3: Record initial audit log run
        $status = $analysis['is_fixable'] ? AiRemedyRun::STATUS_ANALYZED : AiRemedyRun::STATUS_UNFIXABLE;

        $run = AiRemedyRun::create([
            'trigger_type' => AiRemedyRun::TRIGGER_SERVER_SPIKE,
            'status' => $status,
            'server_id' => $server->id,
            'user_id' => $request->user()?->id,
            'actor' => 'manual',
            'model_used' => $this->client->getModel(),
            'prompt_tokens' => $analysis['prompt_tokens'],
            'completion_tokens' => $analysis['completion_tokens'],
            'total_cost_usd' => $analysis['cost_usd'],
            'trigger_reason' => $reason,
            'telemetry_snapshot' => $telemetry,
            'diagnosis_summary' => $analysis['summary'],
            'root_cause' => $analysis['root_cause'],
            'safety_tier' => $analysis['safety_tier'],
            'proposed_commands' => $analysis['commands'],
            'before_metrics' => $telemetry,
            'started_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'run' => $run->fresh(),
            'analysis' => $analysis,
            'telemetry' => $telemetry,
        ]);
    }

    /**
     * Execute approved remediation commands on a server.
     */
    public function execute(Request $request, AiRemedyRun $run): JsonResponse
    {
        $customCommands = $request->input('commands');
        if (is_array($customCommands)) {
            $customCommands = array_values(array_filter($customCommands));
        } else {
            $customCommands = null;
        }

        $result = $this->executor->execute($run, $customCommands);

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => $result['error'] ?? 'Execution failed.',
                'run' => $result['run'],
                'output' => $result['output'],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Remediation commands executed successfully.',
            'run' => $result['run'],
            'output' => $result['output'],
        ]);
    }

    /**
     * Run a safe diagnostic simulation in Watch Mode (zero commands executed).
     */
    public function simulateServer(Request $request, AiRemedyTriager $triager): JsonResponse
    {
        $validated = $request->validate([
            'server_id' => 'required|exists:servers,id',
            'scenario' => 'nullable|string|max:255',
        ]);

        $server = Server::findOrFail($validated['server_id']);
        $scenario = (string) ($validated['scenario'] ?? 'Simulated Server Load & Service Spike');

        $result = $triager->triageServerSpike(
            server: $server,
            reason: $scenario,
            actor: 'simulation',
            isSimulation: true,
        );

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => $result['error'] ?? 'Simulation failed to run.',
                'telemetry' => $result['telemetry'] ?? null,
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Simulation completed safely in Watch Mode. Zero commands executed.',
            'run' => $result['run'],
            'analysis' => $result['analysis'],
            'telemetry' => $result['telemetry'],
        ]);
    }

    /**
     * Test OpenRouter connection.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $key = $request->input('api_key');
        $result = $this->client->testConnection($key);

        return response()->json($result);
    }
}
