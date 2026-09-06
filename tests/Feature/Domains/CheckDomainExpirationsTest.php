<?php

namespace Tests\Feature\Domains;

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CheckDomainExpirationsTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = Server::factory()->create(['is_ignored' => false]);
    }

    public function test_command_checks_due_sites_and_skips_recent_sites(): void
    {
        $now = Carbon::now();

        // Site 1: never checked (due)
        $dueNew = Site::factory()->spinupwp()->create([
            'server_id' => $this->server->id,
            'domain' => 'site-new.com',
            'domain_rdap_checked_at' => null,
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_NONE,
        ]);

        // Site 2: green, checked 2 days ago (not due; weekly cadence)
        $recentGreen = Site::factory()->spinupwp()->create([
            'server_id' => $this->server->id,
            'domain' => 'site-green.com',
            'domain_rdap_checked_at' => $now->copy()->subDays(2),
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_GREEN,
            'domain_expires_at' => $now->copy()->addDays(90),
        ]);

        // Site 3: yellow, checked 22 hours ago (due; daily cadence)
        $dueYellow = Site::factory()->spinupwp()->create([
            'server_id' => $this->server->id,
            'domain' => 'site-yellow.com',
            'domain_rdap_checked_at' => $now->copy()->subHours(22),
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_YELLOW,
            'domain_expires_at' => $now->copy()->addDays(15),
        ]);

        // Site 4: red, checked 2 hours ago (not due; daily cadence)
        $recentRed = Site::factory()->spinupwp()->create([
            'server_id' => $this->server->id,
            'domain' => 'site-red.com',
            'domain_rdap_checked_at' => $now->copy()->subHours(2),
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_RED,
            'domain_expires_at' => $now->copy()->addDays(2),
        ]);

        Http::fake([
            'https://rdap.org/domain/site-new.com' => Http::response([
                'events' => [['eventAction' => 'expiration', 'eventDate' => $now->copy()->addDays(100)->toIso8601String()]],
                'entities' => [],
                'status' => [],
            ], 200),
            'https://rdap.org/domain/site-yellow.com' => Http::response([
                'events' => [['eventAction' => 'expiration', 'eventDate' => $now->copy()->addDays(14)->toIso8601String()]],
                'entities' => [],
                'status' => [],
            ], 200),
        ]);

        $this->artisan('clockwork:check-domain-expirations')
            ->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'site-new.com'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'site-yellow.com'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'site-green.com'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'site-red.com'));
    }

    public function test_state_transition_fires_chat_notification(): void
    {
        $now = Carbon::now();

        $site = Site::factory()->spinupwp()->create([
            'server_id' => $this->server->id,
            'domain' => 'transitioning.com',
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_GREEN,
            'domain_expires_at' => $now->copy()->addDays(60),
            'domain_rdap_checked_at' => null,
        ]);

        // RDAP returns expiration 15 days out (transitions to yellow)
        Http::fake([
            'https://rdap.org/domain/transitioning.com' => Http::response([
                'events' => [['eventAction' => 'expiration', 'eventDate' => $now->copy()->addDays(15)->toIso8601String()]],
                'entities' => [],
                'status' => [],
            ], 200),
        ]);

        $notifier = Mockery::mock(ChatNotifier::class);
        $notifier->shouldReceive('domainExpirationStateChanged')
            ->once()
            ->with(Mockery::on(fn (Site $s) => $s->id === $site->id), Site::DOMAIN_EXPIRATION_STATE_GREEN, Site::DOMAIN_EXPIRATION_STATE_YELLOW)
            ->andReturn(true);

        $this->app->instance(ChatNotifier::class, $notifier);

        $this->artisan('clockwork:check-domain-expirations')
            ->assertSuccessful();

        $site->refresh();
        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_YELLOW, $site->domain_expiration_state);

        // Run again with force: state remains yellow, notification should NOT re-fire
        $this->artisan('clockwork:check-domain-expirations', ['--force' => true])
            ->assertSuccessful();
    }

    public function test_silent_mode_suppresses_notifications(): void
    {
        $now = Carbon::now();

        $site = Site::factory()->spinupwp()->create([
            'server_id' => $this->server->id,
            'domain' => 'silent-site.com',
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_GREEN,
            'domain_expires_at' => $now->copy()->addDays(60),
            'domain_rdap_checked_at' => null,
        ]);

        Http::fake([
            'https://rdap.org/domain/silent-site.com' => Http::response([
                'events' => [['eventAction' => 'expiration', 'eventDate' => $now->copy()->addDays(5)->toIso8601String()]], // red
                'entities' => [],
                'status' => [],
            ], 200),
        ]);

        $notifier = Mockery::mock(ChatNotifier::class);
        $notifier->shouldReceive('domainExpirationStateChanged')->never();
        $this->app->instance(ChatNotifier::class, $notifier);

        $this->artisan('clockwork:check-domain-expirations', ['--silent' => true])
            ->assertSuccessful();

        $site->refresh();
        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_RED, $site->domain_expiration_state);
    }

    public function test_brand_new_site_already_expiring_on_first_check_still_notifies(): void
    {
        $now = Carbon::now();

        // A site's very first-ever check always starts from oldState=NONE —
        // this must still notify when the result is immediately yellow/red,
        // not just on a later transition (see DomainExpirationChecker's
        // notification guard, which used to special-case oldState=NONE away
        // entirely regardless of what the new state was).
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $this->server->id,
            'domain' => 'brand-new-and-expiring.com',
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_NONE,
            'domain_expires_at' => null,
            'domain_rdap_checked_at' => null,
        ]);

        Http::fake([
            'https://rdap.org/domain/brand-new-and-expiring.com' => Http::response([
                'events' => [['eventAction' => 'expiration', 'eventDate' => $now->copy()->addDays(3)->toIso8601String()]],
                'entities' => [],
                'status' => [],
            ], 200),
        ]);

        $notifier = Mockery::mock(ChatNotifier::class);
        $notifier->shouldReceive('domainExpirationStateChanged')
            ->once()
            ->with(Mockery::on(fn (Site $s) => $s->id === $site->id), Site::DOMAIN_EXPIRATION_STATE_NONE, Site::DOMAIN_EXPIRATION_STATE_RED)
            ->andReturn(true);
        $this->app->instance(ChatNotifier::class, $notifier);

        $this->artisan('clockwork:check-domain-expirations')
            ->assertSuccessful();

        $site->refresh();
        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_RED, $site->domain_expiration_state);
    }

    public function test_recheck_endpoint_returns_json_and_bypasses_cadence(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();

        $site = Site::factory()->spinupwp()->create([
            'server_id' => $this->server->id,
            'domain' => 'on-demand.com',
            'domain_rdap_checked_at' => $now, // just checked
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_GREEN,
            'domain_expires_at' => $now->copy()->addDays(50),
        ]);

        Http::fake([
            'https://rdap.org/domain/on-demand.com' => Http::response([
                'events' => [['eventAction' => 'expiration', 'eventDate' => $now->copy()->addDays(20)->toIso8601String()]],
                'entities' => [
                    [
                        'roles' => ['registrar'],
                        'vcardArray' => [
                            'vcard',
                            [['fn', new \stdClass, 'text', 'GoDaddy.com, LLC']],
                        ],
                    ],
                ],
                'status' => [],
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson(route('sites.domain.recheck', $site));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'state' => Site::DOMAIN_EXPIRATION_STATE_YELLOW,
                'state_label' => 'Domain renewal',
                'registrar' => 'GoDaddy.com, LLC',
            ]);

        $site->refresh();
        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_YELLOW, $site->domain_expiration_state);
        $this->assertSame('GoDaddy.com, LLC', $site->domain_registrar);
    }
}
