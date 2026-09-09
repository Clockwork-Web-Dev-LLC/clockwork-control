<?php

namespace App\Http\Controllers;

use App\Services\Ingest\IngestScheduleGate;
use App\Services\Process\BackgroundArtisan;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IngestSettingsController extends Controller
{
    public function index(IngestScheduleGate $gate): View
    {
        $config = $gate->currentConfig();
        $timezones = $this->commonTimezones();

        return view('settings.ingest', compact('config', 'timezones'));
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
