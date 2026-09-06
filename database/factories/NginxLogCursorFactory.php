<?php

namespace Database\Factories;

use App\Models\NginxLogCursor;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NginxLogCursor>
 */
class NginxLogCursorFactory extends Factory
{
    protected $model = NginxLogCursor::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'log_path' => '/var/log/nginx/'.$this->faker->domainWord().'-access.log',
            'inode' => $this->faker->numberBetween(1000, 999999),
            'offset' => 0,
        ];
    }
}
