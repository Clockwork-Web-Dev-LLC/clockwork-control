<?php

namespace Tests\Feature\Gatekeeper;

use App\Models\ReviewQueueEntry;
use App\Models\Site;
use App\Models\User;
use App\Services\Fail2ban\IgnoreIpListBuilder;
use App\Services\Fail2ban\IgnoreIpMatcher;
use App\Services\Gatekeeper\GatekeeperSettingsPusher;
use App\Services\Llar\LlarLockoutPuller;
use App\Services\Sites\SiteMySqlClient;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

describe('Gatekeeper Login Lockouts', function () {
    describe('LlarLockoutPuller with Gatekeeper', function () {
        it('queries REST only without MySQL fallback when site advertises gatekeeper capability', function () {
            $site = Site::factory()->create([
                'domain' => 'example.gov',
                'is_wordpress' => true,
                'companion_installed' => true,
                'companion_secret' => 'test-secret',
                'companion_capabilities' => ['gatekeeper', 'lockouts'],
                'db_name' => 'wp_db',
                'db_user' => 'wp_user',
                'db_password' => 'wp_pass',
            ]);

            // Fake the REST HTTP response from Companion
            Http::fake([
                'https://example.gov/wp-json/clockwork/v1/lockouts' => Http::response([
                    'ok' => true,
                    'lockouts' => [
                        [
                            'ip' => '203.0.113.10',
                            'unlock_at' => '2026-09-19T12:00:00Z',
                            'source_table' => 'clockwork_lockouts',
                        ],
                    ],
                ], 200),
            ]);

            $mysqlMock = $this->mock(SiteMySqlClient::class, function ($mock) {
                // Must NEVER call MySQL fallback when gatekeeper capability is present
                $mock->shouldNotReceive('query');
            });

            $puller = new LlarLockoutPuller($mysqlMock);
            $lockouts = $puller->activeLockouts($site);

            expect($lockouts)->toHaveCount(1)
                ->and($lockouts[0]['ip'])->toBe('203.0.113.10')
                ->and($lockouts[0]['source_table'])->toBe('clockwork_lockouts');
        });

        it('does not fall back to MySQL when REST fails on a gatekeeper-enabled site', function () {
            $site = Site::factory()->create([
                'domain' => 'example.gov',
                'is_wordpress' => true,
                'companion_installed' => true,
                'companion_secret' => 'test-secret',
                'companion_capabilities' => ['gatekeeper'],
                'db_name' => 'wp_db',
                'db_user' => 'wp_user',
                'db_password' => 'wp_pass',
            ]);

            // Fake 500 error from WordPress
            Http::fake([
                'https://example.gov/wp-json/clockwork/v1/lockouts' => Http::response(['error' => 'server error'], 500),
            ]);

            $mysqlMock = $this->mock(SiteMySqlClient::class, function ($mock) {
                $mock->shouldNotReceive('query');
            });

            $puller = new LlarLockoutPuller($mysqlMock);
            $lockouts = $puller->activeLockouts($site);

            // Fail-open: returns empty array rather than scraping stale LLAR SQL
            expect($lockouts)->toBeArray()->toBeEmpty();
        });

        it('pulls serverless Pressable site without db_password into review queue', function () {
            // Pressable site has server_id null, db_password null, but companion_installed + gatekeeper
            $pressableSite = Site::factory()->pressable()->create([
                'domain' => 'pressable-lockout.example.gov',
                'is_wordpress' => true,
                'companion_installed' => true,
                'companion_secret' => 'pressable-secret',
                'companion_capabilities' => ['gatekeeper', 'lockouts'],
                'db_password' => null,
            ]);

            $this->mock(LlarLockoutPuller::class, function ($mock) use ($pressableSite) {
                $mock->shouldReceive('activeLockouts')
                    ->once()
                    ->withArgs(fn (Site $s) => $s->is($pressableSite))
                    ->andReturn([
                        [
                            'ip' => '203.0.113.10',
                            'unlock_at' => Carbon::now()->addMinutes(20),
                            'source_table' => 'clockwork_lockouts',
                        ],
                    ]);
            });

            $this->mock(IgnoreIpMatcher::class, function ($mock) {
                $mock->shouldReceive('reason')->with('203.0.113.10')->andReturn(null);
            });

            $this->artisan('clockwork:pull-llar-lockouts')
                ->assertSuccessful();

            $entry = ReviewQueueEntry::query()
                ->where('ip', '203.0.113.10')
                ->where('site_id', $pressableSite->id)
                ->first();

            expect($entry)->not->toBeNull()
                ->and($entry->server_id)->toBeNull()
                ->and($entry->status)->toBe(ReviewQueueEntry::STATUS_PENDING)
                ->and($entry->reason)->toContain('Gatekeeper');
        });
    });

    describe('PushGatekeeperSettings Console Command', function () {
        it('skips sites without gatekeeper capability and pushes to sites with gatekeeper capability', function () {
            $capableSite = Site::factory()->create([
                'domain' => 'capable.example.gov',
                'companion_installed' => true,
                'companion_capabilities' => ['gatekeeper'],
                'is_inactive' => false,
            ]);

            $uncapableSite = Site::factory()->create([
                'domain' => 'uncapable.example.gov',
                'companion_installed' => true,
                'companion_capabilities' => ['lockouts'],
                'is_inactive' => false,
            ]);

            $this->mock(GatekeeperSettingsPusher::class, function ($mock) use ($capableSite, $uncapableSite) {
                $mock->shouldReceive('maybePush')
                    ->once()
                    ->withArgs(fn (Site $s) => $s->is($capableSite))
                    ->andReturn(true);

                $mock->shouldNotReceive('maybePush')
                    ->withArgs(fn (Site $s) => $s->is($uncapableSite));
            });

            $this->artisan('clockwork:push-gatekeeper-settings')
                ->assertSuccessful()
                ->expectsOutputToContain('Completed: 1 synced, 1 skipped (capability missing), 0 failed.');
        });
    });

    describe('Gatekeeper Settings Hub Auth & Validation', function () {
        it('requires authentication to view or update fleet gatekeeper settings', function () {
            $this->get(route('settings.gatekeeper.index'))
                ->assertRedirect(route('login'));

            $this->patch(route('settings.gatekeeper.update'), [])
                ->assertRedirect(route('login'));
        });

        it('renders the gatekeeper fleet policy settings view for authenticated users', function () {
            $user = User::factory()->create();

            $this->actingAs($user)
                ->get(route('settings.gatekeeper.index'))
                ->assertSuccessful()
                ->assertSee('Login Lockouts (Gatekeeper)')
                ->assertSee('Failure Threshold');
        });

        it('validates threshold bounds min 3 max 20', function () {
            $user = User::factory()->create();

            // Threshold < 3 must fail validation
            $this->actingAs($user)
                ->from(route('settings.gatekeeper.index'))
                ->patch(route('settings.gatekeeper.update'), [
                    'threshold' => 2,
                    'window_seconds' => 1200,
                    'lockout_seconds' => 1200,
                    'consecutive_lockouts_for_extended' => 4,
                    'extended_lockout_seconds' => 86400,
                ])
                ->assertSessionHasErrors(['threshold']);

            // Threshold > 20 must fail validation
            $this->actingAs($user)
                ->from(route('settings.gatekeeper.index'))
                ->patch(route('settings.gatekeeper.update'), [
                    'threshold' => 21,
                    'window_seconds' => 1200,
                    'lockout_seconds' => 1200,
                    'consecutive_lockouts_for_extended' => 4,
                    'extended_lockout_seconds' => 86400,
                ])
                ->assertSessionHasErrors(['threshold']);
        });

        it('validates bounded duration fields', function () {
            $user = User::factory()->create();

            // window_seconds < 60 must fail
            $this->actingAs($user)
                ->from(route('settings.gatekeeper.index'))
                ->patch(route('settings.gatekeeper.update'), [
                    'threshold' => 4,
                    'window_seconds' => 30,
                    'lockout_seconds' => 1200,
                    'consecutive_lockouts_for_extended' => 4,
                    'extended_lockout_seconds' => 86400,
                ])
                ->assertSessionHasErrors(['window_seconds']);

            // lockout_seconds > 86400 must fail
            $this->actingAs($user)
                ->from(route('settings.gatekeeper.index'))
                ->patch(route('settings.gatekeeper.update'), [
                    'threshold' => 4,
                    'window_seconds' => 1200,
                    'lockout_seconds' => 100000,
                    'consecutive_lockouts_for_extended' => 4,
                    'extended_lockout_seconds' => 86400,
                ])
                ->assertSessionHasErrors(['lockout_seconds']);
        });

        it('successfully saves valid fleet settings', function () {
            $user = User::factory()->create();

            $this->actingAs($user)
                ->patch(route('settings.gatekeeper.update'), [
                    'enabled' => 1,
                    'threshold' => 5,
                    'window_seconds' => 1800,
                    'lockout_seconds' => 1800,
                    'consecutive_lockouts_for_extended' => 3,
                    'extended_lockout_seconds' => 43200,
                    'headline' => 'Access Denied',
                    'body' => 'Too many login failures. Try again in {duration}.',
                    'support_label' => 'Helpdesk',
                    'support_email' => 'helpdesk@example.gov',
                    'show_ip' => 1,
                    'show_unlock_link' => 1,
                    'ignore_ips' => "203.0.113.50\n203.0.113.51",
                    'ignore_cidrs' => '198.51.100.0/24',
                ])
                ->assertRedirect(route('settings.gatekeeper.index'))
                ->assertSessionHas('status');

            $settings = app(Settings::class);
            expect($settings->get('gatekeeper.enabled'))->toBeTrue()
                ->and($settings->get('gatekeeper.threshold'))->toBe(5)
                ->and($settings->get('gatekeeper.window_seconds'))->toBe(1800)
                ->and($settings->get('gatekeeper.headline'))->toBe('Access Denied')
                ->and($settings->get('gatekeeper.ignore_ips'))->toBe(['203.0.113.50', '203.0.113.51'])
                ->and($settings->get('gatekeeper.ignore_cidrs'))->toBe(['198.51.100.0/24']);
        });
    });

    describe('Site Settings Overrides', function () {
        it('saves and clears site-level gatekeeper overrides', function () {
            $user = User::factory()->create();
            $site = Site::factory()->create([
                'domain' => 'override.example.gov',
                'companion_installed' => true,
                'companion_capabilities' => ['gatekeeper'],
            ]);

            $this->actingAs($user)
                ->patch(route('sites.gatekeeper.update', $site), [
                    'enabled' => '1',
                    'threshold' => 6,
                    'headline' => 'Agency Access Blocked',
                    'support_email' => 'admin@example.gov',
                ])
                ->assertRedirect(route('sites.show', ['site' => $site, 'tab' => 'settings']))
                ->assertSessionHas('status');

            $site->refresh();
            expect($site->gatekeeper_settings)->toBeArray()
                ->and($site->gatekeeper_settings['enabled'])->toBeTrue()
                ->and($site->gatekeeper_settings['threshold'])->toBe(6)
                ->and($site->gatekeeper_settings['headline'])->toBe('Agency Access Blocked');

            // Reset back to fleet defaults by submitting defaults/empty
            $this->actingAs($user)
                ->patch(route('sites.gatekeeper.update', $site), [
                    'enabled' => 'default',
                    'threshold' => '',
                    'headline' => '',
                ])
                ->assertRedirect(route('sites.show', ['site' => $site, 'tab' => 'settings']));

            $site->refresh();
            expect($site->gatekeeper_settings)->toBeNull();
        });
    });

    describe('GatekeeperSettingsPusher defaults', function () {
        it('does not ship a placeholder support email as the fleet default', function () {
            $this->mock(IgnoreIpListBuilder::class, function ($mock) {
                $mock->shouldReceive('build')->andReturn([]);
            });

            $payload = app(GatekeeperSettingsPusher::class)->buildPayload();

            expect($payload['support_email'])->toBe('')
                ->and($payload['enabled'])->toBeFalse();
        });
    });
});
