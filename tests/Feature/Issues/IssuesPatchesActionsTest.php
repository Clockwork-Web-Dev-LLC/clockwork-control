<?php

namespace Tests\Feature\Issues;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "Patches available" card on /issues must offer a way to actually
 * install the pending packages — a reboot alone never clears that list.
 */
class IssuesPatchesActionsTest extends TestCase
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

    public function test_patches_card_offers_install_updates_per_row_and_in_bulk(): void
    {
        $ready = Server::factory()->create(['name' => 'patch-a.example.com', 'ssh_password' => 'pw', 'upgrade_required' => true]);
        $queued = Server::factory()->create(['name' => 'patch-b.example.com', 'ssh_password' => 'pw', 'upgrade_required' => true, 'update_status' => Server::UPDATE_STATUS_QUEUED]);
        $noSsh = Server::factory()->create(['name' => 'patch-c.example.com', 'ssh_password' => null, 'clockwork_jail_provisioned_at' => null, 'upgrade_required' => true]);

        $response = $this->actingAs(User::factory()->create())->get(route('issues.index'));
        $response->assertOk();
        $html = $response->getContent();

        // Bulk button counts only the servers that can actually be queued.
        $this->assertStringContainsString('Install updates on all 1', $html);
        $this->assertStringContainsString('data-server-ids="'.$ready->id.'"', $html);

        // Per-row states: actionable / already in flight / no credentials.
        $this->assertStringContainsString('data-server-id="'.$ready->id.'"', $html);
        $this->assertStringContainsString('class="patch-now', $html);
        $this->assertMatchesRegularExpression('/data-server-id="'.$queued->id.'".*?fa-spinner[^<]*<\/i>\s*queued/s', $html);
        $this->assertMatchesRegularExpression('/data-server-id="'.$noSsh->id.'".*?no SSH/s', $html);

        // Reboot is only offered when the box actually needs one (the JS handler
        // text mentions "Reboot now" too, so check for the rendered button class).
        $this->assertStringNotContainsString('class="reboot-now', $html);
        $this->assertStringNotContainsString('reboot pending', $html);
    }

    public function test_reboot_now_only_appears_on_patches_rows_that_need_a_reboot(): void
    {
        Server::factory()->create(['name' => 'kernel.example.com', 'ssh_password' => 'pw', 'upgrade_required' => true, 'reboot_required' => true]);

        $html = $this->actingAs(User::factory()->create())->get(route('issues.index'))->assertOk()->getContent();

        $this->assertStringContainsString('reboot pending', $html);
        $this->assertStringContainsString('class="reboot-now', $html);
        $this->assertStringContainsString('Install updates', $html);
    }
}
