<?php

namespace Modules\AiRemedy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Server;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\AiRemedy\Models\AiRemedyRun;
use Modules\AiRemedy\Services\AiRemedyTriager;
use Modules\AiRemedy\Services\CommandSafetyGuard;
use Modules\AiRemedy\Services\OpenRouterClient;
use Modules\AiRemedy\Services\RemedyExecutor;
use Modules\AiRemedy\Services\RunApprovalPolicy;
use Modules\AiRemedy\Services\ServerSiteContext;
use Modules\AiRemedy\Services\ServerTelemetryCollector;

class AiRemedyController extends Controller
{
    public function __construct(
        protected OpenRouterClient $client,
        protected ServerTelemetryCollector $collector,
        protected RemedyExecutor $executor,
        protected RunApprovalPolicy $policy,
    ) {}

    /**
     * Display the AiRemedy audit log dashboard.
     */
    public function index(Request $request): View
    {
        $showHidden = $request->boolean('hidden');

        $query = AiRemedyRun::query()
            ->with(['server', 'site', 'user'])
            ->when($showHidden, fn ($q) => $q->whereNotNull('hidden_at'), fn ($q) => $q->whereNull('hidden_at'))
            ->latest('id');

        if ($status = $request->input('status')) {
            if ($status === 'needs_review') {
                $query->where('actor', 'interactive')
                    ->whereIn('status', [AiRemedyRun::STATUS_ANALYZED, AiRemedyRun::STATUS_PENDING]);
            } else {
                $query->where('status', $status);
            }
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

        $reviews = [];
        foreach ($runs as $run) {
            $reviews[$run->id] = $this->policy->review($run, $request->user());
        }

        $needsReviewCount = AiRemedyRun::query()
            ->where('actor', 'interactive')
            ->whereIn('status', [AiRemedyRun::STATUS_ANALYZED, AiRemedyRun::STATUS_PENDING])
            ->whereNull('hidden_at')
            ->count();

        return view('ai-remedy::index', [
            'runs' => $runs,
            'reviews' => $reviews,
            'stats' => $stats,
            'servers' => $servers,
            'activeStatus' => $status,
            'selectedServerId' => $serverId,
            'showHidden' => $showHidden,
            'hiddenCount' => AiRemedyRun::whereNotNull('hidden_at')->count(),
            'needsReviewCount' => $needsReviewCount,
        ]);
    }

    /**
     * Delete selected runs from the incident log. A run that is executing
     * right now is kept. Executed fixes keep their immutable `ai_remediation`
     * action-log entry, so deleting a run never erases what ran on a server.
     */
    public function destroyMany(Request $request, ActionLogger $actionLogger): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $runs = AiRemedyRun::query()->whereIn('id', $validated['ids'])->get(['id', 'status', 'server_id']);
        $executing = $runs->where('status', AiRemedyRun::STATUS_EXECUTING);
        $deletable = $runs->reject(fn (AiRemedyRun $run) => $run->status === AiRemedyRun::STATUS_EXECUTING);
        $ids = $deletable->pluck('id')->all();

        if ($ids !== []) {
            AiRemedyRun::query()->whereIn('id', $ids)->delete();

            $actionLogger->record(
                actionType: 'ai_remedy_runs_deleted',
                summary: 'Deleted '.count($ids).' AiRemedy incident run'.(count($ids) === 1 ? '' : 's'),
                target: 'ai-remedy',
                details: ['run_ids' => $ids, 'user_id' => $request->user()?->id],
            );
        }

        $message = count($ids).' run'.(count($ids) === 1 ? '' : 's').' deleted.';
        if ($executing->isNotEmpty()) {
            $message .= ' '.$executing->count().' still executing '.($executing->count() === 1 ? 'was' : 'were').' kept.';
        }

        return redirect()->back(fallback: route('ai-remedy.index'))->with('status', $message);
    }

    /**
     * Hide selected runs from the incident log without deleting them.
     */
    public function hideMany(Request $request): RedirectResponse
    {
        $ids = $this->validatedRunIds($request);
        $count = AiRemedyRun::query()->whereIn('id', $ids)->whereNull('hidden_at')
            ->update(['hidden_at' => now(), 'hidden_by_user_id' => $request->user()?->id]);

        return redirect()->back(fallback: route('ai-remedy.index'))
            ->with('status', $count.' run'.($count === 1 ? '' : 's').' hidden. See the Hidden tab to bring them back.');
    }

