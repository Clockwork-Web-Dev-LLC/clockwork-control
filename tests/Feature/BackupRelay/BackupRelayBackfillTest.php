<?php

namespace Tests\Feature\BackupRelay;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupRelayBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_migration_only_enables_pressable_care_plan_sites(): void
    {
        $site1 = Site::query()->create([
            'domain' => 'pressable-care-plan.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '111',
            'care_plan_enabled' => true,
            'backup_relay_enabled' => false,
            'is_wordpress' => true,
        ]);

        $site2 = Site::query()->create([
            'domain' => 'spinupwp-care-plan.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'spinupwp_id' => 222,
            'care_plan_enabled' => true,
            'backup_relay_enabled' => false,
            'is_wordpress' => true,
        ]);

        $site3 = Site::query()->create([
            'domain' => 'pressable-no-care-plan.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '333',
            'care_plan_enabled' => false,
            'backup_relay_enabled' => false,
            'is_wordpress' => true,
        ]);

        $migration = require database_path('migrations/2026_09_05_190100_backfill_backup_relay_enabled_on_sites_table.php');
        $migration->up();

        $this->assertTrue((bool) $site1->fresh()->backup_relay_enabled);
        $this->assertFalse((bool) $site2->fresh()->backup_relay_enabled);
        $this->assertFalse((bool) $site3->fresh()->backup_relay_enabled);
    }
}
