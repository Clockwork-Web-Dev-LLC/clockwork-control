<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteUptimeEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteUptimeEvent>
 */
class SiteUptimeEventFactory extends Factory
{
    protected $model = SiteUptimeEvent::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'error' => 'Connection timed out',
            'event_at' => now(),
        ];
    }

    public function up(): static
    {
        return $this->state(fn () => ['event_type' => SiteUptimeEvent::TYPE_UP, 'error' => null, 'status_code' => 200]);
    }
}
