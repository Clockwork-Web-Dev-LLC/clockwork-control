<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Diagnostics\CheckResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Seo\Diagnostics\GoogleConnectionCheck;
use Modules\Seo\Google\GoogleOAuth;
use Modules\Seo\Google\GoogleOAuthException;
use Modules\Seo\Google\GoogleTokenProvider;
use Modules\Seo\Jobs\RefreshGooglePropertiesJob;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Support\SeoSettings;

/**
 * Connect, reconnect, test and disconnect the agency Google connection.
 * Admin only. Connect once as systems@; every reconnect issues a new refresh
 * token and Google silently drops the oldest past 100 per client, so don't
 * reconnect casually.
 */
class GoogleConnectionController extends Controller
{
    private const STATE_KEY = 'seo.google.oauth_state';

    public function __construct(
        private readonly GoogleOAuth $oauth,
        private readonly GoogleTokenProvider $tokens,
    ) {}

    public function connect(Request $request, SeoSettings $settings): RedirectResponse
    {
        if (! $this->oauth->isConfigured()) {
            return redirect()->route('seo.settings')
                ->with('error', 'Add the Google OAuth client ID and secret for SEO on Settings → Integrations first.');
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($this->oauth->authorizationUrl($state, $settings->loginHint()));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = (string) $request->session()->pull(self::STATE_KEY, '');
        $state = (string) $request->query('state', '');

        if ($expected === '' || ! hash_equals($expected, $state)) {
            return redirect()->route('seo.settings')
                ->with('error', 'Google sign-in could not be verified (the request expired or did not start here). Try Connect again.');
        }

        if ($request->filled('error')) {
            return redirect()->route('seo.settings')
                ->with('error', 'Google sign-in was cancelled or refused: '.Str::limit((string) $request->query('error'), 100));
        }

        try {
            $token = $this->oauth->exchangeCode((string) $request->query('code', ''));
        } catch (GoogleOAuthException $e) {
            return redirect()->route('seo.settings')->with('error', $e->getMessage());
        }

        if (! is_string($token['refresh_token']) || $token['refresh_token'] === '') {
            return redirect()->route('seo.settings')
                ->with('error', 'Google did not return a refresh token, so the nightly sync could not run. Remove Clockwork\'s access at myaccount.google.com/permissions for this account, then Connect again.');
        }

        $scopes = array_values(array_filter(explode(' ', $token['scope'])));
        $missing = array_diff(['https://www.googleapis.com/auth/webmasters.readonly', 'https://www.googleapis.com/auth/analytics.readonly'], $scopes);

        $connection = GoogleConnection::current() ?? new GoogleConnection;
        $connection->forceFill([
            'account_email' => $this->oauth->userEmail($token['access_token']),
            'scopes' => $scopes,
            'refresh_token' => $token['refresh_token'],
            'status' => GoogleConnection::STATUS_ACTIVE,
            'last_refreshed_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
            'connected_by_user_id' => $request->user()?->id,
        ])->save();

        $this->tokens->forget($connection);
        $this->tokens->remember($connection, $token['access_token'], $token['expires_in']);

        RefreshGooglePropertiesJob::dispatch();

        Log::info('seo.google.connected', ['account' => $connection->account_email, 'user_id' => $request->user()?->id]);

        $redirect = redirect()->route('seo.settings')
            ->with('status', 'Google connected as '.($connection->account_email ?? 'an unknown account').'. Loading its Search Console properties.');

        return $missing === []
            ? $redirect
            : $redirect->with('warning', 'Google did not grant every permission. Untick nothing on the consent screen and Connect again.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $connection = GoogleConnection::current();

        if ($connection !== null) {
            $this->oauth->revoke($connection->refresh_token);
            $this->tokens->forget($connection);
            // Deleting the row (not marking it revoked) stops the nightly
            // schedule without failing it; stored search data stays.
            $connection->delete();

            Log::info('seo.google.disconnected', ['account' => $connection->account_email, 'user_id' => $request->user()?->id]);
        }

        return redirect()->route('seo.settings')->with('status', 'Google disconnected. Stored search data is kept.');
    }

    public function test(GoogleConnectionCheck $check): RedirectResponse
    {
        $result = $check->run();
        $key = $result->status === CheckResult::STATUS_OK ? 'status' : 'error';

        return redirect()->route('seo.settings')->with($key, $result->summary.($result->detail ? ' ('.Str::limit($result->detail, 200).')' : ''));
    }
}
