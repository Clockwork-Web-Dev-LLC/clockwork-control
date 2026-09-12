<?php

use App\Models\IgnoredIssue;
use App\Models\PluginDirectoryStatus;
use App\Models\Site;
use App\Models\User;
use App\Services\Security\ClosedPluginAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ClosedPluginAuditor — matching & issues integration', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    it('flags active plugins whose directory slug is closed on WP.org', function () {
        PluginDirectoryStatus::factory()->closed('Removed due to security issue')->create([
            'slug' => 'vulnerable-abandoned',
            'closed_date' => '2023-11-20',
        ]);
        PluginDirectoryStatus::factory()->create([
            'slug' => 'active-healthy',
            'status' => PluginDirectoryStatus::STATUS_OPEN,
        ]);
        PluginDirectoryStatus::factory()->notFound()->create([
            'slug' => 'custom-inhouse',
        ]);

        $site = Site::factory()->create([
            'is_inactive' => false,
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        [
                            'slug' => 'vulnerable-abandoned/vulnerable-abandoned.php',
                            'name' => 'Vulnerable Abandoned',
                            'version' => '1.0.4',
                            'active' => true,
                        ],
                        [
                            'slug' => 'active-healthy/active-healthy.php',
                            'name' => 'Active Healthy',
                            'version' => '2.1.0',
                            'active' => true,
                        ],
                        [
                            'slug' => 'custom-inhouse/custom-inhouse.php',
                            'name' => 'Custom In-House',
                            'version' => '1.0.0',
                            'active' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $auditor = new ClosedPluginAuditor;
        $findings = $auditor->forSite($site);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]['slug'])->toBe('vulnerable-abandoned')
            ->and($findings[0]['name'])->toBe('Vulnerable Abandoned')
            ->and($findings[0]['version'])->toBe('1.0.4')
            ->and($findings[0]['reason'])->toBe('Removed due to security issue')
            ->and($findings[0]['closed_date'])->toBe('2023-11-20');
    });

    it('does NOT flag deactivated plugins even if they are closed on WP.org', function () {
        PluginDirectoryStatus::factory()->closed()->create([
            'slug' => 'dormant-closed',
        ]);

        $site = Site::factory()->create([
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        [
                            'slug' => 'dormant-closed/dormant.php',
                            'name' => 'Dormant Closed',
                            'version' => '1.0.0',
                            'active' => false, // deactivated in WordPress
                        ],
                    ],
                ],
            ],
        ]);

        $auditor = new ClosedPluginAuditor;
        $findings = $auditor->forSite($site);

        expect($findings)->toBeEmpty();
    });

    it('excludes inactive sites from monitored findings and count', function () {
        PluginDirectoryStatus::factory()->closed()->create(['slug' => 'abandoned-tool']);

        $site = Site::factory()->inactive()->create([
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        ['slug' => 'abandoned-tool/tool.php', 'active' => true],
                    ],
                ],
            ],
        ]);

        $auditor = new ClosedPluginAuditor;
        $monitored = $auditor->findingsForMonitoredSites();

        expect($monitored['sites'])->toBeEmpty()
            ->and($auditor->flaggedSiteCount())->toBe(0);
    });

    it('excludes operator-ignored sites from monitored findings and IssueCounter', function () {
        PluginDirectoryStatus::factory()->closed()->create(['slug' => 'abandoned-cart']);

        $site = Site::factory()->create([
            'is_inactive' => false,
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        ['slug' => 'abandoned-cart/cart.php', 'name' => 'Abandoned Cart', 'active' => true],
                    ],
                ],
            ],
        ]);

        $auditor = new ClosedPluginAuditor;
        expect($auditor->flaggedSiteCount())->toBe(1);

        // Operator suppresses issue via IgnoredIssue
        IgnoredIssue::create([
            'issue_type' => IgnoredIssue::TYPE_PLUGIN_CLOSED,
            'site_id' => $site->id,
            'reason' => 'Client is replacing this next sprint',
            'ignored_by_user_id' => $this->user->id,
        ]);

        $monitored = $auditor->findingsForMonitoredSites();
        expect($monitored['sites'])->toBeEmpty()
            ->and($auditor->flaggedSiteCount())->toBe(0);
    });

    it('surfaces closed plugins on the /issues dashboard and supports ignore/unignore flow', function () {
        PluginDirectoryStatus::factory()->closed('Closed due to trademark violation')->create([
            'slug' => 'trademarked-plugin',
            'closed_date' => '2024-02-15',
        ]);

        $site = Site::factory()->create([
            'domain' => 'test-issues.example.com',
            'is_inactive' => false,
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        [
                            'slug' => 'trademarked-plugin/trademarked.php',
                            'name' => 'Trademarked Plugin',
                            'version' => '3.2.1',
                            'active' => true,
                        ],
                    ],
                ],
            ],
        ]);

        // 1. Check Issues page renders section and finding
        $response = $this->actingAs($this->user)->get(route('issues.index'));
        $response->assertOk();
        $response->assertSee('section-plugins_closed');
        $response->assertSee('test-issues.example.com');
        $response->assertSee('Trademarked Plugin');
        $response->assertSee('v3.2.1');
        $response->assertSee('Closed due to trademark violation');

        // 2. Ignore the issue
        $this->actingAs($this->user)
            ->post(route('issues.ignore'), [
                'issue_type' => IgnoredIssue::TYPE_PLUGIN_CLOSED,
                'site_id' => $site->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ignored_issues', [
            'issue_type' => IgnoredIssue::TYPE_PLUGIN_CLOSED,
            'site_id' => $site->id,
        ]);

        // 3. Check Issues page now lists site under acknowledged/ignored bar
        $responseAfterIgnore = $this->actingAs($this->user)->get(route('issues.index'));
        $responseAfterIgnore->assertOk();
        $responseAfterIgnore->assertSee('1 site(s) ignored');
        $responseAfterIgnore->assertSee('test-issues.example.com');

        // 4. Unignore restores site
        $ignored = IgnoredIssue::where('site_id', $site->id)->firstOrFail();
        $this->actingAs($this->user)
            ->post(route('issues.unignore', $ignored))
            ->assertRedirect();

        $this->assertDatabaseMissing('ignored_issues', [
            'id' => $ignored->id,
        ]);
    });
});
