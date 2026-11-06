<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Seo\Google\GoogleOAuth;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Models\GoogleProperty;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Support\SeoSettings;

class SeoSettingsController extends Controller
{
    public function index(GoogleOAuth $oauth, SeoSettings $settings): View
    {
        $connection = GoogleConnection::current();

        return view('seo::settings', [
            'connection' => $connection,
            'clientSource' => $oauth->clientSource(),
            'isConfigured' => $oauth->isConfigured(),
            'redirectUri' => $oauth->redirectUri(),
            'loginHint' => $settings->loginHint(),
            'finalAfterDays' => $settings->finalAfterDays(),
            'aiReferrerRegex' => $settings->aiReferrerRegex(),
            'defaultAiReferrerRegex' => SeoSettings::DEFAULT_AI_REFERRER_REGEX,
            'gscPropertyCount' => $connection
                ? GoogleProperty::query()->where('connection_id', $connection->id)->where('kind', GoogleProperty::KIND_GSC)->count()
                : 0,
            'profileCount' => SiteProfile::query()->count(),
        ]);
    }

    public function update(Request $request, SeoSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'login_hint' => ['required', 'email', 'max:191'],
            'final_after_days' => ['required', 'integer', 'min:2', 'max:15'],
            'ai_referrer_regex' => ['nullable', 'string', 'max:4096'],
        ]);

        $settings->save([
            'login_hint' => $validated['login_hint'],
            'final_after_days' => (int) $validated['final_after_days'],
            'ai_referrer_regex' => $validated['ai_referrer_regex'] ?? null,
        ]);

        return redirect()->route('seo.settings')->with('status', 'SEO settings saved.');
    }
}
