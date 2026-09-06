<?php

namespace Database\Factories;

use App\Models\BackupRelayRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRelayRun>
 */
class BackupRelayRunFactory extends Factory
{
    protected $model = BackupRelayRun::class;

    public function definition(): array
    {
        $started = now()->subMinutes(30);

        return [
            'sites_total' => 10,
            'sites_archived' => 10,
            'sites_skipped' => 0,
            'sites_failed' => 0,
            'failures' => [],
            'started_at' => $started,
            'finished_at' => $started->copy()->addMinutes(25),
        ];
    }

    public function withFailures(int $failedCount = 1): static
    {
        return $this->state(fn (array $attrs) => [
            'sites_failed' => $failedCount,
            'sites_archived' => max(0, ($attrs['sites_total'] ?? 10) - $failedCount),
            'failures' => array_fill(0, $failedCount, ['domain' => 'example.com', 'error' => 'timeout']),
        ]);
    }
}
