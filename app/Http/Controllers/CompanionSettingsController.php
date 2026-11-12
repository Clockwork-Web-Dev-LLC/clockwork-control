<?php

namespace App\Http\Controllers;

use App\Jobs\PushCompanionBrandingJob;
use App\Mail\SiteVulnerabilityReportMail;
use App\Models\Site;
use App\Services\Companion\CompanionBrandingManager;
use App\Services\Process\BackgroundArtisan;
use Closure;
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
        if (! in_array($activeTab, ['companion', 'renegade', 'reports', 'email'], true)) {
            $activeTab = 'companion';
        }

        return view('settings.companion', [
            'branding' => $branding,
            'renegadeBranding' => $brandingManager->getRenegade(),
            'reportsBranding' => $reportsBranding,
            'emailBranding' => $emailBranding,
            'masterPalette' => $brandingManager->getMasterPalette(),
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

        if ($tab === 'master') {
            $validated = $request->validate([
                'primary_color' => CompanionBrandingManager::hexColorRules(),
                'accent_color' => CompanionBrandingManager::hexColorRules(),
                'apply_to_all' => 'nullable|boolean',
            ]);

            $applyToAll = $request->boolean('apply_to_all');
            $brandingManager->saveMasterPalette([
                'primary_color' => $validated['primary_color'] ?? null,
                'accent_color' => $validated['accent_color'] ?? null,
            ], $applyToAll);

            $msg = $applyToAll
                ? 'Master agency palette saved and applied to Companion, Reports, and Emails.'
                : 'Master agency palette saved successfully.';

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'masterPalette' => $brandingManager->getMasterPalette(),
                ]);
            }

            return redirect()->route('settings.companion.index', ['tab' => $request->input('current_tab', 'companion')])->with('status', $msg);
        }

        if ($tab === 'renegade') {
            $validated = $request->validate([
                'enabled' => 'nullable|boolean',
                'plugin_name' => 'nullable|string|max:120',
                'brand_text' => 'nullable|string|max:60',
                'plugin_description' => 'nullable|string|max:500',
                'menu_title' => 'nullable|string|max:60',
                'menu_icon' => 'nullable|string|max:60',
                'primary_color' => CompanionBrandingManager::hexColorRules(),
                'accent_color' => CompanionBrandingManager::hexColorRules(),
                'hide_plugin_row' => 'nullable|boolean',
                'hide_help_links' => 'nullable|boolean',
            ]);

            $brandingManager->saveRenegade([
                'enabled' => $request->boolean('enabled'),
                'plugin_name' => $validated['plugin_name'] ?? null,
                'brand_text' => $validated['brand_text'] ?? null,
                'plugin_description' => $validated['plugin_description'] ?? null,
                'menu_title' => $validated['menu_title'] ?? null,
                'menu_icon' => $validated['menu_icon'] ?? null,
                'primary_color' => $validated['primary_color'] ?? null,
                'accent_color' => $validated['accent_color'] ?? null,
                'hide_plugin_row' => $request->boolean('hide_plugin_row'),
                'hide_help_links' => $request->boolean('hide_help_links'),
            ]);

            if ($request->boolean('push_immediately', true)) {
                PushCompanionBrandingJob::dispatch();
            }

            $msg = 'Clockwork Renegade branding saved successfully.';

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'branding' => $brandingManager->getRenegade(),
                ]);
            }

            return redirect()->route('settings.companion.index', ['tab' => 'renegade'])->with('status', $msg);
        }

        if ($tab === 'reports') {
            $validated = $request->validate([
                'enabled' => 'nullable|boolean',
                'company_name' => 'nullable|string|max:120',
                'support_email' => 'nullable|email|max:120',
                'support_url' => self::publicUrlRules(),
                'primary_color' => CompanionBrandingManager::hexColorRules(),
                'accent_color' => CompanionBrandingManager::hexColorRules(),
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
                'header_bg' => CompanionBrandingManager::hexColorRules(),
                'accent_color' => CompanionBrandingManager::hexColorRules(),
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
            'company_url' => self::publicUrlRules(),
            'support_email' => 'nullable|email|max:120',
            'support_url' => self::publicUrlRules(),
            'plugin_name' => 'nullable|string|max:120',
            'plugin_description' => 'nullable|string|max:500',
            'menu_title' => 'nullable|string|max:60',
            'menu_icon' => 'nullable|string|max:60',
            'brand_text' => 'nullable|string|max:60',
            'logo_url' => self::logoUrlRules(),
            'primary_color' => CompanionBrandingManager::hexColorRules(),
            'accent_color' => CompanionBrandingManager::hexColorRules(),
            'hide_plugin_row' => 'nullable|boolean',
            'hide_help_links' => 'nullable|boolean',
            'footer_text' => 'nullable|string|max:255',
            'unlock_hub_domain' => 'nullable|string|max:120',
        ]);

        $brandingData = [
            'enabled' => $request->boolean('enabled'),
            'company_name' => $validated['company_name'] ?? null,
            'company_url' => $validated['company_url'] ?? null,
            'support_email' => $validated['support_email'] ?? null,
            'support_url' => $validated['support_url'] ?? null,
            'plugin_name' => $validated['plugin_name'] ?? null,
            'plugin_description' => $validated['plugin_description'] ?? null,
            'menu_title' => $validated['menu_title'] ?? null,
            'menu_icon' => $validated['menu_icon'] ?? null,
            'brand_text' => $validated['brand_text'] ?? null,
            'primary_color' => $validated['primary_color'] ?? null,
            'accent_color' => $validated['accent_color'] ?? null,
            'hide_plugin_row' => $request->boolean('hide_plugin_row'),
            'hide_help_links' => $request->boolean('hide_help_links'),
            'footer_text' => $validated['footer_text'] ?? null,
            'unlock_hub_domain' => $validated['unlock_hub_domain'] ?? null,
        ];

        if ($request->has('logo_url')) {
            $brandingData['logo_url'] = $request->input('logo_url');
        }

        $brandingManager->save($brandingData);

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

    public function sync(Request $request, BackgroundArtisan $background): RedirectResponse|JsonResponse
    {
        $result = $background->start(
            'companion.push_branding',
            ['clockwork:push-companion-branding'],
            900,
            'companion-branding-bg',
        );

        if ($result->alreadyRunning()) {
            $msg = 'A branding sync is already running.';
        } elseif ($result->failed()) {
            $msg = $result->error ?? 'Could not start the branding sync.';

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $msg], 500);
            }

            return back()->with('status_error', $msg);
        } else {
            $msg = 'Branding sync started in the background. Companion sites will pick up the new branding as each push completes.';
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => $msg]);
        }

        return back()->with('status', $msg);
    }

    public function reset(CompanionBrandingManager $brandingManager): RedirectResponse
    {
        $brandingManager->reset();

        return redirect()->route('settings.companion.index')
            ->with('status', 'Companion branding reset to Clockwork Control defaults.');
    }

    public function resetRenegade(CompanionBrandingManager $brandingManager): RedirectResponse
    {
        $brandingManager->resetRenegade();

        return redirect()->route('settings.companion.index', ['tab' => 'renegade'])
            ->with('status', 'Renegade branding reset to agency defaults.');
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

    /**
     * Rules for an address clients are sent to (agency website, support
     * portal). Blank is allowed. A value must be a public http(s) URL: not
     * localhost, a private or reserved IP, an internal-only name, or
     * Control's own URL (APP_URL), which runs on a private host clients
     * can't reach.
     *
     * @return list<mixed>
     */
    private static function publicUrlRules(): array
    {
        return [
            'nullable',
            'string',
            'max:255',
            'url:http,https',
            function (string $attribute, mixed $value, Closure $fail): void {
                $host = is_string($value) ? parse_url($value, PHP_URL_HOST) : null;

                // Not a URL at all: the url rule already says so.
                if (! is_string($host) || $host === '') {
                    return;
                }

                if (! self::isPublicHost($host)) {
                    $fail('The :attribute must be a public web address clients can reach, not localhost, a private network address or this app\'s own URL.');
                }
            },
        ];
    }

    private static function isPublicHost(string $host): bool
    {
        $host = rtrim(strtolower(trim($host, '[]')), '.');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
        }

        // Single-label names (localhost, intranet hosts) don't resolve publicly.
        if (! str_contains($host, '.')) {
            return false;
        }

        foreach (['.localhost', '.local', '.internal', '.lan', '.home.arpa', '.test', '.invalid'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return ! is_string($appHost) || $host !== rtrim(strtolower($appHost), '.');
    }

    /**
     * Rules for the white-label logo URL: empty, a relative `/storage/branding/...`
     * path (or APP_URL-prefixed uploaded logo URL), or a public http(s) URL.
     *
     * @return list<mixed>
     */
    private static function logoUrlRules(): array
    {
        return [
            'nullable',
            'string',
            'max:500',
            function (string $attribute, mixed $value, Closure $fail): void {
                $url = trim((string) $value);
                if ($url === '') {
                    return;
                }

                // Allow locally uploaded public-disk logos (`/storage/branding/...` or `<APP_URL>/storage/branding/...`).
                if (str_starts_with($url, '/storage/branding/') && ! str_contains($url, '..')) {
                    return;
                }

                $appUrl = rtrim((string) config('app.url'), '/');
                if ($appUrl !== '' && str_starts_with($url, $appUrl.'/storage/branding/') && ! str_contains($url, '..')) {
                    return;
                }

                if (! preg_match('#^https?://#i', $url)) {
                    $fail('The :attribute must be an http(s) URL or an uploaded branding path.');

                    return;
                }

                $host = parse_url($url, PHP_URL_HOST);
                if (! is_string($host) || $host === '' || ! self::isPublicHost($host)) {
                    $fail('The :attribute must point to a public web host, not localhost or a private network address.');
                }
            },
        ];
    }
}
