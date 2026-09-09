<?php

namespace App\Http\Controllers;

use App\Jobs\PushCompanionBrandingJob;
use App\Mail\SiteVulnerabilityReportMail;
use App\Models\Site;
use App\Services\Companion\CompanionBrandingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Modules\Core\ModuleStateResolver;

class CompanionSettingsController extends Controller
{
    public function index(Request $request, CompanionBrandingManager $brandingManager): View
    {
        $branding = $brandingManager->get();
        $reportsBranding = $brandingManager->getReportsBranding();
        $emailBranding = $brandingManager->getEmailBranding();
        $reportsModuleEnabled = app(ModuleStateResolver::class)->isEnabled('client_reports');

        $totalInstalled = Site::query()
            ->where('companion_installed', true)
            ->where('is_inactive', false)
            ->count();
        $totalSites = Site::query()
            ->where('is_inactive', false)
            ->count();

        $activeTab = (string) $request->query('tab', 'companion');
        if (! in_array($activeTab, ['companion', 'reports', 'email'], true)) {
            $activeTab = 'companion';
        }

        return view('settings.companion', [
            'branding' => $branding,
            'reportsBranding' => $reportsBranding,
            'emailBranding' => $emailBranding,
            'reportsModuleEnabled' => $reportsModuleEnabled,
            'activeTab' => $activeTab,
            'totalInstalled' => $totalInstalled,
            'totalSites' => $totalSites,
        ]);
    }

    public function preview(CompanionBrandingManager $brandingManager): View
    {
        $branding = $brandingManager->get();

        return view('settings.companion-preview', [
            'branding' => $branding,
        ]);
    }

