<?php

namespace App\Services\Diagnostics;

use Throwable;

/**
 * Runs every registered DiagnosticCheck and returns a row per check. The
 * runner catches Throwables so one broken check can't take the page down —
 * the view should still render with a fail row for that check and the rest
 * intact.
 */
class DiagnosticsRunner
{
    /**
     * @param  iterable<DiagnosticCheck>  $checks
     */
    public function __construct(private readonly iterable $checks) {}

    /**
     * @return list<array{id: string, name: string, description: string, result: CheckResult}>
     */
    public function run(): array
    {
        $rows = [];

        foreach ($this->checks as $check) {
            $start = (int) (microtime(true) * 1000);
            try {
                $result = $check->run();
            } catch (Throwable $e) {
                $result = CheckResult::fail(
                    'Check threw: '.class_basename($e),
                    $e->getMessage(),
                    (int) (microtime(true) * 1000) - $start,
                );
            }

            $rows[] = [
                'id' => $check->id(),
                'name' => $check->name(),
                'description' => $check->description(),
                'result' => $result,
            ];
        }

        return $rows;
    }
}
