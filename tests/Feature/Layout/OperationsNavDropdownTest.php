<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleRegistry;
use Modules\Core\ModuleStateResolver;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('Desktop Operations nav dropdown', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
        $server = Server::factory()->create();
        Site::factory()->spinupwp()->create(['server_id' => $server->id]);
    });

    it('lists the same Operations pages as the mobile drawer', function () {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $menu = str($html)->after('id="cw-ops-menu"')->before('</template>')->toString();

        expect($menu)
            ->toContain(route('capacity.index'))
            ->toContain(route('operations.server-updates.index'))
            ->toContain(route('maintenance-history.index'))
            ->toContain(route('ai-remedy.index'));
    });

    it('drops AiRemedy from the dropdown when the module is disabled', function () {
        InstalledModule::create(['module_id' => 'ai-remedy', 'name' => 'AiRemedy', 'enabled' => false]);
        app(ModuleStateResolver::class)->flush();

        $html = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $menu = str($html)->after('id="cw-ops-menu"')->before('</template>')->toString();

        expect($menu)
            ->toContain(route('capacity.index'))
            ->not->toContain('AiRemedy');
    });
});

describe('Command Center rail Modules group', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
        $server = Server::factory()->create();
        Site::factory()->spinupwp()->create(['server_id' => $server->id]);
    });

    it('lists every visible module nav item except AiRemedy, like the mobile drawer', function () {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $rail = str($html)->after('<!-- Modules Navigation')->before('<!-- Bottom Sidebar Footer')->toString();

        $expected = array_filter(
            app(ModuleRegistry::class)->navItems(),
            fn ($item) => $item->isVisible() && $item->route !== 'ai-remedy.index',
        );

        expect($expected)->not->toBeEmpty();
        foreach ($expected as $item) {
            expect($rail)->toContain(route($item->route));
        }
        expect($rail)->not->toContain(route('ai-remedy.index'));
    });
});

describe('Command Center rail collapsible groups', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
        $server = Server::factory()->create();
        Site::factory()->spinupwp()->create(['server_id' => $server->id]);
    });

    it('renders Operations, Configuration and Modules collapsed on an unrelated page', function () {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        foreach (['operations', 'configuration', 'modules'] as $group) {
            $block = str($html)->after("railGroup('{$group}', false)")->before('</a>')->toString();
            expect($block)->toMatch('/x-show="!sidebarOpen \|\| open"\s+style="display: none"/');
        }
    });

    it('opens the group that contains the current page', function () {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('capacity.index'))
            ->assertOk()
            ->getContent();

        expect($html)->toContain("railGroup('operations', true)")
            ->toContain("railGroup('configuration', false)");

        $ops = str($html)->after("railGroup('operations', true)")->before('</a>')->toString();
        expect($ops)->not->toContain('style="display: none"');
    });
});