    /**
     * Put hidden runs back in the incident log.
     */
    public function unhideMany(Request $request): RedirectResponse
    {
        $ids = $this->validatedRunIds($request);
        $count = AiRemedyRun::query()->whereIn('id', $ids)->whereNotNull('hidden_at')
            ->update(['hidden_at' => null, 'hidden_by_user_id' => null]);

        return redirect()->back(fallback: route('ai-remedy.index', ['hidden' => 1]))
            ->with('status', $count.' run'.($count === 1 ? '' : 's').' restored to the incident log.');
    }

    /**
     * @return list<int>
     */
    protected function validatedRunIds(Request $request): array
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        return array_values(array_map('intval', $validated['ids']));
    }

    /**
     * View detailed forensics for a specific run.
     */
    public function show(AiRemedyRun $run): View|JsonResponse
    {
        $run->load(['server', 'site', 'user']);
        $review = $this->policy->review($run, request()->user());

        if (request()->wantsJson()) {
            return response()->json(['ok' => true, 'run' => $run, 'review' => $review]);
        }

        return view('ai-remedy::show', ['run' => $run, 'review' => $review]);
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

        // Step 2: OpenRouter AI Analysis — include the real sites so the model
        // never has to guess docroots or SSH users.
        $telemetry['wordpress_sites'] = app(ServerSiteContext::class)->sites($server);
        $analysis = $this->client->diagnoseServerSpike($telemetry, $reason);

        // Step 3: Record initial audit log run
        $status = $analysis['is_fixable'] ? AiRemedyRun::STATUS_ANALYZED : AiRemedyRun::STATUS_UNFIXABLE;

        // Store the tier the backend guard will actually enforce, not the tier
        // the LLM claimed, matching AiRemedyTriager.
        $safety = app(CommandSafetyGuard::class)->evaluateBatch($analysis['commands'] ?? []);

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
            'safety_tier' => $analysis['is_fixable'] ? $safety['highest_tier'] : AiRemedyRun::TIER_UNFIXABLE,
            'is_fixable' => (bool) ($analysis['is_fixable'] ?? true),
            'is_maintenance' => (bool) ($analysis['is_maintenance'] ?? false),
            'maintenance_type' => $analysis['maintenance_type'] ?? null,
            'proposed_commands' => $analysis['commands'],
            'before_metrics' => $telemetry,
            'started_at' => now(),
        ]);

        $run = $run->fresh() ?? $run;

        return response()->json([
            'ok' => true,
            'run' => $run,
            'review' => $this->policy->review($run, $request->user()),
            'analysis' => $analysis,
            'telemetry' => $telemetry,
        ]);
    }

    /**
     * Execute an operator-approved selection of a run's proposed commands.
     *
     * Body: `selected` — list of {index, command} for the proposed commands the
     * operator ticked, in order (`command` may be an edited version). Proposed
     * commands not listed are recorded as skipped. The legacy `commands` string
     * list is still accepted and mapped by position.
     */
    public function execute(Request $request, AiRemedyRun $run): JsonResponse
    {
        $blocked = $this->policy->blockedReason($run);
        if ($blocked !== null) {
            return $this->executeFailure($run, $blocked, 409);
        }

        $proposed = array_values(array_map('strval', $run->proposed_commands ?? []));
        $selection = $this->parseSelection($request, count($proposed));
        if (is_string($selection)) {
            return $this->executeFailure($run, $selection, 422);
        }

        $commands = array_map(fn (array $s) => $s['command'], $selection);

        $safety = app(CommandSafetyGuard::class)->evaluateBatch($commands);
        if (! $safety['allowed']) {
            return $this->executeFailure($run, 'Selected commands rejected by safety policy: '.implode('; ', $safety['rejected_commands']), 422);
        }

        if ($run->server) {
            $sites = app(ServerSiteContext::class);
            foreach ($commands as $command) {
                if (($problem = $sites->problem($command, $run->server)) !== null) {
                    return $this->executeFailure($run, "\"{$command}\": {$problem}", 422);
                }
            }
        }

        $decisions = $this->buildDecisions($proposed, $selection);

        $result = $this->executor->execute($run, $commands, $decisions, $request->user());

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => $result['error'] ?? 'Execution failed.',
                'run' => $result['run'],
                'output' => $result['output'],
                'results' => $result['results'] ?? [],
            ], ! empty($result['conflict']) ? 409 : 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Remediation commands executed successfully.',
            'run' => $result['run'],
            'output' => $result['output'],
            'results' => $result['results'] ?? [],
        ]);
    }

    /**
     * @return list<array{index: ?int, command: string}>|string Selection, or an error message
     */
    protected function parseSelection(Request $request, int $proposedCount): array|string
    {
        $raw = $request->input('selected');

        if ($raw === null && is_array($request->input('commands'))) {
            $raw = [];
            foreach (array_values($request->input('commands')) as $i => $command) {
                $raw[] = ['index' => $i < $proposedCount ? $i : null, 'command' => $command];
            }
        }

        if (! is_array($raw) || $raw === []) {
            return 'Select at least one command to run.';
        }

        $selection = [];
        $seen = [];
        foreach ($raw as $item) {
            if (! is_array($item) || ! is_string($item['command'] ?? null)) {
                return 'Each selected command must be a string.';
            }

            $command = trim($item['command']);
            if ($command === '') {
                return 'Blank commands cannot be executed. Untick the command or restore its text.';
            }

            $index = $item['index'] ?? null;
            if ($index !== null) {
                if (! is_int($index) && ! ctype_digit((string) $index)) {
                    return 'Invalid command index.';
                }
                $index = (int) $index;
                if ($index < 0 || $index >= $proposedCount || isset($seen[$index])) {
                    return 'Invalid command index.';
                }
                $seen[$index] = true;
            }

            $selection[] = ['index' => $index, 'command' => $command];
        }

        return $selection;
    }

    /**
     * One decision per proposed command (run / edited / skipped), plus any
     * operator-added commands, for the audit trail.
     *
     * @param  list<string>  $proposed
     * @param  list<array{index: ?int, command: string}>  $selection
     * @return list<array<string, mixed>>
     */
    protected function buildDecisions(array $proposed, array $selection): array
    {
        $byIndex = [];
        $added = [];
        foreach ($selection as $item) {
            if ($item['index'] === null) {
                $added[] = $item['command'];
            } else {
                $byIndex[$item['index']] = $item['command'];
            }
        }

        $decisions = [];
        foreach ($proposed as $i => $original) {
            if (! array_key_exists($i, $byIndex)) {
                $decisions[] = ['index' => $i, 'decision' => 'skipped', 'command' => $original];
            } elseif (trim($original) === $byIndex[$i]) {
                $decisions[] = ['index' => $i, 'decision' => 'run', 'command' => $original];
            } else {
                $decisions[] = ['index' => $i, 'decision' => 'edited', 'command' => $byIndex[$i], 'original_command' => $original];
            }
        }

        foreach ($added as $command) {
            $decisions[] = ['index' => null, 'decision' => 'added', 'command' => $command];
        }

        return $decisions;
    }

    protected function executeFailure(AiRemedyRun $run, string $message, int $status): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => $message,
            'run' => $run,
            'output' => '',
            'results' => [],
        ], $status);
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

    /**
     * Record an operator evaluation verdict on an AiRemedy run.
     */
    public function setVerdict(Request $request, AiRemedyRun $run): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'verdict' => ['required', 'string', Rule::in([
                AiRemedyRun::VERDICT_CORRECT,
                AiRemedyRun::VERDICT_PARTIAL,
                AiRemedyRun::VERDICT_WRONG,
                AiRemedyRun::VERDICT_UNSURE,
            ])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $run->update([
            'verdict' => $validated['verdict'],
            'verdict_note' => $validated['note'] ?? null,
            'verdict_by_user_id' => $request->user()?->id,
            'verdict_at' => Carbon::now(),
        ]);

        ActionLog::create([
            'server_id' => $run->server_id,
            'site_id' => $run->site_id,
            'action_type' => 'ai_remedy_verdict',
            'summary' => "Recorded verdict '{$validated['verdict']}' on AiRemedy run #{$run->id}",
            'actor' => $request->user()?->name ?? 'operator',
            'ran_at' => Carbon::now(),
            'details' => [
                'run_id' => $run->id,
                'verdict' => $validated['verdict'],
                'note' => $validated['note'] ?? null,
            ],
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'verdict' => $run->verdict,
                'message' => 'Verdict recorded successfully.',
            ]);
        }

        return back()->with('status', 'Verdict recorded successfully.');
    }
}
