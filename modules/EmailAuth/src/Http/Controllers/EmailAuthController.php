<?php

namespace Modules\EmailAuth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Domains\RootDomainResolver;
use App\Services\Process\BackgroundArtisan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            ->filter(fn ($d) => $d !== '' && str_contains($d, '.'))
            ->unique()
            ->values();

        foreach ($siteDomains as $apex) {
            EmailAuthDomain::withTrashed()->firstOrCreate(['domain' => $apex]);
        }

        $query = EmailAuthDomain::query()
            ->whereIn('domain', $siteDomains)
            ->with(['latestCheck']);

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

        // Summary counts restricted to platform sites
        $baseCountQuery = EmailAuthDomain::query()->whereIn('domain', $siteDomains);
        $totalMonitored = (clone $baseCountQuery)->count();
        $totalPass = (clone $baseCountQuery)->whereNull('ignored_at')->where('last_overall_status', 'pass')->count();
        $totalWarn = (clone $baseCountQuery)->whereNull('ignored_at')->where('last_overall_status', 'warn')->count();
        $totalFail = (clone $baseCountQuery)->whereNull('ignored_at')->where('last_overall_status', 'fail')->count();
        $totalIgnored = (clone $baseCountQuery)->whereNotNull('ignored_at')->count();

        // Sites mapping per domain for count pills
        $siteCounts = Site::query()
            ->whereNotNull('domain')
            ->where('is_inactive', false)
            ->get(['id', 'domain'])
            ->groupBy(fn ($s) => RootDomainResolver::resolve((string) $s->domain))
            ->map(fn ($group) => $group->count());

        $isGlobalScanning = Cache::has('email_auth.scan_fleet');

        return view('email-auth::index', compact(
            'domains',
            'status',
            'search',
            'totalMonitored',
            'totalPass',
            'totalWarn',
            'totalFail',
            'totalIgnored',
            'siteCounts',
            'isGlobalScanning'
        ));
    }

    public function scanAll(Request $request, BackgroundArtisan $artisan, ActionLogger $actionLogger): JsonResponse|RedirectResponse
    {
        $lockKey = 'email_auth.scan_fleet';
        $result = $artisan->start(
            $lockKey,
            ['clockwork:check-email-auth'],
            900,
            'email-auth-scan-fleet-bg'
        );

        if ($result->alreadyRunning()) {
            $msg = 'A global email authentication scan is already in progress.';
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'message' => $msg, 'already_running' => true], 409);
            }

            return back()->with('status', $msg);
        }

        if ($result->failed()) {
            $err = $result->error ?? 'Could not start global scan.';
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $err], 500);
            }

            return back()->with('error', $err);
        }

        $actionLogger->record(
            actionType: 'email_auth_fleet_scan_started',
            summary: 'Dispatched global email authentication fleet scan',
            target: 'email-auth'
        );

        $msg = 'Global email authentication scan started in the background. Fleet status will update as domains complete.';

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'message' => $msg]);
        }

        return back()->with('status', $msg);
    }

    public function scanNow(Request $request, EmailAuthScanner $scanner): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string'],
        ]);

        $rawInput = strtolower(trim($validated['domain']));
        $rawInput = preg_replace('#^https?://#i', '', $rawInput);
        $rawInput = explode('/', $rawInput)[0];
        $rawInput = explode(':', $rawInput)[0];
        $rawInput = trim($rawInput, '.');

        // Enforce full FQDN domain syntax with dot and valid hostname characters
        if (! str_contains($rawInput, '.') || ! filter_var($rawInput, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $err = 'Invalid domain. Please enter a valid fully qualified domain name (e.g. example.com).';
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $err], 422);
            }

            return back()->with('error', $err);
        }

        $apex = RootDomainResolver::resolve($rawInput);
        if ($apex === '' || ! str_contains($apex, '.')) {
            $err = 'Invalid domain specified. Root domain could not be resolved.';
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $err], 422);
            }

            return back()->with('error', $err);
        }

        // Restrict to sites actually on the platform
        $siteDomains = Site::query()
            ->whereNotNull('domain')
            ->where('is_inactive', false)
            ->hostMonitored()
            ->pluck('domain')
            ->map(fn ($d) => RootDomainResolver::resolve((string) $d))
            ->filter(fn ($d) => $d !== '' && str_contains($d, '.'))
            ->unique()
            ->values();

        if (! $siteDomains->contains($apex)) {
            $err = "Domain '{$apex}' does not belong to any active monitored site on this platform.";
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $err], 422);
            }

            return back()->with('error', $err);
        }

        // Restore if previously soft-deleted
        $domainModel = EmailAuthDomain::withTrashed()->where('domain', $apex)->first();
        if ($domainModel?->trashed()) {
            $domainModel->restore();
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

    public function destroy(Request $request, string $domain, ActionLogger $actionLogger): JsonResponse|RedirectResponse
    {
        $domainModel = EmailAuthDomain::where('domain', $domain)->firstOrFail();
        $domainModel->delete();

        $actionLogger->record(
            actionType: 'email_auth_domain_removed',
            summary: "Removed domain {$domain} from Email Authentication monitoring",
            target: 'email-auth',
            details: ['domain' => $domain]
        );

        $msg = "Domain {$domain} removed from Email Authentication monitoring.";

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $msg,
            ]);
        }

        return redirect()->route('email-auth.index')->with('status', $msg);
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
