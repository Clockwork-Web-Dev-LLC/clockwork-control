<?php

namespace App\Http\Controllers;

use App\Models\IgnoredWpAdmin;
use App\Models\Site;
use App\Services\Security\FleetAdminAuditor;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityAdminsController extends Controller
{
    public function index(FleetAdminAuditor $auditor): View
    {
        $rows = $auditor->inventory();
        $flaggedCount = collect($rows)->where('flagged', true)->count();

        return view('security.admins', [
            'activeTab' => 'admins',
            'rows' => $rows,
            'flaggedCount' => $flaggedCount,
            'flaggedWpAdminCount' => $flaggedCount,
            'approvedDomains' => implode(', ', $auditor->approvedDomains()),
            'approvedEmails' => implode(', ', $auditor->approvedEmails()),
            'allowlistConfigured' => $auditor->allowlistConfigured(),
        ]);
    }

    public function updateAllowlist(Request $request, Settings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'approved_domains' => ['nullable', 'string', 'max:2000'],
            'approved_emails' => ['nullable', 'string', 'max:4000'],
        ]);

        $settings->put(
            FleetAdminAuditor::SETTING_DOMAINS,
            $this->splitList($validated['approved_domains'] ?? ''),
        );
        $settings->put(
            FleetAdminAuditor::SETTING_EMAILS,
            $this->splitList($validated['approved_emails'] ?? ''),
        );

        return back()->with('status', 'Approved WordPress admin allowlist saved.');
    }

    public function ignore(Request $request, Site $site): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        IgnoredWpAdmin::query()->updateOrCreate(
            [
                'site_id' => $site->id,
                'subject' => strtolower(trim($validated['subject'])),
            ],
            [
                'reason' => $validated['reason'] ?? null,
                'ignored_by_user_id' => $request->user()?->id,
            ],
        );

        return back()->with('status', 'Administrator acknowledgement saved.');
    }

    public function unignore(Site $site, IgnoredWpAdmin $ignoredWpAdmin): RedirectResponse
    {
        abort_unless($ignoredWpAdmin->site_id === $site->id, 404);
        $ignoredWpAdmin->delete();

        return back()->with('status', 'Administrator is flagged again.');
    }

    /**
     * @return list<string>
     */
    private function splitList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_filter(array_map(
            fn (string $item) => strtolower(ltrim(trim($item), '@')),
            $parts,
        )));
    }
}
