<?php

namespace Modules\EmailAuth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Domains\RootDomainResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\EmailAuth\Models\EmailAuthDomain;
use Modules\EmailAuth\Services\EmailAuthScanner;

class EmailAuthController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $search = $request->query('search');

        // Ensure all apex domains from active monitored sites are initialized in email_auth_domains
        $siteDomains = Site::query()
            ->whereNotNull('domain')
            ->where('is_inactive', false)
            ->hostMonitored()
            ->pluck('domain')
            ->map(fn ($d) => RootDomainResolver::resolve((string) $d))
            ->filter(fn ($d) => $d !== '')
            ->unique()
            ->values();

        foreach ($siteDomains as $apex) {
            EmailAuthDomain::firstOrCreate(['domain' => $apex]);
        }

        $query = EmailAuthDomain::query()->with(['latestCheck']);

        if ($search) {
            $query->where('domain', 'like', "%{$search}%");
        }

        if ($status === 'ignored') {
            $query->whereNotNull('ignored_at');
        } elseif ($status === 'pass' || $status === 'warn' || $status === 'fail') {
            $query->whereNull('ignored_at')
                ->where('last_overall_status', $status);
        } elseif ($status === 'unscanned') {
            $query->whereNull('last_checked_at');
        }

        $domains = $query->orderBy('domain')->paginate(30)->withQueryString();

        // Summary counts
        $totalMonitored = EmailAuthDomain::count();
        $totalPass = EmailAuthDomain::whereNull('ignored_at')->where('last_overall_status', 'pass')->count();
        $totalWarn = EmailAuthDomain::whereNull('ignored_at')->where('last_overall_status', 'warn')->count();
        $totalFail = EmailAuthDomain::whereNull('ignored_at')->where('last_overall_status', 'fail')->count();
        $totalIgnored = EmailAuthDomain::whereNotNull('ignored_at')->count();

        // Sites mapping per domain for count pills
        $siteCounts = Site::query()
            ->whereNotNull('domain')
            ->where('is_inactive', false)
            ->get(['id', 'domain'])
            ->groupBy(fn ($s) => RootDomainResolver::resolve((string) $s->domain))
            ->map(fn ($group) => $group->count());

        return view('email-auth::index', compact(
            'domains',
            'status',
            'search',
            'totalMonitored',
            'totalPass',
            'totalWarn',
            'totalFail',
            'totalIgnored',
            'siteCounts'
        ));
    }

    public function scanNow(Request $request, EmailAuthScanner $scanner): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string'],
        ]);

        $apex = RootDomainResolver::resolve($validated['domain']);
        if ($apex === '') {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => 'Invalid domain'], 422);
            }

            return back()->with('error', 'Invalid domain specified.');
        }

        $check = $scanner->scan($apex);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'check' => $check,
            ]);
        }

        return back()->with('status', "Scanned email authentication for {$apex}: ".strtoupper($check->overall_status).'.');
    }

    public function toggleIgnore(Request $request, string $domain, ActionLogger $actionLogger): JsonResponse|RedirectResponse
    {
        $domainModel = EmailAuthDomain::where('domain', $domain)->firstOrFail();

        $wasIgnored = $domainModel->isIgnored();
        if ($wasIgnored) {
            $domainModel->update([
                'ignored_at' => null,
                'ignored_reason' => null,
            ]);
            $actionLogger->record(
                actionType: 'email_auth_domain_unignored',
                summary: "Unignored email authentication monitoring for {$domain}",
                target: 'email-auth',
                details: ['domain' => $domain]
            );
            $msg = "Domain {$domain} is now actively monitored.";
        } else {
            $reason = (string) $request->input('reason', 'Operator ignored via Email Auth panel');
            $domainModel->update([
                'ignored_at' => now(),
                'ignored_reason' => $reason,
            ]);
            $actionLogger->record(
                actionType: 'email_auth_domain_ignored',
                summary: "Ignored email authentication monitoring for {$domain}",
                target: 'email-auth',
                details: ['domain' => $domain, 'reason' => $reason]
            );
            $msg = "Domain {$domain} has been ignored from alerts.";
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'ignored' => ! $wasIgnored,
                'message' => $msg,
            ]);
        }

        return back()->with('status', $msg);
    }

    public function updateSelectors(Request $request, string $domain, EmailAuthScanner $scanner): JsonResponse|RedirectResponse
    {
        $domainModel = EmailAuthDomain::where('domain', $domain)->firstOrFail();

        $validated = $request->validate([
            'selectors' => ['nullable', 'string'],
        ]);

        $selectorsStr = (string) ($validated['selectors'] ?? '');
        $selectors = array_values(array_unique(array_filter(array_map('trim', explode(',', $selectorsStr)))));

        $domainModel->update([
            'custom_dkim_selectors' => $selectors,
        ]);

        // Re-scan with newly configured selectors
        $check = $scanner->scan($domain);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'custom_dkim_selectors' => $selectors,
                'check' => $check,
            ]);
        }

        return back()->with('status', "Updated DKIM selectors for {$domain} and re-scanned.");
    }
}
