<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\Tag;
use App\Models\User;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleStateResolver;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

beforeEach(function () {
    $this->mockIssueCounterZero();

    $this->server = Server::factory()->create(['is_ignored' => false]);
    $this->stagingServer = Server::factory()->create(['is_ignored' => false]);
    $this->stagingServer->tags()->attach(Tag::create(['name' => 'Staging', 'slug' => 'staging']));
});

describe('GET /seo', function () {
    it('redirects to login when unauthenticated', function () {
        $this->get(route('seo.index'))->assertRedirect(route('login'));
    });

    it('lists blocking production sites ahead of indexable ones with fleet totals', function () {
        Site::factory()->create([
            'server_id' => $this->server->id,
            'domain' => 'aaa-healthy.com',
            'seo_indexable' => true,
            'seo_checked_at' => now(),
        ]);
        Site::factory()->create([
            'server_id' => $this->server->id,
            'domain' => 'zzz-blocked.com',
            'seo_indexable' => false,
            'seo_blocked_reason' => 'meta_noindex',
            'seo_checked_at' => now(),
        ]);
        Site::factory()->create([
            'server_id' => $this->server->id,
            'domain' => 'mmm-new.com',
            'seo_indexable' => true,
            'seo_checked_at' => null,
        ]);
        Site::factory()->create([
            'server_id' => $this->server->id,
            'domain' => 'bbb-off.com',
            'seo_monitoring_enabled' => false,
        ]);

        $response = $this->actingAs(User::factory()->create())->get(route('seo.index'));

        $response->assertOk();
        $response->assertSeeInOrder(['zzz-blocked.com', 'mmm-new.com', 'aaa-healthy.com', 'bbb-off.com']);
        $response->assertSee('Blocking Search Engines');
        $response->assertSee('Meta noindex');
        $response->assertSee('Not checked yet');
        expect($response->viewData('totals'))->toMatchArray([
            'sites' => 4,
            'indexable' => 1,
            'blocking' => 1,
            'unchecked' => 1,
            'disabled' => 1,
        ]);
    });

    it('leaves out inactive sites and sites on staging servers', function () {
        Site::factory()->create([
            'server_id' => $this->server->id,
            'domain' => 'retired.com',
            'is_inactive' => true,
        ]);
        Site::factory()->create([
            'server_id' => $this->stagingServer->id,
            'domain' => 'staging-clone.com',
            'seo_indexable' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('seo.index'))
            ->assertOk()
            ->assertDontSee('retired.com')
            ->assertDontSee('staging-clone.com');
    });
});

it('adds an SEO link to the gear menu', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.diagnostics.index'))
        ->assertOk()
        ->assertSee(route('seo.index'), false);
});

it('drops the nav link when the module is disabled', function () {
    InstalledModule::updateOrCreate(
        ['module_id' => 'seo'],
        ['name' => 'SEO', 'enabled' => false, 'source' => 'bundled', 'status' => 'active']
    );
    app(ModuleStateResolver::class)->flush();

    $this->actingAs(User::factory()->create())
        ->get(route('settings.diagnostics.index'))
        ->assertOk()
        ->assertDontSee(route('seo.index'), false);
});
