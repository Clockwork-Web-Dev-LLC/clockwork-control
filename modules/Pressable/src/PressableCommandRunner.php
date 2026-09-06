<?php

namespace Modules\Pressable;

use RuntimeException;

/**
 * Turns Pressable's fire-and-forget command API into a synchronous
 * "run this and give me output + exit code" primitive.
 *
 * The problem this solves: POST /sites/{id}/wordpress/commands returns only
 * {message: "Success"} — no job id, no status endpoint (the job_id the MCP
 * layer shows is an MCP-side convenience; the raw REST API has nothing).
 * The ONLY way to observe a command's result is the site activity log,
 * where each completed command lands as an `ssh.command` entry shaped:
 *
 *   Command [ <command text> ] :::: <output>
 *
 * Correlation + parsing constraints (all confirmed live 2026-08-28):
 *
 *  1. Log entries appear only AFTER the command finishes, with a
 *     monotonically-increasing integer id. We snapshot the max id before
 *     submitting and only accept newer entries — so re-running the exact
 *     same command text on a site can't match a stale entry.
 *  2. The whole message (command + output) is TRUNCATED around ~1KB. A
 *     trailing sentinel would be cut off by verbose output, so the wrapper
 *     below captures output first and emits the sentinel + exit code as the
 *     FIRST line of output, immune to tail-truncation. The sentinel regex
 *     requires digits after the colon so the literal `$?` in the echoed
 *     command text can never false-match.
 *  3. Pressable kills any command that produces no output for 30s
 *     ("Command requires interactive input..."). Every script we run must
 *     either finish fast or emit something within that window.
 *  4. The command text itself is recorded verbatim in the activity log,
 *     visible in Pressable's control panel for ~30 days. NEVER pass
 *     long-lived secrets through run()/submit() — see
 *     PressableCompanionInstaller's bootstrap-then-rotate dance.
 */
class PressableCommandRunner
{
    /** Matches constraint 3's 30s no-output kill + observed queue latency. */
    private const POLL_INTERVAL_SECONDS = 5;

    public function __construct(private readonly PressableClient $client) {}

    /**
     * Fire-and-forget submit. Use for commands whose success is verified by
     * a later run() (e.g. chunked file-part uploads verified by a hash
     * check), because their own log entries are useless: a 50KB upload
     * chunk fills the ~1KB message budget with command text, truncating any
     * output — including any sentinel — away.
     */
    public function submit(int|string $pressableSiteId, string $command): void
    {
        $this->client->runBashCommands($pressableSiteId, [$command]);
    }

    /**
     * Run a bash command and wait for its result.
     *
     * @return array{ok: bool, exit: ?int, output: string}
     */
    public function run(int|string $pressableSiteId, string $command, int $timeoutSeconds = 120): array
    {
        $nonce = bin2hex(random_bytes(6));
        $marker = "CWRC{$nonce}";

        // Capture-first wrapper: output is buffered, then the marker+exit
        // line is emitted BEFORE the buffered output so truncation (which
        // eats the tail) can never remove it. `tail -c 700` keeps the
        // interesting end of long output inside the message budget.
        $wrapped = 'CWOUT=$({ '.$command.' ; } 2>&1); '
            ."echo \"{$marker}:$?\"; "
            .'printf %s "$CWOUT" | tail -c 700';

        $beforeId = $this->newestActivityId($pressableSiteId);

        $this->client->runBashCommands($pressableSiteId, [$wrapped]);

        $deadline = time() + $timeoutSeconds;
        do {
            sleep(self::POLL_INTERVAL_SECONDS);

            foreach ($this->client->activityLogs($pressableSiteId, 'ssh.command') as $row) {
                if ((int) ($row['id'] ?? 0) <= $beforeId) {
                    continue;
                }
                $message = (string) ($row['message'] ?? '');

                // Digits required — the command text contains the literal
                // `CWRC<nonce>:$?`, only the output contains `CWRC<nonce>:0`.
                if (preg_match("/{$marker}:(\\d+)/", $message, $m)) {
                    $exit = (int) $m[1];
                    $output = trim((string) substr($message, strpos($message, $m[0]) + strlen($m[0])));

                    return ['ok' => $exit === 0, 'exit' => $exit, 'output' => $output];
                }

                // Pressable's own kill message replaces the output entirely,
                // so the marker never appears. Match on our command's nonce
                // in the COMMAND portion to attribute the kill to this run.
                if (str_contains($message, $marker)
                    && str_contains($message, 'Command requires interactive input')) {
                    return [
                        'ok' => false,
                        'exit' => null,
                        'output' => 'Killed by Pressable: no output for 30s (command stalled or waited for input).',
                    ];
                }
            }
        } while (time() < $deadline);

        return [
            'ok' => false,
            'exit' => null,
            'output' => "Timed out after {$timeoutSeconds}s waiting for the activity-log entry. "
                .'The command may still complete — check the site activity log before retrying anything destructive.',
        ];
    }

    /**
     * Run a command, throw on failure. For steps where a non-zero exit has
     * no sensible recovery path.
     *
     * @return string the command's (possibly truncated) output
     */
    public function runOrFail(int|string $pressableSiteId, string $command, int $timeoutSeconds = 120): string
    {
        $result = $this->run($pressableSiteId, $command, $timeoutSeconds);

        if (! $result['ok']) {
            $exit = $result['exit'] === null ? 'none' : (string) $result['exit'];
            throw new RuntimeException("Pressable command failed (exit {$exit}): {$result['output']}");
        }

        return $result['output'];
    }

    private function newestActivityId(int|string $pressableSiteId): int
    {
        $rows = $this->client->activityLogs($pressableSiteId, null, 1);

        return (int) ($rows[0]['id'] ?? 0);
    }
}
