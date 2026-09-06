<?php

namespace App\Http\Controllers;

use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operator-only maintenance utilities. Today: on-demand DB backup
 * (gzipped mysqldump streamed straight to the browser, never written
 * to disk on the server).
 *
 * The backup contains encrypted columns (SSH keys, per-site DB creds,
 * Companion secrets) plus all snapshot/threat-log data. Decrypting any
 * of the encrypted columns requires both the dump AND `APP_KEY` from
 * the .env file — pair the two carefully.
 */
class MaintenanceController extends Controller
{
    public function index(): View
    {
        $cfg = config('database.connections.'.config('database.default'));
        $dbInfo = [
            'name' => $cfg['database'] ?? 'unknown',
            'host' => $cfg['host'] ?? 'unknown',
            'driver' => $cfg['driver'] ?? 'unknown',
        ];

        // Quick byte count so the operator has a rough idea what to expect.
        // Sum of data + index length per InnoDB convention. Fast — single
        // information_schema query.
        $totalBytes = (int) DB::connection()
            ->selectOne(
                'SELECT COALESCE(SUM(data_length + index_length), 0) AS total
                 FROM information_schema.tables
                 WHERE table_schema = ?',
                [$dbInfo['name']],
            )?->total;

        return view('settings.maintenance', [
            'dbInfo' => $dbInfo,
            'totalBytes' => $totalBytes,
        ]);
    }

    /**
     * Stream a gzipped mysqldump straight to the browser. No temp file
     * on the server side — passthru pipes mysqldump | gzip directly into
     * the response body.
     *
     * --single-transaction: consistent InnoDB snapshot without locking.
     * --quick: stream rows, don't buffer the whole table in memory.
     * --no-tablespaces: skip the PROCESS privilege requirement on
     *                   tablespaces metadata (Herd's MySQL root may not
     *                   have it, and we don't need that data).
     */
    public function downloadBackup(): StreamedResponse
    {
        $cfg = config('database.connections.'.config('database.default'));
        $filename = 'clockwork-backup-'.now()->format('Ymd-His').'.sql.gz';

        $cmd = $this->buildDumpCommand($cfg);

        return response()->streamDownload(function () use ($cmd) {
            // Run with the "MYSQL_PWD" env var when a password is configured
            // (avoids it appearing in `ps` output). passthru lets the dump
            // bytes flow straight to the response body.
            passthru($cmd);
        }, $filename, [
            'Content-Type' => 'application/gzip',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function buildDumpCommand(array $cfg): string
    {
        $user = (string) ($cfg['username'] ?? 'root');
        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (string) ($cfg['port'] ?? '3306');
        $name = (string) ($cfg['database'] ?? 'clockwork');
        $pwd = (string) ($cfg['password'] ?? '');

        $envPrefix = $pwd !== '' ? 'MYSQL_PWD='.escapeshellarg($pwd).' ' : '';

        return $envPrefix.sprintf(
            'mysqldump --user=%s --host=%s --port=%s --single-transaction --quick --no-tablespaces %s | gzip',
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($name),
        );
    }

    public function updateTelemetry(Request $request, Settings $settings): RedirectResponse
    {
        $settings->put('telemetry.enabled', $request->boolean('enabled'));

        return redirect()->route('settings.maintenance.index')
            ->with('status', 'Telemetry setting saved.');
    }

    public function sendTelemetryNow(): RedirectResponse
    {
        Artisan::queue('clockwork:send-telemetry');

        return back()->with('status', 'Telemetry report queued — sending in background.');
    }
}
