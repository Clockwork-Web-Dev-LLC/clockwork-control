<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Seo\Google\GoogleOAuth;
use Modules\Seo\Jobs\RefreshGooglePropertiesJob;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Support\SeoSettings;
use Tests\Fixtures\GoogleOAuthFixtures;

beforeEach(function () {
    mockIssueCounterZero();
    Http::preventStrayRequests();
    Queue::fake();
    GoogleOAuthFixtures::configureClient();
    $this->admin = User::factory()->admin()->create();
});

it('sends the admin to Google with offline access, consent, the read-only scopes and a session state', function () {
    $response = $this->actingAs($this->admin)->get(route('seo.google.connect'));

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith(GoogleOAuth::AUTH_URL)
        ->and($query['access_type'])->toBe('offline')
        ->and($query['prompt'])->toBe('consent')
        ->and(explode(' ', $query['scope']))->toBe(GoogleOAuth::SCOPES)
        ->and($query['redirect_uri'])->toBe(route('seo.google.callback'))
        // No account is assumed until an agency names one.
        ->and($query)->not->toHaveKeys(['login_hint', 'hd'])
        ->and($query['state'])->toBe(session('seo.google.oauth_state'));
});

it('refuses to start without a client ID and secret', function () {
    config(['clockwork.seo.google_client_id' => null, 'clockwork.seo.google_client_secret' => null, 'services.google.client_id' => null]);

    $this->actingAs($this->admin)->get(route('seo.google.connect'))
        ->assertRedirect(route('seo.settings'))
        ->assertSessionHas('error');
});

it('rejects a callback whose state does not match', function () {
    $this->actingAs($this->admin)
        ->withSession(['seo.google.oauth_state' => 'expected-state'])
        ->get(route('seo.google.callback', ['state' => 'forged', 'code' => 'abc']))
        ->assertRedirect(route('seo.settings'))
        ->assertSessionHas('error');

    expect(GoogleConnection::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('stores the refresh token encrypted, never renders it, and loads the property list', function () {
    Http::fake([
        GoogleOAuth::TOKEN_URL => Http::response(GoogleOAuthFixtures::codeExchange()),
        GoogleOAuth::USERINFO_URL => Http::response(GoogleOAuthFixtures::userinfo()),
    ]);

    $this->actingAs($this->admin)
        ->withSession(['seo.google.oauth_state' => 'good-state'])
        ->get(route('seo.google.callback', ['state' => 'good-state', 'code' => 'auth-code']))
        ->assertRedirect(route('seo.settings'))
        ->assertSessionHas('status');

    $connection = GoogleConnection::current();
    expect($connection->account_email)->toBe('systems@agency.example')
        ->and($connection->status)->toBe(GoogleConnection::STATUS_ACTIVE)
        ->and($connection->refresh_token)->toBe(GoogleOAuthFixtures::REFRESH_TOKEN)
        ->and($connection->connected_by_user_id)->toBe($this->admin->id);

    $raw = DB::table('seo_google_connections')->value('refresh_token');
    expect($raw)->not->toBe(GoogleOAuthFixtures::REFRESH_TOKEN)
        ->and($raw)->not->toContain('test-refresh-token');

    Http::assertSent(fn (Request $r) => $r->url() === GoogleOAuth::TOKEN_URL
        && $r['grant_type'] === 'authorization_code'
        && $r['redirect_uri'] === route('seo.google.callback'));
    Queue::assertPushed(RefreshGooglePropertiesJob::class);

    $this->actingAs($this->admin)->get(route('seo.settings'))
        ->assertOk()
        ->assertSee('systems@agency.example')
        ->assertDontSee(GoogleOAuthFixtures::REFRESH_TOKEN);
});

it('treats a token response without a refresh token as an error', function () {
    Http::fake([
        GoogleOAuth::TOKEN_URL => Http::response(GoogleOAuthFixtures::codeExchange(withRefresh: false)),
        GoogleOAuth::USERINFO_URL => Http::response(GoogleOAuthFixtures::userinfo()),
    ]);

    $this->actingAs($this->admin)
        ->withSession(['seo.google.oauth_state' => 's'])
        ->get(route('seo.google.callback', ['state' => 's', 'code' => 'c']))
        ->assertSessionHas('error');

    expect(GoogleConnection::query()->count())->toBe(0);
});

it('reconnecting updates the one connection row instead of adding another', function () {
    $old = GoogleOAuthFixtures::connection(['status' => GoogleConnection::STATUS_REVOKED]);
    Http::fake([
        GoogleOAuth::TOKEN_URL => Http::response(GoogleOAuthFixtures::codeExchange()),
        GoogleOAuth::USERINFO_URL => Http::response(GoogleOAuthFixtures::userinfo()),
    ]);

    $this->actingAs($this->admin)
        ->withSession(['seo.google.oauth_state' => 's'])
        ->get(route('seo.google.callback', ['state' => 's', 'code' => 'c']));

    expect(GoogleConnection::query()->count())->toBe(1)
        ->and(GoogleConnection::current()->id)->toBe($old->id)
        ->and(GoogleConnection::current()->status)->toBe(GoogleConnection::STATUS_ACTIVE);
});

it('keeps operators out of the settings and connection routes', function () {
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)->get(route('seo.settings'))->assertForbidden();
    $this->actingAs($operator)->get(route('seo.google.connect'))->assertForbidden();
    $this->actingAs($operator)->post(route('seo.google.disconnect'))->assertForbidden();
});

it('disconnect revokes the token at Google and deletes the row', function () {
    GoogleOAuthFixtures::connection();
    Http::fake([GoogleOAuth::REVOKE_URL => Http::response([], 200)]);

    $this->actingAs($this->admin)->post(route('seo.google.disconnect'))
        ->assertRedirect(route('seo.settings'));

    Http::assertSent(fn (Request $r) => $r->url() === GoogleOAuth::REVOKE_URL && $r['token'] === GoogleOAuthFixtures::REFRESH_TOKEN);
    expect(GoogleConnection::query()->count())->toBe(0);
});

it('renders the settings page with the redirect URI to register and saves sync settings', function () {
    $this->actingAs($this->admin)->get(route('seo.settings'))
        ->assertOk()
        ->assertSee(route('seo.google.callback'))
        ->assertSee('Connect Google');

    $this->actingAs($this->admin)->post(route('seo.settings.update'), [
        'login_hint' => 'seo@agency.example',
        'final_after_days' => 6,
        'ai_referrer_regex' => '(chatgpt\.com)',
    ])->assertRedirect(route('seo.settings'));

    $settings = app(SeoSettings::class);
    expect($settings->loginHint())->toBe('seo@agency.example')
        ->and($settings->finalAfterDays())->toBe(6)
        ->and($settings->aiReferrerRegex())->toBe('(chatgpt\.com)');
});
