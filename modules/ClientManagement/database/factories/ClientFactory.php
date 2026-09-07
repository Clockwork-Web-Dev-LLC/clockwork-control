<?php

namespace Modules\ClientManagement\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ClientManagement\Models\Client;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'company_name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'additional_emails' => [fake()->safeEmail(), fake()->safeEmail()],
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'notes' => fake()->sentence(),
        ];
    }
}
