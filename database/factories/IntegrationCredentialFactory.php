<?php

namespace Database\Factories;

use App\Models\IntegrationCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationCredential>
 */
class IntegrationCredentialFactory extends Factory
{
    protected $model = IntegrationCredential::class;

    public function definition(): array
    {
        return [
            'integration' => 'spinupwp',
            'key' => 'token',
            'value' => $this->faker->uuid(),
        ];
    }
}
