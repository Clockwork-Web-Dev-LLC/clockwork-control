<?php

namespace Modules\Mattermost;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatNotifier;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Per-event opt-out for Mattermost notifications. Each event in
 * `ChatNotifier::EVENTS` shows as a toggle; flipping one immediately
 * flips the bool in the `notifications.mattermost.events` Settings JSON —
 * no separate Save button (see toggleEvent()).
 *
 * The notifier reads the same key at fire time and early-returns when
 * the event is disabled — log lines + DB rows still get written, only
 * the chat post is suppressed.
 *
 * Future event types are added by extending the `EVENTS` constant in
 * the notifier and gating the new method with
 * `self::isEventEnabled('your_key')`. The UI auto-discovers entries.
 */
class MattermostSettingsController extends Controller
{
    public function index(Settings $settings): View|RedirectResponse
    {
        if (! config('clockwork.mattermost.enabled')) {
            return redirect()->route('settings.integrations.index')->with(
                'status_error',
                'Mattermost notifications are not enabled — set CLOCKWORK_MATTERMOST_ENABLED and a webhook URL in .env before configuring preferences.'
            );
        }

        $stored = (array) $settings->get(MattermostNotifier::SETTINGS_KEY, []);
        $events = [];
        foreach (ChatNotifier::EVENTS as $key => $meta) {
            $events[$key] = [
                'label' => $meta['label'],
                'description' => $meta['description'],
                'enabled' => array_key_exists($key, $stored)
                    ? (bool) $stored[$key]
                    : (bool) ($meta['default'] ?? true),
            ];
        }

        return view('settings.mattermost', [
            'events' => $events,
            'webhookConfigured' => filled(config('clockwork.mattermost.webhook_url')),
        ]);
    }

    /**
     * Auto-save endpoint for a single event's toggle — no separate Save
     * button. Rehydrates the full event map (same defaulting rules as
     * index()) so the stored settings JSON always has every key explicitly
     * set, then flips just the one requested key.
     */
    public function toggleEvent(Request $request, string $key, Settings $settings): JsonResponse
    {
        abort_unless(array_key_exists($key, ChatNotifier::EVENTS), 404);
        abort_unless((bool) config('clockwork.mattermost.enabled'), 404);

        $stored = (array) $settings->get(MattermostNotifier::SETTINGS_KEY, []);
        $next = [];
        foreach (ChatNotifier::EVENTS as $eventKey => $meta) {
            $next[$eventKey] = array_key_exists($eventKey, $stored)
                ? (bool) $stored[$eventKey]
                : (bool) ($meta['default'] ?? true);
        }

        $was = $next[$key];
        $enabled = $request->boolean('enabled');
        $next[$key] = $enabled;

        $settings->put(MattermostNotifier::SETTINGS_KEY, $next);

        if ($was !== $enabled) {
            Log::info('mattermost.notifications_settings_updated', [
                'actor_id' => $request->user()?->id,
                'diff' => [$key => ['was' => $was, 'now' => $enabled]],
            ]);
        }

        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }
}
