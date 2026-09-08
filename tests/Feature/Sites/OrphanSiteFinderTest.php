<?php

namespace Tests\Feature\Sites;

use App\Models\Site;
use App\Services\Sites\OrphanSiteFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SpinupWp\SpinupWpClient;
use Tests\TestCase;

class OrphanSiteFinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ignores_a_gridpane_site_with_a_null_spinupwp_id(): void
    {
        // A real SpinupWP orphan — should be classified.
        Site::factory()->spinupwp()->create(['spinupwp_id' => null, 'archived_at' => null]);
        // Never a SpinupWP orphan, regardless of spinupwp_id being null —
        // it's a different provider entirely.
        Site::factory()->gridpane()->create(['archived_at' => null]);

        $this->mock(SpinupWpClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('sites')->once()->andReturn([]);
        });

        $results = app(OrphanSiteFinder::class)->find();

        expect($results)->toHaveCount(1);
        expect($results->first()['site']->isSpinupWp())->toBeTrue();
    }

    public function test_it_skips_the_spinupwp_lookup_entirely_when_spinupwp_is_not_configured(): void
    {
        Site::factory()->spinupwp()->create(['spinupwp_id' => null, 'archived_at' => null]);

        $this->mock(SpinupWpClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(false);
            $mock->shouldReceive('sites')->never();
        });

        $results = app(OrphanSiteFinder::class)->find();

        expect($results)->toHaveCount(1);
        expect($results->first()['classification'])->toBe('unknown');
        expect($results->first()['parent'])->toBeNull();
    }

    public function test_it_returns_empty_when_there_are_no_orphans_at_all(): void
    {
        Site::factory()->spinupwp()->create(['archived_at' => null]);
        Site::factory()->gridpane()->create(['archived_at' => null]);

        $this->mock(SpinupWpClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->never();
            $mock->shouldReceive('sites')->never();
        });

        $results = app(OrphanSiteFinder::class)->find();

        expect($results)->toBeEmpty();
    }
}
