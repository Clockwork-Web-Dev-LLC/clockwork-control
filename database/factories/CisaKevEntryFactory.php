<?php

namespace Database\Factories;

use App\Models\CisaKevEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CisaKevEntry>
 */
class CisaKevEntryFactory extends Factory
{
    protected $model = CisaKevEntry::class;

    public function definition(): array
    {
        $year = $this->faker->numberBetween(2018, 2026);
        $num = $this->faker->numberBetween(1000, 99999);

        return [
            'cve' => sprintf('CVE-%d-%d', $year, $num),
            'vendor_project' => $this->faker->company(),
            'product' => $this->faker->word(),
            'date_added' => $this->faker->date(),
        ];
    }
}
