<?php

/**
 * Shared exec() shadow for App\Http\Controllers.
 *
 * Both OperationsUpdatesController::refresh() and
 * IssuesController::fetchAllDbCreds() shell out via the raw, unqualified
 * exec() to launch a detached `nohup php artisan ... &` background process
 * — same technique documented in OperationsUpdatesControllerTest.php and
 * tests/Feature/Console/EnsureQueueWorkerTest.php: PHP resolves an
 * unqualified call by first checking the CURRENT namespace, so declaring
 * App\Http\Controllers\exec() here shadows it for every call site in that
 * namespace, real global exec() never runs.
 *
 * PHP fatal-errors on redeclaring the same namespaced function twice, and
 * both controllers live in App\Http\Controllers, so this single shadow is
 * shared (via require_once, guarded by function_exists) rather than each
 * test file declaring its own — the first two test files that both needed
 * this collided until it moved here. Any future App\Http\Controllers
 * background-job test should require this file too instead of redeclaring.
 *
 * Calls accumulate in $GLOBALS['__cw_http_controllers_exec_calls'] —
 * callers should reset it in beforeEach() and assert against it directly.
 */

namespace App\Http\Controllers {
    if (! function_exists(__NAMESPACE__.'\\exec')) {
        function exec(string $command, &$output = null, &$result_code = null)
        {
            $GLOBALS['__cw_http_controllers_exec_calls'][] = $command;
            $result_code = 0;

            return '';
        }
    }
}
