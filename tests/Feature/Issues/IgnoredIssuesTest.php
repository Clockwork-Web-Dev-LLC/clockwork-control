<?php

namespace Tests\Feature\Issues;

use App\Models\IgnoredIssue;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\IssueCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IgnoredIssuesTest extends TestCase
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

    public function test_guest_cannot_ignore_or_unignore_issues(): void
    {
        $site = Site::factory()->create();

        $this->post(route('issues.ignore'), [
            'issue_type' => 'seo_indexability',
            'site_id' => $site->id,
        ])->assertRedirect(route('login'));

        $issue = IgnoredIssue::create([
            'issue_type' => 'seo_indexability',
            'site_id' => $site->id,
        ]);

        $this->post(route('issues.unignore', $issue))
            ->assertRedirect(route('login'));
    }

    public function test_can_ignore_seo_indexability_issue_with_reason(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create([
            'domain' => 'intranet.example.com',
            'seo_indexable' => false,
            'seo_blocked_reason' => 'meta_noindex',
            'seo_monitoring_enabled' => true,
        ]);

        $response = $this->actingAs($user)
            ->post(route('issues.ignore'), [
                'issue_type' => 'seo_indexability',
                'site_id' => $site->id,
                'reason' => 'Internal employee intranet — intentional noindex',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Issue ignored successfully.');

        $this->assertDatabaseHas('ignored_issues', [
            'issue_type' => 'seo_indexability',
            'site_id' => $site->id,
            'reason' => 'Internal employee intranet — intentional noindex',
            'ignored_by_user_id' => $user->id,
        ]);
    }

    public function test_can_ignore_via_json_request(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('issues.ignore'), [
                'issue_type' => 'seo_indexability',
                'site_id' => $site->id,
                'reason' => 'Staging portal',
            ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => 'Issue ignored successfully.',
            ]);
    }

    public function test_can_unignore_issue_to_resume_monitoring(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create(['domain' => 'portal.example.com']);

        $ignored = IgnoredIssue::create([
            'issue_type' => 'seo_indexability',
            'site_id' => $site->id,
            'reason' => 'Temporary test',
            'ignored_by_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->post(route('issues.unignore', $ignored));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Restored portal.example.com to active monitoring.');

        $this->assertDatabaseMissing('ignored_issues', [
            'id' => $ignored->id,
        ]);
    }

    public function test_ignored_seo_issue_is_excluded_from_active_list_and_issue_counter(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create([
            'is_ignored' => false,
            'status' => Server::STATUS_GREEN,
            'last_ssh_ok_at' => now(),
            'clockwork_jail_provisioned_at' => now(),
        ]);

        $activeSite = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'active-blocked.com',
            'seo_indexable' => false,
            'seo_blocked_reason' => 'meta_noindex',
            'seo_monitoring_enabled' => true,
            'is_inactive' => false,
        ]);

        $intranetSite = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'intranet.example.com',
            'seo_indexable' => false,
            'seo_blocked_reason' => 'meta_noindex',
            'seo_monitoring_enabled' => true,
            'is_inactive' => false,
        ]);

        $counter = new IssueCounter;
        $initialTotal = $counter->total();

        // Initially both are counted in IssuesController and IssueCounter
        $response = $this->actingAs($user)->get(route('issues.index'));
        $response->assertOk();
        $activeSeo = $response->viewData('seoIssues');
        $this->assertTrue($activeSeo->contains('id', $intranetSite->id));
        $this->assertTrue($activeSeo->contains('id', $activeSite->id));

        // Now ignore the intranet site
        IgnoredIssue::create([
            'issue_type' => 'seo_indexability',
            'site_id' => $intranetSite->id,
            'reason' => 'Employee intranet',
            'ignored_by_user_id' => $user->id,
        ]);

        // IssueCounter should drop by 1
        $this->assertSame($initialTotal - 1, $counter->total());

        // Issues page should exclude intranetSite from active and show it in ignored
        $responseAfter = $this->actingAs($user)->get(route('issues.index'));
        $responseAfter->assertOk();

        $activeAfter = $responseAfter->viewData('seoIssues');
        $ignoredAfter = $responseAfter->viewData('ignoredSeoIssues');

        $this->assertFalse($activeAfter->contains('id', $intranetSite->id));
        $this->assertTrue($activeAfter->contains('id', $activeSite->id));

        $this->assertTrue($ignoredAfter->contains('site_id', $intranetSite->id));
        $responseAfter->assertSee('Employee intranet');
        $responseAfter->assertSee('intranet.example.com');
    }
}
