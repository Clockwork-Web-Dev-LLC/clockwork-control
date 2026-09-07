<?php

namespace Modules\SiteMaintenance\Http\Controllers;
use App\Http\Controllers\Controller;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class SiteMaintenanceController extends Controller
{
    /**
     * Get maintenance mode status.
     */
    public function show(Site $site): JsonResponse
    {
        if (! $site->companion_installed) {
            return response()->json([
                'ok' => false,
                'error' => 'Companion plugin is not installed on this site.',
            ], 400);
        }

        try {
            $client = new ClockworkCompanionClient($site);
            $status = $client->maintenanceMode();

            return response()->json(array_merge(['ok' => true], $status));
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Toggle maintenance mode on/off or update message.
     */
    public function update(Site $site, Request $request, ActionLogger $logger): RedirectResponse|JsonResponse
    {
        if (! $site->companion_installed) {
            return back()->with('status_error', 'Companion plugin is not installed on this site.');
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'secret_key' => ['nullable', 'string', 'max:128'],
        ]);

        $enabled = (bool) $validated['enabled'];
        $title = $validated['title'] ?? null;
        $message = $validated['message'] ?? null;
        $secretKey = $validated['secret_key'] ?? null;

        try {
            $client = new ClockworkCompanionClient($site);
            $result = $client->setMaintenanceMode($enabled, $title, $message, $secretKey);

            $actionWord = $enabled ? 'enabled' : 'disabled';
            $logger->record(
                actionType: ActionLog::TYPE_MAINTENANCE_MODE_TOGGLED,
                summary: "Maintenance mode {$actionWord} on {$site->domain}.",
                site: $site,
                target: $actionWord,
                ok: true,
                details: [
                    'enabled' => $enabled,
                    'title' => $title,
                ],
            );

            $flashMsg = $enabled
                ? "Maintenance mode ENABLED on {$site->domain}. Visitors will see a 503 maintenance screen."
                : "Maintenance mode DISABLED on {$site->domain}. Site is live.";

            if ($request->wantsJson()) {
                return response()->json(array_merge(['ok' => true, 'message' => $flashMsg], $result));
            }

            return back()->with('status', $flashMsg);
        } catch (Throwable $e) {
            $logger->record(
                actionType: ActionLog::TYPE_MAINTENANCE_MODE_TOGGLED,
                summary: "Failed to toggle maintenance mode on {$site->domain}: {$e->getMessage()}",
                site: $site,
                target: $enabled ? 'enable' : 'disable',
                ok: false,
                error: $e->getMessage(),
            );

            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
            }

            return back()->with('status_error', "Failed to update maintenance mode: {$e->getMessage()}");
        }
    }
}
