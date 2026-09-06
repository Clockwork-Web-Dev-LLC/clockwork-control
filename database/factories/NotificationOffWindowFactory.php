<?php

namespace Database\Factories;

use App\Models\NotificationOffWindow;
use App\Models\NotificationRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationOffWindow>
 */
class NotificationOffWindowFactory extends Factory
{
    protected $model = NotificationOffWindow::class;

    public function definition(): array
    {
        return [
            'recipient_id' => NotificationRecipient::factory(),
            'label' => 'Weekend',
            'start_dow' => 6,
            'start_time' => '18:00:00',
            'end_dow' => 0,
            'end_time' => '23:59:59',
            'timezone' => 'America/New_York',
            'enabled' => true,
        ];
    }
}
