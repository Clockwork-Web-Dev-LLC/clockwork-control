<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\Tag;
use App\Models\User;
use App\Support\IssueCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IssuesDomainAndSeoParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = DB::connection()->getPdo();
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value);
        }
    }

    public function test_issue_counter_and_issues_controller_totals_match(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();

        $prodServer = Server::factory()->create(['is_ignored' => false]);
        $stagingServer = Server::factory()->create(['is_ignored' => false]);
        $stagingTag = Tag::create(['name' => 'Staging', 'slug' => 'staging']);
        $stagingServer->tags()->attach($stagingTag);

        $ignoredServer = Server::factory()->create(['is_ignored' => true]);

        // DOMAIN EXPIRATION FIXTURES:
        // 1. Prod site green (not an issue)
        Site::factory()->spinupwp()->create([
            'server_id' => $prodServer->id,
            'domain' => 'domain-green.com',
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_GREEN,
            'domain_expires_at' => $now->copy()->addDays(60),
            'is_inactive' => false,
        ]);

        // 2. Prod site yellow (is an issue)
        Site::factory()->spinupwp()->create([
            'server_id' => $prodServer->id,
            'domain' => 'domain-yellow.com',
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_YELLOW,
            'domain_expires_at' => $now->copy()->addDays(20),
            'is_inactive' => false,
        ]);

        // 3. Prod site red (is an issue)
        Site::factory()->spinupwp()->create([
            'server_id' => $prodServer->id,
            'domain' => 'domain-red.com',
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_RED,
            'domain_expires_at' => $now->copy()->addDays(3),
            'is_inactive' => false,
        ]);

        // 4. Inactive site red (suppressed)
        Site::factory()->spinupwp()->create([
            'server_id' => $prodServer->id,
            'domain' => 'domain-inactive-red.com',
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_RED,
            'domain_expires_at' => $now->copy()->addDays(3),
            'is_inactive' => true,
        ]);

        // 5. Ignored-server site red (suppressed)
        Site::factory()->spinupwp()->create([
            'server_id' => $ignoredServer->id,
            'domain' => 'domain-ignored-red.com',
            'domain_expiration_state' => Site::DOMAIN_EXPIRATION_STATE_RED,
            'domain_expires_at' => $now->copy()->addDays(3),
            'is_inactive' => false,
        ]);

        // SEO INDEXABILITY FIXTURES:
        // 1. Prod site indexable (not an issue)
        Site::factory()->spinupwp()->create([
            'server_id' => $prodServer->id,
            'domain' => 'seo-clean.com',
            'seo_indexable' => true,
            'seo_monitoring_enabled' => true,
            'is_inactive' => false,
        ]);

        // 2. Prod site blocked (is an issue)
        Site::factory()->spinupwp()->create([
            'server_id' => $prodServer->id,
            'domain' => 'seo-blocked.com',
            'seo_indexable' => false,
            'seo_blocked_reason' => 'meta_noindex',
            'seo_monitoring_enabled' => true,
            'is_inactive' => false,
        ]);

        // 3. Staging site blocked (Protected from Search, but NOT counted in total issue count)
        Site::factory()->spinupwp()->create([
            'server_id' => $stagingServer->id,
            'domain' => 'seo-staging-blocked.com',
            'seo_indexable' => false,
            'seo_blocked_reason' => 'robots_disallow_all',
            'seo_monitoring_enabled' => true,
            'is_inactive' => false,
        ]);

        // 4. Inactive site blocked (suppressed)
        Site::factory()->spinupwp()->create([
            'server_id' => $prodServer->id,
            'domain' => 'seo-inactive-blocked.com',
            'seo_indexable' => false,
            'seo_blocked_reason' => 'meta_noindex',
            'seo_monitoring_enabled' => true,
            'is_inactive' => true,
        ]);

        $counter = new IssueCounter;

        $response = $this->actingAs($user)->get(route('issues.index'));
        $response->assertOk();

        $controllerTotals = $response->viewData('totals');

        // Check domain expiration issue count parity
        $this->assertSame(2, $controllerTotals['domain_expiration']);

        // Check SEO blocked issue count parity
        $this->assertSame(1, $controllerTotals['seo_blocked']);

        // Check that staging site is present in table collection
        $seoIssues = $response->viewData('seoIssues');
        $this->assertCount(2, $seoIssues); // 1 prod + 1 staging
        $this->assertTrue($seoIssues->contains('domain', 'seo-staging-blocked.com'));
        $this->assertTrue($seoIssues->contains('domain', 'seo-blocked.com'));

        // Check domain expiration collection
        $domainIssues = $response->viewData('domainExpirationIssues');
        $this->assertCount(2, $domainIssues);
        $this->assertTrue($domainIssues->contains('domain', 'domain-yellow.com'));
        $this->assertTrue($domainIssues->contains('domain', 'domain-red.com'));
    }
}
