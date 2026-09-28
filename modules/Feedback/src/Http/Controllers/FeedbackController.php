<?php

namespace Modules\Feedback\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Feedback\Models\FeedbackComment;
use Modules\Feedback\Models\FeedbackItem;

class FeedbackController extends Controller
{
    /**
     * Display the central Feedback Working List / Backlog dashboard.
     */
    public function index(Request $request): View
    {
        $query = FeedbackItem::query()->with(['user', 'comments.user']);

        // Filter by status tab (default: active if not specified)
        $status = $request->input('status', 'active');
        if ($status === 'active') {
            $query->whereIn('status', [FeedbackItem::STATUS_OPEN, FeedbackItem::STATUS_IN_PROGRESS]);
        } elseif (in_array($status, [FeedbackItem::STATUS_OPEN, FeedbackItem::STATUS_IN_PROGRESS, FeedbackItem::STATUS_RESOLVED, FeedbackItem::STATUS_DISMISSED], true)) {
            $query->where('status', $status);
        }

        // Filter by category type
        if ($type = $request->input('type')) {
            if (in_array($type, [FeedbackItem::TYPE_BUG, FeedbackItem::TYPE_TWEAK, FeedbackItem::TYPE_FEATURE, FeedbackItem::TYPE_COPY], true)) {
                $query->where('type', $type);
            }
        }

        // Filter by page path
        if ($path = $request->input('path')) {
            $query->where('path', $path);
        }

        // Search in title, content, path, route
        if ($search = trim((string) $request->input('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%")
                    ->orWhere('path', 'like', "%{$search}%")
                    ->orWhere('route_name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $items = $query->orderByDesc('created_at')->paginate(25)->withQueryString();

        $stats = [
            'total' => FeedbackItem::count(),
            'open' => FeedbackItem::where('status', FeedbackItem::STATUS_OPEN)->count(),
            'in_progress' => FeedbackItem::where('status', FeedbackItem::STATUS_IN_PROGRESS)->count(),
            'resolved' => FeedbackItem::where('status', FeedbackItem::STATUS_RESOLVED)->count(),
        ];

        // Unique paths for dropdown filter
        $availablePaths = FeedbackItem::distinct('path')->pluck('path')->sort()->values();

        return view('feedback::index', [
            'items' => $items,
            'stats' => $stats,
            'availablePaths' => $availablePaths,
            'currentStatus' => $status,
            'currentType' => $request->input('type', ''),
            'currentPath' => $request->input('path', ''),
            'searchQuery' => $search,
        ]);
    }

    /**
     * Get active pins for the current URL path on the live page overlay.
     */
    public function pins(Request $request): JsonResponse
    {
        $rawPath = (string) $request->input('path', '/');
        $parsedPath = parse_url($rawPath, PHP_URL_PATH) ?: '/';

        $items = FeedbackItem::where('path', $parsedPath)
            ->with(['user', 'comments.user'])
            ->orderBy('id', 'asc')
            ->get();

        $pins = $items->map(function (FeedbackItem $item, int $index) {
            return [
                'id' => $item->id,
                'number' => $index + 1,
                'title' => $item->title,
                'content' => $item->content,
                'type' => $item->type,
                'type_label' => $item->formattedType(),
                'type_class' => $item->typeBadgeClass(),
                'status' => $item->status,
                'status_class' => $item->statusBadgeClass(),
                'selector' => $item->selector,
                'element_tag' => $item->element_tag,
                'element_text' => $item->element_text,
                'x_pos' => $item->x_pos,
                'y_pos' => $item->y_pos,
                'created_at' => $item->created_at->diffForHumans(),
                'author' => [
                    'name' => $item->user ? $item->user->name : 'User',
                    'email' => $item->user ? $item->user->email : '',
                    'avatar' => $item->user ? $item->user->avatarUrl(48) : '',
                ],
                'comments_count' => $item->comments->count(),
                'comments' => $item->comments->map(fn (FeedbackComment $c) => [
                    'id' => $c->id,
                    'content' => $c->content,
                    'created_at' => $c->created_at->diffForHumans(),
                    'author' => [
                        'name' => $c->user ? $c->user->name : 'User',
                        'email' => $c->user ? $c->user->email : '',
                        'avatar' => $c->user ? $c->user->avatarUrl(40) : '',
                    ],
                ]),
                'claude_prompt' => $item->toClaudePrompt(),
            ];
        });

        return response()->json([
            'ok' => true,
            'path' => $parsedPath,
            'pins' => $pins,
            'count' => $pins->count(),
        ]);
    }

    /**
     * Store a newly created feedback note/pin from the live page or backlog.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string'],
            'path' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'type' => ['nullable', 'string', 'in:bug,tweak,feature,copy'],
            'selector' => ['nullable', 'string'],
            'element_tag' => ['nullable', 'string', 'max:50'],
            'element_text' => ['nullable', 'string'],
            'x_pos' => ['nullable', 'numeric'],
            'y_pos' => ['nullable', 'numeric'],
            'viewport_width' => ['nullable', 'integer'],
            'viewport_height' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ]);

        $path = parse_url($validated['path'], PHP_URL_PATH) ?: '/';

        // Auto-detect Route and Controller action via router
        $routeName = null;
        $controllerAction = null;
        $viewName = null;

        try {
            $fakeReq = Request::create($path, 'GET');
            $route = app('router')->getRoutes()->match($fakeReq);
            $routeName = $route->getName();
            $controllerAction = $route->getActionName();

            // Infer common Blade view paths based on route name
            if ($routeName) {
                $candidate = str_replace('.', '/', $routeName);
                if (file_exists(resource_path("views/{$candidate}.blade.php"))) {
                    $viewName = "resources/views/{$candidate}.blade.php";
                } elseif (file_exists(resource_path("views/dashboard/{$candidate}.blade.php"))) {
                    $viewName = "resources/views/dashboard/{$candidate}.blade.php";
                }
            }
        } catch (\Throwable) {
            // Unmatched route or dynamic parameter error: safe fallback
        }

        $user = $request->user();
        $userId = $user ? $user->id : 1;

        $item = FeedbackItem::create([
            'user_id' => $userId,
            'url' => $validated['url'],
            'path' => $path,
            'route_name' => $routeName,
            'controller_action' => $controllerAction,
            'view_name' => $viewName,
            'selector' => $validated['selector'] ?? null,
            'element_tag' => $validated['element_tag'] ?? null,
            'element_text' => $validated['element_text'] ?? null,
            'x_pos' => (float) ($validated['x_pos'] ?? 0),
            'y_pos' => (float) ($validated['y_pos'] ?? 0),
            'viewport_width' => $validated['viewport_width'] ?? null,
            'viewport_height' => $validated['viewport_height'] ?? null,
            'type' => $validated['type'] ?? FeedbackItem::TYPE_TWEAK,
            'status' => FeedbackItem::STATUS_OPEN,
            'title' => $validated['title'],
            'content' => $validated['content'],
            'metadata' => $validated['metadata'] ?? [],
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Feedback logged successfully.',
            'item' => [
                'id' => $item->id,
                'title' => $item->title,
                'content' => $item->content,
                'type' => $item->type,
                'status' => $item->status,
                'selector' => $item->selector,
                'claude_prompt' => $item->toClaudePrompt(),
            ],
        ]);
    }

    /**
     * Add a threaded reply / comment to an existing feedback item.
     */
    public function storeComment(Request $request, FeedbackItem $feedback): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $currentUser = $request->user();
        $comment = FeedbackComment::create([
            'feedback_item_id' => $feedback->id,
            'user_id' => $currentUser ? $currentUser->id : 1,
            'content' => $validated['content'],
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'comment' => [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'created_at' => $comment->created_at->diffForHumans(),
                    'author' => [
                        'name' => $comment->user ? $comment->user->name : 'User',
                        'email' => $comment->user ? $comment->user->email : '',
                        'avatar' => $comment->user ? $comment->user->avatarUrl(40) : '',
                    ],
                ],
                'updated_prompt' => $feedback->fresh(['comments.user'])->toClaudePrompt(),
            ]);
        }

        return back()->with('status', 'Comment added.');
    }

    /**
     * Update status, category, or note contents.
     */
    public function update(Request $request, FeedbackItem $feedback): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:open,in_progress,resolved,dismissed'],
            'type' => ['nullable', 'string', 'in:bug,tweak,feature,copy'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
        ]);

        $feedback->update(array_filter($validated, fn ($v) => $v !== null));

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'status' => $feedback->status,
                'status_class' => $feedback->statusBadgeClass(),
                'message' => 'Feedback updated.',
            ]);
        }

        return back()->with('status', 'Feedback updated.');
    }

    /**
     * Return formatted Claude prompt for an item.
     */
    public function claudePrompt(FeedbackItem $feedback): JsonResponse
    {
        $feedback->load(['user', 'comments.user']);

        return response()->json([
            'ok' => true,
            'prompt' => $feedback->toClaudePrompt(),
        ]);
    }

    /**
     * Delete a feedback item.
     */
    public function destroy(FeedbackItem $feedback): JsonResponse|RedirectResponse
    {
        $feedback->delete();

        if (request()->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Feedback item deleted.',
            ]);
        }

        return redirect()->route('feedback.index')->with('status', 'Feedback item deleted.');
    }
}
