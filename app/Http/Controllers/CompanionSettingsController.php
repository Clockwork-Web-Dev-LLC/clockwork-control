<?php

namespace App\Http\Controllers;

use App\Jobs\PushCompanionBrandingJob;
use App\Models\Site;
use App\Services\Companion\CompanionBrandingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanionSettingsController extends Controller
{
    public function index(CompanionBrandingManager $brandingManager): View
    {
        $branding = $brandingManager->get();
        $totalInstalled = Site::query()
            ->where('companion_installed', true)
            ->where('is_inactive', false)
            ->count();
        $totalSites = Site::query()
            ->where('is_inactive', false)
            ->count();

        return view('settings.companion', [
            'branding' => $branding,
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
            'logo' => 'required|file|mimes:png,jpg,jpeg,svg,webp|max:2048',
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
}
