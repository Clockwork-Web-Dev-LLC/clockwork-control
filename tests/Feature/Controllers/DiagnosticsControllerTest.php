<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| DiagnosticsController — GET /settings/diagnostics
|--------------------------------------------------------------------------
|
| tests/Feature/Modules/NewHostingModuleSettingsPagesTest.php already
| smoke-tests this route (200 + WP Engine/Kinsta/Cloudways names visible).
| This file only adds the missing auth-gate test, plus one check confirming
| the page actually renders checks() beyond the 3 hosting-module ones —
| including the hardcoded "control" checks (database, outbound HTTPS,
| storage, mailer, Google OAuth) that run first per DiagnosticsController's
| own ordering comment. Kept short per instructions.
|
| Http::fake() keeps this fully offline: OutboundHttpCheck calls
| api.github.com unconditionally (not gated by any config flag), and this
| suite must never attempt real network I/O.
*/

it('redirects unauthenticated requests', function () {
    $this->get(route('settings.diagnostics.index'))->assertRedirect(route('login'));
});

it('renders all registered checks, not just the 3 hosting-module ones', function () {
    Http::fake();
    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.diagnostics.index'));

    $response->assertOk()
        // Hardcoded "control" checks (run first, per DiagnosticsController::checks()).
        ->assertSee('MySQL database')
        ->assertSee('Outbound HTTPS')
        ->assertSee('Storage writable')
        ->assertSee('Mailer (SMTP)')
        ->assertSee('Google OAuth')
        // A hardcoded integration check listed after the module-contributed ones.
        ->assertSee('Cloudflare API')
        // Module-contributed checks (already covered in depth elsewhere).
        ->assertSee('WP Engine')
        ->assertSee('Kinsta')
        ->assertSee('Cloudways');
});
