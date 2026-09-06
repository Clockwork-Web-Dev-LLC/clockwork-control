<?php

namespace Tests\Feature\Ssl;

use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Services\Ssl\SiteCertRefresher;
use App\Services\Ssl\SslChecker;

/*
|--------------------------------------------------------------------------
| SslChecker::run() notification-firing gate
|--------------------------------------------------------------------------
|
| This file is only about the gating around the single
| $this->mattermost->sslStateChanged($site, $oldState, $newState) call site
| in SslChecker::run() (app/Services/Ssl/SslChecker.php, ~line 112):
|
|   - fires ONLY when the freshly computed sslState() differs from the
|     stored cert_state column (a genuine transition)
|   - never fires when $silent=true (initial backfill)
|   - never fires when the OLD stored state was null (first-ever assignment)
|   - fires when transitioning between two real non-null states
|
| tests/Feature/Chat/ChatNotifierGatingTest.php already covers
| ChatNotifierDispatcher's own is_inactive gating for sslStateChanged — not
| re-tested here. ChatNotifier is mocked directly at the container level, so
| none of that dispatcher/is_inactive logic is even in play in this file.
|
| All sites here use the default SpinupWP factory shape (cert_source =
| spinupwp_le, a monitored server). Site::sslState()'s SPINUPWP_LE branch
| only reports SSL_STATE_YELLOW when cert_renews_at (plus a grace period)
| has passed without the cert rolling forward; otherwise it's GREEN as long
| as cert_expires_at is in the future. Most tests below pick field values
| that compute to GREEN on the *initial* (pre-refresh) row, which keeps
| SslChecker's CAP_CERT_SYNC branch from ever calling SiteCertRefresher —
| letting the real SiteCertRefresher/SpinupWpClient resolve harmlessly from
| the container without hitting the network. The one test that needs the
| refresh branch actually taken mocks SiteCertRefresher explicitly.
*/

describe('SslChecker::run() notification gate', function () {
    it('fires sslStateChanged on a genuine transition (yellow -> green) when not silent', function () {
        $site = Site::factory()->create([
            'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
            'cert_expires_at' => now()->addDays(90),
            'cert_renews_at' => now()->addDays(60),
            'cert_state' => Site::SSL_STATE_YELLOW,
        ]);

        $this->mock(ChatNotifier::class, function ($mock) use ($site) {
            $mock->shouldReceive('sslStateChanged')
                ->once()
                ->withArgs(fn (Site $s, string $from, string $to) => $s->is($site)
                    && $from === Site::SSL_STATE_YELLOW
                    && $to === Site::SSL_STATE_GREEN)
                ->andReturn(true);
        });

        $result = app(SslChecker::class)->run();

        expect($result['transitioned'])->toBe(1)
            ->and($result['notified'])->toBe(1);
        expect($site->fresh()->cert_state)->toBe(Site::SSL_STATE_GREEN);
    });

    it('fires sslStateChanged on a real transition reached via the SpinupWP cert-refresh path (yellow -> green)', function () {
        // Initial row computes to YELLOW (renews_at + grace already passed),
        // so SslChecker's CAP_CERT_SYNC branch calls SiteCertRefresher before
        // re-evaluating — this exercises that refresh branch actually taken,
        // not just the "no refresh needed" case above.
        $site = Site::factory()->create([
            'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
            'cert_expires_at' => now()->addDays(20),
            'cert_renews_at' => now()->subDays(5),
            'cert_state' => Site::SSL_STATE_YELLOW,
        ]);

        expect($site->sslState())->toBe(Site::SSL_STATE_YELLOW);

        $this->mock(SiteCertRefresher::class, function ($mock) use ($site) {
            $mock->shouldReceive('refresh')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturnUsing(function (Site $s) {
                    // Simulate SpinupWP reporting the cert already renewed.
                    $s->cert_renews_at = now()->addDays(60);
                    $s->save();

                    return ['from_state' => Site::SSL_STATE_YELLOW, 'to_state' => Site::SSL_STATE_GREEN, 'expires_at' => null, 'renews_at' => null];
                });
        });

        $this->mock(ChatNotifier::class, function ($mock) use ($site) {
            $mock->shouldReceive('sslStateChanged')
                ->once()
                ->withArgs(fn (Site $s, string $from, string $to) => $s->is($site)
                    && $from === Site::SSL_STATE_YELLOW
                    && $to === Site::SSL_STATE_GREEN)
                ->andReturn(true);
        });

        $result = app(SslChecker::class)->run();

        expect($result['refreshed'])->toBe(1)
            ->and($result['transitioned'])->toBe(1)
            ->and($result['notified'])->toBe(1);
        expect($site->fresh()->cert_state)->toBe(Site::SSL_STATE_GREEN);
    });

    it('does NOT fire when the computed state has not changed', function () {
        $site = Site::factory()->create([
            'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
            'cert_expires_at' => now()->addDays(90),
            'cert_renews_at' => now()->addDays(60),
            'cert_state' => Site::SSL_STATE_GREEN,
        ]);

        expect($site->sslState())->toBe(Site::SSL_STATE_GREEN);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('sslStateChanged')->never();
        });

        $result = app(SslChecker::class)->run();

        expect($result['transitioned'])->toBe(0)
            ->and($result['notified'])->toBe(0);
        expect($site->fresh()->cert_state)->toBe(Site::SSL_STATE_GREEN);
    });

    it('does NOT fire when $silent=true, even though the state genuinely transitions', function () {
        $site = Site::factory()->create([
            'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
            'cert_expires_at' => now()->addDays(90),
            'cert_renews_at' => now()->addDays(60),
            'cert_state' => Site::SSL_STATE_YELLOW,
        ]);

        expect($site->sslState())->toBe(Site::SSL_STATE_GREEN);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('sslStateChanged')->never();
        });

        $result = app(SslChecker::class)->run(silent: true);

        // State is still persisted on the silent backfill path — only the
        // notification itself is suppressed.
        expect($result['transitioned'])->toBe(1)
            ->and($result['notified'])->toBe(0);
        expect($site->fresh()->cert_state)->toBe(Site::SSL_STATE_GREEN);
    });

    it('does NOT fire when the old stored state was null (first-ever assignment), even when not silent', function () {
        $site = Site::factory()->create([
            'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
            'cert_expires_at' => now()->addDays(90),
            'cert_renews_at' => now()->addDays(60),
            'cert_state' => null,
        ]);

        expect($site->cert_state)->toBeNull()
            ->and($site->sslState())->toBe(Site::SSL_STATE_GREEN);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('sslStateChanged')->never();
        });

        $result = app(SslChecker::class)->run();

        // The state IS persisted (first-ever assignment is still a real
        // transition for bookkeeping purposes) — only the notification is
        // skipped because there was no prior real state to alert a change from.
        expect($result['transitioned'])->toBe(1)
            ->and($result['notified'])->toBe(0);
        expect($site->fresh()->cert_state)->toBe(Site::SSL_STATE_GREEN);
    });
});
