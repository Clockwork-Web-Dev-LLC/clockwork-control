<?php

namespace Modules\CommentModeration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class SiteCommentsController extends Controller
{
    /**
     * Fetch comments via Companion client (JSON endpoint for asynchronous tab refresh or API access).
     */
    public function index(Site $site, Request $request): JsonResponse
    {
        if (! $site->companion_installed) {
            return response()->json([
                'ok' => false,
                'error' => 'Companion plugin is not installed on this site.',
            ], 400);
        }

        $filters = [
            'status' => (string) $request->query('status', 'all'),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 20),
        ];

        if ($request->filled('search')) {
            $filters['search'] = (string) $request->query('search');
        }

        try {
            $client = new ClockworkCompanionClient($site);
            $data = $client->comments($filters);

            return response()->json(array_merge(['ok' => true], $data));
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Moderate one or more comments (approve, hold, spam, trash, delete).
     */
    public function moderate(Site $site, Request $request, ActionLogger $logger): RedirectResponse|JsonResponse
    {
        if (! $site->companion_installed) {
            return back()->with('status_error', 'Companion plugin is not installed on this site.');
        }

        $validated = $request->validate([
            'comment_ids' => ['required', 'array', 'min:1'],
            'comment_ids.*' => ['integer'],
            'action' => ['required', 'string', 'in:approve,hold,spam,trash,delete'],
        ]);

        $commentIds = array_values(array_map('intval', $validated['comment_ids']));
        $action = $validated['action'];

        try {
            $client = new ClockworkCompanionClient($site);
            $result = $client->moderateComments($commentIds, $action);

            $logger->record(
                actionType: ActionLog::TYPE_COMMENTS_MODERATED,
                summary: 'Moderated '.count($commentIds)." comment(s) with action '{$action}' on {$site->domain}.",
                site: $site,
                target: $action,
                ok: true,
                details: [
                    'comment_ids' => $commentIds,
                    'action' => $action,
                    'success_count' => $result['success_count'] ?? count($commentIds),
                    'fail_count' => $result['fail_count'] ?? 0,
                ],
            );

            $msg = "Successfully performed '{$action}' on {$result['success_count']} comment(s).";

            if ($request->wantsJson()) {
                return response()->json(array_merge(['ok' => true, 'message' => $msg], $result));
            }

            return back()->with('status', $msg);
        } catch (Throwable $e) {
            $logger->record(
                actionType: ActionLog::TYPE_COMMENTS_MODERATED,
                summary: "Failed moderating comments on {$site->domain}: {$e->getMessage()}",
                site: $site,
                target: $action,
                ok: false,
                error: $e->getMessage(),
            );

            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
            }

            return back()->with('status_error', "Failed to moderate comments: {$e->getMessage()}");
        }
    }

    /**
     * Purge spam and trash comments older than N days (default 30).
     */
    public function cleanup(Site $site, Request $request, ActionLogger $logger): RedirectResponse|JsonResponse
    {
        if (! $site->companion_installed) {
            return back()->with('status_error', 'Companion plugin is not installed on this site.');
        }

        $validated = $request->validate([
            'older_than_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        $days = (int) ($validated['older_than_days'] ?? 30);

        try {
            $client = new ClockworkCompanionClient($site);
            $result = $client->cleanupComments($days);

            $logger->record(
                actionType: ActionLog::TYPE_COMMENTS_CLEANUP,
                summary: "Purged {$result['total_purged']} comments older than {$days} days on {$site->domain}.",
                site: $site,
                target: (string) $days,
                ok: true,
                details: $result,
            );

            $msg = "Purged {$result['total_purged']} comment(s) older than {$days} days ({$result['purged_spam']} spam, {$result['purged_trash']} trash).";

            if ($request->wantsJson()) {
                return response()->json(array_merge(['ok' => true, 'message' => $msg], $result));
            }

            return back()->with('status', $msg);
        } catch (Throwable $e) {
            $logger->record(
                actionType: ActionLog::TYPE_COMMENTS_CLEANUP,
                summary: "Failed comment cleanup on {$site->domain}: {$e->getMessage()}",
                site: $site,
                target: (string) $days,
                ok: false,
                error: $e->getMessage(),
            );

            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
            }

            return back()->with('status_error', "Failed comment cleanup: {$e->getMessage()}");
        }
    }
}
