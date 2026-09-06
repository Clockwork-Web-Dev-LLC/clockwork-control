<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->domainWord().'.example.com';

        return [
            'name' => $name,
            'hostname' => $this->faker->unique()->ipv4(),
            'ssh_port' => 22,
            'ssh_user' => 'clockwork-deploy',
            'provider' => Server::PROVIDER_DIGITALOCEAN,
            'provider_id' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'status' => Server::STATUS_GREEN,
            'is_ignored' => false,
            'auto_ban_llar' => false,
            'auto_ban_wordfence' => false,
            'upgrade_required' => false,
            'reboot_required' => false,
        ];
    }

    public function digitalOcean(): static
    {
        return $this->state(fn () => ['provider' => Server::PROVIDER_DIGITALOCEAN]);
    }

    public function hetzner(): static
    {
        return $this->state(fn () => ['provider' => Server::PROVIDER_HETZNER]);
    }

    public function azure(): static
    {
        return $this->state(fn () => ['provider' => Server::PROVIDER_AZURE]);
    }

    public function cloudways(): static
    {
        return $this->state(fn () => ['provider' => Server::PROVIDER_CLOUDWAYS]);
    }

    public function vultr(): static
    {
        return $this->state(fn () => ['provider' => Server::PROVIDER_VULTR]);
    }

    public function linode(): static
    {
        return $this->state(fn () => ['provider' => Server::PROVIDER_LINODE]);
    }

    public function gridpane(): static
    {
        return $this->state(fn () => ['provider' => Server::PROVIDER_GRIDPANE]);
    }

    public function ignored(string $reason = 'staging'): static
    {
        return $this->state(fn () => ['is_ignored' => true, 'ignore_reason' => $reason]);
    }

    public function statusRed(): static
    {
        return $this->state(fn () => ['status' => Server::STATUS_RED]);
    }

    public function statusYellow(): static
    {
        return $this->state(fn () => ['status' => Server::STATUS_YELLOW]);
    }
}
