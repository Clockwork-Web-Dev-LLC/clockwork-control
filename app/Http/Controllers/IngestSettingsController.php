<?php

namespace App\Http\Controllers;

use App\Services\Ingest\IngestScheduleGate;
use App\Services\Logs\ThreatLogPartitionedTable;
use App\Services\Logs\ThreatLogRetention;
use App\Services\Process\BackgroundArtisan;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IngestSettingsController extends Controller
{
    public function index(IngestScheduleGate $gate, ThreatLogRetention $retention, ThreatLogPartitionedTable $partitions): View
    {
        $config = $gate->currentConfig();
        $timezones = $this->commonTimezones();
        $retentionState = $retention->viewState();
        $partitionStatus = $partitions->status();

        return view('settings.ingest', compact('config', 'timezones', 'retentionState', 'partitionStatus'));
    }

    public function update(Request $request, Settings $settings, IngestScheduleGate $gate): RedirectResponse
    {
        $rules = [
            'always_on' => ['nullable', 'boolean'],
            'start_time' => ['required', 'regex:/^\d{1,2}:\d{2}$/'],
            'end_time' => ['required', 'regex:/^\d{1,2}:\d{2}$/'],
            'timezone' => ['required', 'timezone'],
            'frequency_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ];
        foreach (IngestScheduleGate::SOURCES as $src) {
            $rules["sources.{$src}.enabled"] = ['nullable', 'boolean'];
        }
        $validated = $request->validate($rules);

        $payload = [
            IngestScheduleGate::KEY_ALWAYS_ON => (bool) $request->input('always_on', false),
            IngestScheduleGate::KEY_WINDOW_START => $validated['start_time'],
            IngestScheduleGate::KEY_WINDOW_END => $validated['end_time'],
            IngestScheduleGate::KEY_TIMEZONE => $validated['timezone'],
            IngestScheduleGate::KEY_FREQUENCY_MIN => (int) $validated['frequency_minutes'],
        ];
        foreach (IngestScheduleGate::SOURCES as $src) {
            $payload[$gate->enabledKey($src)] = (bool) ($request->input("sources.{$src}.enabled", false));
        }

        $settings->putMany($payload);

        return redirect()->route('settings.ingest.index')->with('status', 'Scheduling saved.');
    }

    public function updateRetention(Request $request, ThreatLogRetention $retention): RedirectResponse
    {
        $validated = $request->validate([
            'retention_amount' => ['required', 'integer', 'min:1', 'max:365'],
            'retention_unit' => ['required', 'in:days,weeks'],
        ]);

        $days = ThreatLogRetention::daysFrom(
            (int) $validated['retention_amount'],
            (string) $validated['retention_unit'],
        );

        if ($days < ThreatLogRetention::MIN_DAYS || $days > ThreatLogRetention::MAX_DAYS) {
            return back()
                ->withInput()
                ->withErrors([
                    'retention_amount' => 'Keep between '.ThreatLogRetention::MIN_DAYS.' and '.ThreatLogRetention::MAX_DAYS.' days (weird-stats still reads a 7-day window).',
                ]);
        }

        $retention->save((int) $validated['retention_amount'], (string) $validated['retention_unit']);

        return redirect()->route('settings.ingest.index')
            ->with('status', "Raw nginx log retention set to {$days} days. Nightly prune will use this window.");
    }

    public function pruneNow(): RedirectResponse
    {
        $result = app(BackgroundArtisan::class)->start(
            'logs.prune-threat-logs',
            ['clockwork:prune-threat-logs'],
            14400,
            'prune-threat-logs-bg',
        );

        if ($result->alreadyRunning()) {
            return back()->with('status', 'A threat log prune is already running.');
        }

        if ($result->failed()) {
            return back()->with('queue_error', $result->error ?? 'Could not start the threat log prune.');
        }

        return back()->with('status', 'Threat log prune started in the background. Old raw rows delete in chunks; rollups are kept.');
    }

    public function rebuildPartitions(ThreatLogPartitionedTable $partitions): RedirectResponse
    {
        if (! $partitions->supportsPartitioning()) {
            return back()->with('queue_error', 'Table partitioning requires a MySQL database connection.');
        }

        if ($partitions->isPartitioned()) {
            return back()->with('status', 'threat_logs is already partitioned into monthly tables; no rebuild needed.');
        }

        $result = app(BackgroundArtisan::class)->start(
            'logs.rebuild-threat-logs-partitions',
            ['clockwork:rebuild-threat-logs-partitions'],
            14400,
            'rebuild-threat-logs-partitions-bg',
        );

        if ($result->alreadyRunning()) {
            return back()->with('status', 'A partition rebuild is already running in the background.');
        }

        if ($result->failed()) {
            return back()->with('queue_error', $result->error ?? 'Could not start the partition rebuild.');
        }

        return back()->with('status', 'Partition rebuild started in the background. Check storage/logs/rebuild-threat-logs-partitions-bg.log for progress.');
    }

    /**
     * Run a specific source's pull command now, ignoring the window/cadence gate.
     */
    public function runNow(Request $request): RedirectResponse
    {
        $source = $request->input('source', IngestScheduleGate::SOURCE_LLAR);

        $command = match ($source) {
            IngestScheduleGate::SOURCE_LLAR => 'clockwork:pull-llar-lockouts',
            IngestScheduleGate::SOURCE_WORDFENCE => 'clockwork:pull-wordfence-blocks',
            default => null,
        };

        if (! $command) {
            return back()->with('queue_error', "Unknown source '{$source}'.");
        }

        $result = app(BackgroundArtisan::class)->start(
            'ingest.'.$source,
            [$command],
            600,
            'ingest-'.$source.'-bg',
        );

        if ($result->alreadyRunning()) {
            return back()->with('status', "A {$source} pull is already running.");
        }

        if ($result->failed()) {
            return back()->with('queue_error', $result->error ?? "Could not start the {$source} pull.");
        }

        return back()->with('status', "Pull started for {$source} in the background. Refresh /review in a minute.");
    }

    /**
     * @return array<int, string>
     */
    private function commonTimezones(): array
    {
        return [
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'America/Anchorage',
            'America/Halifax',
            'America/Toronto',
            'UTC',
            'Europe/London',
            'Europe/Berlin',
            'Asia/Tokyo',
            'Australia/Sydney',
        ];
    }
}
