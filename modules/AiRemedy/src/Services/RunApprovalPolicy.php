<?php

namespace Modules\AiRemedy\Services;

use App\Models\User;
use Modules\AiRemedy\Models\AiRemedyRun;

/**
 * Decides whether an operator may approve and run a run's proposed fix, and
 * classifies each proposed command so the review panel can offer per-command
 * choices. Shared by the execute endpoint, RemedyExecutor, and the views so the
 * UI never offers a Run button the backend would refuse.
 */
class RunApprovalPolicy
{
    /**
     * Diagnoses older than this are refused; the server has likely changed
     * since the telemetry was taken, so the operator re-diagnoses instead.
     */
    public const MAX_AGE_MINUTES = 120;

    /** Statuses a run can be executed from. */
    public const EXECUTABLE_STATUSES = [
        AiRemedyRun::STATUS_PENDING,
        AiRemedyRun::STATUS_ANALYZED,
        AiRemedyRun::STATUS_ALLOWED_MAINTENANCE,
    ];

    public function __construct(
        protected CommandSafetyGuard $guard,
        protected SpinupWpServiceRoute $spinupWp,
        protected ServerSiteContext $sites,
    ) {}

    /**
     * Why this run can't be executed, or null when it can.
     */
    public function blockedReason(AiRemedyRun $run): ?string
    {
        if ($run->isSimulation()) {
            return 'Simulations never execute commands.';
        }

        if ($run->isWatchMode()) {
            return 'Recorded in Shadow (Watch) Mode, which never executes commands. Switch to Copilot mode or run Diagnose on the server to act on it.';
        }

        if (! in_array($run->status, self::EXECUTABLE_STATUSES, true)) {
            return match ($run->status) {
                AiRemedyRun::STATUS_EXECUTING => 'This fix is already running.',
                AiRemedyRun::STATUS_RESOLVED, AiRemedyRun::STATUS_FAILED => 'This fix has already been run. Diagnose the server again to get a fresh fix.',
                AiRemedyRun::STATUS_UNFIXABLE => 'AiRemedy marked this incident as needing a developer; there is no fix to run.',
                AiRemedyRun::STATUS_REJECTED => 'This fix was rejected by the safety policy.',
                default => "Runs with status \"{$run->status}\" can't be executed.",
            };
        }

        if (! $run->server_id) {
            return 'No server is linked to this incident, so there is nowhere to run the fix.';
        }

        if (empty($run->proposed_commands)) {
            return 'AiRemedy did not propose any commands.';
        }

        $startedAt = $run->started_at ?? $run->created_at;
        if ($startedAt && $startedAt->lt(now()->subMinutes(self::MAX_AGE_MINUTES))) {
            return 'This diagnosis is more than '.(self::MAX_AGE_MINUTES / 60).' hours old. Diagnose the server again before running a fix.';
        }

        return null;
    }

    /**
     * Review payload for the approval panel.
     *
     * @return array{
     *     executable: bool,
     *     can_execute: bool,
     *     blocked_reason: ?string,
     *     commands: list<array{index: int, command: string, tier: string, allowed: bool, reason: ?string, route: string, suggestion: ?string}>
     * }
     */
    public function review(AiRemedyRun $run, ?User $user = null): array
    {
        $blocked = $this->blockedReason($run);

        $server = $run->server;
        $commands = [];
        foreach (array_values($run->proposed_commands ?? []) as $index => $command) {
            $command = (string) $command;
            $result = $this->guard->evaluate($command);
            $allowed = $result['allowed'];
            $reason = $result['reason'] ?? null;
            $suggestion = null;

            // The guard only checks command shape; make sure site commands
            // target a real site on this server (the LLM can guess paths).
            if ($allowed && $server && ($problem = $this->sites->problem($command, $server)) !== null) {
                $allowed = false;
                $reason = $problem;
                $suggestion = $this->sites->suggestion($command, $server);
            }

            $commands[] = [
                'index' => $index,
                'command' => $command,
                'tier' => $result['tier'],
                'allowed' => $allowed,
                'reason' => $reason,
                'route' => $server && $this->spinupWp->applies($command, $server) ? 'spinupwp_api' : 'ssh',
                'suggestion' => $suggestion,
            ];
        }

        return [
            'executable' => $blocked === null,
            // Execution is admin-only (see routes/web.php); operators can see the
            // panel's classification but not the Run button.
            'can_execute' => $blocked === null && (bool) $user?->isAdmin(),
            'blocked_reason' => $blocked,
            'commands' => $commands,
        ];
    }
}
