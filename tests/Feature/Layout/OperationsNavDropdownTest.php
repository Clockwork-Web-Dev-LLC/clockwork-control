<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Modules\Core\InstalledModule;
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
