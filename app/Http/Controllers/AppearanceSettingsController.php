<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

class AppearanceSettingsController extends Controller
{
    public const VALID_THEMES = ['system', 'light', 'dark', 'high-contrast'];

    public const COOKIE_NAME = 'cw_theme';

    /**
     * Persist operator theme preference (system / light / dark / midnight / high-contrast).
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::in(self::VALID_THEMES)],
        ]);

        $theme = $validated['theme'];

        if ($user = $request->user()) {
            $user->update(['theme' => $theme]);
        }

        // 1-year client cookie so pre-paint FOUC script and guest pages know the preference
        // without waiting for database hydration. httpOnly must be false so document.cookie can read it.
        $cookie = Cookie::make(
            name: self::COOKIE_NAME,
            value: $theme,
            minutes: 525600, // 1 year
            path: '/',
            domain: null,
            secure: $request->isSecure(),
            httpOnly: false,
            raw: false,
            sameSite: 'lax'
        );

        return response()->json([
            'ok' => true,
            'theme' => $theme,
        ])->withCookie($cookie);
    }
}
