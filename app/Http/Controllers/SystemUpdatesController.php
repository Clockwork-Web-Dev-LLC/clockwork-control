<?php

namespace App\Http\Controllers;

use App\Services\Updates\SystemUpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Controller for the operator-triggered Clockwork Control system updates
 * hub (Settings → Updates). Provides a WordPress-style experience for
 * reviewing and executing core updates, companion fleet rollout, and modules.
 */
class SystemUpdatesController extends Controller
{
    /**
     * Display the System Updates status dashboard.
     */
    public function index(SystemUpdateService $updateService): View
    {
        $updateInfo = $updateService->checkForUpdates(force: false);
        $companionStatus = $updateService->getCompanionFleetStatus();
        $lastCheckedAt = $updateService->getLastCheckedAt();
        $lastApplyResult = $updateService->getLastApplyResult();

        return view('settings.updates', [
            'updateInfo' => $updateInfo,
            'companionStatus' => $companionStatus,
            'lastCheckedAt' => $lastCheckedAt,
            'lastApplyResult' => $lastApplyResult,
        ]);
    }

    /**
     * Force refresh the update check against the upstream release channel.
     * Triggered directly by the operator via the "Check Again" button.
     */
    public function check(Request $request, SystemUpdateService $updateService): RedirectResponse
    {
        $updateInfo = $updateService->checkForUpdates(force: true);

        if (! empty($updateInfo['has_update'])) {
            $msg = 'An update is available! Clockwork Control v'.$updateInfo['latest_version'].' is now ready to install.';

            return redirect()
                ->route('settings.updates.index')
                ->with('status_update_available', $msg);
        }

        if (! empty($updateInfo['error'])) {
            return redirect()
                ->route('settings.updates.index')
                ->with('status_update_error', $updateInfo['error']);
        }

        return redirect()
            ->route('settings.updates.index')
            ->with('status_update_ok', 'You have the latest version of Clockwork Control (v'.$updateInfo['current_version'].').');
    }

    /**
     * Apply the update to the current installation.
     * Triggered directly by the operator via the "Update Now" button.
     */
    public function apply(Request $request, SystemUpdateService $updateService): RedirectResponse
    {
        $result = $updateService->applyUpdate();

        if ($result['success']) {
            return redirect()
                ->route('settings.updates.index')
                ->with('status_update_ok', 'Clockwork Control has been updated successfully!')
                ->with('update_steps', $result['steps'] ?? []);
        }

        return redirect()
            ->route('settings.updates.index')
            ->with('status_update_error', $result['error'] ?? 'Update failed.')
            ->with('update_steps', $result['steps'] ?? []);
    }
}
