<?php

namespace Database\Factories;

use App\Models\ContactFormTest;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactFormTest>
 */
class ContactFormTestFactory extends Factory
{
    protected $model = ContactFormTest::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'slot' => 1,
            'form_id' => (string) $this->faker->numberBetween(1, 999),
            'form_plugin' => Site::CONTACT_FORM_PLUGIN_CF7,
            'frequency' => ContactFormTest::FREQUENCY_DAILY,
            'enabled' => true,
            'state' => ContactFormTest::STATE_PENDING,
            'failure_streak' => 0,
        ];
    }

    public function failing(int $streak = 2): static
    {
        return $this->state(fn () => [
            'state' => ContactFormTest::STATE_FAILED,
            'failure_streak' => $streak,
        ]);
    }
}
