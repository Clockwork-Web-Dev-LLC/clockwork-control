<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\ThreatLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreatLog>
 */
class ThreatLogFactory extends Factory
{
    protected $model = ThreatLog::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'source' => ThreatLog::SOURCE_NGINX,
            'event_at' => now(),
            'ip' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'request_path' => '/wp-login.php',
            'request_method' => 'POST',
            'status_code' => 403,
        ];
    }
}
