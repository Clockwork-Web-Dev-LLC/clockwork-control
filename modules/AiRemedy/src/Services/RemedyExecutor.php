<?php

namespace Modules\AiRemedy\Services;

use App\Models\Server;
use App\Models\User;
use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use App\Services\Ssh\SshClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AiRemedy\Models\AiRemedyRun;
use Throwable;

class RemedyExecutor
{
    public function __construct(
        protected SshClient $ssh,
        protected CommandSafetyGuard $guard,
        protected ServerTelemetryCollector $collector,
        protected ActionLogger $actionLogger,
        protected RunApprovalPolicy $policy,
    ) {}

    /**
     * Execute approved remediation commands on the target server.
     *
     * @param  array<int, string>|null  $customCommands  Optional override of run's proposed commands (e.g. an operator-selected subset)
     * @param  list<array<string, mixed>>  $decisions  Per-proposed-command operator decisions (run / edited / skipped) for the audit trail
     * @return array{ok: bool, output: string, run: AiRemedyRun, error?: string, conflict?: bool}
     */
    public function execute(AiRemedyRun $run, ?array $customCommands = null, array $decisions = [], ?User $approvedBy = null): array
    {
        $blocked = $this->policy->blockedReason($run);
        if ($blocked !== null) {
            return ['ok' => false, 'output' => '', 'run' => $run, 'error' => $blocked, 'conflict' => true];
        }

        $server = $run->server;
        if (! $server) {
            $run->update([
                'status' => AiRemedyRun::STATUS_FAILED,
                'error_message' => 'No server associated with this remedy run.',
            ]);

            return ['ok' => false, 'output' => '', 'run' => $run, 'error' => 'No server found.'];
        }

        $commandsToRun = array_values($customCommands ?: $run->proposed_commands ?: []);
        if (empty($commandsToRun)) {
            return ['ok' => false, 'output' => '', 'run' => $run, 'error' => 'No commands to execute.'];
        }

        foreach ($commandsToRun as $cmd) {
            if (! is_string($cmd) || trim($cmd) === '') {
                return ['ok' => false, 'output' => '', 'run' => $run, 'error' => 'Blank commands cannot be executed.'];
            }
        }

        // Safety policy check
        $safety = $this->guard->evaluateBatch($commandsToRun);
        if (! $safety['allowed']) {
            $errMsg = 'Blocked by Safety Policy: '.implode('; ', $safety['rejected_commands']);
            $run->update([
                'status' => AiRemedyRun::STATUS_REJECTED,
                'error_message' => $errMsg,
            ]);

            return ['ok' => false, 'output' => '', 'run' => $run, 'error' => $errMsg];
        }

        // If autonomous (auto-heal), strictly forbid anything above Tier 1
        if ($run->actor === 'autonomous' && $safety['highest_tier'] !== CommandSafetyGuard::TIER_1_SAFE) {
            $errMsg = 'Autonomous execution blocked: commands require manual review (not Tier 1 safe).';
            $run->update([
                'status' => AiRemedyRun::STATUS_REJECTED,
                'error_message' => $errMsg,
            ]);

            return ['ok' => false, 'output' => '', 'run' => $run, 'error' => $errMsg];
        }

        // One execution per server at a time: two fixes racing on the same box
        // (or a double-click) must never interleave SSH commands.
        $lock = Cache::lock("ai-remedy:execute:server:{$server->id}", 300);
        if (! $lock->get()) {
            return ['ok' => false, 'output' => '', 'run' => $run, 'error' => 'Another AiRemedy fix is already running on this server.', 'conflict' => true];
        }

        try {
            // Atomically claim the run so it can only ever execute once, even if
            // two requests pass the policy check at the same moment.
            $claimed = AiRemedyRun::query()
                ->whereKey($run->id)
                ->whereIn('status', RunApprovalPolicy::EXECUTABLE_STATUSES)
                ->update([
                    'status' => AiRemedyRun::STATUS_EXECUTING,
                    'approved_commands' => $commandsToRun,
                ]);

            if ($claimed === 0) {
                return ['ok' => false, 'output' => '', 'run' => $run->fresh() ?? $run, 'error' => 'This fix is already running or has already been run.', 'conflict' => true];
            }

            $run->refresh();

            return $this->runCommands($run, $server, $commandsToRun, $decisions, $approvedBy);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $commandsToRun
     * @param  list<array<string, mixed>>  $decisions
     * @return array{ok: bool, output: string, run: AiRemedyRun, results: list<array<string, mixed>>, error?: string}
     */
    protected function runCommands(AiRemedyRun $run, Server $server, array $commandsToRun, array $decisions, ?User $approvedBy): array
    {
        $start = microtime(true);
        $fullOutput = '';
        $hadError = false;
        $errorMessage = null;
        $results = [];

        try {
            $session = $this->ssh->connect($server);
            $session->setTimeout(30);

            foreach ($commandsToRun as $cmd) {
                $fullOutput .= "$ {$cmd}\n";
                $cmdOutput = (string) $session->exec($cmd);
                $fullOutput .= $cmdOutput."\n";

                $exitStatus = 0;
                try {
                    $status = $session->getExitStatus();
                    $exitStatus = ($status !== false && $status !== null) ? (int) $status : 0;
                } catch (\BadMethodCallException) {
                    $exitStatus = 0;
                }

                $results[] = [
                    'command' => $cmd,
                    'exit_status' => $exitStatus,
                    'output' => Str::limit($cmdOutput, 2000),
                ];

                if ($exitStatus !== 0) {
                    $hadError = true;
                    $errorMessage = "Command '{$cmd}' exited with non-zero status code {$exitStatus}.";
                    $fullOutput .= "[Process exited with code {$exitStatus}]\n";
                    break;
                }
            }

            $session->disconnect();
        } catch (Throwable $e) {
            $hadError = true;
            $errorMessage = $e->getMessage();
            $fullOutput .= "\n[Execution Exception]: ".$e->getMessage();
        }

        // Commands after a failure never ran; record that explicitly.
        foreach (array_slice($commandsToRun, count($results)) as $notRun) {
            $results[] = ['command' => $notRun, 'exit_status' => null, 'output' => null, 'not_run' => true];
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        // Gather post-remedy telemetry
        $afterTelemetry = $this->collector->collect($server);

        $isOk = ! $hadError;
        $status = $isOk ? AiRemedyRun::STATUS_RESOLVED : AiRemedyRun::STATUS_FAILED;

        $run->update([
            'status' => $status,
            'execution_output' => $fullOutput,
            'after_metrics' => $afterTelemetry['ok'] ? $afterTelemetry : null,
            'completed_at' => Carbon::now(),
            'error_message' => $hadError ? ($errorMessage ?? 'SSH execution failed.') : null,
        ]);

        // Record in Clockwork ActionLogger
        $this->actionLogger->record(
            actionType: 'ai_remediation',
            summary: "AiRemedy executed on {$server->name}: {$run->summary_safe()}",
            server: $server,
            target: $run->status,
            details: [
                'run_id' => $run->id,
                'model' => $run->model_used,
                'commands' => $commandsToRun,
                'decisions' => $decisions,
                'results' => $results,
                'approved_by_user_id' => $approvedBy?->id,
                'cost_usd' => $run->total_cost_usd,
            ],
            ok: $isOk,
            elapsedMs: $elapsedMs,
            actor: $run->actor,
        );

        // Notify chat channels (Slack, Mattermost)
        try {
            if (app()->has(ChatNotifier::class)) {
                app(ChatNotifier::class)->aiRemedyExecuted($run->fresh());
            }
        } catch (Throwable $e) {
            Log::warning('ai_remedy.notification_failed', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'ok' => $isOk,
            'output' => $fullOutput,
            'run' => $run->fresh() ?? $run,
            'results' => $results,
        ];
    }
}
