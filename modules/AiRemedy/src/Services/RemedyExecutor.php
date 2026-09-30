<?php

namespace Modules\AiRemedy\Services;

use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use App\Services\Ssh\SshClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\AiRemedy\Models\AiRemedyRun;
use Throwable;

class RemedyExecutor
{
    public function __construct(
        protected SshClient $ssh,
        protected CommandSafetyGuard $guard,
        protected ServerTelemetryCollector $collector,
        protected ActionLogger $actionLogger,
    ) {}

    /**
     * Execute approved remediation commands on the target server.
     *
     * @param  array<int, string>|null  $customCommands  Optional override of run's proposed commands
     * @return array{ok: bool, output: string, run: AiRemedyRun, error?: string}
     */
    public function execute(AiRemedyRun $run, ?array $customCommands = null): array
    {
        $server = $run->server;
        if (! $server) {
            $run->update([
                'status' => AiRemedyRun::STATUS_FAILED,
                'error_message' => 'No server associated with this remedy run.',
            ]);

            return ['ok' => false, 'output' => '', 'run' => $run, 'error' => 'No server found.'];
        }

        $commandsToRun = $customCommands ?: $run->proposed_commands ?: [];
        if (empty($commandsToRun)) {
            return ['ok' => false, 'output' => '', 'run' => $run, 'error' => 'No commands to execute.'];
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

        $run->update([
            'status' => AiRemedyRun::STATUS_EXECUTING,
            'approved_commands' => $commandsToRun,
        ]);

        $start = microtime(true);
        $fullOutput = '';
        $hadError = false;
        $errorMessage = null;

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
            'run' => $run->fresh(),
        ];
    }
}
