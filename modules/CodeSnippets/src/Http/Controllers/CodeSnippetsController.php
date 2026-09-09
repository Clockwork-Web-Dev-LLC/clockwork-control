<?php

namespace Modules\CodeSnippets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\CodeSnippets\Models\CodeSnippet;
use Throwable;

class CodeSnippetsController extends Controller
{
    /**
     * Display list of snippets and execution workbench.
     */
    public function index(Request $request): View
    {
        $snippets = CodeSnippet::query()
            ->orderByDesc('is_preset')
            ->orderBy('name')
            ->get();

        $sites = Site::query()
            ->where('is_inactive', false)
            ->where('companion_installed', true)
            ->orderBy('domain')
            ->get(['id', 'domain', 'companion_capabilities']);

        $selectedSiteId = (int) $request->query('site_id', 0);
        $selectedSnippetId = (int) $request->query('snippet_id', 0);

        $canManage = $request->user()?->isAdmin() ?? false;

        return view('code-snippets::dashboard.snippets.index', compact('snippets', 'sites', 'selectedSiteId', 'selectedSnippetId', 'canManage'));
    }

    /**
     * Store a new custom code snippet.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'code' => ['required', 'string'],
        ]);

        $snippet = CodeSnippet::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'code' => $validated['code'],
            'is_preset' => false,
        ]);

        return redirect()->route('snippets.index', ['snippet_id' => $snippet->id])
            ->with('status', "Snippet '{$snippet->name}' created.");
    }

    /**
     * Update an existing snippet.
     */
    public function update(CodeSnippet $snippet, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'code' => ['required', 'string'],
        ]);

        $snippet->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'code' => $validated['code'],
        ]);

        return redirect()->route('snippets.index', ['snippet_id' => $snippet->id])
            ->with('status', "Snippet '{$snippet->name}' updated.");
    }

    /**
     * Delete a custom snippet.
     */
    public function destroy(CodeSnippet $snippet): RedirectResponse
    {
        if ($snippet->is_preset) {
            return back()->with('status_error', 'Built-in preset snippets cannot be deleted.');
        }

        $name = $snippet->name;
        $snippet->delete();

        return redirect()->route('snippets.index')
            ->with('status', "Snippet '{$name}' deleted.");
    }

    /**
     * Execute PHP code snippet across selected sites.
     */
    public function execute(Request $request, ActionLogger $logger): JsonResponse
    {
        $validated = $request->validate([
            'site_ids' => ['required', 'array', 'min:1', 'max:15'],
            'site_ids.*' => ['integer'],
            'code' => ['required', 'string'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $code = trim($validated['code']);
        $timeout = (int) ($validated['timeout'] ?? 30);
        $siteIds = array_values(array_map('intval', $validated['site_ids']));

        $sites = Site::query()
            ->whereIn('id', $siteIds)
            ->where('companion_installed', true)
            ->get();

        $results = [];

        foreach ($sites as $site) {
            $caps = $site->companion_capabilities ?? [];
            if (! in_array('code-snippets', $caps, true)) {
                $results[] = [
                    'site_id' => $site->id,
                    'domain' => $site->domain,
                    'ok' => false,
                    'error' => 'Companion plugin missing code-snippets capability.',
                ];

                continue;
            }

            try {
                $client = new ClockworkCompanionClient($site);
                $execResult = $client->executeCodeSnippet($code, $timeout);

                $results[] = [
                    'site_id' => $site->id,
                    'domain' => $site->domain,
                    'ok' => (bool) ($execResult['ok'] ?? false),
                    'output' => (string) ($execResult['output'] ?? ''),
                    'return_value' => $execResult['return_value'] ?? null,
                    'duration_ms' => $execResult['duration_ms'] ?? 0,
                    'memory_used_bytes' => $execResult['memory_used_bytes'] ?? 0,
                    'error' => $execResult['error'] ?? null,
                ];

                $logger->record(
                    actionType: ActionLog::TYPE_CODE_SNIPPET_EXECUTED,
                    summary: "Executed code snippet on {$site->domain}: ".(($execResult['ok'] ?? false) ? 'OK' : 'Error'),
                    site: $site,
                    target: 'snippet',
                    details: [
                        'duration_ms' => $execResult['duration_ms'] ?? 0,
                        'memory_used_bytes' => $execResult['memory_used_bytes'] ?? 0,
                        'ok' => $execResult['ok'] ?? false,
                    ],
                    ok: (bool) ($execResult['ok'] ?? false),
                    error: $execResult['error'] ?? null,
                );
            } catch (Throwable $e) {
                $results[] = [
                    'site_id' => $site->id,
                    'domain' => $site->domain,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];

                $logger->record(
                    actionType: ActionLog::TYPE_CODE_SNIPPET_EXECUTED,
                    summary: "Failed executing snippet on {$site->domain}: {$e->getMessage()}",
                    site: $site,
                    target: 'snippet',
                    ok: false,
                    error: $e->getMessage(),
                );
            }
        }

        return response()->json([
            'ok' => true,
            'total_sites' => count($siteIds),
            'results' => $results,
        ]);
    }
}
