<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteCoreChecksumAllowlist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteCoreChecksumAllowlist>
 */
class SiteCoreChecksumAllowlistFactory extends Factory
{
    protected $model = SiteCoreChecksumAllowlist::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'path' => 'wp-content/mu-plugins/'.$this->faker->word().'.php',
            'bucket' => SiteCoreChecksumAllowlist::BUCKET_UNEXPECTED,
            'reason' => 'known custom mu-plugin',
        ];
    }
}
