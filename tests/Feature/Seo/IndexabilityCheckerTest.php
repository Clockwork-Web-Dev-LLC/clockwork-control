<?php

namespace Tests\Feature\Seo;

use App\Models\Server;
use App\Models\Site;
use App\Models\Tag;
use App\Models\User;
use App\Services\Chat\ChatNotifier;
use App\Services\Seo\IndexabilityChecker;
use App\Services\Uptime\UptimeProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class IndexabilityCheckerTest extends TestCase
{
    use RefreshDatabase;

    private Server $prodServer;

    private Server $stagingServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prodServer = Server::factory()->create(['is_ignored' => false]);

        $this->stagingServer = Server::factory()->create(['is_ignored' => false]);
        $stagingTag = Tag::create(['name' => 'Staging', 'slug' => 'staging']);
        $this->stagingServer->tags()->attach($stagingTag);
    }

    public function test_probe_with_noindex_blocks_production_site_and_alerts(): void
    {
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $this->prodServer->id,
            'domain' => 'prod-site.com',
            'seo_indexable' => true,
            'seo_monitoring_enabled' => true,
        ]);

        $notifier = Mockery::mock(ChatNotifier::class);
        $notifier->shouldReceive('seoIndexabilityBlocked')
            ->once()
            ->with(Mockery::on(fn (Site $s) => $s->id === $site->id), 'meta_noindex', Mockery::type('string'))
            ->andReturn(true);
        $this->app->instance(ChatNotifier::class, $notifier);

        $probe = UptimeProbeResult::success(
            statusCode: 200,
            responseTimeMs: 120,
            body: '<html><head><meta name="robots" content="noindex, nofollow"></head></html>',
            xRobotsTagHeader: null
        );

        $checker = app(IndexabilityChecker::class);
        $checker->checkFromProbeResult($site, $probe);

        $site->refresh();
        $this->assertFalse($site->seo_indexable);
        $this->assertSame('meta_noindex', $site->seo_blocked_reason);
        $this->assertStringContainsString('noindex', $site->seo_blocked_snippet);
        $this->assertSame('Blocking Search Engines', $site->seoStatusLabel());

        // Repeated probe does NOT re-alert
        $checker->checkFromProbeResult($site, $probe);
    }

    public function test_staging_site_reflects_protected_status_and_never_alerts(): void
    {
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $this->stagingServer->id,
            'domain' => 'staging-site.com',
            'seo_indexable' => true,
            'seo_monitoring_enabled' => true,
        ]);

        $notifier = Mockery::mock(ChatNotifier::class);
        $notifier->shouldReceive('seoIndexabilityBlocked')->never();
        $this->app->instance(ChatNotifier::class, $notifier);

        $probe = UptimeProbeResult::success(
            statusCode: 200,
            responseTimeMs: 120,
            body: '<html><head><meta name="robots" content="noindex"></head></html>',
            xRobotsTagHeader: null
        );

        $checker = app(IndexabilityChecker::class);
        $checker->checkFromProbeResult($site, $probe);

        $site->refresh();
        $this->assertFalse($site->seo_indexable);
        $this->assertSame('Protected from Search', $site->seoStatusLabel());
    }

    public function test_blocked_to_clean_transition_fires_recovery_notification(): void
    {
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $this->prodServer->id,
            'domain' => 'recovering-site.com',
            'seo_indexable' => false,
            'seo_blocked_reason' => 'header_noindex',
            'seo_blocked_snippet' => 'X-Robots-Tag: noindex',
            'seo_monitoring_enabled' => true,
        ]);

        $notifier = Mockery::mock(ChatNotifier::class);
        $notifier->shouldReceive('seoIndexabilityRecovered')
            ->once()
            ->with(Mockery::on(fn (Site $s) => $s->id === $site->id))
            ->andReturn(true);
        $this->app->instance(ChatNotifier::class, $notifier);

        $cleanProbe = UptimeProbeResult::success(
            statusCode: 200,
            responseTimeMs: 100,
            body: '<html><head><title>Clean Site</title></head></html>',
            xRobotsTagHeader: null
        );

        $checker = app(IndexabilityChecker::class);
        $checker->checkFromProbeResult($site, $cleanProbe);

        $site->refresh();
        $this->assertTrue($site->seo_indexable);
        $this->assertNull($site->seo_blocked_reason);
        $this->assertNull($site->seo_blocked_snippet);
        $this->assertSame('Indexable', $site->seoStatusLabel());
    }

    public function test_check_robots_txt_command_detects_disallow_directive(): void
    {
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $this->prodServer->id,
            'domain' => 'robots-test.com',
            'seo_indexable' => true,
            'seo_monitoring_enabled' => true,
        ]);

        Http::fake([
            'https://robots-test.com/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200),
        ]);

        $notifier = Mockery::mock(ChatNotifier::class);
        $notifier->shouldReceive('seoIndexabilityBlocked')->once()->andReturn(true);
        $this->app->instance(ChatNotifier::class, $notifier);

        $this->artisan('clockwork:check-robots-txt', ['--site' => $site->id])
            ->assertSuccessful();

        $site->refresh();
        $this->assertFalse($site->seo_indexable);
        $this->assertSame('robots_disallow_all', $site->seo_blocked_reason);
        $this->assertStringContainsString('Disallow:', $site->seo_blocked_snippet);
    }

    public function test_a_down_probe_does_not_clear_an_existing_block_or_fire_a_recovery_notification(): void
    {
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $this->prodServer->id,
            'domain' => 'flaky-but-blocked.com',
            'seo_indexable' => false,
            'seo_blocked_reason' => 'meta_noindex',
            'seo_blocked_snippet' => '<meta name="robots" content="noindex">',
            'seo_meta_snippet' => '<meta name="robots" content="noindex">',
            'seo_monitoring_enabled' => true,
        ]);

        $notifier = Mockery::mock(ChatNotifier::class);
        $notifier->shouldReceive('seoIndexabilityRecovered')->never();
        $this->app->instance(ChatNotifier::class, $notifier);

        $checker = app(IndexabilityChecker::class);

        // A failed/erroring probe tells us nothing about the site's real
        // homepage — it must not be treated as "clean."
        $checker->checkFromProbeResult($site, UptimeProbeResult::badStatus(503, 200, 'HTTP 503 (maintenance)'));
        $checker->checkFromProbeResult($site, UptimeProbeResult::transportFailed('Connection refused'));

        $site->refresh();
        $this->assertFalse($site->seo_indexable);
        $this->assertSame('meta_noindex', $site->seo_blocked_reason);
    }

    public function test_a_failed_robots_txt_fetch_does_not_clear_an_existing_robots_block(): void
    {
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $this->prodServer->id,
            'domain' => 'robots-flaky.com',
            'seo_indexable' => false,
            'seo_blocked_reason' => 'robots_disallow_all',
            'seo_blocked_snippet' => 'Disallow: /',
            'seo_robots_snippet' => 'Disallow: /',
            'seo_monitoring_enabled' => true,
        ]);

        Http::fake([
            'https://robots-flaky.com/robots.txt' => Http::response('', 503),
        ]);

        $notifier = Mockery::mock(ChatNotifier::class);
        $notifier->shouldReceive('seoIndexabilityRecovered')->never();
        $this->app->instance(ChatNotifier::class, $notifier);

        $this->artisan('clockwork:check-robots-txt', ['--site' => $site->id])->assertSuccessful();

        $site->refresh();
        $this->assertFalse($site->seo_indexable);
        $this->assertSame('robots_disallow_all', $site->seo_blocked_reason);
    }

    public function test_fixing_the_meta_block_does_not_falsely_clear_a_concurrent_robots_block(): void
    {
        // Site is blocked by BOTH a noindex meta tag (currently the
        // top-priority reported reason) AND a robots.txt disallow-all,
        // tracked independently via seo_robots_snippet.
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $this->prodServer->id,
            'domain' => 'dual-blocked.com',
            'seo_indexable' => false,
            'seo_blocked_reason' => 'meta_noindex',
            'seo_blocked_snippet' => '<meta name="robots" content="noindex">',
            'seo_meta_snippet' => '<meta name="robots" content="noindex">',
            'seo_robots_snippet' => 'Disallow: /',
            'seo_monitoring_enabled' => true,
        ]);

        $notifier = Mockery::mock(ChatNotifier::class);
        $notifier->shouldReceive('seoIndexabilityRecovered')->never();
        $notifier->shouldReceive('seoIndexabilityBlocked')->never();
        $this->app->instance(ChatNotifier::class, $notifier);

        // The meta tag was just removed from the homepage — but robots.txt
        // still disallows everything, so the site must stay blocked.
        $cleanProbe = UptimeProbeResult::success(
            statusCode: 200,
            responseTimeMs: 100,
            body: '<html><head><title>Meta fixed</title></head></html>',
            xRobotsTagHeader: null
        );

        $checker = app(IndexabilityChecker::class);
        $checker->checkFromProbeResult($site, $cleanProbe);

        $site->refresh();
        $this->assertFalse($site->seo_indexable);
        $this->assertSame('robots_disallow_all', $site->seo_blocked_reason);
        $this->assertNull($site->seo_meta_snippet);
        $this->assertSame('Disallow: /', $site->seo_robots_snippet);
    }

    public function test_preflight_endpoint_runs_synchronously_and_returns_summary(): void
    {
        $user = User::factory()->create();

        $site = Site::factory()->spinupwp()->create([
            'server_id' => $this->prodServer->id,
            'domain' => 'preflight-check.com',
            'seo_indexable' => false,
            'seo_blocked_reason' => 'meta_noindex',
        ]);

        Http::fake([
            'https://preflight-check.com/' => Http::response('<html><head><title>Clean Page</title></head></html>', 200),
            'https://preflight-check.com/robots.txt' => Http::response("User-agent: *\nDisallow: /wp-admin/\nAllow: /", 200),
        ]);

        $notifier = Mockery::mock(ChatNotifier::class);
        $notifier->shouldReceive('seoIndexabilityRecovered')->once()->andReturn(true);
        $this->app->instance(ChatNotifier::class, $notifier);

        $response = $this->actingAs($user)->postJson(route('sites.seo.preflight', $site));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'indexable' => true,
                'status_label' => 'Indexable',
                'reason' => null,
                'is_staging' => false,
            ]);

        $site->refresh();
        $this->assertTrue($site->seo_indexable);
    }
}
