<?php

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'database';
    }

    public function name(): string
    {
        return 'MySQL database';
    }

    public function description(): string
    {
        return 'Run a trivial SELECT against the primary connection.';
    }

    public function run(): CheckResult
    {
        $start = microtime(true);
        try {
            $row = DB::selectOne('SELECT VERSION() AS version');
            $ms = (int) ((microtime(true) - $start) * 1000);
            $version = is_object($row) && isset($row->version) ? (string) $row->version : 'unknown';
            $cfg = config('database.connections.'.config('database.default'));

            return CheckResult::ok(
                "Connected · MySQL {$version}",
                ($cfg['host'] ?? '?').' / '.($cfg['database'] ?? '?'),
                $ms,
            );
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Connection failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
