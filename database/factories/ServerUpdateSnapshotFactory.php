<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerUpdateSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerUpdateSnapshot>
 */
class ServerUpdateSnapshotFactory extends Factory
{
    protected $model = ServerUpdateSnapshot::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'polled_at' => now(),
            'total_updates' => 0,
            'security_updates' => 0,
            'reboot_required' => false,
            'poll_status' => ServerUpdateSnapshot::STATUS_OK,
        ];
    }

    public function withPendingUpdates(int $total = 5, int $security = 2): static
    {
        return $this->state(fn () => ['total_updates' => $total, 'security_updates' => $security]);
    }

    public function sshFailed(string $error = 'connection refused'): static
    {
        return $this->state(fn () => ['poll_status' => ServerUpdateSnapshot::STATUS_SSH_FAILED, 'poll_error' => $error]);
    }
}
