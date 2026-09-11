<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteWorkLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SiteWorkLogsController extends Controller
{
    public function store(Request $request, Site $site): RedirectResponse
    {
        $validated = $this->validated($request);
        $site->workLogs()->create([
            ...$validated,
            'user_id' => $request->user()?->id,
        ]);

        return back()->with('status', 'Work log saved.');
    }

    public function update(Request $request, Site $site, SiteWorkLog $workLog): RedirectResponse
    {
        abort_unless($workLog->site_id === $site->id, 404);
        $workLog->update($this->validated($request));

        return back()->with('status', 'Work log updated.');
    }

    public function destroy(Site $site, SiteWorkLog $workLog): RedirectResponse
    {
        abort_unless($workLog->site_id === $site->id, 404);
        $workLog->delete();

        return back()->with('status', 'Work log removed.');
    }

    /**
     * @return array{worked_on: string, hours: string, description: string}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'worked_on' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:999.99'],
            'description' => ['required', 'string', 'max:2000'],
        ]);
    }
}