    public function update(Request $request, CompanionBrandingManager $brandingManager): RedirectResponse|JsonResponse
    {
        $tab = $request->input('tab', 'companion');

        if ($tab === 'reports') {
            $validated = $request->validate([
                'enabled' => 'nullable|boolean',
                'company_name' => 'nullable|string|max:120',
                'support_email' => 'nullable|email|max:120',
                'support_url' => 'nullable|string|max:255',
                'primary_color' => 'nullable|string|max:30',
                'accent_color' => 'nullable|string|max:30',
                'footer_text' => 'nullable|string|max:500',
            ]);

            $brandingManager->saveReportsBranding([
                'enabled' => $request->boolean('enabled'),
                'company_name' => $validated['company_name'] ?? null,
                'support_email' => $validated['support_email'] ?? null,
                'support_url' => $validated['support_url'] ?? null,
                'primary_color' => $validated['primary_color'] ?? null,
                'accent_color' => $validated['accent_color'] ?? null,
                'footer_text' => $validated['footer_text'] ?? null,
            ]);

            $msg = 'Client Reports styling and branding saved successfully.';

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'branding' => $brandingManager->getReportsBranding(),
                ]);
            }

            return redirect()->route('settings.companion.index', ['tab' => 'reports'])->with('status', $msg);
        }

        if ($tab === 'email') {
            $validated = $request->validate([
                'enabled' => 'nullable|boolean',
                'company_name' => 'nullable|string|max:120',
                'sender_name' => 'nullable|string|max:120',
                'reply_to' => 'nullable|email|max:120',
                'header_bg' => 'nullable|string|max:30',
                'accent_color' => 'nullable|string|max:30',
                'badge_text' => 'nullable|string|max:60',
                'footer_text' => 'nullable|string|max:500',
                'use_logo' => 'nullable|boolean',
            ]);

            $brandingManager->saveEmailBranding([
                'enabled' => $request->boolean('enabled'),
                'company_name' => $validated['company_name'] ?? null,
                'sender_name' => $validated['sender_name'] ?? null,
                'reply_to' => $validated['reply_to'] ?? null,
                'header_bg' => $validated['header_bg'] ?? null,
                'accent_color' => $validated['accent_color'] ?? null,
                'badge_text' => $validated['badge_text'] ?? null,
                'footer_text' => $validated['footer_text'] ?? null,
                'use_logo' => $request->boolean('use_logo', true),
            ]);

            $msg = 'Plugin notification email branding saved successfully.';

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'branding' => $brandingManager->getEmailBranding(),
                ]);
            }

            return redirect()->route('settings.companion.index', ['tab' => 'email'])->with('status', $msg);
        }

        $validated = $request->validate([
            'enabled' => 'nullable|boolean',
            'company_name' => 'nullable|string|max:120',
            'company_url' => 'nullable|string|max:255',
            'support_email' => 'nullable|email|max:120',
            'support_url' => 'nullable|string|max:255',
            'plugin_name' => 'nullable|string|max:120',
            'plugin_description' => 'nullable|string|max:500',
            'menu_title' => 'nullable|string|max:60',
            'menu_icon' => 'nullable|string|max:60',
            'hide_plugin_row' => 'nullable|boolean',
            'hide_help_links' => 'nullable|boolean',
            'footer_text' => 'nullable|string|max:255',
        ]);

        $brandingManager->save([
            'enabled' => $request->boolean('enabled'),
            'company_name' => $validated['company_name'] ?? null,
            'company_url' => $validated['company_url'] ?? null,
            'support_email' => $validated['support_email'] ?? null,
            'support_url' => $validated['support_url'] ?? null,
            'plugin_name' => $validated['plugin_name'] ?? null,
            'plugin_description' => $validated['plugin_description'] ?? null,
            'menu_title' => $validated['menu_title'] ?? null,
            'menu_icon' => $validated['menu_icon'] ?? null,
            'hide_plugin_row' => $request->boolean('hide_plugin_row'),
            'hide_help_links' => $request->boolean('hide_help_links'),
            'footer_text' => $validated['footer_text'] ?? null,
        ]);

        if ($request->boolean('sync_fleet')) {
            PushCompanionBrandingJob::dispatch();
            $msg = 'Companion branding saved and queued for fleet sync.';
        } else {
            $msg = 'Companion branding saved successfully.';
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
                'branding' => $brandingManager->get(),
            ]);
        }

        return redirect()->route('settings.companion.index')->with('status', $msg);
    }

    public function uploadLogo(Request $request, CompanionBrandingManager $brandingManager): RedirectResponse|JsonResponse
    {
        $request->validate([
            'logo' => 'required|file|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        $url = $brandingManager->uploadLogo($request->file('logo'));

        $msg = 'Brand logo uploaded successfully.';

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
                'logo_url' => $url,
            ]);
        }

        return back()->with('status', $msg);
    }

    public function sync(Request $request, CompanionBrandingManager $brandingManager): RedirectResponse|JsonResponse
    {
        $res = $brandingManager->syncFleet();
        $msg = "Branding synced to {$res['successful']} of {$res['total']} site(s).";

        if ($res['failed'] > 0) {
            $msg .= " ({$res['failed']} failed)";
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => $res['failed'] === 0,
                'message' => $msg,
                'stats' => $res,
            ]);
        }

        return back()->with($res['failed'] > 0 ? 'warning' : 'status', $msg);
    }

    public function reset(CompanionBrandingManager $brandingManager): RedirectResponse
    {
        $brandingManager->reset();

        return redirect()->route('settings.companion.index')
            ->with('status', 'Companion branding reset to Clockwork Control defaults.');
    }

    public function resetReports(CompanionBrandingManager $brandingManager): RedirectResponse
    {
        $brandingManager->resetReports();

        return redirect()->route('settings.companion.index', ['tab' => 'reports'])
            ->with('status', 'Client Reports styling reset to shared defaults.');
    }

    public function resetEmail(CompanionBrandingManager $brandingManager): RedirectResponse
    {
        $brandingManager->resetEmail();

        return redirect()->route('settings.companion.index', ['tab' => 'email'])
            ->with('status', 'Plugin notification email branding reset to defaults.');
    }

    public function sendTestEmail(Request $request, CompanionBrandingManager $brandingManager): JsonResponse
    {
        $validated = $request->validate([
            'recipient' => ['required', 'email:rfc'],
        ]);

        $site = Site::query()->first() ?? new Site([
            'domain' => 'demo-site.example.com',
            'is_wordpress' => true,
        ]);

        $dummyVulns = [
            [
                'plugin_name' => 'Elementor Website Builder',
                'plugin_slug' => 'elementor',
                'current_version' => '3.18.0',
                'active' => true,
                'patched_in' => '3.18.2',
                'vulnerability' => (object) [
                    'title' => 'Elementor <= 3.18.1 - Contributor+ Stored Cross-Site Scripting via Template Import',
                    'cve' => 'CVE-2023-48777',
                    'cvss_score' => 6.5,
                    'cvss_severity' => 'Medium',
                    'patched_in' => '3.18.2',
                ],
            ],
            [
                'plugin_name' => 'Essential Addons for Elementor',
                'plugin_slug' => 'essential-addons-for-elementor-lite',
                'current_version' => '5.9.1',
                'active' => true,
                'patched_in' => '5.9.4',
                'vulnerability' => (object) [
                    'title' => 'Essential Addons <= 5.9.3 - Authenticated (Contributor+) Stored XSS',
                    'cve' => 'CVE-2024-12345',
                    'cvss_score' => 6.4,
                    'cvss_severity' => 'Medium',
                    'patched_in' => '5.9.4',
                ],
            ],
        ];

        $branding = $brandingManager->getEmailBranding();

        try {
            Mail::to($validated['recipient'])->send(
                new SiteVulnerabilityReportMail(
                    site: $site,
                    vulns: $dummyVulns,
                    senderName: $branding['sender_name'] ?: 'Clockwork Security',
                    branding: $branding,
                )
            );

            return response()->json([
                'ok' => true,
                'message' => "Test vulnerability report emailed to {$validated['recipient']}.",
                'recipient' => $validated['recipient'],
                'mailer' => config('mail.default'),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Mail send failed: '.$e->getMessage(),
            ], 500);
        }
    }
}
