<?php

namespace Database\Factories;

use App\Models\NotificationRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationRecipient>
 */
class NotificationRecipientFactory extends Factory
{
    protected $model = NotificationRecipient::class;

    public function definition(): array
    {
        return [
            'type' => NotificationRecipient::TYPE_TEAM,
            'name' => $this->faker->name(),
            'phone' => '+1555555'.$this->faker->unique()->numerify('####'),
            'email' => $this->faker->safeEmail(),
            'notify_sms' => true,
            'notify_email' => false,
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    public function team(): static
    {
        return $this->state(fn () => [
            'type' => NotificationRecipient::TYPE_TEAM,
        ]);
    }

    public function client(?string $company = null): static
    {
        return $this->state(fn () => [
            'type' => NotificationRecipient::TYPE_CLIENT,
            'company' => $company ?? $this->faker->company(),
            'notify_email' => true,
        ]);
    }

    public function emailOnly(): static
    {
        return $this->state(fn () => [
            'phone' => null,
            'email' => $this->faker->safeEmail(),
            'notify_sms' => false,
            'notify_email' => true,
        ]);
    }
}
