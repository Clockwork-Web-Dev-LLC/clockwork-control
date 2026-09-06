<?php

namespace Database\Factories;

use App\Models\ContactFormTestRun;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactFormTestRun>
 */
class ContactFormTestRunFactory extends Factory
{
    protected $model = ContactFormTestRun::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'ran_at' => now(),
            'mode' => ContactFormTestRun::MODE_LIVE,
            'accepted' => true,
            'mail_invoked' => true,
            'mail_outcome' => ContactFormTestRun::MAIL_SENT,
            'status' => ContactFormTestRun::STATUS_SUCCESS,
        ];
    }

    public function failed(string $error = 'no email received'): static
    {
        return $this->state(fn () => [
            'status' => ContactFormTestRun::STATUS_FAILED,
            'error' => $error,
        ]);
    }
}
