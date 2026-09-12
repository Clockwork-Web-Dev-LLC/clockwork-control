<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CarePlanSettingsController extends Controller
{
    public function index(Settings $settings): View
    {
        $enabled = (bool) $settings->get(
            'care_plans.enabled',
            config('clockwork.care_plans.enabled', true),
        );

        $totalSites = Site::query()->count();
        $enrolledSites = Site::query()->where('care_plan_enabled', true)->count();
        $autoUpdateSites = Site::query()
            ->where('care_plan_enabled', true)
            ->where('auto_updates_paused', false)
            ->count();

        return view('settings.care-plans', [
            'enabled' => $enabled,
            'totalSites' => $totalSites,
            'enrolledSites' => $enrolledSites,
            'autoUpdateSites' => $autoUpdateSites,
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $enabled = $request->boolean('care_plans_enabled');
        $settings->put('care_plans.enabled', $enabled);

        return redirect()->route('settings.care-plans.index')
            ->with('status', 'Care plan policy updated successfully.');
    }
}
