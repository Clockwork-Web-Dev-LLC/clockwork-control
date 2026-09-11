<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Site has two mutually-exclusive shapes driven by hosting_provider —
 * SpinupWP sites have a real server_id + spinupwp_id, Pressable sites have
 * server_id always null + a pressable_site_id instead. definition() defaults
 * to the SpinupWP shape (the historical substrate); use ->pressable() for
 * the other shape explicitly. Don't call both spinupwp() and pressable() on
 * the same builder — pressable() doesn't clear a spinupwp() server_id.
 *
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'spinupwp_id' => $this->faker->unique()->numberBetween(1000, 999999),
            'pressable_site_id' => null,
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'domain' => $this->faker->unique()->domainName(),
            'is_wordpress' => true,
            'is_multisite' => false,
            'wordfence_enabled' => false,
            'llar_enabled' => false,
            'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
            'cloudflare_state' => Site::CF_UNKNOWN,
            'is_inactive' => false,
            'companion_installed' => false,
            'care_plan_enabled' => false,
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'unknown',
            'uptime_consecutive_failures' => 0,
            'contact_form_test_enabled' => false,
            'contact_form_test_failure_streak' => 0,
        ];
    }

    public function spinupwp(): static
    {
        return $this->state(fn () => [
            'server_id' => Server::factory(),
            'spinupwp_id' => $this->faker->unique()->numberBetween(1000, 999999),
            'pressable_site_id' => null,
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
        ]);
    }

    public function pressable(): static
    {
        return $this->state(fn () => [
            'server_id' => null,
            'spinupwp_id' => null,
            'pressable_site_id' => (string) $this->faker->unique()->numberBetween(10000, 999999),
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'cert_source' => Site::CERT_SOURCE_LIVE_PROBE,
        ]);
    }

    public function wpEngine(): static
    {
        return $this->state(fn () => [
            'server_id' => null,
            'spinupwp_id' => null,
            'pressable_site_id' => null,
            'wpengine_install_name' => $this->faker->unique()->lexify('install???????'),
            'hosting_provider' => Site::HOSTING_PROVIDER_WPENGINE,
            'cert_source' => Site::CERT_SOURCE_EXTERNAL,
        ]);
    }

    public function kinsta(): static
    {
        return $this->state(fn () => [
            'server_id' => null,
            'spinupwp_id' => null,
            'pressable_site_id' => null,
            'kinsta_environment_id' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'hosting_provider' => Site::HOSTING_PROVIDER_KINSTA,
            'cert_source' => Site::CERT_SOURCE_LIVE_PROBE,
        ]);
    }

    public function cloudways(): static
    {
        return $this->state(fn () => [
            'server_id' => Server::factory()->cloudways(),
            'spinupwp_id' => null,
            'pressable_site_id' => null,
            'cloudways_app_id' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'hosting_provider' => Site::HOSTING_PROVIDER_CLOUDWAYS,
            'cert_source' => Site::CERT_SOURCE_EXTERNAL,
        ]);
    }

    public function gridpane(): static
    {
        return $this->state(fn () => [
            'server_id' => Server::factory()->gridpane(),
            'spinupwp_id' => null,
            'pressable_site_id' => null,
            'gridpane_site_id' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'hosting_provider' => Site::HOSTING_PROVIDER_GRIDPANE,
            'cert_source' => Site::CERT_SOURCE_EXTERNAL,
        ]);
    }

    public function custom(): static
    {
        return $this->state(fn () => [
            'server_id' => null,
            'spinupwp_id' => null,
            'pressable_site_id' => null,
            'hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM,
            'cert_source' => Site::CERT_SOURCE_LIVE_PROBE,
        ]);
    }

    public function carePlan(): static
    {
        return $this->state(fn () => ['care_plan_enabled' => true]);
    }

    public function withCompanionInstalled(): static
    {
        return $this->state(fn () => [
            'companion_installed' => true,
            'companion_version' => '1.30.0',
            'companion_secret' => Str::random(40),
            'companion_last_seen_at' => now(),
        ]);
    }

    public function certExpiringInDays(int $days): static
    {
        return $this->state(fn () => [
            'cert_source' => Site::CERT_SOURCE_EXTERNAL,
            'cert_expires_at' => now()->addDays($days),
        ]);
    }

    public function inactive(string $reason = 'client cancelled'): static
    {
        return $this->state(fn () => ['is_inactive' => true, 'inactive_reason' => $reason]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
